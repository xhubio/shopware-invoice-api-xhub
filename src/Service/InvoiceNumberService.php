<?php

declare(strict_types=1);

namespace Xhubio\InvoiceApiXhub\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEntity;

/**
 * Atomic gap-free invoice-number allocator + token-based format expansion.
 *
 * Mirrors the WooCommerce reference (Invoice_Api_Xhub_Order_Mapper::next_sequence)
 * including the LAST_INSERT_ID(expr) trick:
 *
 *   INSERT INTO invoice_api_xhub_seq (period, current)
 *   VALUES (:period, LAST_INSERT_ID(1))
 *   ON DUPLICATE KEY UPDATE current = LAST_INSERT_ID(current + 1)
 *
 * Passing the expression in BOTH the VALUES clause and the UPDATE branch makes
 * Connection::lastInsertId() return the new value in either path — first
 * insert OR duplicate update — even though `period` is VARCHAR (not an
 * AUTO_INCREMENT column). The whole statement is atomic at the DB level so
 * concurrent generates can never share a number.
 *
 * §14 UStG note: every increment is committed BEFORE the API call to
 * invoice-api.xhub.io. If the generate fails the seq value is "burned" and
 * the next invoice gets the next number — gaps are tolerable per BFH-
 * Rechtsprechung. What is NOT tolerable is duplicate numbers; the atomic
 * INSERT/UPDATE pattern prevents that.
 */
final class InvoiceNumberService
{
    private const LOG_SOURCE = 'invoice-api-xhub';

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Build the invoice number for a Shopware order.
     *
     * @param OrderEntity $order             Shopware order
     * @param string      $format            Format string with tokens like
     *                                       'INV-{order_number}', '2026-{seq:0000}', etc.
     * @param string      $resetMode         'yearly' | 'monthly' | 'never'
     * @param string|null $perOrderOverride  Wins over format if non-empty
     */
    public function build(
        OrderEntity $order,
        string $format,
        string $resetMode,
        ?string $perOrderOverride,
    ): string {
        if (null !== $perOrderOverride && '' !== trim($perOrderOverride)) {
            return trim($perOrderOverride);
        }

        $format = '' !== trim($format) ? $format : 'INV-{order_number}';

        // Shopware types getOrderDateTime() as non-nullable \DateTimeInterface,
        // so we accept it as-is — no defensive fallback required.
        $orderDate = $order->getOrderDateTime();

        $year  = $orderDate->format('Y');
        $month = $orderDate->format('m');
        $day   = $orderDate->format('d');

        // Resolve {seq} / {seq:NNNN} first so the counter only advances when
        // the format string actually requests it. The padding hint is a
        // digit string whose length determines pad width — using strlen
        // instead of (int) avoids the trap where '0000' casts to 0.
        if (preg_match('/\{seq(?::([0-9]+))?\}/', $format, $matches) === 1) {
            $period   = $this->resolvePeriod($resetMode, $year, $month);
            $padding  = isset($matches[1]) ? strlen($matches[1]) : 0;
            $seq      = $this->nextSequence($period);
            $seqStr   = $padding > 0
                ? str_pad((string) $seq, $padding, '0', STR_PAD_LEFT)
                : (string) $seq;
            $format = preg_replace('/\{seq(?::[0-9]+)?\}/', $seqStr, $format) ?? $format;
        }

        $orderNumber = $order->getOrderNumber() ?? $order->getId();

        return strtr($format, [
            '{order_number}' => (string) $orderNumber,
            '{order_id}'     => $order->getId(),
            '{year}'         => $year,
            '{month}'        => $month,
            '{day}'          => $day,
        ]);
    }

    private function resolvePeriod(string $resetMode, string $year, string $month): string
    {
        return match ($resetMode) {
            'never'   => 'all',
            'monthly' => $year . '-' . $month,
            default   => $year, // 'yearly' is the default
        };
    }

    /**
     * Atomically allocate the next sequence number for the given period.
     *
     * Returns the newly-allocated integer. Throws \RuntimeException on any
     * DB error (logged with full context).
     */
    private function nextSequence(string $period): int
    {
        try {
            $this->connection->executeStatement(
                'INSERT INTO `invoice_api_xhub_seq` (`period`, `current`) '
                . 'VALUES (:period, LAST_INSERT_ID(1)) '
                . 'ON DUPLICATE KEY UPDATE `current` = LAST_INSERT_ID(`current` + 1)',
                ['period' => $period],
            );

            $value = $this->connection->lastInsertId();

            return (int) $value;
        } catch (DBALException $e) {
            $this->logger->error(
                'Invoice-api.xhub sequence allocation failed: ' . $e->getMessage(),
                [
                    'source'    => self::LOG_SOURCE,
                    'period'    => $period,
                    'exception' => $e,
                ],
            );
            throw new \RuntimeException(
                'Failed to allocate invoice sequence for period "' . $period . '": ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }
}
