<?php

declare(strict_types=1);

namespace Xhubio\InvoiceApiXhub\Enum;

/**
 * Output format the invoice-api.xhub.io service generates.
 *
 * The string values are the wire-level slugs the API expects in the URL
 * (`POST /api/v1/invoice/{country}/{format}/generate`) and the same slugs
 * the plugin-config XML stores in the `format` system_config row. Keeping
 * the values aligned means a roundtrip
 *   config-string -> InvoiceFormat::from(...) -> ->value
 * is loss-less and we can swap between the two representations freely.
 *
 * Live availability (2026-Q2): only PDF/XRechnung/ZUGFeRD generate-able.
 * The other 7 formats listed in the marketing copy ship from Q3 2026; until
 * then they intentionally stay out of this enum so a typo at the call site
 * surfaces at compile-time instead of as an opaque API 404 at runtime.
 */
enum InvoiceFormat: string
{
    case Pdf       = 'pdf';
    case Xrechnung = 'xrechnung';
    case Zugferd   = 'zugferd';
}
