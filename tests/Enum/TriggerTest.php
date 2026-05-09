<?php

declare(strict_types=1);

namespace Xhubio\InvoiceApiXhub\Tests\Enum;

use PHPUnit\Framework\TestCase;
use Xhubio\InvoiceApiXhub\Enum\Trigger;

/**
 * Sanity tests for the Trigger backed enum.
 *
 * The values must match the option keys persisted in the plugin config XML
 * (`off`, `on_pending`, `on_on_hold`, `on_processing`, `on_completed`) — the
 * subscriber rebuilds the enum via Trigger::tryFrom() on every state event
 * and silently no-ops on unknown values.
 */
final class TriggerTest extends TestCase
{
    public function testFromAcceptsAllKnownValues(): void
    {
        self::assertSame(Trigger::Off, Trigger::from('off'));
        self::assertSame(Trigger::OnPending, Trigger::from('on_pending'));
        self::assertSame(Trigger::OnHold, Trigger::from('on_on_hold'));
        self::assertSame(Trigger::OnProcessing, Trigger::from('on_processing'));
        self::assertSame(Trigger::OnCompleted, Trigger::from('on_completed'));
    }

    public function testFromThrowsOnUnknownValue(): void
    {
        $this->expectException(\ValueError::class);
        Trigger::from('on_paid');
    }

    public function testTryFromReturnsNullForUnknownValue(): void
    {
        self::assertNull(Trigger::tryFrom(''));
        self::assertNull(Trigger::tryFrom('on_paid'));
        self::assertSame(Trigger::OnCompleted, Trigger::tryFrom('on_completed'));
    }
}
