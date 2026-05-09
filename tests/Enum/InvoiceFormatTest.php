<?php

declare(strict_types=1);

namespace Xhubio\InvoiceApiXhub\Tests\Enum;

use PHPUnit\Framework\TestCase;
use Xhubio\InvoiceApiXhub\Enum\InvoiceFormat;

/**
 * Sanity tests for the InvoiceFormat backed enum.
 *
 * The enum's wire-level values (`pdf`, `xrechnung`, `zugferd`) must stay in
 * lockstep with the slugs the API expects in the URL path, otherwise we'd
 * silently 404 on every generate. These tests are the cheapest way to flag
 * an accidental rename in the case-list.
 */
final class InvoiceFormatTest extends TestCase
{
    public function testFromAcceptsAllKnownValues(): void
    {
        self::assertSame(InvoiceFormat::Pdf, InvoiceFormat::from('pdf'));
        self::assertSame(InvoiceFormat::Xrechnung, InvoiceFormat::from('xrechnung'));
        self::assertSame(InvoiceFormat::Zugferd, InvoiceFormat::from('zugferd'));
    }

    public function testFromThrowsOnUnknownValue(): void
    {
        $this->expectException(\ValueError::class);
        InvoiceFormat::from('factur-x');
    }

    public function testTryFromReturnsNullForUnknownValue(): void
    {
        self::assertNull(InvoiceFormat::tryFrom('ubl'));
        self::assertSame(InvoiceFormat::Pdf, InvoiceFormat::tryFrom('pdf'));
    }
}
