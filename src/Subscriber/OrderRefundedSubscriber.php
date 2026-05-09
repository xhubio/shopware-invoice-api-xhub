<?php

declare(strict_types=1);

namespace Xhubio\InvoiceApiXhub\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Xhubio\InvoiceApiXhub\Enum\InvoiceType;
use Xhubio\InvoiceApiXhub\MessageQueue\GenerateInvoiceMessage;

/**
 * Refund → credit-note pipeline.
 *
 * Shopware analogue of the WooCommerce `woocommerce_order_refunded` hook
 * in `Order_Handler::handle_refund()`. Refunds in Shopware are surfaced
 * as state transitions on `order_transaction` (full refund: 'refunded';
 * partial refund: 'refunded_partially'). When either fires, we dispatch
 * a credit-note generate so the merchant has a Storno document to file
 * for tax purposes.
 *
 * Tax-law context: §14 UStG (DE), §11 Abs. 12 UStG (AT) and the comparable
 * EU VAT Directive 2006/112/EC require a corrective document referencing
 * the original invoice for every refunded amount. XRechnung BT-3 carries
 * `type=380` for invoice and `type=381` for credit-note; the OrderMapper
 * receives `type='credit_note'` in the message and emits the right BT-3.
 *
 * We do NOT check whether an original invoice exists before dispatching:
 * the OrderMapper / API will reject a credit-note that has no reference,
 * and surfacing that error to the merchant is more useful than silently
 * swallowing the refund event.
 */
final class OrderRefundedSubscriber implements EventSubscriberInterface
{
    private const LOG_SOURCE = 'invoice-api-xhub';

    /** Shopware technicalNames for refund-state transitions. */
    private const REFUND_STATES = [
        'refunded',
        'refunded_partially',
    ];

    /**
     * @param EntityRepository<OrderTransactionCollection> $orderTransactionRepository
     */
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly EntityRepository $orderTransactionRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            StateMachineTransitionEvent::class => 'onTransition',
        ];
    }

    public function onTransition(StateMachineTransitionEvent $event): void
    {
        if ('order_transaction' !== $event->getEntityName()) {
            return;
        }

        $toState = $event->getToPlace()->getTechnicalName();
        if (!\in_array($toState, self::REFUND_STATES, true)) {
            return;
        }

        $transactionId = $event->getEntityId();
        $context       = $event->getContext();

        $orderId = $this->resolveOrderId($transactionId, $context);
        if (null === $orderId) {
            $this->logger->warning(
                sprintf('Refund event for order_transaction %s — could not resolve order id.', $transactionId),
                ['source' => self::LOG_SOURCE],
            );

            return;
        }

        try {
            $this->bus->dispatch(new GenerateInvoiceMessage($orderId, InvoiceType::CreditNote));
            $this->logger->info(
                sprintf('Dispatched credit-note generation for order %s (transaction=%s, state=%s)', $orderId, $transactionId, $toState),
                ['source' => self::LOG_SOURCE],
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to dispatch credit-note generation: ' . $e->getMessage(),
                [
                    'source'        => self::LOG_SOURCE,
                    'orderId'       => $orderId,
                    'transactionId' => $transactionId,
                    'exception'     => $e,
                ],
            );
        }
    }

    private function resolveOrderId(string $transactionId, Context $context): ?string
    {
        $criteria = new Criteria([$transactionId]);
        $transaction = $this->orderTransactionRepository->search($criteria, $context)->first();

        if (!$transaction instanceof OrderTransactionEntity) {
            return null;
        }

        return $transaction->getOrderId();
    }
}
