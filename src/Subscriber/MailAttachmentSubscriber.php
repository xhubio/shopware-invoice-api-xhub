<?php

declare(strict_types=1);

namespace Xhubio\InvoiceApiXhub\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\MailTemplate\Service\Event\MailBeforeValidateEvent;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Xhubio\InvoiceApiXhub\Service\InvoiceFileStorage;

/**
 * Auto-attaches the generated invoice file to outgoing customer mails.
 *
 * Listens to `MailBeforeValidateEvent` (the earliest mail event with mutable
 * `data`); resolves the order from `templateData['order']` and, if the order
 * has an `invoice_api_xhub_filepath` custom field set, reads the bytes from
 * the private filesystem and adds them to `data['binAttachments']` so the
 * MailFactory will include them on the rendered Symfony Mime\Email.
 *
 * Skips silently when:
 *   - `attachToEmail` config flag is false (default true).
 *   - The mail has no order in `templateData` (registration, password-reset,
 *     newsletter, etc.).
 *   - The order has no invoice generated yet (filepath custom-field empty).
 *   - The on-disk file is missing (e.g., privacy-erased after generation).
 *
 * Failures are caught and logged so a broken attachment can never abort the
 * mail itself — Shopware would otherwise rollback the entire mail send.
 *
 * Ties into the "auto-email-attachment" competitor-parity feature: ALL major
 * Shopware invoicing competitors (Pickware, sevDesk, Lexware Office) attach
 * generated invoices to the order-confirmation mail by default; this listener
 * brings invoice-api.xhub on par.
 */
final class MailAttachmentSubscriber implements EventSubscriberInterface
{
    private const LOG_SOURCE = 'invoice-api-xhub';

    private const CONFIG_DOMAIN = 'InvoiceApiXhub.config';

    private const CF_FILEPATH = 'invoice_api_xhub_filepath';
    private const CF_FILENAME = 'invoice_api_xhub_filename';

    /** MIME-Whitelist mirrored from the admin download controller (P0-3 fix). */
    private const ALLOWED_MIMES = [
        'pdf' => 'application/pdf',
        'xml' => 'application/xml',
    ];

    /**
     * @param EntityRepository<OrderCollection> $orderRepository
     */
    public function __construct(
        private readonly InvoiceFileStorage $fileStorage,
        private readonly EntityRepository $orderRepository,
        private readonly SystemConfigService $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            MailBeforeValidateEvent::class => 'onMailBeforeValidate',
        ];
    }

    public function onMailBeforeValidate(MailBeforeValidateEvent $event): void
    {
        try {
            $configValues = $this->loadConfig();

            // Default true so existing installs keep behaving the same way
            // the config-help text promised since Wave 1.
            $attach = !\array_key_exists('attachToEmail', $configValues)
                || (bool) $configValues['attachToEmail'];

            if (!$attach) {
                return;
            }

            $orderId = $this->resolveOrderId($event);
            if (null === $orderId) {
                // Non-order mail (registration, password-reset, ...). Silent
                // skip — this listener only enriches order-related mails.
                return;
            }

            $order = $this->loadOrder($orderId, $event->getContext());
            if (!$order instanceof OrderEntity) {
                return;
            }

            $cf       = $order->getCustomFields() ?? [];
            $filepath = isset($cf[self::CF_FILEPATH]) && is_string($cf[self::CF_FILEPATH])
                ? $cf[self::CF_FILEPATH]
                : '';
            $filename = isset($cf[self::CF_FILENAME]) && is_string($cf[self::CF_FILENAME])
                ? $cf[self::CF_FILENAME]
                : '';

            if ('' === $filepath || '' === $filename) {
                return;
            }

            $bytes = $this->fileStorage->read($filepath);
            if (null === $bytes) {
                $this->logger->info(
                    sprintf('Skipping mail attachment for order %s — file %s missing on disk.', $orderId, $filepath),
                    ['source' => self::LOG_SOURCE],
                );

                return;
            }

            $mime = $this->mimeForFilename($filename);

            $data = $event->getData();
            /** @var list<array{content: resource|string, fileName: string|null, mimeType: string|null}> $existing */
            $existing = isset($data['binAttachments']) && is_array($data['binAttachments'])
                ? $data['binAttachments']
                : [];

            $existing[] = [
                'content'  => $bytes,
                'fileName' => $filename,
                'mimeType' => $mime,
            ];

            $event->addData('binAttachments', $existing);

            $this->logger->info(
                sprintf('Attached invoice %s to outgoing mail for order %s.', $filename, $orderId),
                ['source' => self::LOG_SOURCE],
            );
        } catch (\Throwable $e) {
            // Never abort a mail send because attachment fetching blew up.
            $this->logger->error(
                'MailAttachmentSubscriber failed: ' . $e->getMessage(),
                [
                    'source'    => self::LOG_SOURCE,
                    'exception' => $e,
                ],
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function loadConfig(): array
    {
        $raw = $this->config->get(self::CONFIG_DOMAIN);

        return is_array($raw) ? $raw : [];
    }

    /**
     * Resolve the order id out of the mail's templateData. Shopware places the
     * order under several keys depending on the trigger (order_confirmation,
     * order_state_change, document_*); we check the common ones in order of
     * frequency and return the first hit.
     */
    private function resolveOrderId(MailBeforeValidateEvent $event): ?string
    {
        $templateData = $event->getTemplateData();

        // Direct OrderEntity instance (most order-related mails).
        $candidate = $templateData['order'] ?? null;
        if ($candidate instanceof OrderEntity) {
            $id = $candidate->getId();

            return '' !== $id ? $id : null;
        }

        // Fallback: orderId scalar (some flow-builder paths).
        $orderId = $templateData['orderId'] ?? null;
        if (is_string($orderId) && '' !== $orderId) {
            return $orderId;
        }

        return null;
    }

    private function loadOrder(string $orderId, Context $context): ?OrderEntity
    {
        try {
            $criteria = new Criteria([$orderId]);
            $order    = $this->orderRepository->search($criteria, $context)->first();

            return $order instanceof OrderEntity ? $order : null;
        } catch (\Throwable $e) {
            $this->logger->error(
                'MailAttachmentSubscriber: order lookup failed: ' . $e->getMessage(),
                [
                    'source'    => self::LOG_SOURCE,
                    'orderId'   => $orderId,
                    'exception' => $e,
                ],
            );

            return null;
        }
    }

    /**
     * Derive a safe MIME from the filename extension. Falls back to
     * application/octet-stream when the extension is outside the whitelist
     * so unknown content cannot smuggle an inline-renderable type.
     */
    private function mimeForFilename(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return self::ALLOWED_MIMES[$ext] ?? 'application/octet-stream';
    }
}
