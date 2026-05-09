<?php

declare(strict_types=1);

namespace Xhubio\InvoiceApiXhub\Tests\Storefront;

use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Response;
use Xhubio\InvoiceApiXhub\Service\InvoiceFileStorage;
use Xhubio\InvoiceApiXhub\Storefront\Controller\InvoiceDownloadController;

/**
 * Unit tests for the customer-portal invoice download.
 *
 * Verifies the ownership check, the missing-invoice path, and the happy path.
 * SalesChannelContext is mocked because constructing one for real requires
 * the full Shopware kernel.
 */
final class InvoiceDownloadControllerTest extends TestCase
{
    private const ORDER_ID    = 'order-123';
    private const CUSTOMER_ID = 'cust-123';

    private Context $context;

    private Filesystem $fs;

    private InvoiceFileStorage $storage;

    protected function setUp(): void
    {
        $this->context = Context::createDefaultContext();
        $this->fs      = new Filesystem(new InMemoryFilesystemAdapter());
        $this->storage = new InvoiceFileStorage($this->fs);
    }

    public function testReturnsFileBytesWhenCustomerOwnsOrder(): void
    {
        $this->storage->write(self::ORDER_ID, 'INV-100.pdf', 'PDFBYTES');

        $order = $this->buildOrder(
            self::ORDER_ID,
            ownerCustomerId: self::CUSTOMER_ID,
            filename: 'INV-100.pdf',
            filepath: 'invoice-api-xhub/' . self::ORDER_ID . '/INV-100.pdf',
        );

        $controller = new InvoiceDownloadController(
            $this->storage,
            $this->buildOrderRepository($order),
            new NullLogger(),
        );

        $response = $controller->download(
            self::ORDER_ID,
            $this->buildSalesChannelContext($this->buildCustomer(self::CUSTOMER_ID)),
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('PDFBYTES', $response->getContent());
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringContainsString('INV-100.pdf', (string) $response->headers->get('Content-Disposition'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testReturns404WhenAnotherCustomerTriesToDownload(): void
    {
        $this->storage->write(self::ORDER_ID, 'INV-200.pdf', 'PDFBYTES');

        $order = $this->buildOrder(
            self::ORDER_ID,
            ownerCustomerId: self::CUSTOMER_ID, // owner = cust-123
            filename: 'INV-200.pdf',
            filepath: 'invoice-api-xhub/' . self::ORDER_ID . '/INV-200.pdf',
        );

        $controller = new InvoiceDownloadController(
            $this->storage,
            $this->buildOrderRepository($order),
            new NullLogger(),
        );

        // Logged-in customer is a different person.
        $response = $controller->download(
            self::ORDER_ID,
            $this->buildSalesChannelContext($this->buildCustomer('attacker-456')),
        );

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testReturns404WhenOrderHasNoInvoiceGenerated(): void
    {
        $order = $this->buildOrder(
            self::ORDER_ID,
            ownerCustomerId: self::CUSTOMER_ID,
            filename: '',
            filepath: '',
        );

        $controller = new InvoiceDownloadController(
            $this->storage,
            $this->buildOrderRepository($order),
            new NullLogger(),
        );

        $response = $controller->download(
            self::ORDER_ID,
            $this->buildSalesChannelContext($this->buildCustomer(self::CUSTOMER_ID)),
        );

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testReturns404WhenAnonymousCustomer(): void
    {
        // No order-load needed — controller short-circuits at the customer
        // null-check, so the repository must NOT be called.
        $repo = $this->createMock(EntityRepository::class);
        $repo->expects($this->never())->method('search');

        $controller = new InvoiceDownloadController(
            $this->storage,
            $repo,
            new NullLogger(),
        );

        $response = $controller->download(
            self::ORDER_ID,
            $this->buildSalesChannelContext(null),
        );

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testReturns404WhenInvoiceFileMissingOnDisk(): void
    {
        // Custom field points to a path that no longer exists on disk.
        $order = $this->buildOrder(
            self::ORDER_ID,
            ownerCustomerId: self::CUSTOMER_ID,
            filename: 'INV-deleted.pdf',
            filepath: 'invoice-api-xhub/' . self::ORDER_ID . '/INV-deleted.pdf',
        );

        $controller = new InvoiceDownloadController(
            $this->storage,
            $this->buildOrderRepository($order),
            new NullLogger(),
        );

        $response = $controller->download(
            self::ORDER_ID,
            $this->buildSalesChannelContext($this->buildCustomer(self::CUSTOMER_ID)),
        );

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    // ---------------------------------------------------------- helpers

    private function buildOrder(
        string $orderId,
        string $ownerCustomerId,
        string $filename,
        string $filepath,
    ): OrderEntity {
        $orderCustomer = new OrderCustomerEntity();
        $orderCustomer->setId('oc-' . $orderId);
        $orderCustomer->setCustomerId($ownerCustomerId);

        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setOrderCustomer($orderCustomer);

        $cf = [];
        if ('' !== $filename) {
            $cf['invoice_api_xhub_filename'] = $filename;
        }
        if ('' !== $filepath) {
            $cf['invoice_api_xhub_filepath'] = $filepath;
        }
        $order->setCustomFields($cf);

        return $order;
    }

    private function buildCustomer(string $customerId): CustomerEntity
    {
        $c = new CustomerEntity();
        $c->setId($customerId);

        return $c;
    }

    private function buildSalesChannelContext(?CustomerEntity $customer): SalesChannelContext
    {
        $ctx = $this->createMock(SalesChannelContext::class);
        $ctx->method('getCustomer')->willReturn($customer);
        $ctx->method('getContext')->willReturn($this->context);

        return $ctx;
    }

    /**
     * @return EntityRepository<OrderCollection>
     */
    private function buildOrderRepository(OrderEntity $order): EntityRepository
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('search')->willReturnCallback(
            fn (Criteria $c, Context $ctx): EntitySearchResult => new EntitySearchResult(
                'order',
                1,
                new OrderCollection([$order]),
                null,
                $c,
                $ctx,
            ),
        );

        return $repo;
    }
}
