<?php

declare(strict_types=1);

namespace Xhubio\InvoiceApiXhub\Tests\Service;

use Doctrine\DBAL\Connection;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Xhubio\InvoiceApiXhub\Service\ApiClient;
use Xhubio\InvoiceApiXhub\Service\InvoiceFileStorage;
use Xhubio\InvoiceApiXhub\Service\InvoiceGenerator;
use Xhubio\InvoiceApiXhub\Service\InvoiceNumberService;
use Xhubio\InvoiceApiXhub\Service\OrderMapper;
use Xhubio\InvoiceApiXhub\Service\TemplateResolver;

/**
 * Integration-style unit tests for the InvoiceGenerator orchestrator.
 *
 * Because all collaborator services are `final`, we cannot mock them — we
 * wire real instances with mockable I/O at the boundary (HTTP client,
 * filesystem, DB connection, entity repository, system config). This is
 * still pure-PHP, no Shopware kernel boot.
 */
final class InvoiceGeneratorTest extends TestCase
{
    private Context $context;

    protected function setUp(): void
    {
        $this->context = Context::createDefaultContext();
    }

    public function testGenerateReturnsFailureWhenOrderNotFound(): void
    {
        $generator = $this->buildGenerator(
            httpResponses: [],
            orderInRepo: null,
            config: ['apiKey' => 'k', 'baseUrl' => 'https://service.invoice-api.xhub.io'],
        );

        $result = $generator->generate('missing-id', $this->context);

        self::assertFalse($result['success']);
        self::assertStringContainsString('not found', $result['message']);
    }

    public function testGenerateReturnsFailureWhenApiKeyIsEmpty(): void
    {
        $order     = $this->buildOrder('order-1');
        $generator = $this->buildGenerator(
            httpResponses: [],
            orderInRepo: $order,
            config: ['apiKey' => '', 'baseUrl' => 'https://service.invoice-api.xhub.io'],
        );

        $result = $generator->generate('order-1', $this->context);

        self::assertFalse($result['success']);
        self::assertFalse($result['skipped']);
        self::assertStringContainsString('API key is not configured', $result['message']);
    }

    public function testGenerateSkipsOrderWithoutLineItems(): void
    {
        $order = $this->buildOrder('order-empty', includeLineItem: false);

        $generator = $this->buildGenerator(
            httpResponses: [],
            orderInRepo: $order,
            config: ['apiKey' => 'k', 'baseUrl' => 'https://service.invoice-api.xhub.io', 'sellerCountryCode' => 'DE'],
        );

        $result = $generator->generate('order-empty', $this->context);

        self::assertFalse($result['success']);
        self::assertTrue($result['skipped']);
        self::assertSame('no line items', $result['message']);
    }

    public function testGenerateHappyPathPersistsFileAndReturnsFilename(): void
    {
        $order = $this->buildOrder('order-1');

        $http = new MockHttpClient([
            new MockResponse(json_encode([
                'success'  => true,
                'data'     => base64_encode('PDFBYTES'),
                'mimeType' => 'application/pdf',
                'filename' => 'INV-100.pdf',
                'format'   => 'pdf',
            ], JSON_THROW_ON_ERROR), ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]),
        ]);

        $fs = new Filesystem(new InMemoryFilesystemAdapter());
        $generator = $this->buildGenerator(
            httpResponses: $http,
            orderInRepo: $order,
            config: [
                'apiKey'            => 'k',
                'baseUrl'           => 'https://service.invoice-api.xhub.io',
                'country'           => 'DE',
                'format'            => 'pdf',
                'sellerCountryCode' => 'DE',
                'sellerName'        => 'Invoice-api.xhub Seller GmbH',
                'sellerStreet'      => 'Friedrichstr. 12',
                'sellerCity'        => 'Berlin',
                'sellerPostalCode'  => '10117',
            ],
            filesystem: $fs,
        );

        $result = $generator->generate('order-1', $this->context);

        self::assertTrue($result['success']);
        self::assertFalse($result['skipped']);
        self::assertSame('INV-100.pdf', $result['filename']);
        self::assertSame('PDFBYTES', $fs->read('invoice-api-xhub/order-1/INV-100.pdf'));
    }

    public function testGenerateUsesDefaultBaseUrlWhenConfigBlank(): void
    {
        $order = $this->buildOrder('order-2');

        $captured = '';
        $http = new MockHttpClient(function ($m, $u) use (&$captured): MockResponse {
            $captured = $u;

            return new MockResponse(json_encode([
                'success'  => true,
                'data'     => base64_encode('PDF'),
                'mimeType' => 'application/pdf',
                'filename' => 'INV-100.pdf',
                'format'   => 'pdf',
            ], JSON_THROW_ON_ERROR));
        });

        $generator = $this->buildGenerator(
            httpResponses: $http,
            orderInRepo: $order,
            config: [
                'apiKey'           => 'k',
                'baseUrl'          => '   ',
                'country'          => 'DE',
                'format'           => 'pdf',
                'sellerCountryCode' => 'DE',
                'sellerName'       => 'A',
                'sellerStreet'     => 'B',
                'sellerCity'       => 'C',
                'sellerPostalCode' => '00000',
            ],
        );

        $generator->generate('order-2', $this->context);

        self::assertStringStartsWith('https://service.invoice-api.xhub.io/', $captured);
    }

    public function testGenerateReturnsFailureWhenApiResponseHasNoData(): void
    {
        $order = $this->buildOrder('order-3');

        $http = new MockHttpClient([
            new MockResponse(
                json_encode(['success' => true, 'data' => '', 'message' => 'empty'], JSON_THROW_ON_ERROR),
                ['http_code' => 200],
            ),
        ]);

        $generator = $this->buildGenerator(
            httpResponses: $http,
            orderInRepo: $order,
            config: $this->minimalConfig(),
        );

        $result = $generator->generate('order-3', $this->context);

        self::assertFalse($result['success']);
        self::assertStringContainsString('empty', $result['message']);
    }

    public function testGenerateReturnsFailureWhenApiClientThrows(): void
    {
        $order = $this->buildOrder('order-4');

        $http = new MockHttpClient([
            new MockResponse(
                json_encode(['success' => false, 'message' => 'rate limit'], JSON_THROW_ON_ERROR),
                ['http_code' => 429],
            ),
        ]);

        $generator = $this->buildGenerator(
            httpResponses: $http,
            orderInRepo: $order,
            config: $this->minimalConfig(),
        );

        $result = $generator->generate('order-4', $this->context);

        self::assertFalse($result['success']);
        self::assertStringContainsString('rate limit', $result['message']);
    }

    public function testGenerateUsesXrechnungFilenameForXrechnungFormat(): void
    {
        $order = $this->buildOrder('order-x');

        $http = new MockHttpClient([
            new MockResponse(json_encode([
                'success'  => true,
                'data'     => base64_encode('<xml/>'),
                'mimeType' => 'application/xml',
                // no filename — generator must fall back to local filename
                'format'   => 'xrechnung',
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ]);

        $fs = new Filesystem(new InMemoryFilesystemAdapter());
        $generator = $this->buildGenerator(
            httpResponses: $http,
            orderInRepo: $order,
            config: array_merge($this->minimalConfig(), ['format' => 'xrechnung']),
            filesystem: $fs,
        );

        $result = $generator->generate('order-x', $this->context);

        self::assertTrue($result['success']);
        self::assertStringEndsWith('_xrechnung.xml', $result['filename']);
    }

    // -------------------------------------------------------------- helpers

    /**
     * @param list<MockResponse>|MockHttpClient $httpResponses
     * @param array<string,mixed>               $config
     */
    private function buildGenerator(
        array|MockHttpClient $httpResponses,
        ?OrderEntity $orderInRepo,
        array $config,
        ?Filesystem $filesystem = null,
    ): InvoiceGenerator {
        $http = $httpResponses instanceof MockHttpClient ? $httpResponses : new MockHttpClient($httpResponses);
        $api  = new ApiClient($http, new NullLogger());

        $conn = $this->createMock(Connection::class);
        $conn->method('executeStatement')->willReturn(1);
        $conn->method('lastInsertId')->willReturn('100');

        $numberService = new InvoiceNumberService($conn, new NullLogger());
        $resolver      = new TemplateResolver();
        $mapper        = new OrderMapper($numberService, $resolver);

        $fs       = $filesystem ?? new Filesystem(new InMemoryFilesystemAdapter());
        $storage  = new InvoiceFileStorage($fs);

        $sysConfig = $this->createMock(SystemConfigService::class);
        $sysConfig->method('get')->with('InvoiceApiXhub.config')->willReturn($config);

        $orderRepo = $this->createMock(EntityRepository::class);
        $collection = new OrderCollection($orderInRepo instanceof OrderEntity ? [$orderInRepo] : []);
        $orderRepo->method('search')->willReturn(new EntitySearchResult(
            'order',
            $collection->count(),
            $collection,
            null,
            new Criteria(),
            $this->context,
        ));
        $orderRepo->method('update')->willReturnCallback(
            fn ($data, Context $ctx) => new \Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent(
                $ctx,
                new \Shopware\Core\Framework\Event\NestedEventCollection(),
                [],
            ),
        );

        return new InvoiceGenerator(
            $api,
            $mapper,
            $resolver,
            $storage,
            $sysConfig,
            $orderRepo,
            new NullLogger(),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function minimalConfig(): array
    {
        return [
            'apiKey'            => 'k',
            'baseUrl'           => 'https://service.invoice-api.xhub.io',
            'country'           => 'DE',
            'format'            => 'pdf',
            'sellerCountryCode' => 'DE',
            'sellerName'        => 'A',
            'sellerStreet'      => 'B',
            'sellerCity'        => 'C',
            'sellerPostalCode'  => '00000',
        ];
    }

    private function buildOrder(string $orderId, bool $includeLineItem = true): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setOrderNumber('SW-' . $orderId);
        $order->setOrderDateTime(new \DateTimeImmutable('2026-04-30'));

        $cust = new OrderCustomerEntity();
        $cust->setId('cust-' . $orderId);
        $cust->assign(['firstName' => 'X', 'lastName' => 'Y', 'email' => 'a@b.c']);
        $order->setOrderCustomer($cust);

        $country = new CountryEntity();
        $country->setId('cty');
        $country->setIso('DE');

        $billing = new OrderAddressEntity();
        $billing->setId('addr-' . $orderId);
        $billing->assign([
            'firstName' => 'X',
            'lastName'  => 'Y',
            'street'    => 'S 1',
            'city'      => 'Berlin',
            'zipcode'   => '10117',
            'country'   => $country,
        ]);
        $order->setBillingAddress($billing);

        if ($includeLineItem) {
            $li = new OrderLineItemEntity();
            $li->setId('li-' . $orderId);
            $li->setIdentifier('li');
            $li->setLabel('Item');
            $li->setQuantity(1);
            $taxes = new CalculatedTaxCollection();
            $li->setPrice(new \Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice(
                10.0,
                10.0,
                $taxes,
                new TaxRuleCollection(),
                1,
            ));
            $order->setLineItems(new OrderLineItemCollection([$li]));
        } else {
            $order->setLineItems(new OrderLineItemCollection());
        }

        $order->setPrice(new CartPrice(
            netPrice: 10.0,
            totalPrice: 10.0,
            positionPrice: 10.0,
            calculatedTaxes: new CalculatedTaxCollection(),
            taxRules: new TaxRuleCollection(),
            taxStatus: CartPrice::TAX_STATE_NET,
        ));
        $order->setDeliveries(new OrderDeliveryCollection());

        return $order;
    }
}
