<?php

declare(strict_types=1);

namespace Xhubio\InvoiceApiXhub\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Wave 7D migration: append-only audit log for every Invoice-api.xhub action
 * the plugin performs against an order.
 *
 * The previous "logs" view in InvoiceController synthesised entries from the
 * order's custom fields — handy as MVP, but it could only ever show ONE
 * generate per order (the latest) and it had no notion of regenerate /
 * download / erase / credit-note steps. The new `invoice_api_xhub_audit`
 * table backs a real audit trail: one row per action, foreign-keyed to the
 * order so the rows go away with the order itself (ON DELETE CASCADE
 * matches the GDPR contract — when the order is gone, so is the audit).
 *
 * Indexes:
 *   idx_order_id   -> O(log n) lookup for the per-order "Logs" tab.
 *   idx_created_at -> supports a future "all-orders global log" view that
 *                     the marketing copy already references in v1.1 roadmap.
 *
 * Idempotent CREATE; updateDestructive intentionally empty so reinstalls do
 * not lose audit history (the Plugin::uninstall() path drops the table
 * explicitly when keepUserData=false).
 */
class Migration1715000001CreateAuditTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1715000001;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `invoice_api_xhub_audit` (
                `id` BINARY(16) NOT NULL,
                `order_id` BINARY(16) NOT NULL,
                `action` VARCHAR(32) NOT NULL,
                `status` VARCHAR(16) NOT NULL,
                `format` VARCHAR(16) NULL,
                `file_path` VARCHAR(255) NULL,
                `http_code` INT NULL,
                `duration_ms` INT NULL,
                `error_message` TEXT NULL,
                `user_id` BINARY(16) NULL,
                `created_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_order_id` (`order_id`),
                KEY `idx_created_at` (`created_at`),
                CONSTRAINT `fk.invoice_audit.order_id` FOREIGN KEY (`order_id`)
                    REFERENCES `order` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive cleanup at migration time. Plugin::uninstall()
        // drops the table when keepUserData=false; otherwise the audit
        // trail is retained across plugin upgrades.
    }
}
