<?php

declare(strict_types=1);

namespace Xhubio\InvoiceApiXhub\MessageQueue;

use Shopware\Core\Framework\Context;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Xhubio\InvoiceApiXhub\Service\InvoiceGenerator;

/**
 * Symfony Messenger handler that runs the actual API call off-thread.
 *
 * Equivalent to the WooCommerce Action Scheduler callback
 * (`Order_Handler::do_generate_action`) — both move the network round-trip
 * to invoice-api.xhub.io out of the request that triggered the order
 * status transition, so checkout / admin save is no longer blocked on a
 * remote API.
 *
 * Context is intentionally rebuilt here rather than carried in the message:
 * Shopware Context contains scoped state (rule scope, source, etc.) that is
 * not portable across processes. `Context::createDefaultContext()` gives the
 * handler the system-level context it needs to load orders and persist
 * custom fields.
 */
#[AsMessageHandler]
final class GenerateInvoiceHandler
{
    public function __construct(
        private readonly InvoiceGenerator $generator,
    ) {
    }

    public function __invoke(GenerateInvoiceMessage $message): void
    {
        $context = Context::createDefaultContext();
        $this->generator->generate($message->orderId, $context, $message->type);
    }
}
