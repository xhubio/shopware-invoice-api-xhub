<?php

declare(strict_types=1);

namespace Xhubio\InvoiceApiXhub\Tests\Subscriber;

use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\MailTemplate\Service\Event\MailBeforeValidateEvent;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Event\NestedEventCollection;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Xhubio\InvoiceApiXhub\Service\InvoiceFileStorage;
use Xhubio\InvoiceApiXhub\Subscriber\MailAttachmentSubscriber;

/**
 * Unit tests for the auto-email-attachment subscriber.
 *
 * Mirrors the InvoiceFileStorageTest setUp() pattern: real Flysystem
 * in-memory adapter wrapped in a real InvoiceFileStorage so we exercise the
 * read() path end-to-end. The repository and SystemConfigService are mocked
 * because they are framework-owned.
 */
final class MailAttachmentSubscriberTest extends TestCase
{
    private const ORDER_ID = 'order-attach-1';

    private Context $context;

    private Filesystem $fs;

    private InvoiceFileStorage $storage;

    protected function setUp(): void
    {
        $this->context = Context::createDefaultContext();
        $this->fs      = new Filesystem(new InMemoryFilesystemAdapter());
        $this->storage = new InvoiceFileStorage($this->fs);
    }

    public function testAttachesInvoiceWhenOrderHasGeneratedFile(): void
    {
        // Pre-seed the file storage with the same path the InvoiceGenerator
        // would have written.
        $this->storage->write(self::ORDER_ID, 'INV-100.pdf', 'PDFBYTES');

        $order = $this->buildOrderWithInvoice(self::ORDER_ID, 'INV-100.pdf', 'invoice-api-xhub/' . self::ORDER_ID . '/INV-100.pdf');

        $subscriber = new MailAttachmentSubscriber(
            $this->storage,
            $this->buildOrderRepository($order),
            $this->buildConfig(['attachToEmail' => true]),
            new NullLogger(),
        );

        $event = new MailBeforeValidateEvent(
            data: ['recipients' => ['a@b.c' => 'A B']],
            context: $this->context,
            templateData: ['order' => $order],
        );

        $subscriber->onMailBeforeValidate($event);

        $data = $event->getData();
        self::assertArrayHasKey('binAttachments', $data);
        self::assertIsArray($data['binAttachments']);
        self::assertCount(1, $data['binAttachments']);

        $attachment = $data['binAttachments'][0];
        self::assertSame('PDFBYTES', $attachment['content']);
        self::assertSame('INV-100.pdf', $attachment['fileName']);
        self::assertSame('application/pdf', $attachment['mimeType']);
    }

    public function testSkipsWhenOrderHasNoInvoiceGenerated(): void
    {
        // Order exists but no invoice custom-fields set yet.
        $order = new OrderEntity();
        $order->setId(self::ORDER_ID);
        $order->setCustomFields([]);

        $subscriber = new MailAttachmentSubscriber(
            $this->storage,
            $this->buildOrderRepository($order),
            $this->buildConfig(['attachToEmail' => true]),
            new NullLogger(),
        );

        $event = new MailBeforeValidateEvent(
            data: [],
            context: $this->context,
            templateData: ['order' => $order],
        );

        $subscriber->onMailBeforeValidate($event);

        self::assertArrayNotHasKey('binAttachments', $event->getData());
    }

    public function testSkipsNonOrderMail(): void
    {
        // Registration / password-reset mails do not carry an order in
        // templateData. Subscriber must silently do nothing — and not even
        // talk to the order repository.
        $repo = $this->createMock(EntityRepository::class);
        $repo->expects($this->never())->method('search');

        $subscriber = new MailAttachmentSubscriber(
            $this->storage,
            $repo,
            $this->buildConfig(['attachToEmail' => true]),
            new NullLogger(),
        );

        $event = new MailBeforeValidateEvent(
            data: ['recipients' => ['a@b.c' => 'A B']],
            context: $this->context,
            templateData: ['customer' => ['firstName' => 'Anna']],
        );

        $subscriber->onMailBeforeValidate($event);

        self::assertArrayNotHasKey('binAttachments', $event->getData());
    }

    public function testSkipsWhenAttachToEmailConfigDisabled(): void
    {
        // Even if everything else is in place, the config flag must short-circuit.
        $this->storage->write(self::ORDER_ID, 'INV-200.pdf', 'PDFBYTES');
        $order = $this->buildOrderWithInvoice(self::ORDER_ID, 'INV-200.pdf', 'invoice-api-xhub/' . self::ORDER_ID . '/INV-200.pdf');

        $repo = $this->createMock(EntityRepository::class);
        // Order repository must not even be queried when feature is off.
        $repo->expects($this->never())->method('search');

        $subscriber = new MailAttachmentSubscriber(
            $this->storage,
            $repo,
            $this->buildConfig(['attachToEmail' => false]),
            new NullLogger(),
        );

        $event = new MailBeforeValidateEvent(
            data: [],
            context: $this->context,
            templateData: ['order' => $order],
        );

        $subscriber->onMailBeforeValidate($event);

        self::assertArrayNotHasKey('binAttachments', $event->getData());
    }

    public function testSkipsWhenInvoiceFileMissingOnDisk(): void
    {
        // Order has the custom-field pointing at a path, but the file was
        // privacy-erased in the meantime — listener must silently skip.
        $order = $this->buildOrderWithInvoice(
            self::ORDER_ID,
            'INV-missing.pdf',
            'invoice-api-xhub/' . self::ORDER_ID . '/INV-missing.pdf',
        );

        $subscriber = new MailAttachmentSubscriber(
            $this->storage,
            $this->buildOrderRepository($order),
            $this->buildConfig(['attachToEmail' => true]),
            new NullLogger(),
        );

        $event = new MailBeforeValidateEvent(
            data: [],
            context: $this->context,
            templateData: ['order' => $order],
        );

        $subscriber->onMailBeforeValidate($event);

        self::assertArrayNotHasKey('binAttachments', $event->getData());
    }

    public function testPreservesExistingBinAttachments(): void
    {
        // If another subscriber already added an attachment, ours must
        // append rather than overwrite.
        $this->storage->write(self::ORDER_ID, 'INV-300.pdf', 'PDFBYTES');
        $order = $this->buildOrderWithInvoice(self::ORDER_ID, 'INV-300.pdf', 'invoice-api-xhub/' . self::ORDER_ID . '/INV-300.pdf');

        $subscriber = new MailAttachmentSubscriber(
            $this->storage,
            $this->buildOrderRepository($order),
            $this->buildConfig(['attachToEmail' => true]),
            new NullLogger(),
        );

        $existingAttachment = [
            'content'  => 'EXISTING',
            'fileName' => 'flow-attachment.pdf',
            'mimeType' => 'application/pdf',
        ];

        $event = new MailBeforeValidateEvent(
            data: ['binAttachments' => [$existingAttachment]],
            context: $this->context,
            templateData: ['order' => $order],
        );

        $subscriber->onMailBeforeValidate($event);

        $attachments = $event->getData()['binAttachments'] ?? null;
        self::assertIsArray($attachments);
        self::assertCount(2, $attachments);
        self::assertSame($existingAttachment, $attachments[0]);
        self::assertSame('PDFBYTES', $attachments[1]['content']);
    }

    // ----------------------------------------------------------- helpers

    private function buildOrderWithInvoice(string $orderId, string $filename, string $filepath): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setCustomFields([
            'invoice_api_xhub_filename' => $filename,
            'invoice_api_xhub_filepath' => $filepath,
        ]);

        return $order;
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
        $repo->method('update')->willReturnCallback(
            fn (array $data, Context $ctx): EntityWrittenContainerEvent => new EntityWrittenContainerEvent(
                $ctx,
                new NestedEventCollection(),
                [],
            ),
        );

        return $repo;
    }

    /**
     * @param array<string,mixed> $values
     */
    private function buildConfig(array $values): SystemConfigService
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->with('InvoiceApiXhub.config')->willReturn($values);

        return $config;
    }
}
