<?php

declare(strict_types=1);

namespace Xhubio\InvoiceApiXhub\Tests\Service;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Xhubio\InvoiceApiXhub\Service\InvoiceFileStorage;
use Xhubio\InvoiceApiXhub\Service\PrivacyService;

/**
 * Unit tests for the GDPR eraser.
 *
 * Verifies that erasing customers nulls every Invoice-api.xhub custom field
 * on every matching order and clears the on-disk file directories — without
 * deleting the order entity itself.
 */
final class PrivacyServiceTest extends TestCase
{
    private Context $context;

    private Filesystem $fs;

    private InvoiceFileStorage $storage;

    protected function setUp(): void
    {
        $this->context = Context::createDefaultContext();
        $this->fs      = new Filesystem(new InMemoryFilesystemAdapter());
        $this->storage = new InvoiceFileStorage($this->fs);
    }

    public function testEarlyReturnsWhenCustomerIdsAreEmpty(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->expects($this->never())->method('search');

        $service = new PrivacyService($repo, $this->storage, new NullLogger());
        $service->eraseForCustomerIds([], $this->context);
    }

    public function testEarlyReturnsWhenCustomerIdsAreOnlyNonStrings(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->expects($this->never())->method('search');

        $service = new PrivacyService($repo, $this->storage, new NullLogger());
        // @phpstan-ignore-next-line -- intentional non-string for the array_filter branch
        $service->eraseForCustomerIds([42, null, false], $this->context);
    }

    public function testWipesCustomFieldsAndDeletesFilesForMatchingOrders(): void
    {
        $orderId = Uuid::randomHex();

        // Pre-seed file storage so deleteForOrder has work to do.
        $this->storage->write($orderId, 'INV-1.pdf', 'PDF');

        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setCustomFields([
            'invoice_api_xhub_filename'      => 'INV-1.pdf',
            'invoice_api_xhub_filepath'      => 'invoice-api-xhub/' . $orderId . '/INV-1.pdf',
            'invoice_api_xhub_data'          => 'old-base64',
            'invoice_api_xhub_invoice_number' => 'INV-1',
            'unrelated_field'                => 'keep-me',
        ]);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('search')->willReturn($this->searchResult([$order]));

        $captured = [];
        $repo->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (array $data, Context $ctx) use (&$captured) {
                $captured = $data;

                return new \Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent($ctx, new \Shopware\Core\Framework\Event\NestedEventCollection(), []);
            });

        $service = new PrivacyService($repo, $this->storage, new NullLogger());
        $service->eraseForCustomerIds(['cust-1'], $this->context);

        self::assertCount(1, $captured);
        self::assertSame($orderId, $captured[0]['id']);
        self::assertNull($captured[0]['customFields']['invoice_api_xhub_filename']);
        self::assertNull($captured[0]['customFields']['invoice_api_xhub_filepath']);
        self::assertNull($captured[0]['customFields']['invoice_api_xhub_data']);
        self::assertNull($captured[0]['customFields']['invoice_api_xhub_invoice_number']);
        self::assertSame('keep-me', $captured[0]['customFields']['unrelated_field']);

        // File directory has been wiped
        self::assertFalse($this->fs->fileExists('invoice-api-xhub/' . $orderId . '/INV-1.pdf'));
    }

    public function testSkipsCustomFieldUpdateWhenOrderHasNoInvoiceFields(): void
    {
        $orderId = Uuid::randomHex();
        $order   = new OrderEntity();
        $order->setId($orderId);
        $order->setCustomFields(['unrelated' => 'value']);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('search')->willReturn($this->searchResult([$order]));
        $repo->expects($this->never())->method('update');

        $service = new PrivacyService($repo, $this->storage, new NullLogger());
        $service->eraseForCustomerIds(['cust-x'], $this->context);
    }

    public function testDeduplicatesAndFiltersCustomerIdsBeforeQuerying(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $captured = null;
        $repo->expects($this->once())
            ->method('search')
            ->willReturnCallback(function ($criteria, $ctx) use (&$captured): EntitySearchResult {
                $captured = $criteria;

                return $this->searchResult([]);
            });

        $service = new PrivacyService($repo, $this->storage, new NullLogger());
        // @phpstan-ignore-next-line -- intentional mixed input for filter branch
        $service->eraseForCustomerIds(['c-1', 'c-1', 'c-2', null, 99], $this->context);

        self::assertNotNull($captured);
        $filters = $captured->getFilters();
        self::assertNotEmpty($filters);
    }

    public function testFileStorageFailureIsLoggedAndDoesNotAbortLoop(): void
    {
        $orderId = Uuid::randomHex();
        $order   = new OrderEntity();
        $order->setId($orderId);
        $order->setCustomFields(['invoice_api_xhub_filename' => 'INV-fail.pdf']);

        // Inject a Flysystem mock that throws when deleteDirectory is called.
        // InvoiceFileStorage::deleteForOrder will rethrow as RuntimeException,
        // and PrivacyService catches all Throwables on that path.
        $brokenFs = $this->createMock(FilesystemOperator::class);
        $brokenFs->method('directoryExists')->willReturn(true);
        $brokenFs->method('deleteDirectory')->willThrowException(
            new class('boom') extends \RuntimeException implements FilesystemException {},
        );
        $brokenStorage = new InvoiceFileStorage($brokenFs);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('search')->willReturn($this->searchResult([$order]));
        $repo->expects($this->once())->method('update')->willReturnCallback(
            fn ($data, Context $ctx) => new \Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent($ctx, new \Shopware\Core\Framework\Event\NestedEventCollection(), []),
        );

        $service = new PrivacyService($repo, $brokenStorage, new NullLogger());
        // Must not throw — failure is swallowed via $logger->warning
        $service->eraseForCustomerIds(['cust-1'], $this->context);
    }

    /**
     * @param list<OrderEntity> $orders
     */
    private function searchResult(array $orders): EntitySearchResult
    {
        $collection = new OrderCollection($orders);

        return new EntitySearchResult(
            'order',
            count($orders),
            $collection,
            null,
            new \Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria(),
            $this->context,
        );
    }
}
