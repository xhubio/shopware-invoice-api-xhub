<?php

declare(strict_types=1);

namespace Xhubio\InvoiceApiXhub\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Orchestrator for invoice generation — Shopware analogue of the WooCommerce
 * `Invoice_Api_Xhub_Order_Handler::generate_for_order()`.
 *
 * Single entry-point for both auto (event-driven) and manual (controller-
 * driven) generation:
 *
 *   $result = $generator->generate($orderId, $context);
 *   $result = $generator->generate($orderId, $context, 'credit_note');
 *
 * The contract is intentionally identical for both paths so the
 * EventSubscriber, the Messenger handler, and the admin controller can
 * call the same method and surface the same `['success'=>bool, ...]`
 * outcome to the caller.
 *
 * Persistence model:
 *   - File bytes go to the private Flysystem under
 *     `invoice-api-xhub/<orderId>/<filename>` (see InvoiceFileStorage).
 *   - Order custom fields hold the relative path, filename, mime, format,
 *     generation timestamp and last error. The legacy `_data` field
 *     (base64-in-meta) is kept exclusively as an "always cleared" key so
 *     historic plugin versions cannot leave stale base64 around.
 *   - Compliance warnings (success=false but data present) are reserved
 *     for a future iteration; the API client today raises on success=false,
 *     so we treat any thrown exception as a hard failure.
 *
 * §14 UStG idempotency: the InvoiceNumberService allocates a *new* sequence
 * number on every successful re-generate. That is intentional — when a
 * merchant re-runs the generate (e.g., after fixing seller VAT-ID) the new
 * file overwrites the previous one and gets a new gap-free number. The
 * "burned" old number stays committed in the seq table; gaps are tolerated
 * by BFH-Rechtsprechung, duplicates are not.
 */
final class InvoiceGenerator
{
    private const LOG_SOURCE = 'invoice-api-xhub';

    private const CONFIG_DOMAIN = 'InvoiceApiXhub.config';

    private const CF_FILENAME    = 'invoice_api_xhub_filename';
    private const CF_FILEPATH    = 'invoice_api_xhub_filepath';
    private const CF_MIME        = 'invoice_api_xhub_mime';
    private const CF_FORMAT      = 'invoice_api_xhub_format';
    private const CF_GENERATED   = 'invoice_api_xhub_generated_at';
    private const CF_LAST_ERROR  = 'invoice_api_xhub_last_error';
    private const CF_DATA_LEGACY = 'invoice_api_xhub_data';
    private const CF_TEMPLATE_ID = 'invoice_api_xhub_template_id';
    private const CF_INVOICE_NO  = 'invoice_api_xhub_invoice_number';

    /**
     * @param EntityRepository<OrderCollection> $orderRepository
     */
    public function __construct(
        private readonly ApiClient $apiClient,
        private readonly OrderMapper $orderMapper,
        private readonly TemplateResolver $templateResolver,
        private readonly InvoiceFileStorage $fileStorage,
        private readonly SystemConfigService $config,
        private readonly EntityRepository $orderRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Generate (or skip) an invoice for the given order.
     *
     * @return array{success:bool,message:string,filename?:?string,skipped?:bool}
     */
    public function generate(string $orderId, Context $context, string $type = 'invoice'): array
    {
        $logCtx = ['source' => self::LOG_SOURCE, 'orderId' => $orderId, 'type' => $type];

        $order = $this->loadOrder($orderId, $context);
        if (!$order instanceof OrderEntity) {
            $msg = sprintf('Order %s not found.', $orderId);
            $this->logger->warning($msg, $logCtx);

            return ['success' => false, 'skipped' => false, 'message' => $msg];
        }

        $configValues = $this->loadConfig();

        $apiKey  = isset($configValues['apiKey']) ? trim((string) $configValues['apiKey']) : '';
        $baseUrl = isset($configValues['baseUrl']) && '' !== trim((string) $configValues['baseUrl'])
            ? trim((string) $configValues['baseUrl'])
            : 'https://service.invoice-api.xhub.io';

        if ('' === $apiKey) {
            $msg = 'API key is not configured. Open Invoice-api.xhub settings to add it.';
            $this->logger->warning($msg, $logCtx);
            $this->persistError($orderId, $context, $order, $msg);

            return ['success' => false, 'skipped' => false, 'message' => $msg];
        }

        // Skip refund-only orders / broken imports — API rule BR-16 ("at
        // least one invoice line"). This is an intentional skip, NOT a
        // failure; we don't write to the last_error custom field so the
        // admin meta-box stays clean.
        $lineItems = $order->getLineItems();
        if (null === $lineItems || 0 === \count($lineItems)) {
            $this->logger->info('skipped: no line items', $logCtx);

            return ['success' => false, 'skipped' => true, 'message' => 'no line items'];
        }

        try {
            $payload = $this->orderMapper->mapToInvoice($order, $configValues, $type);
        } catch (\Throwable $e) {
            $this->logger->error(
                'OrderMapper failed: ' . $e->getMessage(),
                array_merge($logCtx, ['exception' => $e]),
            );
            $this->persistError($orderId, $context, $order, $e->getMessage());

            return ['success' => false, 'skipped' => false, 'message' => $e->getMessage()];
        }

        $templateId = $this->templateResolver->resolve($order, $configValues);

        $country = isset($configValues['country']) ? (string) $configValues['country'] : 'DE';
        $format  = isset($configValues['format']) ? (string) $configValues['format'] : 'xrechnung';

        // Filename: the API will return its own preferred filename, but we
        // need a deterministic local fallback in case the response omits it.
        // Mirror the WC convention: `<invoiceNumber>[_<format>].<ext>`.
        $invoiceNumber = $this->extractInvoiceNumber($payload, $orderId);
        $localFilename = $this->buildFilename($invoiceNumber, $format);

        try {
            // ApiClient::generate() builds the {invoice, formatOptions}
            // wrapper itself — we pass the inner mapped payload only.
            $response = $this->apiClient->generate(
                $country,
                $format,
                $payload,
                $templateId,
                $apiKey,
                $baseUrl,
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'ApiClient::generate failed: ' . $e->getMessage(),
                array_merge($logCtx, [
                    'country'   => $country,
                    'format'    => $format,
                    'exception' => $e,
                ]),
            );
            $this->persistError($orderId, $context, $order, $e->getMessage());

            return ['success' => false, 'skipped' => false, 'message' => $e->getMessage()];
        }

        // Authoritative success signal: data present AND a string. The API
        // sometimes echoes the input as `data` (object) on Zod failure; we
        // must NOT treat that as a file payload. ApiClient already throws
        // in that case (success=false), but the defensive check is cheap.
        if (empty($response['data']) || !is_string($response['data'])) {
            $msg = $this->apiClient->buildErrorMessage($response) ?? 'Invoice API returned no data.';
            $this->logger->error('generate returned no data: ' . $msg, $logCtx);
            $this->persistError($orderId, $context, $order, $msg);

            return ['success' => false, 'skipped' => false, 'message' => $msg];
        }

        $bytes = base64_decode($response['data'], true);
        if (false === $bytes) {
            $msg = 'API returned non-base64 data.';
            $this->logger->error($msg, $logCtx);
            $this->persistError($orderId, $context, $order, $msg);

            return ['success' => false, 'skipped' => false, 'message' => $msg];
        }

        $remoteFilename = isset($response['filename']) && is_string($response['filename']) && '' !== $response['filename']
            ? $response['filename']
            : $localFilename;

        try {
            $relativePath = $this->fileStorage->write($orderId, $remoteFilename, $bytes);
        } catch (\Throwable $e) {
            $this->logger->error(
                'FileStorage::write failed: ' . $e->getMessage(),
                array_merge($logCtx, ['exception' => $e]),
            );
            $this->persistError($orderId, $context, $order, $e->getMessage());

            return ['success' => false, 'skipped' => false, 'message' => $e->getMessage()];
        }

        $this->persistResult($orderId, $context, $order, $response, $relativePath, $remoteFilename, $format);

        $this->logger->info(
            sprintf(
                'Order %s generated: file=%s bytes=%d type=%s template=%s',
                $orderId,
                $remoteFilename,
                strlen($bytes),
                $type,
                $templateId ?? 'default',
            ),
            $logCtx,
        );

        return [
            'success'  => true,
            'skipped'  => false,
            'filename' => $remoteFilename,
            'message'  => '',
        ];
    }

    private function loadOrder(string $orderId, Context $context): ?OrderEntity
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('billingAddress');
        $criteria->addAssociation('billingAddress.country');
        $criteria->addAssociation('orderCustomer');
        $criteria->addAssociation('currency');
        $criteria->addAssociation('deliveries');
        $criteria->addAssociation('deliveries.shippingOrderAddress');
        $criteria->addAssociation('deliveries.shippingOrderAddress.country');
        $criteria->addAssociation('transactions');
        $criteria->addAssociation('transactions.paymentMethod');
        $criteria->addAssociation('addresses');

        $order = $this->orderRepository->search($criteria, $context)->first();

        return $order instanceof OrderEntity ? $order : null;
    }

    /**
     * Read all `InvoiceApiXhub.config.*` keys into a flat assoc array.
     * Keys are returned without the prefix (e.g. `apiKey`, `country`).
     *
     * @return array<string,mixed>
     */
    private function loadConfig(): array
    {
        $raw = $this->config->get(self::CONFIG_DOMAIN);

        return is_array($raw) ? $raw : [];
    }

    /**
     * Derive a deterministic filename from invoice number + format.
     *
     * Format → extension/suffix mapping:
     *   pdf       → INV-12.pdf
     *   xrechnung → INV-12_xrechnung.xml
     *   zugferd   → INV-12_zugferd.pdf
     *
     * Mirrors the WC reference for cross-shop consistency. Filenames are
     * sanitized to alphanumeric + dash/underscore/dot to avoid filesystem
     * issues across Linux/macOS/Windows uploads dirs.
     */
    private function buildFilename(string $invoiceNumber, string $format): string
    {
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', $invoiceNumber) ?? $invoiceNumber;
        $base = trim($base, '-_.');
        if ('' === $base) {
            $base = 'invoice';
        }

        $format = strtolower($format);

        return match ($format) {
            'xrechnung' => $base . '_xrechnung.xml',
            'zugferd'   => $base . '_zugferd.pdf',
            'pdf'       => $base . '.pdf',
            default     => $base . '.' . preg_replace('/[^a-z0-9]/', '', $format),
        };
    }

    /**
     * Pull the invoice number out of the mapped payload. The OrderMapper is
     * expected to set `invoice.number` (matching the live API contract);
     * fall back to the order id if that wasn't set so we still get a stable
     * filename for diagnosis.
     *
     * @param array<string,mixed> $payload
     */
    private function extractInvoiceNumber(array $payload, string $orderId): string
    {
        if (isset($payload['number']) && is_string($payload['number']) && '' !== $payload['number']) {
            return $payload['number'];
        }
        if (isset($payload['invoiceNumber']) && is_string($payload['invoiceNumber']) && '' !== $payload['invoiceNumber']) {
            return $payload['invoiceNumber'];
        }

        return 'invoice-' . $orderId;
    }

    /**
     * Persist a successful generation to order custom fields.
     *
     * @param array<string,mixed> $apiResponse
     */
    private function persistResult(
        string $orderId,
        Context $context,
        OrderEntity $order,
        array $apiResponse,
        string $relativeFilepath,
        string $filename,
        string $configuredFormat,
    ): void {
        $existing = $order->getCustomFields() ?? [];

        $mime = isset($apiResponse['mimeType']) && is_string($apiResponse['mimeType'])
            ? $apiResponse['mimeType']
            : 'application/octet-stream';
        $format = isset($apiResponse['format']) && is_string($apiResponse['format'])
            ? $apiResponse['format']
            : $configuredFormat;

        $update = array_merge($existing, [
            self::CF_FILENAME    => $filename,
            self::CF_FILEPATH    => $relativeFilepath,
            self::CF_MIME        => $mime,
            self::CF_FORMAT      => $format,
            self::CF_GENERATED   => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c'),
            self::CF_LAST_ERROR  => '',
            // Always clear any legacy base64 leftover from older plugin
            // versions so the order stays lean and GDPR-erase has less to
            // hunt down later.
            self::CF_DATA_LEGACY => '',
        ]);

        // Preserve template id if it was set as an override on the order.
        if (array_key_exists(self::CF_TEMPLATE_ID, $existing)) {
            $update[self::CF_TEMPLATE_ID] = $existing[self::CF_TEMPLATE_ID];
        }

        $this->orderRepository->update(
            [[
                'id'           => $orderId,
                'customFields' => $update,
            ]],
            $context,
        );
    }

    /**
     * Persist a failure message to the order's last-error custom field.
     * Defensive against a missing OrderEntity (caller may not have it yet).
     */
    private function persistError(string $orderId, Context $context, ?OrderEntity $order, string $errorMessage): void
    {
        $existing = $order?->getCustomFields() ?? [];

        try {
            $this->orderRepository->update(
                [[
                    'id'           => $orderId,
                    'customFields' => array_merge($existing, [
                        self::CF_LAST_ERROR => $errorMessage,
                    ]),
                ]],
                $context,
            );
        } catch (\Throwable $e) {
            // Last-error persistence must never bubble — we are already
            // in an error path; logging is the best we can do.
            $this->logger->error(
                'Failed to persist last_error custom field: ' . $e->getMessage(),
                [
                    'source'    => self::LOG_SOURCE,
                    'orderId'   => $orderId,
                    'exception' => $e,
                ],
            );
        }
    }

    /**
     * Reset every `invoice_api_xhub_*` custom field on an order. Used by
     * PrivacyService and as a hard reset hook from admin actions.
     */
    private function clearCustomFields(string $orderId, Context $context): void
    {
        $this->orderRepository->update(
            [[
                'id'           => $orderId,
                'customFields' => [
                    self::CF_FILENAME    => null,
                    self::CF_FILEPATH    => null,
                    self::CF_MIME        => null,
                    self::CF_FORMAT      => null,
                    self::CF_GENERATED   => null,
                    self::CF_LAST_ERROR  => null,
                    self::CF_DATA_LEGACY => null,
                    self::CF_INVOICE_NO  => null,
                ],
            ]],
            $context,
        );
    }
}
