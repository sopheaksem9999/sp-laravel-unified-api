<?php

declare(strict_types=1);

namespace Sopheak\Core\Mcp\Guidance;

use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * The `request.payload` schema of each write action. It lists what a client
 * may actually send: no timestamps, soft-delete marker, tenant column or
 * userstamps (the API stamps those), the primary key only where the client
 * supplies it, and every writable relationship alias, so a schema-following
 * agent nests children exactly as the include hints say.
 */
final class PayloadSchemaBuilder
{
    /** Relationship types whose arrays accept bare ids (they attach). */
    private const ATTACHING = ['belongsToMany', 'morphToMany', 'morphByMany', 'spatiePermission', 'hasManyThrough'];

    private const CREATING = ['create', 'bulkCreate', 'upsert', 'bulkUpsert'];

    public static function isAttaching(string $relationshipType): bool
    {
        return in_array($relationshipType, self::ATTACHING, true);
    }

    /**
     * Whether a client may send $column with $action. The primary key is
     * writable on create-like actions only when it is a uuid column (the API
     * generates it otherwise); update-like bulk actions address rows by it and
     * are handled by {@see self::forAction()}.
     */
    public static function writableColumn(RecordTableType $config, string $column, string $action): bool
    {
        $primaryKey = (string) ($config->primaryKey ?? 'id');
        if ($column === $primaryKey) {
            $definition = ((array) $config->columns)[$column] ?? null;

            return in_array($action, self::CREATING, true)
                && SchemaRegistryUtils::isUuidColumnType(is_array($definition) ? $definition : (null === $definition ? null : ['type' => $definition]));
        }

        if ('deleted_at' === $column) {
            return false;
        }

        if (in_array($column, ['created_at', 'updated_at'], true)) {
            return $config->overrideTimestamps;
        }

        if (in_array($column, [...RecordService::CREATE_AUDIT_COLUMNS, ...RecordService::UPDATE_AUDIT_COLUMNS], true)) {
            return $config->overrideUserstamps;
        }

        return !(RecordConfigService::enableTenantId() && $config->hasTenantId && $column === RecordConfigService::tenantColumn());
    }

    /**
     * @param array<int, array<string, mixed>> $fields   the endpoint's `fields`
     * @param array<int, array<string, mixed>> $includes the endpoint's `includes`
     * @return array<string, mixed>
     */
    public static function forAction(RecordTableType $config, string $action, array $fields, array $includes): array
    {
        $primaryKey = (string) ($config->primaryKey ?? 'id');
        $addressesRows = in_array($action, ['bulkUpdate', 'bulkDelete', 'bulkMixed'], true);

        $properties = [];
        foreach ($fields as $field) {
            $name = (string) $field['name'];
            $isKey = $name === $primaryKey;
            if (!in_array('write', $field['in'] ?? [], true)) {
                continue;
            }

            if ($isKey ? !$addressesRows && !self::writableColumn($config, $name, $action) : !self::writableColumn($config, $name, $action)) {
                continue;
            }

            if ('bulkDelete' === $action && !$isKey) {
                continue;
            }

            $property = ColumnTypes::jsonSchema((string) ($field['type'] ?? 'string'));
            if (isset($field['enum'])) {
                $property['enum'] = $field['enum'];
            }

            if (isset($field['maxLength'])) {
                $property['maxLength'] = $field['maxLength'];
            }

            $properties[$name] = $property;
        }

        // Only create and update write nested children; the upsert endpoints never run
        // the relationship processors, so an alias there would be silently dropped.
        if (!in_array($action, ['bulkDelete', 'upsert', 'bulkUpsert'], true)) {
            foreach ($includes as $include) {
                if (!($include['writable'] ?? false)) {
                    continue;
                }

                $properties[(string) $include['name']] = [
                    'type' => 'array',
                    'items' => self::isAttaching((string) $include['type'])
                        ? ['type' => ['object', 'integer', 'string']]
                        : ['type' => 'object'],
                ];
            }
        }

        if ('bulkMixed' === $action) {
            $properties['operation'] = ['type' => 'string', 'enum' => ['create', 'update', 'delete', 'upsert']];
        }

        $record = ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];

        $required = match ($action) {
            'create', 'bulkCreate' => self::requiredColumns($fields, $properties),
            'bulkUpdate', 'bulkDelete' => isset($properties[$primaryKey]) ? [$primaryKey] : [],
            default => [],
        };
        if ([] !== $required) {
            $record['required'] = $required;
        }

        return str_starts_with($action, 'bulk') ? ['type' => 'array', 'items' => $record] : $record;
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, mixed> $properties
     * @return array<int, string>
     */
    private static function requiredColumns(array $fields, array $properties): array
    {
        $required = [];
        foreach ($fields as $field) {
            if (($field['required'] ?? false) && isset($properties[(string) $field['name']])) {
                $required[] = (string) $field['name'];
            }
        }

        return $required;
    }
}
