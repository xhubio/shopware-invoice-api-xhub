# Invoice-api.xhub for Shopware

Free, MIT-licensed connector between Shopware 6.6/6.7 and the [invoice-api.xhub.io](https://invoice-api.xhub.io) e-invoicing API. Generates EU-compliant electronic invoices (PDF, XRechnung, ZUGFeRD) directly from Shopware orders.

> Deutsche Dokumentation: [README.de.md](./README.de.md)

## Features

- Auto-generates invoices on configurable order-state transitions
- 3 formats live: **PDF** (14 countries), **XRechnung** (DE), **ZUGFeRD** (DE/AT)
- Roadmap Q3 2026: Factur-X, FatturaPA, Facturae, ebInterface, UBL, ISDOC, NAV
- Atomic, gap-free invoice numbering (§14 UStG-compliant)
- Refund triggers automatic credit-note generation
- GDPR / CCPA hooks
- Vue.js admin module: re-generate, download, history per order
- Symfony Messenger async — non-blocking checkout

## Installation

### Via Composer (recommended)

```bash
composer require xhubio/shopware-invoice-api-xhub
bin/console plugin:refresh
bin/console plugin:install --activate InvoiceApiXhub
bin/console cache:clear
```

### Manual ZIP

1. Download the latest `InvoiceApiXhub-*.zip` from [GitHub Releases](https://github.com/xhubio/shopware-invoice-api-xhub/releases).
2. Extract to `custom/plugins/InvoiceApiXhub` in your Shopware installation.
3. Run `bin/console plugin:refresh && bin/console plugin:install --activate InvoiceApiXhub`.
4. Clear the cache: `bin/console cache:clear`.

## Configuration

1. Sign up at [invoice-api.xhub.io](https://invoice-api.xhub.io) and generate an API key in the [console](https://console.invoice-api.xhub.io/api-keys).
2. In Shopware Admin: **Extensions -> My Extensions -> "Invoice-api.xhub for Shopware" -> "..." menu -> Configure**.
3. Fill in:
   - **API connection**: API key + base URL (default: `https://service.invoice-api.xhub.io`)
   - **Document defaults**: Country, Format, Trigger (when to fire), Email-attach toggle, Payment due days
   - **Invoice numbering**: Format (e.g. `2026-{seq:0000}` for §14 UStG), reset mode
   - **Seller**: Your business details + tax ID + bank account (IBAN/BIC required for some country profiles)
   - **Country specific (DE)**: Default Leitweg-ID for B2G XRechnung
4. Save.

## How to test (review walkthrough)

This section guides the Shopware Marketplace reviewer through a complete test of the plugin. It works against a Shopware installation with the plugin installed and configured per the section above.

**Prerequisites:**

- Plugin installed and activated
- API key set (a sandbox key for review purposes is available — see [Reviewer note](#reviewer-note) below)
- One test order with at least one product line item

**Test 1 — Auto-generation on order completion**

1. Open Admin -> Orders -> select a test order with status `open`.
2. Transition the order to `completed` (Order status -> "Done").
3. Wait ~5 seconds (Symfony Messenger sync transport processes in-request).
4. Refresh the order detail page -> scroll to the bottom of the **General** tab.
5. **Expected:** A new card "Invoice-api.xhub" appears with:
   - "Invoice actions" sub-card showing `Filename: INV-{order-number}.pdf` and `Generated at: <ISO timestamp>`
   - "History" sub-card showing one entry with status `success`

**Test 2 — Re-generate**

1. On the same order, click the "Re-generate invoice" button.
2. **Expected:** Filename and Generated-at update to current timestamp; History gains another `success` entry.

**Test 3 — Download**

1. Click the "Download" button.
2. **Expected:** Browser downloads `INV-{order-number}.pdf` (~20-25 KB), a valid PDF/A-3 file.

**Test 4 — XRechnung format**

1. Configuration -> set Format to `XRechnung (DE)`.
2. Re-generate.
3. **Expected:** Filename becomes `INV-{order-number}_xrechnung.xml`, content is valid UBL 2.1 with BIS 3.0 / EN 16931 customizationID.

**Test 5 — Refund triggers credit-note (optional)**

1. Open Order -> Refund the order_transaction (admin -> Refund button).
2. **Expected:** A new generation event fires with `type=credit_note` and negated amounts.

### Reviewer note

For the review process we provide a sandbox API key with the "review" tag — please email `support@invoice-api.xhub.io` with your reviewer ID, and we will send you a key valid for the duration of the review.

The plugin makes outbound HTTP requests to `https://service.invoice-api.xhub.io` for invoice generation. Data sent: order line items, billing/shipping addresses, configured seller info. No data is sent before the user has explicitly configured the API key. Privacy policy: https://invoice-api.xhub.io/privacy

## External services disclosure

This plugin connects to **invoice-api.xhub.io** (operator: xhub.io) for invoice generation. The plugin POSTs the order's line items, billing address, configured seller info, and tax breakdown to the API and receives a compliant invoice file. No data is sent before the user has configured an API key, and only when the configured order-state trigger fires.

- Service operator: xhub.io
- Terms: https://invoice-api.xhub.io/terms
- Privacy: https://invoice-api.xhub.io/privacy
- Pricing: https://console.invoice-api.xhub.io

## Business model

The plugin is **free** under MIT license, distributed via GitHub, packagist.org, and (planned) the Shopware Store. Revenue lives in the customer's separate subscription to invoice-api.xhub.io. This is the same SaaS-companion model used by [Stripe](https://store.shopware.com/en/sw5stri93537005054.html), [Mollie](https://store.shopware.com/en/sw5moll170498000150.html), and [PayPal](https://store.shopware.com/en/swag257690075008/paypal-payments.html) on Shopware. There is no Pro tier inside the plugin and no licence-key check.

## Compatibility

- Shopware 6.6
- Shopware 6.7
- PHP 8.1+

## Development

See `docs/SETUP-WALKTHROUGH-DOCKER.md` for a one-command Docker-based dev/test stack.

## Support

- Bug reports / feature requests: https://github.com/xhubio/shopware-invoice-api-xhub/issues
- Email: support@invoice-api.xhub.io
- Docs: https://invoice-api.xhub.io/en/docs/integrations/shopware

## License

MIT — see [LICENSE.md](./LICENSE.md)
