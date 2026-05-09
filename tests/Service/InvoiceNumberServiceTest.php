<?php

declare(strict_types=1);

namespace Xhubio\InvoiceApiXhub\Tests\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Order\OrderEntity;
use Xhubio\InvoiceApiXhub\Service\InvoiceNumberService;

/**
 * Unit tests for the atomic-counter + token-expansion invoice number builder.
 *
 * The MySQL-specific INSERT ON DUPLICATE / LAST_INSERT_ID(expr) trick is
 * exercised against a mocked Doctrine\DBAL\Connection rather than a real DB,
 * so the same tests pass on dev machines without a Shopware container.
 */
final class InvoiceNumberServiceTest extends TestCase
{
    public function testReturnsPerOrderOverrideWhenProvided(): void
    {
        $service = new InvoiceNumberService($this->stableConnection(1), new NullLogger());

        self::assertSame(
            'CUSTOM-42',
            $service->build($this->order('SW-1', '2026-05-08'), 'INV-{seq}', 'yearly', 'CUSTOM-42'),
        );
    }

    public function testTrimsPerOrderOverride(): void
    {
        $service = new InvoiceNumberService($this->stableConnection(1), new NullLogger());

        self::assertSame(
            'CUSTOM-42',
            $service->build($this->order('SW-1', '2026-05-08'), 'INV-{seq}', 'yearly', '   CUSTOM-42  '),
        );
    }

    public function testEmptyOverrideFallsThroughToFormat(): void
    {
        $service = new InvoiceNumberService($this->stableConnection(7), new NullLogger());

        self::assertSame(
            'INV-7',
            $service->build($this->order('SW-1', '2026-05-08'), 'INV-{seq}', 'yearly', '   '),
        );
    }

    public function testFormatWithoutSeqDoesNotTouchDatabase(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->never())->method('executeStatement');
        $conn->expects($this->never())->method('lastInsertId');

        $service = new InvoiceNumberService($conn, new NullLogger());

        self::assertSame(
            'INV-SW-9',
            $service->build($this->order('SW-9', '2026-03-15'), 'INV-{order_number}', 'yearly', null),
        );
    }

    public function testSeqPaddingHonoursDigitWidthInTokenHint(): void
    {
        $service = new InvoiceNumberService($this->stableConnection(42), new NullLogger());

        self::assertSame(
            '2026-0042',
            $service->build($this->order('SW-1', '2026-04-30'), '{year}-{seq:0000}', 'yearly', null),
        );
    }

    public function testSeqWithoutPaddingIsNotZeroPadded(): void
    {
        $service = new InvoiceNumberService($this->stableConnection(42), new NullLogger());

        self::assertSame(
            '2026-42',
            $service->build($this->order('SW-1', '2026-04-30'), '{year}-{seq}', 'yearly', null),
        );
    }

    public function testYearlyResetUsesYearOnlyAsPeriod(): void
    {
        $conn = $this->captureConnection(5);

        $service = new InvoiceNumberService($conn->connection, new NullLogger());

        $service->build($this->order('SW-1', '2026-04-30'), '{seq}', 'yearly', null);

        self::assertSame(['period' => '2026'], $conn->capturedParams);
    }

    public function testMonthlyResetUsesYearMonthAsPeriod(): void
    {
        $conn = $this->captureConnection(3);

        $service = new InvoiceNumberService($conn->connection, new NullLogger());

        $service->build($this->order('SW-1', '2026-04-30'), '{seq}', 'monthly', null);

        self::assertSame(['period' => '2026-04'], $conn->capturedParams);
    }

    public function testNeverResetUsesAllAsPeriod(): void
    {
        $conn = $this->captureConnection(99);

        $service = new InvoiceNumberService($conn->connection, new NullLogger());

        $service->build($this->order('SW-1', '2026-04-30'), '{seq}', 'never', null);

        self::assertSame(['period' => 'all'], $conn->capturedParams);
    }

    public function testFormatTokensExpandOrderNumberOrderIdYearMonthDay(): void
    {
        $service = new InvoiceNumberService($this->stableConnection(1), new NullLogger());

        $result = $service->build(
            $this->order('SW-7', '2026-04-30'),
            'O={order_number} I={order_id} {year}/{month}/{day}',
            'yearly',
            null,
        );

        self::assertSame('O=SW-7 I=order-id-7 2026/04/30', $result);
    }

    public function testEmptyFormatFallsBackToInvOrderNumber(): void
    {
        $service = new InvoiceNumberService($this->stableConnection(1), new NullLogger());

        self::assertSame(
            'INV-SW-3',
            $service->build($this->order('SW-3', '2026-04-30'), '', 'yearly', null),
        );
    }

    public function testOrderWithoutOrderNumberFallsBackToOrderId(): void
    {
        $service = new InvoiceNumberService($this->stableConnection(1), new NullLogger());

        $order = new OrderEntity();
        $order->setId('id-only');
        $order->setOrderDateTime(new \DateTimeImmutable('2026-04-30'));

        self::assertSame(
            'INV-id-only',
            $service->build($order, 'INV-{order_number}', 'yearly', null),
        );
    }

    public function testOrderDateExpansionUsesProvidedOrderDate(): void
    {
        $service = new InvoiceNumberService($this->stableConnection(1), new NullLogger());

        $result = $service->build(
            $this->order('SW-1', '2026-01-02'),
            '{year}-{seq}',
            'yearly',
            null,
        );

        self::assertSame('2026-1', $result);
    }

    public function testDbalErrorIsWrappedInRuntimeException(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('executeStatement')->willThrowException(
            new class('SQL boom') extends \RuntimeException implements DBALException {},
        );
        $service = new InvoiceNumberService($conn, new NullLogger());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to allocate invoice sequence');

        $service->build($this->order('SW-1', '2026-04-30'), '{seq}', 'yearly', null);
    }

    private function order(string $orderNumber, string $isoDate): OrderEntity
    {
        $order = new OrderEntity();
        $digits = preg_replace('/[^0-9]/', '', $orderNumber);
        $order->setId('order-id-' . ('' !== $digits ? $digits : '0'));
        $order->setOrderNumber($orderNumber);
        $order->setOrderDateTime(new \DateTimeImmutable($isoDate));

        return $order;
    }

    private function stableConnection(int $nextSequence): Connection
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('executeStatement')->willReturn(1);
        $conn->method('lastInsertId')->willReturn((string) $nextSequence);

        return $conn;
    }

    /**
     * Returns a struct with .connection (mocked) + .capturedParams populated
     * after the next executeStatement call.
     */
    private function captureConnection(int $nextSequence): object
    {
        $captured = new \stdClass();
        $captured->capturedParams = null;
        $captured->connection     = $this->createMock(Connection::class);
        $captured->connection->method('executeStatement')
            ->willReturnCallback(function ($sql, $params) use ($captured): int {
                $captured->capturedParams = $params;

                return 1;
            });
        $captured->connection->method('lastInsertId')->willReturn((string) $nextSequence);

        return $captured;
    }
}
