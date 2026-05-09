<?php

declare(strict_types=1);

namespace Xhubio\InvoiceApiXhub\Storefront\Controller;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Xhubio\InvoiceApiXhub\Service\InvoiceFileStorage;

/**
 * Storefront route — lets a logged-in customer download their own invoice.
 *
 * Mirrors the admin download endpoint (Controller/Admin/InvoiceController.php)
 * but enforces ownership at the application layer: only the customer who
 * placed the order may download its invoice. Anonymous visitors and other
 * customers receive a 404 (not 403) so we do not leak the existence of an
 * order id we don't own — Shopware's storefront convention.
 *
 * Route registration:
 *   GET /account/invoice/{orderId}
 *
 * The controller is intentionally a plain class — it does not extend
 * Shopware\Storefront\Controller\StorefrontController (that lives in the
 * shopware/storefront package which is not a vendor dep of this plugin).
 * Plain Response objects are everything we need; the customer-portal page
 * itself renders via the Twig extension under
 * Resources/views/storefront/page/account/order-detail/invoice-download.html.twig.
 */
#[Route(defaults: [
    PlatformRequest::ATTRIBUTE_ROUTE_SCOPE     => ['storefront'],
    PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED  => true,
])]
class InvoiceDownloadController
{
    private const LOG_SOURCE = 'invoice-api-xhub';

    /**
     * MIME-Whitelist mirrored from the admin controller (P0-3 fix).
     * Anything outside this set falls back to application/octet-stream so
     * the browser cannot inline-render unknown content types.
     */
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
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/account/invoice/{orderId}',
        name: 'frontend.account.invoice-api-xhub.download',
        methods: ['GET'],
        requirements: ['orderId' => '[0-9a-f]{32}']
    )]
    public function download(string $orderId, SalesChannelContext $context): Response
    {
        $customer = $context->getCustomer();
        if (!$customer instanceof CustomerEntity) {
            // The login-required defaults should redirect anonymous visitors
            // before we ever see them, but we still defensive-check here so
            // a misconfigured route can't leak files. 404 not 401 — same
            // shape as the not-found path so existence isn't disclosed.
            return $this->notFound();
        }

        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('orderCustomer');

        $order = $this->orderRepository->search($criteria, $context->getContext())->first();
        if (!$order instanceof OrderEntity) {
            return $this->notFound();
        }

        // Ownership check. The order's orderCustomer.customerId must equal
        // the logged-in customer's id. We use 404 (not 403) to avoid leaking
        // whether a given order id exists for someone else.
        $orderCustomer = $order->getOrderCustomer();
        if ($orderCustomer === null || $orderCustomer->getCustomerId() !== $customer->getId()) {
            $this->logger->info(
                sprintf(
                    'Storefront invoice-download denied: customer %s tried to access order %s.',
                    $customer->getId(),
                    $orderId,
                ),
                ['source' => self::LOG_SOURCE],
            );

            return $this->notFound();
        }

        $cf       = $order->getCustomFields() ?? [];
        $filename = isset($cf['invoice_api_xhub_filename']) && is_string($cf['invoice_api_xhub_filename'])
            ? $cf['invoice_api_xhub_filename']
            : '';
        $filepath = isset($cf['invoice_api_xhub_filepath']) && is_string($cf['invoice_api_xhub_filepath'])
            ? $cf['invoice_api_xhub_filepath']
            : '';

        if ('' === $filename || '' === $filepath) {
            return $this->notFound();
        }

        $bytes = $this->fileStorage->read($filepath);
        if ($bytes === null) {
            // File metadata is set but the on-disk artefact is missing —
            // could happen after a privacy-erase. Treat as not-found.
            return $this->notFound();
        }

        // Audit: record the storefront download for compliance review. We
        // log via PSR-3 (the merchant's platform.log) — the dedicated audit
        // table is owned by Wave 7D and the InvoiceFileStorage layer doesn't
        // depend on it.
        $this->logger->info(
            sprintf(
                'Storefront invoice-download: customer %s, order %s, file %s',
                $customer->getId(),
                $orderId,
                $filename,
            ),
            [
                'source'     => self::LOG_SOURCE,
                'orderId'    => $orderId,
                'customerId' => $customer->getId(),
                'filename'   => $filename,
                'timestamp'  => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c'),
            ],
        );

        $ext  = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mime = self::ALLOWED_MIMES[$ext] ?? 'application/octet-stream';

        $safeFilename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? 'invoice';
        if ('' === $safeFilename) {
            $safeFilename = 'invoice';
        }

        return new Response($bytes, Response::HTTP_OK, [
            'Content-Type'           => $mime,
            'Content-Disposition'    => 'attachment; filename="' . $safeFilename . '"',
            'Content-Length'         => (string) strlen($bytes),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, no-store',
        ]);
    }

    /**
     * Single source of truth for the not-found / forbidden path. Returns a
     * 404 JsonResponse so the response body is never inline-rendered as the
     * (potentially misleading) Storefront 404 page. We deliberately do NOT
     * return a redirect to the account-overview here — the customer must be
     * able to tell from the response that *this specific* link is dead, not
     * that the session expired.
     */
    private function notFound(): JsonResponse
    {
        return new JsonResponse(
            ['success' => false, 'message' => 'Invoice not found'],
            Response::HTTP_NOT_FOUND,
        );
    }
}
