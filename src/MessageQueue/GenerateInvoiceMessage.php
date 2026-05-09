<?php

declare(strict_types=1);

namespace Xhubio\InvoiceApiXhub\MessageQueue;

/**
 * Symfony Messenger DTO that drives off-thread invoice generation.
 *
 * Mirrors the WooCommerce Action Scheduler payload (`order_id`, `type`):
 * the order id is enough to load the entity inside the handler, and `type`
 * carries the document flavour ('invoice' for the regular flow,
 * 'credit_note' for refunds, future-proofed for 'proforma' / 'correction').
 *
 * Intentionally trivial — no Shopware Context is captured here. Context is
 * not safely serialisable for a queue (closures/scopes), so the handler
 * builds a fresh `Context::createDefaultContext()` on the consumer side.
 */
final class GenerateInvoiceMessage
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $type = 'invoice',
    ) {
    }
}
