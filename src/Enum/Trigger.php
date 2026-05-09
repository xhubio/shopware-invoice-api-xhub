<?php

declare(strict_types=1);

namespace Xhubio\InvoiceApiXhub\Enum;

/**
 * Auto-generation trigger choices exposed by the plugin config (`trigger`).
 *
 * The string values mirror the WooCommerce reference plugin's option keys so
 * support docs / KB articles stay portable between shop systems. Only a
 * subset of these has a Shopware order-state equivalent — see
 * OrderStateSubscriber for the trigger -> technicalName mapping. Values
 * without a matching state (currently `OnHold`) are intentionally retained
 * here so the enum stays the source-of-truth for the union; the subscriber
 * logs a no-op debug line at runtime when one of those is selected.
 */
enum Trigger: string
{
    case Off          = 'off';
    case OnPending    = 'on_pending';
    case OnHold       = 'on_on_hold';
    case OnProcessing = 'on_processing';
    case OnCompleted  = 'on_completed';
}
