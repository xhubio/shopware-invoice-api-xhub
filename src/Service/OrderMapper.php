<?php

declare(strict_types=1);

namespace Xhubio\InvoiceApiXhub\Service;

use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Xhubio\InvoiceApiXhub\Enum\InvoiceType;

/**
 * Maps a Shopware OrderEntity into the invoice JSON shape required by
 * invoice-api.xhub.io (`POST /api/v1/invoice/{country}/{format}/generate`).
 *
 * Schema reference (live API spec 1.2.0) — must match WooCommerce mapper 1:1:
 *   - flat seller/buyer addresses (street, city, postalCode, countryCode)
 *   - items[] with description/quantity/unit/unitPrice/taxRate/
 *     netAmount/taxAmount/grossAmount + position
 *   - subtotal + total + taxSummary[]
 *   - paymentTerms.dueDays
 *   - countrySpecific.{leitwegId | buyerReference} for DE
 *
 * Returns ONLY the inner $invoice array. The wrapping
 * `{invoice, formatOptions, templateId}` is built by the caller (the
 * generation service / ApiClient), so the same mapper output works for both
 * /generate and /validate endpoints.
 *
 * Net vs gross handling: Shopware orders carry a tax-status flag that says
 * whether prices are gross-tax (B2C default) or net-tax (typical B2B).
 * Our invoice payload always uses NET amounts at line level + tax separately
 * (UNTDID 5305 / EN16931). The mapper derives net from each line's
 * CalculatedPrice (totalPrice + calculatedTaxes) regardless of the order's
 * tax-status — the totals are the same either way; only the rendering of
 * unitPrice is affected.
 *
 * Edge cases:
 *   - LineItems may carry multiple tax rates (mixed rate per position) — for
 *     MVP we collapse to the first rate and include the full taxAmount on
 *     that line; the per-rate tax summary still aggregates correctly because
 *     it sums calculatedTaxes from order->getPrice() directly, not from
 *     items.
 *   - Promotions/discounts surface as separate line items with negative
 *     amounts; we include them as-is — the API handles negative line items
 *     and they sum into subtotal/total correctly.
 */
final class OrderMapper
{
    public function __construct(
        private readonly InvoiceNumberService $numberService,
        private readonly TemplateResolver $templateResolver,
    ) {
    }

    /**
     * Build the invoice JSON for an order.
     *
     * @param OrderEntity         $order
     * @param array<string,mixed> $config Plugin configuration values
     * @param InvoiceType         $type   Invoice (forward) or CreditNote.
     *                                    Credit notes flip every monetary
     *                                    amount to negative and tag the
     *                                    document type accordingly.
     *
     * @return array<string,mixed> Invoice JSON ready to wrap into
     *                             `{invoice, formatOptions, templateId}`.
     */
    public function mapToInvoice(
        OrderEntity $order,
        array $config,
        InvoiceType $type = InvoiceType::Invoice,
    ): array {
        $country  = strtoupper(isset($config['sellerCountryCode']) ? (string) $config['sellerCountryCode'] : 'DE');
        $currency = $order->getCurrency()?->getIsoCode() ?? 'EUR';

        // Shopware types getOrderDateTime() as non-nullable \DateTimeInterface,
        // so we accept it as-is — no defensive fallback required.
        $issueDate = $order->getOrderDateTime()->format('Y-m-d');
        $dueDays   = isset($config['paymentDueDays']) ? max(0, (int) $config['paymentDueDays']) : 14;
        $dueDate   = $this->addDaysIso($issueDate, $dueDays);

        $customFields    = $order->getCustomFields() ?? [];
        $numberOverride  = isset($customFields['invoice_api_xhub_invoice_number'])
            ? (string) $customFields['invoice_api_xhub_invoice_number']
            : null;

        $invoiceNumber = $this->numberService->build(
            $order,
            (string) ($config['numberFormat'] ?? 'INV-{order_number}'),
            (string) ($config['sequenceReset'] ?? 'yearly'),
            ('' !== ($numberOverride ?? '')) ? $numberOverride : null,
        );

        $items      = $this->buildItems($order);
        $taxSummary = $this->buildTaxSummary($order);
        $subtotal   = $this->sum($items, 'netAmount');
        $total      = $this->sum($items, 'grossAmount');

        $invoice = [
            'invoiceNumber' => $invoiceNumber,
            'type'          => 'invoice',
            'issueDate'     => $issueDate,
            'dueDate'       => $dueDate,
            'currency'      => $currency,
            'seller'        => $this->buildSeller($config),
            'buyer'         => $this->buildBuyer($order),
            'items'         => $items,
            'subtotal'      => $this->round($subtotal),
            'total'         => $this->round($total),
            'taxSummary'    => $taxSummary,
            'paymentTerms'  => [
                'dueDays' => $dueDays,
            ],
        ];

        // Bank account → attached to seller. Required for SEPA bank transfers
        // in ZUGFeRD/XRechnung. paymentMethods: [{type: "bank_transfer"}] is
        // the declaration that goes alongside it.
        $bankAccount = $this->buildBankAccount($config);
        if (null !== $bankAccount) {
            $invoice['seller']['bankAccount'] = $bankAccount;
            $invoice['paymentMethods']        = [
                ['type' => 'bank_transfer'],
            ];
        }

        $countrySpecific = $this->buildCountrySpecific($country, $order, $config);
        if ([] !== $countrySpecific) {
            $invoice['countrySpecific'] = $countrySpecific;
        }

        $note = trim((string) ($order->getCustomerComment() ?? ''));
        if ('' !== $note) {
            $invoice['note'] = $note;
        }

        if (InvoiceType::CreditNote === $type) {
            $invoice = $this->convertToCreditNote($invoice, $customFields);
        }

        // Note: templateId is NOT part of the invoice block — it is a
        // sibling of `invoice` in the request body. Callers obtain it via
        // resolveTemplate() and place it alongside this returned array.
        return $invoice;
    }

    /**
     * Public passthrough so the calling service can resolve the template id
     * via the same dependency-injected resolver and avoid wiring it twice.
     *
     * @param array<string,mixed> $config
     */
    public function resolveTemplate(OrderEntity $order, array $config): ?string
    {
        return $this->templateResolver->resolve($order, $config);
    }

    /**
     * @param array<string,mixed> $config
     *
     * @return array<string,string>
     */
    private function buildSeller(array $config): array
    {
        $required = [
            'name'        => (string) ($config['sellerName'] ?? ''),
            'street'      => (string) ($config['sellerStreet'] ?? ''),
            'city'        => (string) ($config['sellerCity'] ?? ''),
            'postalCode'  => (string) ($config['sellerPostalCode'] ?? ''),
            'countryCode' => strtoupper((string) ($config['sellerCountryCode'] ?? 'DE')),
        ];

        $optional = array_filter([
            'vatId' => trim((string) ($config['sellerVatId'] ?? '')),
            'email' => trim((string) ($config['sellerEmail'] ?? '')),
            'phone' => trim((string) ($config['sellerPhone'] ?? '')),
        ], static fn (string $v): bool => '' !== $v);

        return array_merge($required, $optional);
    }

    /**
     * @return array<string,string>
     */
    private function buildBuyer(OrderEntity $order): array
    {
        $orderCustomer = $order->getOrderCustomer();
        $billing       = $order->getBillingAddress();

        // Prefer the customer's company; fall back to the address-attached
        // company (Shopware can carry both); finally first/last name.
        $company   = trim((string) ($orderCustomer?->getCompany() ?? ''));
        if ('' === $company && $billing instanceof OrderAddressEntity) {
            $company = trim((string) ($billing->getCompany() ?? ''));
        }
        $firstName = trim((string) ($orderCustomer?->getFirstName() ?? ($billing?->getFirstName() ?? '')));
        $lastName  = trim((string) ($orderCustomer?->getLastName() ?? ($billing?->getLastName() ?? '')));
        $name      = '' !== $company ? $company : trim($firstName . ' ' . $lastName);

        $street = '';
        $city   = '';
        $postal = '';
        $cc     = '';
        if ($billing instanceof OrderAddressEntity) {
            $street = trim((string) $billing->getStreet());
            $additional = trim((string) ($billing->getAdditionalAddressLine1() ?? ''));
            if ('' !== $additional) {
                $street = trim($street . ' ' . $additional);
            }
            $city   = (string) $billing->getCity();
            $postal = (string) $billing->getZipcode();

            $countryEntity = $billing->getCountry();
            $cc            = strtoupper((string) ($countryEntity?->getIso() ?? ''));
        }

        $required = [
            'name'        => '' !== $name ? $name : 'Customer',
            'street'      => $street,
            'city'        => $city,
            'postalCode'  => $postal,
            'countryCode' => $cc,
        ];

        $email  = trim((string) ($orderCustomer?->getEmail() ?? ''));
        $vatId  = '';
        if (null !== $orderCustomer) {
            $vatIds = $orderCustomer->getVatIds() ?? [];
            // getVatIds() is typed as list<string>|null in the Shopware
            // entity, so the array check is redundant — only the
            // non-empty guard remains.
            if ([] !== $vatIds) {
                $vatId = trim((string) reset($vatIds));
            }
        }

        $optional = array_filter([
            'email' => $email,
            'vatId' => $vatId,
        ], static fn (string $v): bool => '' !== $v);

        return array_merge($required, $optional);
    }

    /**
     * Build line-item array from the order's lineItems + shipping cost.
     *
     * @return list<array<string,mixed>>
     */
    private function buildItems(OrderEntity $order): array
    {
        $items     = [];
        $position  = 1;
        $lineItems = $order->getLineItems();

        if (null !== $lineItems) {
            foreach ($lineItems as $li) {
                // Skip nested children — they are aggregated into their parent.
                // Top-level items have no parentId or it is null.
                if (null !== $li->getParentId()) {
                    continue;
                }
                $items[] = $this->buildLineItem($li, $position++);
            }
        }

        // Shipping is in the order delivery cost, NOT a line item. Surface it
        // as an additional position so it appears on the invoice.
        $shippingItem = $this->buildShippingItem($order, $position);
        if (null !== $shippingItem) {
            $items[] = $shippingItem;
        }

        return $items;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildLineItem(OrderLineItemEntity $li, int $position): array
    {
        $quantity      = (float) $li->getQuantity();
        $price         = $li->getPrice();
        $netTotal      = 0.0;
        $taxTotal      = 0.0;
        $taxRate       = 0.0;
        $unitNet       = 0.0;

        if (null !== $price) {
            $totalPrice = (float) $price->getTotalPrice();
            $taxes      = $price->getCalculatedTaxes();
            $taxTotal   = (float) $taxes->getAmount();
            $first      = $taxes->first();
            if ($first instanceof CalculatedTax) {
                $taxRate = (float) $first->getTaxRate();
            }
            // totalPrice from CalculatedPrice is the GROSS amount — net = gross - tax
            $netTotal = $totalPrice - $taxTotal;
            $unitNet  = $quantity > 0 ? $netTotal / $quantity : $netTotal;
        }

        $description = (string) ($li->getDescription() ?? $li->getLabel());
        $name        = (string) $li->getLabel();

        return [
            'position'        => $position,
            'description'     => '' !== $description ? $description : $name,
            'quantity'        => $this->round($quantity),
            'unit'            => 'H87',
            'unitPrice'       => $this->round($unitNet),
            'taxRate'         => $taxRate,
            'taxCategoryCode' => $this->deriveTaxCategoryCode($taxRate),
            'netAmount'       => $this->round($netTotal),
            'taxAmount'       => $this->round($taxTotal),
            'grossAmount'     => $this->round($netTotal + $taxTotal),
        ];
    }

    /**
     * Promote shipping cost to a synthetic line item. Returns null if the
     * order has no positive shipping cost (free shipping, pickup, etc.).
     *
     * @return array<string,mixed>|null
     */
    private function buildShippingItem(OrderEntity $order, int $position): ?array
    {
        $deliveries = $order->getDeliveries();
        if (null === $deliveries || 0 === $deliveries->count()) {
            return null;
        }

        $netTotal = 0.0;
        $taxTotal = 0.0;
        $taxRate  = 0.0;
        $haveRate = false;

        foreach ($deliveries as $delivery) {
            $shippingCosts = $delivery->getShippingCosts();
            $totalPrice    = (float) $shippingCosts->getTotalPrice();
            $taxes         = $shippingCosts->getCalculatedTaxes();
            $deliveryTax   = (float) $taxes->getAmount();
            if (!$haveRate) {
                $first = $taxes->first();
                if ($first instanceof CalculatedTax) {
                    $taxRate  = (float) $first->getTaxRate();
                    $haveRate = true;
                }
            }
            $netTotal += $totalPrice - $deliveryTax;
            $taxTotal += $deliveryTax;
        }

        if ($netTotal <= 0.0 && $taxTotal <= 0.0) {
            return null;
        }

        return [
            'position'        => $position,
            'description'     => 'Shipping',
            'quantity'        => 1,
            'unit'            => 'H87',
            'unitPrice'       => $this->round($netTotal),
            'taxRate'         => $taxRate,
            'taxCategoryCode' => $this->deriveTaxCategoryCode($taxRate),
            'netAmount'       => $this->round($netTotal),
            'taxAmount'       => $this->round($taxTotal),
            'grossAmount'     => $this->round($netTotal + $taxTotal),
        ];
    }

    /**
     * Aggregate per-rate tax summary from the order price block. Shopware has
     * already grouped calculatedTaxes per rate, so this is a direct mapping.
     *
     * @return list<array<string,mixed>>
     */
    private function buildTaxSummary(OrderEntity $order): array
    {
        // OrderEntity::getPrice() and CartPrice::getCalculatedTaxes() are both
        // typed non-nullable in Shopware Core, so we can dereference directly.
        $taxes = $order->getPrice()->getCalculatedTaxes();

        $summary = [];
        foreach ($taxes as $t) {
            $rate = (float) $t->getTaxRate();
            // Shopware exposes only taxAmount + price (the taxable base) per
            // rate group. Use $t->getPrice() which is the NET base for this rate.
            $summary[] = [
                'taxRate'         => $rate,
                'taxCategoryCode' => $this->deriveTaxCategoryCode($rate),
                'netAmount'       => $this->round((float) $t->getPrice()),
                'taxAmount'       => $this->round((float) $t->getTax()),
            ];
        }

        return $summary;
    }

    /**
     * Build the seller.bankAccount block. Returns null when no IBAN is set —
     * the caller then omits both bankAccount and paymentMethods from the
     * invoice JSON.
     *
     * @param array<string,mixed> $config
     *
     * @return array<string,string>|null
     */
    private function buildBankAccount(array $config): ?array
    {
        $iban   = trim((string) ($config['sellerIban'] ?? ''));
        $bic    = trim((string) ($config['sellerBic'] ?? ''));
        $bank   = trim((string) ($config['sellerBankName'] ?? ''));
        $holder = trim((string) ($config['sellerAccountHolder'] ?? ''));

        if ('' === $iban) {
            return null;
        }

        $bankAccount = ['iban' => $iban];
        if ('' !== $bic) {
            $bankAccount['bic'] = $bic;
        }
        if ('' !== $bank) {
            $bankAccount['bankName'] = $bank;
        }
        if ('' !== $holder) {
            $bankAccount['accountHolder'] = $holder;
        }

        return $bankAccount;
    }

    /**
     * Country-specific block. Currently only DE — XRechnung requires either
     * a Leitweg-ID (B2G) or a buyer reference (B2B).
     *
     * Resolution order (most-specific wins):
     *   1. Per-order custom field (`invoice_api_xhub_leitweg_id` /
     *      `invoice_api_xhub_buyer_reference`).
     *   2. Plugin config default (`defaultLeitwegId`). No global default
     *      exists for buyerReference — it is order-specific by design.
     *
     * Returns an empty array if neither value is set; the caller then
     * omits the `countrySpecific` key entirely from the invoice JSON.
     *
     * @param array<string,mixed> $config
     *
     * @return array<string,string>
     */
    private function buildCountrySpecific(string $country, OrderEntity $order, array $config): array
    {
        if ('DE' !== $country) {
            return [];
        }

        $customFields = $order->getCustomFields() ?? [];

        $orderLeitweg  = trim((string) ($customFields['invoice_api_xhub_leitweg_id'] ?? ''));
        $configLeitweg = trim((string) ($config['defaultLeitwegId'] ?? ''));
        $leitweg       = '' !== $orderLeitweg ? $orderLeitweg : $configLeitweg;

        $buyerReference = trim((string) ($customFields['invoice_api_xhub_buyer_reference'] ?? ''));

        $cs = [];
        if ('' !== $leitweg) {
            $cs['leitwegId'] = $leitweg;
        }
        if ('' !== $buyerReference) {
            $cs['buyerReference'] = $buyerReference;
        }

        return $cs;
    }

    /**
     * UNTDID 5305 tax category code:
     *   S = Standard rated (any rate > 0)
     *   Z = Zero rated
     *
     * Reverse-charge (AE) and exempt (E) need explicit override — Phase 1.x.
     */
    private function deriveTaxCategoryCode(float $taxRate): string
    {
        return $taxRate > 0 ? 'S' : 'Z';
    }

    /**
     * Convert an invoice payload into a credit-note payload by negating every
     * monetary amount and tagging the type. Optionally references the
     * original invoice via referencedInvoiceNumber when the order carries
     * the parent invoice in custom fields.
     *
     * @param array<string,mixed> $invoice
     * @param array<string,mixed> $customFields
     *
     * @return array<string,mixed>
     */
    private function convertToCreditNote(array $invoice, array $customFields): array
    {
        $invoice['type'] = 'credit_note';

        if (isset($invoice['items']) && is_array($invoice['items'])) {
            foreach ($invoice['items'] as $idx => $item) {
                if (!is_array($item)) {
                    continue;
                }
                foreach (['unitPrice', 'netAmount', 'taxAmount', 'grossAmount'] as $k) {
                    if (isset($item[$k])) {
                        $invoice['items'][$idx][$k] = $this->round(-1.0 * (float) $item[$k]);
                    }
                }
            }
        }

        foreach (['subtotal', 'total'] as $k) {
            if (isset($invoice[$k])) {
                $invoice[$k] = $this->round(-1.0 * (float) $invoice[$k]);
            }
        }

        if (isset($invoice['taxSummary']) && is_array($invoice['taxSummary'])) {
            foreach ($invoice['taxSummary'] as $idx => $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                foreach (['netAmount', 'taxAmount'] as $k) {
                    if (isset($entry[$k])) {
                        $invoice['taxSummary'][$idx][$k] = $this->round(-1.0 * (float) $entry[$k]);
                    }
                }
            }
        }

        $referenced = trim((string) ($customFields['invoice_api_xhub_invoice_number'] ?? ''));
        if ('' !== $referenced) {
            $invoice['referencedInvoiceNumber'] = $referenced;
        }

        return $invoice;
    }

    /**
     * 2-decimal HALF_UP rounding. PHP's default round() is HALF_AWAY_FROM_ZERO
     * which is HALF_UP for positive numbers and HALF_DOWN for negatives — we
     * pass the explicit constant for clarity and to match the WC reference.
     */
    private function round(float $value): float
    {
        return round($value, 2, PHP_ROUND_HALF_UP);
    }

    /**
     * Add days to an ISO date string (Y-m-d) and return the resulting date.
     */
    private function addDaysIso(string $iso, int $days): string
    {
        try {
            return (new \DateTimeImmutable($iso))
                ->modify('+' . max(0, $days) . ' days')
                ->format('Y-m-d');
        } catch (\Exception) {
            return (new \DateTimeImmutable())
                ->modify('+' . max(0, $days) . ' days')
                ->format('Y-m-d');
        }
    }

    /**
     * @param list<array<string,mixed>> $items
     */
    private function sum(array $items, string $key): float
    {
        $total = 0.0;
        foreach ($items as $item) {
            if (isset($item[$key])) {
                $total += (float) $item[$key];
            }
        }

        return $total;
    }
}
