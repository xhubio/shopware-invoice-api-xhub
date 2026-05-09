<?php

declare(strict_types=1);

namespace Xhubio\InvoiceApiXhub\Controller\Admin;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
use Xhubio\InvoiceApiXhub\MessageQueue\GenerateInvoiceMessage;
use Xhubio\InvoiceApiXhub\Service\InvoiceFileStorage;

/**
 * Admin REST endpoints called by the Vue.js admin module.
 *
 * Routes are registered under `/api/_action/invoice-api-xhub/*` and protected
 * by Shopware's `_acl` defaults so only operators with the listed permissions
 * can hit them. Three endpoints are exposed:
 *  - POST   /api/_action/invoice-api-xhub/regenerate        body { orderId, type? }
 *  - GET    /api/_action/invoice-api-xhub/download/{orderId}
 *  - GET    /api/_action/invoice-api-xhub/logs/{orderId}
 *
 * The controller is intentionally a plain class (not extending
 * Shopware's AbstractController) — it has no need for the framework's
 * convenience methods (json(), render(), etc.) and skipping AbstractController
 * keeps the DI graph minimal (no setContainer call required in services.xml).
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class InvoiceController
{
    /**
     * MIME-Whitelist for download. Mirrors the WooCommerce P0-3 fix: never
     * trust a MIME stored in custom fields; derive it from the filename
     * extension and reject everything outside the whitelist (falling back
     * to application/octet-stream so the browser does not auto-render).
     */
    private const ALLOWED_MIMES = [
        'pdf' => 'application/pdf',
        'xml' => 'application/xml',
    ];

    /**
     * @param EntityRepository<OrderCollection> $orderRepository
     */
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly InvoiceFileStorage $fileStorage,
        private readonly EntityRepository $orderRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/api/_action/invoice-api-xhub/regenerate',
        name: 'api.action.invoice_api_xhub.regenerate',
        defaults: ['_acl' => ['order:update']],
        methods: ['POST']
    )]
    public function regenerate(RequestDataBag $body, Context $context): JsonResponse
    {
        $orderId = (string) $body->get('orderId', '');
        $type = (string) $body->get('type', 'invoice');

        // Shopware UUIDs are hex32 (no dashes). Validate strictly so we never
        // pass user-controlled input through to the repository or the queue.
        if ($orderId === '' || preg_match('/^[a-f0-9]{32}$/', $orderId) !== 1) {
            return new JsonResponse(
                ['success' => false, 'message' => 'Invalid orderId'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        if (!in_array($type, ['invoice', 'credit_note'], true)) {
            return new JsonResponse(
                ['success' => false, 'message' => 'Invalid type'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        // Clear existing custom fields so the re-generation starts fresh.
        // Setting individual keys to null on a JSON custom-field column is
        // the supported way to "unset" them in Shopware's DAL.
        $this->orderRepository->update([[
            'id' => $orderId,
            'customFields' => [
                'invoice_api_xhub_filename' => null,
                'invoice_api_xhub_filepath' => null,
                'invoice_api_xhub_data' => null,
                'invoice_api_xhub_last_error' => null,
            ],
        ]], $context);

        $this->bus->dispatch(new GenerateInvoiceMessage($orderId, $type));

        $this->logger->info('invoice-api-xhub: regenerate queued', [
            'orderId' => $orderId,
            'type' => $type,
        ]);

        return new JsonResponse([
            'success' => true,
            'message' => 'Re-generation queued',
            'orderId' => $orderId,
        ], Response::HTTP_ACCEPTED);
    }

    #[Route(
        path: '/api/_action/invoice-api-xhub/download/{orderId}',
        name: 'api.action.invoice_api_xhub.download',
        defaults: ['_acl' => ['order:read']],
        methods: ['GET'],
        requirements: ['orderId' => '[0-9a-f]{32}']
    )]
    public function download(string $orderId, Context $context): Response
    {
        $criteria = new Criteria([$orderId]);
        $order = $this->orderRepository->search($criteria, $context)->first();

        if ($order === null) {
            return new JsonResponse(
                ['success' => false, 'message' => 'Order not found'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $cf = $order->getCustomFields() ?? [];
        $filename = $cf['invoice_api_xhub_filename'] ?? null;
        $filepath = $cf['invoice_api_xhub_filepath'] ?? null;

        if (!is_string($filename) || $filename === '' || !is_string($filepath) || $filepath === '') {
            return new JsonResponse(
                ['success' => false, 'message' => 'No invoice file for this order'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $bytes = $this->fileStorage->read($filepath);
        if ($bytes === null) {
            return new JsonResponse(
                ['success' => false, 'message' => 'Invoice file not found on disk'],
                Response::HTTP_NOT_FOUND,
            );
        }

        // Derive the MIME from the filename extension and clamp it to the
        // whitelist. Anything unknown becomes application/octet-stream so
        // the browser downloads instead of attempting to render it inline.
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mime = self::ALLOWED_MIMES[$ext] ?? 'application/octet-stream';

        // Sanitise the filename for the Content-Disposition header. We strip
        // anything outside [A-Za-z0-9._-] to defeat header-splitting and
        // path-traversal attempts coming from a tampered customField value.
        $safeFilename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? 'invoice';
        if ($safeFilename === '') {
            $safeFilename = 'invoice';
        }

        return new Response($bytes, Response::HTTP_OK, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="' . $safeFilename . '"',
            'Content-Length' => (string) strlen($bytes),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    #[Route(
        path: '/api/_action/invoice-api-xhub/logs/{orderId}',
        name: 'api.action.invoice_api_xhub.logs',
        defaults: ['_acl' => ['order:read']],
        methods: ['GET'],
        requirements: ['orderId' => '[0-9a-f]{32}']
    )]
    public function logs(string $orderId, Context $context): JsonResponse
    {
        // MVP: derive a synthetic "current state" log from the order's
        // custom-fields so the Vue Logs tab has something to render. A real
        // append-only audit log (separate plugin table) is Phase 1.x.
        $criteria = new Criteria([$orderId]);
        $order = $this->orderRepository->search($criteria, $context)->first();

        if ($order === null) {
            return new JsonResponse(['entries' => []], Response::HTTP_OK);
        }

        $cf = $order->getCustomFields() ?? [];
        $entries = [];

        $filename = $cf['invoice_api_xhub_filename'] ?? null;
        if (is_string($filename) && $filename !== '') {
            $entries[] = [
                'timestamp' => $cf['invoice_api_xhub_generated_at'] ?? null,
                'action' => 'generate',
                'format' => $this->extractFormat($filename),
                'status' => 'success',
                'filename' => $filename,
                'message' => '',
            ];
        }

        $lastError = $cf['invoice_api_xhub_last_error'] ?? null;
        if (is_string($lastError) && $lastError !== '') {
            $entries[] = [
                'timestamp' => null,
                'action' => 'generate',
                'format' => null,
                'status' => 'error',
                'filename' => null,
                'message' => $lastError,
            ];
        }

        return new JsonResponse(['entries' => $entries], Response::HTTP_OK);
    }

    /**
     * Extract a format slug from an invoice filename. Naming convention used
     * by InvoiceGenerator is `<orderNumber>_<format>.<ext>` for the variant
     * formats and a plain `.pdf` / `.xml` for the canonical PDF / UBL output.
     */
    private function extractFormat(string $filename): ?string
    {
        if (str_contains($filename, '_xrechnung.')) {
            return 'xrechnung';
        }
        if (str_contains($filename, '_zugferd.')) {
            return 'zugferd';
        }
        if (str_ends_with(strtolower($filename), '.pdf')) {
            return 'pdf';
        }
        if (str_ends_with(strtolower($filename), '.xml')) {
            return 'xml';
        }

        return null;
    }
}
