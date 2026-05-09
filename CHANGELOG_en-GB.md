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
