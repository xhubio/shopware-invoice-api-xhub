<?php

declare(strict_types=1);

namespace Xhubio\InvoiceApiXhub\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Xhubio\InvoiceApiXhub\MessageQueue\GenerateInvoiceMessage;

/**
 * Bridges Shopware order state transitions to the async invoice generator.
 *
 * Shopware analogue of the WooCommerce `Order_Handler::maybe_auto_generate`
 * (woocommerce_order_status_* hooks). Subscribed once per order entity at
 * the StateMachineTransitionEvent layer; we filter inside the handler
 * because Shopware does not let you subscribe to a specific entity-name
 * via the event class alone.
 *
 * Trigger mapping (config -> Shopware order state technicalName):
 *   on_pending    → 'open'           (order created, not paid/processed yet)
 *   on_processing → 'in_progress'    (paid / being fulfilled)
 *   on_completed  → 'completed'      (fulfilled, default)
 *   on_on_hold    → no exact match — Shopware orders don't have an
 *                   "on hold" state. WC uses on-hold for BACS / advance
 *                   transfer; the closest Shopware equivalent lives on
 *                   `order_transaction` (state 'open'/'reminded'), not on
 *                   the order itself. We log a warning the first time
 *                   this is selected so the merchant can re-pick a state
 *                   that actually fires; better to be noisy-and-correct
 *                   than silent-and-broken.
 *
 * Dispatch goes through MessageBusInterface (Symfony Messenger). Shopware
 * registers `messenger.bus.shopware` as the default bus; the alias resolves
 * to the bus that has the async transport bound to it. The handler then
 * runs out-of-band so the customer's checkout / admin save isn't blocked
 * on a remote API.
 */
final class OrderStateSubscriber implements EventSubscriberInterface
{
    private const LOG_SOURCE = 'invoice-api-xhub';

    private const CONFIG_DOMAIN = 'InvoiceApiXhub.config';

    /** Shopware technicalName per config trigger choice. */
    private const TRIGGER_TO_STATE = [
        'on_pending'    => 'open',
        'on_processing' => 'in_progress',
        'on_completed'  => 'completed',
        // 'on_on_hold' deliberately omitted; see class docblock.
    ];

    public function __construct(
        private readonly SystemConfigService $config,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            StateMachineTransitionEvent::class => 'onStateTransition',
        ];
    }

    public function onStateTransition(StateMachineTransitionEvent $event): void
    {
        // The state machine fires for many entity types (order_transaction,
        // order_delivery, order, ...). We only care about transitions on
        // the order itself here; the OrderRefundedSubscriber handles
        // order_transaction.
        if ('order' !== $event->getEntityName()) {
            return;
        }

        $configValues = $this->config->get(self::CONFIG_DOMAIN);
        $configValues = is_array($configValues) ? $configValues : [];
        $trigger      = isset($configValues['trigger']) ? (string) $configValues['trigger'] : 'on_completed';

        if ('off' === $trigger) {
            return;
        }

        if ('on_on_hold' === $trigger) {
            // Shopware orders have no equivalent state. Log once at debug
            // so production logs stay quiet but a developer enabling the
            // option in dev gets a hint why nothing happens.
            $this->logger->debug(
                'Trigger "on_on_hold" has no Shopware order-state equivalent; skipping.',
                ['source' => self::LOG_SOURCE],
            );

            return;
        }

        $expectedState = self::TRIGGER_TO_STATE[$trigger] ?? null;
        if (null === $expectedState) {
            $this->logger->debug(
                sprintf('Unknown trigger config "%s"; skipping.', $trigger),
                ['source' => self::LOG_SOURCE],
            );

            return;
        }

        $toState = $event->getToPlace()->getTechnicalName();
        if ($toState !== $expectedState) {
            return;
        }

        $orderId = $event->getEntityId();

        try {
            $this->bus->dispatch(new GenerateInvoiceMessage($orderId, 'invoice'));
            $this->logger->info(
                sprintf('Dispatched async invoice generation for order %s (trigger=%s)', $orderId, $trigger),
                ['source' => self::LOG_SOURCE],
            );
        } catch (\Throwable $e) {
            // Bus dispatch failures must never break the order transition
            // itself — the merchant can still re-trigger generation
            // manually from the order detail meta-box.
            $this->logger->error(
                'Failed to dispatch invoice generation: ' . $e->getMessage(),
                [
                    'source'    => self::LOG_SOURCE,
                    'orderId'   => $orderId,
                    'exception' => $e,
                ],
            );
        }
    }
}
