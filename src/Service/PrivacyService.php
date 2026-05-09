<?php

declare(strict_types=1);

namespace Xhubio\InvoiceApiXhub\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;

/**
 * GDPR / DSGVO eraser for invoice payloads stored on orders.
 *
 * Equivalent to the WooCommerce `Invoice_Api_Xhub_Privacy::erase_data()` —
 * when a customer is deleted (or a privacy-rights request is processed),
 * we wipe every `invoice_api_xhub_*` custom field on every order belonging
 * to that customer and delete the associated file directory under the
 * private Flysystem.
 *
 * The order itself is NOT deleted here: tax law (HGB §257, §147 AO,
 * comparable EU rules) requires merchants to retain order records for
 * 10 years. Removing the invoice payload is the strongest privacy action
 * we can take while staying compliant — the buyer's name and address
 * remain on the order entity itself, but the e-invoice document (which
 * embeds the same data again) is erased.
 *
 * Idempotent: running twice on the same customer is fine — the second
 * run finds no payloads and reports zero.
 */
final class PrivacyService
{
    private const LOG_SOURCE = 'invoice-api-xhub';

    private const CF_KEYS = [
        'invoice_api_xhub_filename',
        'invoice_api_xhub_filepath',
        'invoice_api_xhub_mime',
        'invoice_api_xhub_format',
        'invoice_api_xhub_generated_at',
        'invoice_api_xhub_last_error',
        'invoice_api_xhub_data',
        'invoice_api_xhub_invoice_number',
        'invoice_api_xhub_template_id',
        'invoice_api_xhub_leitweg_id',
        'invoice_api_xhub_buyer_reference',
    ];

    /**
     * @param EntityRepository<OrderCollection> $orderRepository
     */
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly InvoiceFileStorage $fileStorage,
        private readonly LoggerInterface $logger,
        private readonly ?AuditLogger $auditLogger = null,
    ) {
    }

    /**
     * Erase invoice data for every order belonging to the given customers.
     *
     * @param array<int,string> $customerIds Shopware customer UUIDs
     */
    public function eraseForCustomerIds(array $customerIds, Context $context): void
    {
        $customerIds = array_values(array_filter(array_unique($customerIds), 'is_string'));
        if ([] === $customerIds) {
            return;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('orderCustomer.customerId', $customerIds));
        // Custom fields live on the order entity itself; loading the order
        // is enough — no association needed for the wipe.
        $orders = $this->orderRepository->search($criteria, $context)->getEntities();

        $erasedOrders = 0;
        $erasedFiles  = 0;
        $orderIds     = [];

        foreach ($orders as $order) {
            if (!$order instanceof OrderEntity) {
                continue;
            }
            $orderId    = $order->getId();
            $orderIds[] = $orderId;
            $cf         = $order->getCustomFields() ?? [];

            $hasPayload = !empty($cf['invoice_api_xhub_filename'])
                || !empty($cf['invoice_api_xhub_filepath'])
                || !empty($cf['invoice_api_xhub_data']);

            // Always try to delete the file directory — partial state from
            // a failed earlier wipe could leave files without custom-field
            // markers. deleteForOrder is idempotent on missing dirs.
            try {
                $this->fileStorage->deleteForOrder($orderId);
                $erasedFiles++;
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'PrivacyService: failed to delete invoice files: ' . $e->getMessage(),
                    [
                        'source'    => self::LOG_SOURCE,
                        'orderId'   => $orderId,
                        'exception' => $e,
                    ],
                );
            }

            if (!$hasPayload && [] === array_intersect(self::CF_KEYS, array_keys($cf))) {
                continue;
            }

            $update = $cf;
            foreach (self::CF_KEYS as $key) {
                $update[$key] = null;
            }

            try {
                $this->orderRepository->update(
                    [[
                        'id'           => $orderId,
                        'customFields' => $update,
                    ]],
                    $context,
                );
                $erasedOrders++;
            } catch (\Throwable $e) {
                $this->logger->error(
                    'PrivacyService: failed to update order custom fields: ' . $e->getMessage(),
                    [
                        'source'    => self::LOG_SOURCE,
                        'orderId'   => $orderId,
                        'exception' => $e,
                    ],
                );
            }
        }

        // Wave 7D: also wipe the audit-trail rows for the affected orders.
        // The `invoice_api_xhub_audit` rows would otherwise outlive the
        // erased customFields and constitute a back-channel for the same
        // GDPR-controlled data (filenames carry order numbers, error
        // messages can leak buyer details). The audit wipe is a single
        // bulk DELETE, idempotent on already-erased orders.
        $erasedAuditRows = null !== $this->auditLogger && [] !== $orderIds
            ? $this->auditLogger->eraseForOrderIds($orderIds)
            : 0;

        $this->logger->info(
            sprintf(
                'PrivacyService: erased invoice data for %d customers (orders=%d, files=%d, audit=%d)',
                \count($customerIds),
                $erasedOrders,
                $erasedFiles,
                $erasedAuditRows,
            ),
            ['source' => self::LOG_SOURCE],
        );
    }
}
