<?php

declare(strict_types=1);

namespace Xhubio\InvoiceApiXhub\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Wave 1 migration:
 *   1. Creates the `invoice_api_xhub_seq` table used by InvoiceNumberService
 *      for atomic gap-free sequence allocation.
 *   2. Registers the `invoice_api_xhub` custom-field set on the `order`
 *      entity, with the six fields the order handler reads/writes:
 *        - filepath / filename: where the generated file lives
 *        - data: legacy base64 payload (debug + back-compat with WC plugin)
 *        - last_error: human-readable last failure (shown in admin)
 *        - template_id: per-order PDF template override
 *        - generated_at: ISO timestamp of the last successful generate
 *
 * Idempotent: every insert is gated on a name-uniqueness probe so re-running
 * the migration (or installing on top of a partial install) is safe.
 *
 * updateDestructive() intentionally leaves the data alone — the Plugin
 * uninstall lifecycle handles that, with respect for `keepUserData`.
 */
class Migration1715000000CreateSequenceTable extends MigrationStep
{
    private const FIELD_SET_NAME = 'invoice_api_xhub';

    public function getCreationTimestamp(): int
    {
        return 1715000000;
    }

    public function update(Connection $connection): void
    {
        $this->createSequenceTable($connection);
        $this->registerCustomFieldSet($connection);
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive cleanup at migration time — the Plugin::uninstall()
        // hook drops the table and removes the custom-field set when the
        // user explicitly chooses NOT to keep user data. Leaving this empty
        // protects against accidental data loss if Shopware re-runs
        // destructive migrations.
    }

    private function createSequenceTable(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `invoice_api_xhub_seq` (
                `period` VARCHAR(10) NOT NULL,
                `current` INT UNSIGNED NOT NULL DEFAULT 0,
                `updated_at` DATETIME(3) NULL DEFAULT NULL,
                PRIMARY KEY (`period`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);
    }

    private function registerCustomFieldSet(Connection $connection): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');

        // 1) field set ------------------------------------------------------
        $existingSetId = $connection->fetchOne(
            'SELECT `id` FROM `custom_field_set` WHERE `name` = :name LIMIT 1',
            ['name' => self::FIELD_SET_NAME],
        );

        if (false === $existingSetId) {
            $setId = Uuid::randomBytes();
            $connection->insert('custom_field_set', [
                'id'         => $setId,
                'name'       => self::FIELD_SET_NAME,
                'config'     => json_encode([
                    'label' => [
                        'en-GB' => 'Invoice-api.xhub',
                        'de-DE' => 'Invoice-api.xhub',
                    ],
                ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
                'active'     => 1,
                'position'   => 1,
                'created_at' => $now,
            ], [
                'id'     => ParameterType::BINARY,
                'active' => ParameterType::INTEGER,
            ]);
        } else {
            $setId = is_string($existingSetId) ? $existingSetId : (string) $existingSetId;
        }

        // 2) entity relation (order) ----------------------------------------
        $relationExists = $connection->fetchOne(
            'SELECT `id` FROM `custom_field_set_relation` '
            . 'WHERE `set_id` = :set_id AND `entity_name` = :entity LIMIT 1',
            ['set_id' => $setId, 'entity' => 'order'],
            ['set_id' => ParameterType::BINARY],
        );

        if (false === $relationExists) {
            $connection->insert('custom_field_set_relation', [
                'id'          => Uuid::randomBytes(),
                'set_id'      => $setId,
                'entity_name' => 'order',
                'created_at'  => $now,
            ], [
                'id'     => ParameterType::BINARY,
                'set_id' => ParameterType::BINARY,
            ]);
        }

        // 3) fields ---------------------------------------------------------
        foreach ($this->fieldDefinitions() as $position => $field) {
            $this->ensureCustomField(
                $connection,
                $setId,
                $field['name'],
                $field['type'],
                $field['labelEn'],
                $field['labelDe'],
                $position + 1,
                $now,
            );
        }
    }

    /**
     * @return list<array{name:string,type:string,labelEn:string,labelDe:string}>
     */
    private function fieldDefinitions(): array
    {
        // type: 'text' for short strings, 'html' would render WYSIWYG (not
        // wanted here), 'datetime' for the timestamp, 'textarea' for the
        // legacy base64 data dump (can be many KB so we don't want a single
        // line input). Last-error is short-ish but may include stack-frame
        // tail, so we use 'textarea' too. Filenames/paths/template-ids are
        // <= 255 chars so plain 'text' is fine.
        return [
            [
                'name'    => 'invoice_api_xhub_filepath',
                'type'    => 'text',
                'labelEn' => 'Invoice file path',
                'labelDe' => 'Pfad der Rechnungsdatei',
            ],
            [
                'name'    => 'invoice_api_xhub_filename',
                'type'    => 'text',
                'labelEn' => 'Invoice file name',
                'labelDe' => 'Dateiname der Rechnung',
            ],
            [
                'name'    => 'invoice_api_xhub_data',
                'type'    => 'textarea',
                'labelEn' => 'Invoice data (base64, debug)',
                'labelDe' => 'Rechnungsdaten (Base64, Debug)',
            ],
            [
                'name'    => 'invoice_api_xhub_last_error',
                'type'    => 'textarea',
                'labelEn' => 'Last error',
                'labelDe' => 'Letzter Fehler',
            ],
            [
                'name'    => 'invoice_api_xhub_template_id',
                'type'    => 'text',
                'labelEn' => 'PDF template override',
                'labelDe' => 'PDF-Template-Override',
            ],
            [
                'name'    => 'invoice_api_xhub_generated_at',
                'type'    => 'datetime',
                'labelEn' => 'Generated at',
                'labelDe' => 'Erzeugt am',
            ],
        ];
    }

    private function ensureCustomField(
        Connection $connection,
        string $setId,
        string $name,
        string $type,
        string $labelEn,
        string $labelDe,
        int $position,
        string $now,
    ): void {
        $existing = $connection->fetchOne(
            'SELECT `id` FROM `custom_field` WHERE `name` = :name LIMIT 1',
            ['name' => $name],
        );
        if (false !== $existing) {
            return;
        }

        $componentName = $this->componentNameFor($type);

        $config = [
            'label' => [
                'en-GB' => $labelEn,
                'de-DE' => $labelDe,
            ],
            'customFieldType'     => $type,
            'customFieldPosition' => $position,
            'componentName'       => $componentName,
        ];

        if ('text' === $type || 'textarea' === $type) {
            // sw-field renders <input>/<textarea> based on this `type` prop.
            // For 'textarea' Shopware expects componentName=sw-field +
            // type=textarea so the admin renders the right control.
            $config['type'] = 'textarea' === $type ? 'textarea' : 'text';
        } elseif ('datetime' === $type) {
            $config['type'] = 'date';
            $config['dateType'] = 'datetime';
        }

        $connection->insert('custom_field', [
            'id'         => Uuid::randomBytes(),
            'name'       => $name,
            'type'       => $type,
            'config'     => json_encode($config, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
            'active'     => 1,
            'set_id'     => $setId,
            'created_at' => $now,
        ], [
            'id'     => ParameterType::BINARY,
            'set_id' => ParameterType::BINARY,
            'active' => ParameterType::INTEGER,
        ]);
    }

    private function componentNameFor(string $type): string
    {
        // Shopware admin uses these component names to render the right
        // control inside the order custom-field section. 'sw-field' covers
        // text/textarea; 'sw-datepicker' is the dedicated datetime control.
        return match ($type) {
            'datetime' => 'sw-datepicker',
            default    => 'sw-field',
        };
    }
}
