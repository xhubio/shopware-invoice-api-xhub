<?php

declare(strict_types=1);

namespace Xhubio\InvoiceApiXhub\MessageQueue;

use Xhubio\InvoiceApiXhub\Enum\InvoiceType;

/**
 * Symfony Messenger DTO that drives off-thread invoice generation.
 *
 * Mirrors the WooCommerce Action Scheduler payload (`order_id`, `type`):
 * the order id is enough to load the entity inside the handler, and `type`
 * carries the document flavour (Invoice for the regular flow, CreditNote
 * for refunds, future-proofed for proforma / correction).
 *
 * Intentionally trivial — no Shopware Context is captured here. Context is
 * not safely serialisable for a queue (closures/scopes), so the handler
 * builds a fresh `Context::createDefaultContext()` on the consumer side.
 *
 * The InvoiceType enum serialises as its backing-string when Messenger
 * encodes the envelope (PHP-internal serialize() handles backed enums
 * losslessly), so any in-flight message remains decodable across the
 * 1.0.x → 1.1.0 plugin upgrade that introduced this typing.
 */
final class GenerateInvoiceMessage
{
    public function __construct(
        public readonly string $orderId,
        public readonly InvoiceType $type = InvoiceType::Invoice,
    ) {
    }
}
