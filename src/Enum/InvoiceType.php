<?php

declare(strict_types=1);

namespace Xhubio\InvoiceApiXhub\Enum;

/**
 * Document flavour produced by the generator.
 *
 * `Invoice` is the regular forward document (UNTDID 1001 type=380 in
 * XRechnung BT-3); `CreditNote` is the corrective Storno emitted on refund
 * (type=381). The string values match the plugin's pre-enum convention so
 * we serialise cleanly through Symfony Messenger (the queued
 * GenerateInvoiceMessage stays binary-compatible with messages enqueued by
 * an older plugin version).
 */
enum InvoiceType: string
{
    case Invoice    = 'invoice';
    case CreditNote = 'credit_note';
}
