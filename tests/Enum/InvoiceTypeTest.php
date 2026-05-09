<?php

declare(strict_types=1);

namespace Xhubio\InvoiceApiXhub\Tests\Enum;

use PHPUnit\Framework\TestCase;
use Xhubio\InvoiceApiXhub\Enum\InvoiceType;

/**
 * Sanity tests for the InvoiceType backed enum.
 *
 * The string values are the Symfony Messenger payload contract: every
 * GenerateInvoiceMessage that survives a queue-restart must round-trip
 * the type field losslessly, so the values cannot drift.
 */
final class InvoiceTypeTest extends TestCase
{
    public function testFromAcceptsAllKnownValues(): void
    {
        self::assertSame(InvoiceType::Invoice, InvoiceType::from('invoice'));
        self::assertSame(InvoiceType::CreditNote, InvoiceType::from('credit_note'));
    }

    public function testFromThrowsOnUnknownValue(): void
    {
        $this->expectException(\ValueError::class);
        InvoiceType::from('proforma');
    }

    public function testTryFromReturnsNullForUnknownValue(): void
    {
        self::assertNull(InvoiceType::tryFrom('proforma'));
        self::assertNull(InvoiceType::tryFrom(''));
        self::assertSame(InvoiceType::CreditNote, InvoiceType::tryFrom('credit_note'));
    }
}
