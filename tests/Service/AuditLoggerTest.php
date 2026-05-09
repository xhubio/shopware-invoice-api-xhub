<?php

declare(strict_types=1);

namespace Xhubio\InvoiceApiXhub\Tests\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Uuid\Uuid;
use Xhubio\InvoiceApiXhub\Service\AuditLogger;

/**
 * Unit tests for AuditLogger.
 *
 * The DBAL Connection is unmockable past PHPUnit 9 in some configurations
 * (no mock-friendly interface), so we mock it via createMock and verify
 * the SQL parameters passed in. The cascade-delete-on-order-delete contract
 * is encoded in the migration — verifying it requires a live DB and is
 * intentionally not covered here (the schema FK is the source of truth).
 */
final class AuditLoggerTest extends TestCase
{
    public function testLogInsertsRowWithExpectedColumns(): void
    {
        $orderId = Uuid::randomHex();

        $captured = null;
        $conn = $this->createMock(Connection::class);
        $conn->expects(self::once())
            ->method('insert')
            ->willReturnCallback(function (string $table, array $data, array $types) use (&$captured): int {
                $captured = ['table' => $table, 'data' => $data, 'types' => $types];

                return 1;
            });

        $logger = new AuditLogger($conn, new NullLogger());
        $logger->log($orderId, 'generate', 'success', [
            'format'     => 'xrechnung',
            'filePath'   => 'invoice-api-xhub/' . $orderId . '/INV-1.xml',
            'durationMs' => 42,
        ]);

        self::assertNotNull($captured);
        self::assertSame('invoice_api_xhub_audit', $captured['table']);
        self::assertSame('generate', $captured['data']['action']);
        self::assertSame('success', $captured['data']['status']);
        self::assertSame('xrechnung', $captured['data']['format']);
        self::assertSame(42, $captured['data']['duration_ms']);
        // ParameterType::BINARY for the binary columns
        self::assertSame(ParameterType::BINARY, $captured['types']['order_id']);
        // 16-byte hex2bin payload
        self::assertSame(16, strlen((string) $captured['data']['order_id']));
    }

    public function testLogSilentlyNoOpsOnInvalidOrderId(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects(self::never())->method('insert');

        $logger = new AuditLogger($conn, new NullLogger());
        $logger->log('not-a-uuid', 'generate', 'success');
    }

    public function testGetEntriesReturnsRowsNewestFirst(): void
    {
        $orderId = Uuid::randomHex();

        $rows = [
            [
                'action'        => 'generate',
                'status'        => 'success',
                'format'        => 'pdf',
                'file_path'     => 'invoice-api-xhub/' . $orderId . '/INV-2.pdf',
                'http_code'     => 200,
                'duration_ms'   => 91,
                'error_message' => null,
                'created_at'    => '2026-05-09 12:34:56.789',
            ],
            [
                'action'        => 'generate',
                'status'        => 'error',
                'format'        => null,
                'file_path'     => null,
                'http_code'     => 429,
                'duration_ms'   => 12,
                'error_message' => 'rate limit',
                'created_at'    => '2026-05-09 11:00:00.000',
            ],
        ];

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')->willReturn($rows);

        $logger  = new AuditLogger($conn, new NullLogger());
        $entries = $logger->getEntries($orderId);

        self::assertCount(2, $entries);
        self::assertSame('success', $entries[0]['status']);
        self::assertSame('INV-2.pdf', $entries[0]['filename']);
        self::assertSame(91, $entries[0]['durationMs']);
        self::assertSame('error', $entries[1]['status']);
        self::assertNull($entries[1]['filename']);
        self::assertSame('rate limit', $entries[1]['message']);
    }

    public function testGetEntriesReturnsEmptyArrayForUnknownOrderOrInvalidUuid(): void
    {
        $conn = $this->createMock(Connection::class);
        // fetchAllAssociative returns an empty array for an unknown order;
        // for the invalid-uuid case we never hit the DB.
        $conn->method('fetchAllAssociative')->willReturn([]);

        $logger = new AuditLogger($conn, new NullLogger());

        self::assertSame([], $logger->getEntries(Uuid::randomHex()));
        self::assertSame([], $logger->getEntries('not-a-uuid'));
    }

    public function testEraseForOrderIdsRunsBulkDelete(): void
    {
        $a = Uuid::randomHex();
        $b = Uuid::randomHex();

        $captured = null;
        $conn = $this->createMock(Connection::class);
        $conn->expects(self::once())
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params, array $types) use (&$captured): int {
                $captured = ['sql' => $sql, 'params' => $params, 'types' => $types];

                return 5;
            });

        $logger = new AuditLogger($conn, new NullLogger());
        $deleted = $logger->eraseForOrderIds([$a, $b, 'not-a-uuid']);

        self::assertSame(5, $deleted);
        self::assertNotNull($captured);
        self::assertStringContainsString('DELETE', strtoupper($captured['sql']));
        // Only the two valid UUIDs should be in the bulk-delete payload
        self::assertCount(2, $captured['params'][0]);
    }

    public function testLogSwallowsConnectionErrors(): void
    {
        $orderId = Uuid::randomHex();

        $conn = $this->createMock(Connection::class);
        $conn->method('insert')->willThrowException(new \RuntimeException('db is gone'));

        $logger = new AuditLogger($conn, new NullLogger());
        // Must not throw — losing an audit row is acceptable; aborting
        // the surrounding generate() over a logging failure is not.
        $logger->log($orderId, 'generate', 'success');

        $this->expectNotToPerformAssertions();
    }
}
