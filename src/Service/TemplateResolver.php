<?php

declare(strict_types=1);

namespace Xhubio\InvoiceApiXhub\Service;

use Shopware\Core\Checkout\Order\OrderEntity;

/**
 * Resolves the invoice-api.xhub PDF template id for a given order.
 *
 * Resolution order (highest priority first):
 *   1. Per-order custom field `invoice_api_xhub_template_id` — lets shop
 *      operators override the default template for a specific order without
 *      changing global plugin settings (mirrors WooCommerce meta of the same
 *      name).
 *   2. Plugin configuration default (`templateId`) from SystemConfigService —
 *      the shop-wide default selected in plugin settings.
 *
 * Returns null when no template is configured at any level. The caller (the
 * ApiClient) then omits the `templateId` key from the generate request and
 * the API uses its built-in standard template.
 *
 * Templates are format-specific in the API: a template designed for XRechnung
 * will not work for ZUGFeRD or PDF. Validation of the template against the
 * selected format happens server-side; the resolver intentionally does not
 * try to second-guess that.
 */
final class TemplateResolver
{
    /**
     * @param array<string,mixed> $config Plugin configuration (system_config
     *                                    values resolved by the caller).
     */
    public function resolve(OrderEntity $order, array $config): ?string
    {
        $customFields = $order->getCustomFields() ?? [];
        $override = $customFields['invoice_api_xhub_template_id'] ?? null;
        if (is_string($override) && '' !== trim($override)) {
            return trim($override);
        }

        $default = isset($config['templateId']) ? trim((string) $config['templateId']) : '';

        return '' !== $default ? $default : null;
    }
}
