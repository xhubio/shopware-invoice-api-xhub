<?php

declare(strict_types=1);

namespace Xhubio\InvoiceApiXhub\Tests\Service;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\OrderEntity;
use Xhubio\InvoiceApiXhub\Service\TemplateResolver;

/**
 * Unit tests for the per-order custom-field override resolver.
 *
 * The resolver picks the highest-priority non-empty template id from
 *   1. OrderEntity custom fields
 *   2. Plugin config defaults
 * with a final fallback of null. These tests pin every branch.
 */
final class TemplateResolverTest extends TestCase
{
    private TemplateResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new TemplateResolver();
    }

    public function testReturnsNullWhenNoOverrideAndNoDefault(): void
    {
        $order = $this->order(null);

        self::assertNull($this->resolver->resolve($order, []));
    }

    public function testReturnsConfigDefaultWhenOrderHasNoOverride(): void
    {
        $order = $this->order(null);

        self::assertSame('tpl-default', $this->resolver->resolve($order, ['templateId' => 'tpl-default']));
    }

    public function testTrimsWhitespaceFromConfigDefault(): void
    {
        $order = $this->order(null);

        self::assertSame('tpl-default', $this->resolver->resolve($order, ['templateId' => '  tpl-default  ']));
    }

    public function testReturnsNullWhenConfigDefaultIsEmptyString(): void
    {
        $order = $this->order(null);

        self::assertNull($this->resolver->resolve($order, ['templateId' => '']));
    }

    public function testReturnsNullWhenConfigDefaultIsOnlyWhitespace(): void
    {
        $order = $this->order(null);

        self::assertNull($this->resolver->resolve($order, ['templateId' => "   \t  "]));
    }

    public function testCustomFieldOverrideWinsOverConfigDefault(): void
    {
        $order = $this->order(['invoice_api_xhub_template_id' => 'tpl-override']);

        self::assertSame('tpl-override', $this->resolver->resolve($order, ['templateId' => 'tpl-default']));
    }

    public function testTrimsWhitespaceFromCustomFieldOverride(): void
    {
        $order = $this->order(['invoice_api_xhub_template_id' => '  tpl-x  ']);

        self::assertSame('tpl-x', $this->resolver->resolve($order, []));
    }

    public function testEmptyStringOverrideFallsBackToDefault(): void
    {
        $order = $this->order(['invoice_api_xhub_template_id' => '   ']);

        self::assertSame('tpl-default', $this->resolver->resolve($order, ['templateId' => 'tpl-default']));
    }

    public function testNonStringOverrideIsIgnored(): void
    {
        $order = $this->order(['invoice_api_xhub_template_id' => 42]);

        self::assertSame('tpl-default', $this->resolver->resolve($order, ['templateId' => 'tpl-default']));
    }

    public function testNullCustomFieldsFallsBackToDefault(): void
    {
        $order = new OrderEntity();
        // customFields explicitly NOT set — should be treated as empty.

        self::assertSame('tpl-default', $this->resolver->resolve($order, ['templateId' => 'tpl-default']));
    }

    /**
     * @param array<string,mixed>|null $customFields
     */
    private function order(?array $customFields): OrderEntity
    {
        $order = new OrderEntity();
        if (null !== $customFields) {
            $order->setCustomFields($customFields);
        }

        return $order;
    }
}
