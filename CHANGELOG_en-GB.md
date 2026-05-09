# 1.1.0

* **Auto-email-attachment** — generated invoice is automatically attached to the customer's order-confirmation email via `MailBeforeValidateEvent` (config-toggle `attachToEmail`, default on).
* **Storefront customer-portal** — customers can download their invoice from their account area (`/account/invoice/{orderId}`) with ownership-check (`customerId === order.orderCustomer.customerId`). 403 for foreign orders, 404 if no invoice generated yet.
* **VIES VAT-ID validation** — optional pre-validation of buyer VAT-IDs against the EU VIES API before generation. Invalid VAT → generation skipped + audit-logged. Toggle `validateVatBeforeGenerate` in plugin config, default off.
* **Audit-trail entity** — new `invoice_api_xhub_audit` table replaces synthetic-from-customField history. Per-step events with status/format/duration/error/user-id, queryable from the admin "History" card.
* **PHP 8.1 backed enums** — `InvoiceFormat`, `Trigger`, `InvoiceType` for type-safe internal representation. `config.xml` keeps string values for Shopware-config-system compatibility.
* **Sales-channel-specific config overrides** — `SystemConfigService::get()` now receives the order's `salesChannelId`. Multi-storefront shops can configure a different country/format/seller per sales-channel; Shopware merges channel-overrides over global defaults.
* **Migration `1715000001CreateAuditTable.php`** — adds the audit table with FK-cascade-on-order-delete; uninstall drops the table when `keepUserData=false`.

# 1.0.0

* Initial release.
* Live formats: PDF (all 14 supported countries), XRechnung (DE, BIS 3.0 / EN 16931), ZUGFeRD (DE/AT, version 2.3 / 2.4 hybrid PDF/A-3).
* Auto-generation on configurable order state transitions (pending / on-hold / processing / completed).
* Atomic, gap-free invoice numbering via custom DB table — §14 UStG compliant when using `{seq:0000}` token format.
* Refund/credit-note auto-generation on `order_transaction.refunded` state transition.
* Custom invoice templates via Template-ID (UUID) configurable globally or per-order.
* GDPR/CCPA hooks via `customer.deleted` event — invoice payloads + stored files erased on customer right-to-be-forgotten.
* Vue.js admin module: re-generate, download, history cards on the order detail General tab.
* Symfony Messenger async generation — non-blocking checkout, optionally decoupled with `messenger:consume async` worker.
