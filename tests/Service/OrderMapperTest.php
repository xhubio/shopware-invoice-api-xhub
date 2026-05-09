<?php

declare(strict_types=1);

namespace Xhubio\InvoiceApiXhub\Tests\Service;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Xhubio\InvoiceApiXhub\Enum\InvoiceType;
use Xhubio\InvoiceApiXhub\Service\InvoiceNumberService;
use Xhubio\InvoiceApiXhub\Service\OrderMapper;
use Xhubio\InvoiceApiXhub\Service\TemplateResolver;

/**
 * Unit tests for OrderMapper — the most logic-heavy service.
 *
 * Each test isolates a slice of the mapping (seller, buyer, items, tax
 * summary, bank account, country-specific, credit-note conversion) by
 * constructing a fixture OrderEntity with only the relevant fields populated.
 * Helper builders at the bottom keep individual tests focussed.
 */
final class OrderMapperTest extends TestCase
{
    private OrderMapper $mapper;

    protected function setUp(): void
    {
        // InvoiceNumberService is `final` so we cannot mock it. Instead we
        // wire a real instance with a mocked DBAL Connection that always
        // returns sequence value 100 — fixed numbers keep tests focused on
        // the mapper's own logic, not on number formatting.
        $conn = $this->createMock(Connection::class);
        $conn->method('executeStatement')->willReturn(1);
        $conn->method('lastInsertId')->willReturn('100');

        $numberService = new InvoiceNumberService($conn, new NullLogger());

        $this->mapper = new OrderMapper($numberService, new TemplateResolver());
    }

    public function testMapToInvoiceProducesCanonicalTopLevelFields(): void
    {
        $order = $this->minimalOrder();

        $invoice = $this->mapper->mapToInvoice($order, $this->fullSellerConfig(), InvoiceType::Invoice);

        self::assertSame('INV-SW-1001', $invoice['invoiceNumber']);
        self::assertSame('invoice', $invoice['type']);
        self::assertSame('2026-04-30', $invoice['issueDate']);
        self::assertSame('2026-05-14', $invoice['dueDate']);
        self::assertSame('EUR', $invoice['currency']);
        self::assertSame(14, $invoice['paymentTerms']['dueDays']);
    }

    public function testMapToInvoiceUsesCustomDueDaysFromConfig(): void
    {
        $order = $this->minimalOrder();
        $config = $this->fullSellerConfig() + ['paymentDueDays' => 30];

        $invoice = $this->mapper->mapToInvoice($order, $config, InvoiceType::Invoice);

        self::assertSame(30, $invoice['paymentTerms']['dueDays']);
        self::assertSame('2026-05-30', $invoice['dueDate']);
    }

    public function testMapToInvoiceClampsNegativeDueDaysToZero(): void
    {
        $order = $this->minimalOrder();
        $config = $this->fullSellerConfig() + ['paymentDueDays' => -5];

        $invoice = $this->mapper->mapToInvoice($order, $config, InvoiceType::Invoice);

        self::assertSame(0, $invoice['paymentTerms']['dueDays']);
    }

    public function testCurrencyDefaultsToEurWhenOrderHasNoCurrency(): void
    {
        $order = $this->minimalOrder();
        // Build a fresh order without a currency entity to exercise the
        // "currency null → EUR fallback" branch.
        $bare = new OrderEntity();
        $bare->setId('bare-1');
        $bare->setOrderNumber('SW-9');
        $bare->setOrderDateTime(new \DateTimeImmutable('2026-04-30'));
        $bare->setLineItems(new OrderLineItemCollection());
        $bare->setBillingAddress($order->getBillingAddress());
        $bare->setOrderCustomer($order->getOrderCustomer());
        $bare->setPrice(new CartPrice(
            netPrice: 0.0,
            totalPrice: 0.0,
            positionPrice: 0.0,
            calculatedTaxes: new CalculatedTaxCollection(),
            taxRules: new TaxRuleCollection(),
            taxStatus: CartPrice::TAX_STATE_NET,
        ));
        $bare->setDeliveries(new OrderDeliveryCollection());

        $invoice = $this->mapper->mapToInvoice($bare, $this->fullSellerConfig(), InvoiceType::Invoice);

        self::assertSame('EUR', $invoice['currency']);
    }

    public function testSellerBlockEmitsRequiredFieldsAndOptionalsWhenSet(): void
    {
        $order = $this->minimalOrder();

        $invoice = $this->mapper->mapToInvoice($order, $this->fullSellerConfig(), InvoiceType::Invoice);

        self::assertSame([
            'name'        => 'Invoice-api.xhub Seller GmbH',
            'street'      => 'Friedrichstr. 12',
            'city'        => 'Berlin',
            'postalCode'  => '10117',
            'countryCode' => 'DE',
            'vatId'       => 'DE123456789',
            'email'       => 'seller@example.com',
            'phone'       => '+49301234567',
        ], array_diff_key($invoice['seller'], ['bankAccount' => true]));
    }

    public function testSellerBlockOmitsEmptyOptionalFields(): void
    {
        $order  = $this->minimalOrder();
        $config = [
            'sellerName'        => 'Acme',
            'sellerStreet'      => 'S 1',
            'sellerCity'        => 'C',
            'sellerPostalCode'  => '00000',
            'sellerCountryCode' => 'DE',
            // no email/phone/vatId
        ];

        $invoice = $this->mapper->mapToInvoice($order, $config, InvoiceType::Invoice);

        self::assertArrayNotHasKey('vatId', $invoice['seller']);
        self::assertArrayNotHasKey('email', $invoice['seller']);
        self::assertArrayNotHasKey('phone', $invoice['seller']);
    }

    public function testSellerCountryCodeIsUppercased(): void
    {
        $order  = $this->minimalOrder();
        $config = ['sellerCountryCode' => 'at', 'sellerName' => 'A', 'sellerStreet' => 'S', 'sellerCity' => 'V', 'sellerPostalCode' => '1010'];

        $invoice = $this->mapper->mapToInvoice($order, $config, InvoiceType::Invoice);

        self::assertSame('AT', $invoice['seller']['countryCode']);
    }

    public function testBuyerBlockPrefersCompanyOverPersonName(): void
    {
        $order = $this->minimalOrder();

        $invoice = $this->mapper->mapToInvoice($order, $this->fullSellerConfig(), InvoiceType::Invoice);

        self::assertSame('Buyer Company AG', $invoice['buyer']['name']);
        self::assertSame('Hauptstr. 7 Apt. 4', $invoice['buyer']['street']);
        self::assertSame('Munich', $invoice['buyer']['city']);
        self::assertSame('80331', $invoice['buyer']['postalCode']);
        self::assertSame('DE', $invoice['buyer']['countryCode']);
        self::assertSame('buyer@example.com', $invoice['buyer']['email']);
        self::assertSame('DE987654321', $invoice['buyer']['vatId']);
    }

    public function testBuyerBlockFallsBackToFirstAndLastNameWhenCompanyMissing(): void
    {
        $order = $this->minimalOrder();
        $cust  = $order->getOrderCustomer();
        self::assertNotNull($cust);
        $cust->assign(['company' => null]);

        // Address-attached company is also empty
        $billing = $order->getBillingAddress();
        self::assertNotNull($billing);
        $billing->assign(['company' => null]);

        $invoice = $this->mapper->mapToInvoice($order, $this->fullSellerConfig(), InvoiceType::Invoice);

        self::assertSame('Jane Doe', $invoice['buyer']['name']);
    }

    public function testBuyerBlockFallsBackToCustomerStringWhenAllNamesEmpty(): void
    {
        $order  = $this->minimalOrder();
        $cust   = $order->getOrderCustomer();
        self::assertNotNull($cust);
        $cust->assign(['company' => null, 'firstName' => '', 'lastName' => '']);
        $billing = $order->getBillingAddress();
        self::assertNotNull($billing);
        $billing->assign(['company' => null, 'firstName' => '', 'lastName' => '']);

        $invoice = $this->mapper->mapToInvoice($order, $this->fullSellerConfig(), InvoiceType::Invoice);

        self::assertSame('Customer', $invoice['buyer']['name']);
    }

    public function testBuyerCountryCodeIsUppercased(): void
    {
        $order = $this->minimalOrder();
        $billing = $order->getBillingAddress();
        self::assertNotNull($billing);
        $country = $billing->getCountry();
        self::assertNotNull($country);
        $country->setIso('de');

        $invoice = $this->mapper->mapToInvoice($order, $this->fullSellerConfig(), InvoiceType::Invoice);

        self::assertSame('DE', $invoice['buyer']['countryCode']);
    }

    public function testItemsContainOnePositionPerTopLevelLineItem(): void
    {
        $order = $this->minimalOrder();

        $invoice = $this->mapper->mapToInvoice($order, $this->fullSellerConfig(), InvoiceType::Invoice);

        self::assertCount(1, $invoice['items']);
        $item = $invoice['items'][0];
        self::assertSame(1, $item['position']);
        self::assertSame('Demo Product', $item['description']);
        self::assertSame(2.0, $item['quantity']);
        self::assertSame('H87', $item['unit']);
        self::assertSame(50.0, $item['unitPrice']);
        self::assertSame(19.0, $item['taxRate']);
        self::assertSame('S', $item['taxCategoryCode']);
        self::assertSame(100.0, $item['netAmount']);
        self::assertSame(19.0, $item['taxAmount']);
        self::assertSame(119.0, $item['grossAmount']);
    }

    public function testNestedLineItemsAreSkippedAsTheyAreAggregatedIntoParent(): void
    {
        $order = $this->minimalOrder();
        $items = $order->getLineItems();
        self::assertNotNull($items);

        $child = new OrderLineItemEntity();
        $child->setId('child-1');
        $child->setIdentifier('child-1');
        $child->setLabel('Child');
        $child->setQuantity(1);
        $child->setParentId('any-parent');
        $items->add($child);

        $invoice = $this->mapper->mapToInvoice($order, $this->fullSellerConfig(), InvoiceType::Invoice);

        // still 1 item — child filtered out
        self::assertCount(1, $invoice['items']);
    }

    public function testZeroTaxRateProducesTaxCategoryZ(): void
    {
        $order = $this->orderWithSingleLineItem(taxRate: 0.0, taxAmount: 0.0, totalGross: 50.0);

        $invoice = $this->mapper->mapToInvoice($order, $this->fullSellerConfig(), InvoiceType::Invoice);

        self::assertSame('Z', $invoice['items'][0]['taxCategoryCode']);
        self::assertSame(0.0, $invoice['items'][0]['taxRate']);
    }

    public function testItemUnitPriceIsZeroWhenQuantityIsZero(): void
    {
        $order = $this->orderWithSingleLineItem(taxRate: 19.0, taxAmount: 0.0, totalGross: 0.0, quantity: 0);

        $invoice = $this->mapper->mapToInvoice($order, $this->fullSellerConfig(), InvoiceType::Invoice);

        self::assertSame(0.0, $invoice['items'][0]['unitPrice']);
    }

    public function testShippingItemIsAppendedWhenDeliveryHasShippingCost(): void
    {
        $order = $this->minimalOrder();
        $this->attachDelivery($order, shippingTotalGross: 5.95, shippingTaxAmount: 0.95, shippingTaxRate: 19.0);

        $invoice = $this->mapper->mapToInvoice($order, $this->fullSellerConfig(), InvoiceType::Invoice);

        self::assertCount(2, $invoice['items']);
        $shipping = $invoice['items'][1];
        self::assertSame(2, $shipping['position']);
        self::assertSame('Shipping', $shipping['description']);
        self::assertSame(1, $shipping['quantity']);
        self::assertSame(5.0, $shipping['netAmount']);
        self::assertSame(0.95, $shipping['taxAmount']);
        self::assertSame(5.95, $shipping['grossAmount']);
    }

    public function testShippingItemIsOmittedWhenShippingCostIsZero(): void
    {
        $order = $this->minimalOrder();
        $this->attachDelivery($order, shippingTotalGross: 0.0, shippingTaxAmount: 0.0, shippingTaxRate: 0.0);

        $invoice = $this->mapper->mapToInvoice($order, $this->fullSellerConfig(), InvoiceType::Invoice);

        self::assertCount(1, $invoice['items']);
    }

    public function testTaxSummaryAggregatesPerRateFromOrderPrice(): void
    {
        $order = $this->minimalOrder();

        $invoice = $this->mapper->mapToInvoice($order, $this->fullSellerConfig(), InvoiceType::Invoice);

        self::assertCount(1, $invoice['taxSummary']);
        self::assertSame(19.0, $invoice['taxSummary'][0]['taxRate']);
        self::assertSame('S', $invoice['taxSummary'][0]['taxCategoryCode']);
        self::assertSame(100.0, $invoice['taxSummary'][0]['netAmount']);
        self::assertSame(19.0, $invoice['taxSummary'][0]['taxAmount']);
    }

    public function testBankAccountIsAttachedAndPaymentMethodsSetWhenIbanProvided(): void
    {
        $order  = $this->minimalOrder();
        $config = $this->fullSellerConfig() + [
            'sellerIban'          => 'DE89370400440532013000',
            'sellerBic'           => 'COBADEFFXXX',
            'sellerBankName'      => 'Commerzbank',
            'sellerAccountHolder' => 'Invoice-api.xhub Seller GmbH',
        ];

        $invoice = $this->mapper->mapToInvoice($order, $config, InvoiceType::Invoice);

        self::assertSame([
            'iban'          => 'DE89370400440532013000',
            'bic'           => 'COBADEFFXXX',
            'bankName'      => 'Commerzbank',
            'accountHolder' => 'Invoice-api.xhub Seller GmbH',
        ], $invoice['seller']['bankAccount']);
        self::assertSame([['type' => 'bank_transfer']], $invoice['paymentMethods']);
    }

    public function testBankAccountOmittedWhenIbanMissing(): void
    {
        $order  = $this->minimalOrder();
        $config = $this->fullSellerConfig();

        $invoice = $this->mapper->mapToInvoice($order, $config, InvoiceType::Invoice);

        self::assertArrayNotHasKey('bankAccount', $invoice['seller']);
        self::assertArrayNotHasKey('paymentMethods', $invoice);
    }

    public function testCountrySpecificLeitwegFromCustomFieldWinsOverConfigDefault(): void
    {
        $order = $this->minimalOrder();
        $order->setCustomFields(['invoice_api_xhub_leitweg_id' => '991-12345-67']);
        $config = $this->fullSellerConfig() + ['defaultLeitwegId' => '0000-FALLBACK-0000'];

        $invoice = $this->mapper->mapToInvoice($order, $config, InvoiceType::Invoice);

        self::assertSame('991-12345-67', $invoice['countrySpecific']['leitwegId']);
    }

    public function testCountrySpecificFallsBackToConfigDefaultLeitweg(): void
    {
        $order  = $this->minimalOrder();
        $config = $this->fullSellerConfig() + ['defaultLeitwegId' => 'CFG-0001'];

        $invoice = $this->mapper->mapToInvoice($order, $config, InvoiceType::Invoice);

        self::assertSame('CFG-0001', $invoice['countrySpecific']['leitwegId']);
    }

    public function testCountrySpecificIncludesBuyerReferenceFromCustomField(): void
    {
        $order = $this->minimalOrder();
        $order->setCustomFields(['invoice_api_xhub_buyer_reference' => 'BR-2026-A']);

        $invoice = $this->mapper->mapToInvoice($order, $this->fullSellerConfig(), InvoiceType::Invoice);

        self::assertSame('BR-2026-A', $invoice['countrySpecific']['buyerReference']);
    }

    public function testCountrySpecificOmittedForNonGermanSeller(): void
    {
        $order  = $this->minimalOrder();
        $config = $this->fullSellerConfig();
        $config['sellerCountryCode'] = 'AT';
        $order->setCustomFields(['invoice_api_xhub_leitweg_id' => 'LW-X']);

        $invoice = $this->mapper->mapToInvoice($order, $config, InvoiceType::Invoice);

        self::assertArrayNotHasKey('countrySpecific', $invoice);
    }

    public function testCustomerCommentSurfacedAsNote(): void
    {
        $order = $this->minimalOrder();
        $order->setCustomerComment('  Bitte schnell liefern.  ');

        $invoice = $this->mapper->mapToInvoice($order, $this->fullSellerConfig(), InvoiceType::Invoice);

        self::assertSame('Bitte schnell liefern.', $invoice['note']);
    }

    public function testEmptyCustomerCommentDoesNotEmitNote(): void
    {
        $order = $this->minimalOrder();
        $order->setCustomerComment("   \t   ");

        $invoice = $this->mapper->mapToInvoice($order, $this->fullSellerConfig(), InvoiceType::Invoice);

        self::assertArrayNotHasKey('note', $invoice);
    }

    public function testCreditNoteFlipsAmountsAndTags(): void
    {
        $order = $this->minimalOrder();
        $order->setCustomFields(['invoice_api_xhub_invoice_number' => 'INV-99']);

        $invoice = $this->mapper->mapToInvoice($order, $this->fullSellerConfig(), InvoiceType::CreditNote);

        self::assertSame('credit_note', $invoice['type']);
        self::assertSame('INV-99', $invoice['referencedInvoiceNumber']);
        self::assertLessThan(0, $invoice['total']);
        self::assertLessThan(0, $invoice['subtotal']);
        self::assertLessThan(0, $invoice['items'][0]['netAmount']);
        self::assertLessThan(0, $invoice['items'][0]['taxAmount']);
        self::assertLessThan(0, $invoice['items'][0]['grossAmount']);
        self::assertLessThan(0, $invoice['items'][0]['unitPrice']);
        self::assertLessThan(0, $invoice['taxSummary'][0]['netAmount']);
        self::assertLessThan(0, $invoice['taxSummary'][0]['taxAmount']);
    }

    public function testResolveTemplateDelegatesToTemplateResolver(): void
    {
        $order = $this->minimalOrder();
        $order->setCustomFields(['invoice_api_xhub_template_id' => 'tpl-XYZ']);

        self::assertSame('tpl-XYZ', $this->mapper->resolveTemplate($order, []));
    }

    // -------------------------------------------------------------- helpers

    /**
     * @return array<string,mixed>
     */
    private function fullSellerConfig(): array
    {
        return [
            'sellerName'        => 'Invoice-api.xhub Seller GmbH',
            'sellerStreet'      => 'Friedrichstr. 12',
            'sellerCity'        => 'Berlin',
            'sellerPostalCode'  => '10117',
            'sellerCountryCode' => 'DE',
            'sellerVatId'       => 'DE123456789',
            'sellerEmail'       => 'seller@example.com',
            'sellerPhone'       => '+49301234567',
        ];
    }

    private function minimalOrder(): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId('order-uuid-1');
        $order->setOrderNumber('SW-1001');
        $order->setOrderDateTime(new \DateTimeImmutable('2026-04-30'));

        $currency = new CurrencyEntity();
        $currency->setId('cur-1');
        $currency->setIsoCode('EUR');
        $order->setCurrency($currency);

        // Order customer
        $cust = new OrderCustomerEntity();
        $cust->setId('cust-1');
        $cust->assign([
            'company'   => 'Buyer Company AG',
            'firstName' => 'Jane',
            'lastName'  => 'Doe',
            'email'     => 'buyer@example.com',
            'vatIds'    => ['DE987654321'],
        ]);
        $order->setOrderCustomer($cust);

        // Billing address with country
        $country = new CountryEntity();
        $country->setId('country-de');
        $country->setIso('DE');

        $billing = new OrderAddressEntity();
        $billing->setId('addr-1');
        $billing->assign([
            'firstName'              => 'Jane',
            'lastName'               => 'Doe',
            'street'                 => 'Hauptstr. 7',
            'additionalAddressLine1' => 'Apt. 4',
            'city'                   => 'Munich',
            'zipcode'                => '80331',
            'country'                => $country,
            'company'                => 'Buyer Company AG',
        ]);
        $order->setBillingAddress($billing);

        // Single line item: 2x 50€ net = 100 net, 19 tax, 119 gross
        $li = new OrderLineItemEntity();
        $li->setId('li-1');
        $li->setIdentifier('li-1');
        $li->setLabel('Demo Product');
        $li->setDescription('Demo Product');
        $li->setQuantity(2);
        $li->setPrice($this->calculatedPrice(50.0, 119.0, 19.0, 19.0));

        $col = new OrderLineItemCollection([$li]);
        $order->setLineItems($col);

        // Order-level price block — drives the tax summary
        $order->setPrice(new CartPrice(
            netPrice: 100.0,
            totalPrice: 119.0,
            positionPrice: 100.0,
            calculatedTaxes: new CalculatedTaxCollection([
                new CalculatedTax(19.0, 19.0, 100.0),
            ]),
            taxRules: new TaxRuleCollection(),
            taxStatus: CartPrice::TAX_STATE_NET,
        ));

        $order->setDeliveries(new OrderDeliveryCollection());

        return $order;
    }

    private function orderWithSingleLineItem(
        float $taxRate,
        float $taxAmount,
        float $totalGross,
        int $quantity = 1,
    ): OrderEntity {
        $order = $this->minimalOrder();
        $items = $order->getLineItems();
        self::assertNotNull($items);
        // Replace the single line item.
        $li = new OrderLineItemEntity();
        $li->setId('li-x');
        $li->setIdentifier('li-x');
        $li->setLabel('Item');
        $li->setQuantity($quantity);
        $li->setPrice($this->calculatedPrice($totalGross, $totalGross, $taxAmount, $taxRate));

        $order->setLineItems(new OrderLineItemCollection([$li]));

        return $order;
    }

    private function attachDelivery(
        OrderEntity $order,
        float $shippingTotalGross,
        float $shippingTaxAmount,
        float $shippingTaxRate,
    ): void {
        $delivery = new OrderDeliveryEntity();
        $delivery->setId('del-1');
        $delivery->setShippingCosts($this->calculatedPrice(
            $shippingTotalGross,
            $shippingTotalGross,
            $shippingTaxAmount,
            $shippingTaxRate,
        ));
        $order->setDeliveries(new OrderDeliveryCollection([$delivery]));
    }

    private function calculatedPrice(
        float $unitPrice,
        float $totalGross,
        float $taxAmount,
        float $taxRate,
    ): CalculatedPrice {
        $taxes = new CalculatedTaxCollection([
            new CalculatedTax($taxAmount, $taxRate, $totalGross - $taxAmount),
        ]);

        return new CalculatedPrice(
            unitPrice: $unitPrice,
            totalPrice: $totalGross,
            calculatedTaxes: $taxes,
            taxRules: new TaxRuleCollection(),
            quantity: 1,
        );
    }
}
