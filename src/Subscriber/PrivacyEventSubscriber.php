<?php

declare(strict_types=1);

namespace Xhubio\InvoiceApiXhub\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeletedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Xhubio\InvoiceApiXhub\Service\PrivacyService;

/**
 * GDPR / DSGVO event bridge — listens for `customer.deleted` and forwards
 * the affected customer ids to the PrivacyService for invoice-data wipe.
 *
 * Shopware fires `<entity>.deleted` (via EntityDeletedEvent) on every DAL
 * delete; the event name carries the entity slug so we can subscribe with
 * key `customer.deleted` and avoid handling the broader EntityDeletedEvent
 * for unrelated entities.
 *
 * Why a thin subscriber instead of the eraser logic inline:
 *   - PrivacyService is also called from the manual data-cleanup admin
 *     endpoint and from uninstall flows; keeping it as a service means
 *     identical behaviour across all three entry points.
 *   - The subscriber must never throw — a failing privacy wipe must not
 *     abort the customer-delete transaction (Shopware would roll the
 *     whole delete back). We catch and log instead.
 *
 * Note: an EntityDeletedEvent fires AFTER the row is gone, so we cannot
 * load the customer entity here. The event's getIds() method returns the
 * deleted primary keys directly, which is exactly what PrivacyService
 * needs to query orders by `orderCustomer.customerId`.
 */
final class PrivacyEventSubscriber implements EventSubscriberInterface
{
    private const LOG_SOURCE = 'invoice-api-xhub';

    public function __construct(
        private readonly PrivacyService $privacyService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'customer.deleted' => 'onCustomerDeleted',
        ];
    }

    public function onCustomerDeleted(EntityDeletedEvent $event): void
    {
        try {
            $ids = $event->getIds();
            if ([] === $ids) {
                return;
            }
            $this->privacyService->eraseForCustomerIds($ids, $event->getContext());
        } catch (\Throwable $e) {
            // Never let a privacy wipe abort the original delete. We log
            // so a missed wipe is visible in the platform log; the admin
            // can re-run the wipe manually if needed.
            $this->logger->error(
                'PrivacyEventSubscriber: erase failed: ' . $e->getMessage(),
                [
                    'source'    => self::LOG_SOURCE,
                    'exception' => $e,
                ],
            );
        }
    }
}
