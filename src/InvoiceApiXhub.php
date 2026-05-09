<?php declare(strict_types=1);

namespace Xhubio\InvoiceApiXhub;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;

/**
 * Plugin entry point for "Invoice-api.xhub for Shopware".
 *
 * Bridges Shopware orders to the invoice-api.xhub.io e-invoicing service.
 * Currently supports generation of PDF, XRechnung and ZUGFeRD documents;
 * additional formats (Factur-X, FatturaPA, Facturae, ebInterface, UBL, ISDOC,
 * NAV) ship from Q3 2026 onwards and will appear automatically.
 *
 * Lifecycle responsibilities:
 *  - install:    no manual work — Migrations under Migration/ run automatically
 *                and create the invoice_api_xhub_seq counter table plus the
 *                custom-field-set used to attach generated documents to orders.
 *  - activate:   no-op (services become available via the DI container).
 *  - deactivate: no-op (event subscribers are simply unregistered by Shopware).
 *  - update:     no-op for now; future migrations will be appended under
 *                Migration/ and pick themselves up automatically.
 *  - uninstall:  when the operator opted out of "keep user data", this drops
 *                the sequence-counter table and removes the custom-field-set
 *                so no plugin artefacts remain on the shop database.
 */
class InvoiceApiXhub extends Plugin
{
    private const SEQUENCE_TABLE = 'invoice_api_xhub_seq';

    private const CUSTOM_FIELD_SET_NAME = 'invoice_api_xhub';

    public function install(InstallContext $context): void
    {
        // Intentionally empty. The Shopware migration runner picks up files
        // under src/Migration/ during the install lifecycle and creates the
        // schema + custom-field-set there. Keeping this method explicit so
        // the lifecycle contract is documented in code.
    }

    public function activate(ActivateContext $context): void
    {
        // Intentionally empty. Activation is handled by Shopware's plugin
        // system (services become resolvable, subscribers get registered).
    }

    public function deactivate(DeactivateContext $context): void
    {
        // Intentionally empty. Deactivation is handled by Shopware.
    }

    public function update(UpdateContext $context): void
    {
        // Intentionally empty. Schema upgrades are delivered as additional
        // Migration files; Shopware runs the new ones automatically when the
        // plugin version is bumped.
    }

    public function uninstall(UninstallContext $context): void
    {
        parent::uninstall($context);

        if ($context->keepUserData()) {
            return;
        }

        $container = $this->container;
        if ($container === null) {
            return;
        }

        /** @var Connection $connection */
        $connection = $container->get(Connection::class);

        // Drop the sequence-counter table created during install. We use a
        // plain DDL statement here (no schema-manager) to avoid pulling in
        // doctrine/dbal schema dependencies during uninstall.
        $connection->executeStatement(
            'DROP TABLE IF EXISTS `' . self::SEQUENCE_TABLE . '`'
        );

        // Remove the custom-field-set we registered for orders. We resolve
        // the row by name first so we can also clean up the relation table
        // even if there are no custom fields attached anymore.
        $customFieldSetId = $connection->fetchOne(
            'SELECT `id` FROM `custom_field_set` WHERE `name` = :name LIMIT 1',
            ['name' => self::CUSTOM_FIELD_SET_NAME]
        );

        if ($customFieldSetId !== false && $customFieldSetId !== null) {
            $connection->executeStatement(
                'DELETE FROM `custom_field_set_relation` WHERE `set_id` = :id',
                ['id' => $customFieldSetId]
            );
            $connection->executeStatement(
                'DELETE FROM `custom_field` WHERE `set_id` = :id',
                ['id' => $customFieldSetId]
            );
            $connection->executeStatement(
                'DELETE FROM `custom_field_set` WHERE `id` = :id',
                ['id' => $customFieldSetId]
            );
        }

        // Defensive: clean up any leftover system_config rows we may have
        // written so reinstalls start from defaults.
        $connection->executeStatement(
            "DELETE FROM `system_config` WHERE `configuration_key` LIKE 'InvoiceApiXhub.config.%'"
        );
    }
}
