<?php

declare(strict_types=1);

namespace Xhubio\InvoiceApiXhub\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Append-only audit logger backing the per-order "Logs" tab.
 *
 * Each plugin action (generate / regenerate / download / credit_note /
 * erase) inserts a single row into `invoice_api_xhub_audit`. The reads
 * happen on the admin Logs tab (newest first) and on the GDPR erase path
 * (where we wipe rows for a customer's orders alongside the customFields
 * payload).
 *
 * Failure-handling contract: log() is best-effort. A throw from the DB
 * driver is caught and re-emitted through the PSR-3 logger as a warning,
 * never re-thrown — losing an audit row is bad, but it must not abort the
 * actual invoice generation. The InvoiceGenerator already swallows its own
 * persistence errors for the same reason.
 */
final class AuditLogger
{
    private const LOG_SOURCE = 'invoice-api-xhub';

    private const TABLE = 'invoice_api_xhub_audit';

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Insert one audit entry.
     *
     * @param array{
     *     format?: ?string,
     *     filePath?: ?string,
     *     httpCode?: ?int,
     *     durationMs?: ?int,
     *     errorMessage?: ?string,
     *     userId?: ?string,
     * } $context
     */
    public function log(string $orderId, string $action, string $status, array $context = []): void
    {
        try {
            $orderBin = $this->hexToBin($orderId);
            if (null === $orderBin) {
                // We refuse to insert a row that wouldn't satisfy the FK
                // anyway — better to log+skip than to swallow a DB error.
                $this->logger->warning(
                    'AuditLogger: skipping log entry — invalid orderId',
                    ['source' => self::LOG_SOURCE, 'orderId' => $orderId, 'action' => $action],
                );

                return;
            }

            $userId = isset($context['userId']) && is_string($context['userId'])
                ? $this->hexToBin($context['userId'])
                : null;

            $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');

            $this->connection->insert(self::TABLE, [
                'id'            => Uuid::randomBytes(),
                'order_id'      => $orderBin,
                'action'        => $action,
                'status'        => $status,
                'format'        => $this->stringOrNull($context['format'] ?? null),
                'file_path'     => $this->stringOrNull($context['filePath'] ?? null),
                'http_code'     => $this->intOrNull($context['httpCode'] ?? null),
                'duration_ms'   => $this->intOrNull($context['durationMs'] ?? null),
                'error_message' => $this->stringOrNull($context['errorMessage'] ?? null),
                'user_id'       => $userId,
                'created_at'    => $now,
            ], [
                'id'            => ParameterType::BINARY,
                'order_id'      => ParameterType::BINARY,
                'user_id'       => ParameterType::BINARY,
                'http_code'     => ParameterType::INTEGER,
                'duration_ms'   => ParameterType::INTEGER,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'AuditLogger: failed to insert audit row: ' . $e->getMessage(),
                [
                    'source'    => self::LOG_SOURCE,
                    'orderId'   => $orderId,
                    'action'    => $action,
                    'exception' => $e,
                ],
            );
        }
    }

    /**
     * Fetch all audit entries for an order, newest first. Each row is
     * shaped for direct JSON-encoding by the admin controller.
     *
     * @return list<array{
     *     timestamp: string,
     *     action: string,
     *     status: string,
     *     format: ?string,
     *     filename: ?string,
     *     httpCode: ?int,
     *     durationMs: ?int,
     *     message: string,
     * }>
     */
    public function getEntries(string $orderId): array
    {
        $orderBin = $this->hexToBin($orderId);
        if (null === $orderBin) {
            return [];
        }

        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT `action`, `status`, `format`, `file_path`, `http_code`, '
                . '`duration_ms`, `error_message`, `created_at` '
                . 'FROM `' . self::TABLE . '` WHERE `order_id` = :order_id '
                . 'ORDER BY `created_at` DESC, `id` DESC',
                ['order_id' => $orderBin],
                ['order_id' => ParameterType::BINARY],
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'AuditLogger: failed to fetch audit rows: ' . $e->getMessage(),
                [
                    'source'    => self::LOG_SOURCE,
                    'orderId'   => $orderId,
                    'exception' => $e,
                ],
            );

            return [];
        }

        $entries = [];
        foreach ($rows as $row) {
            $filePath = isset($row['file_path']) ? (string) $row['file_path'] : '';
            $filename = '' !== $filePath ? basename($filePath) : null;

            $entries[] = [
                'timestamp'  => (string) ($row['created_at'] ?? ''),
                'action'     => (string) ($row['action'] ?? ''),
                'status'     => (string) ($row['status'] ?? ''),
                'format'     => isset($row['format']) ? (string) $row['format'] : null,
                'filename'   => $filename,
                'httpCode'   => isset($row['http_code']) ? (int) $row['http_code'] : null,
                'durationMs' => isset($row['duration_ms']) ? (int) $row['duration_ms'] : null,
                'message'    => (string) ($row['error_message'] ?? ''),
            ];
        }

        return $entries;
    }

    /**
     * Erase audit rows for the given orders. Used by PrivacyService alongside
     * the customField-wipe so the audit trail does not become a back-channel
     * for the GDPR-erased data.
     *
     * @param array<int,string> $orderIds
     */
    public function eraseForOrderIds(array $orderIds): int
    {
        $orderIds = array_values(array_filter($orderIds, 'is_string'));
        if ([] === $orderIds) {
            return 0;
        }

        $bin = [];
        foreach ($orderIds as $hex) {
            $b = $this->hexToBin($hex);
            if (null !== $b) {
                $bin[] = $b;
            }
        }
        if ([] === $bin) {
            return 0;
        }

        try {
            return (int) $this->connection->executeStatement(
                'DELETE FROM `' . self::TABLE . '` WHERE `order_id` IN (?)',
                [$bin],
                [ArrayParameterType::BINARY],
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'AuditLogger: failed to erase audit rows: ' . $e->getMessage(),
                ['source' => self::LOG_SOURCE, 'exception' => $e],
            );

            return 0;
        }
    }

    /**
     * Convert a hex32 (Shopware UUID) to its 16-byte binary form. Returns
     * null on bad input — the caller skips the operation rather than
     * pushing a malformed binary at the FK.
     */
    private function hexToBin(string $hex): ?string
    {
        if (1 !== preg_match('/^[a-f0-9]{32}$/i', $hex)) {
            return null;
        }

        $bin = @hex2bin($hex);

        return false === $bin ? null : $bin;
    }

    private function stringOrNull(mixed $v): ?string
    {
        if (null === $v) {
            return null;
        }
        if (!is_scalar($v) && !(is_object($v) && method_exists($v, '__toString'))) {
            return null;
        }
        $s = (string) $v;

        return '' === $s ? null : $s;
    }

    private function intOrNull(mixed $v): ?int
    {
        if (null === $v || '' === $v) {
            return null;
        }
        if (!is_numeric($v)) {
            return null;
        }

        return (int) $v;
    }
}
