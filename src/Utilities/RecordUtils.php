<?php

namespace Sopheak\Core\Utilities;

use Illuminate\Support\Facades\DB;
use PDO;
use Sopheak\Core\Services\RecordConfigService;

class RecordUtils
{
    public static function isTenantIdEnabled(): bool
    {
        return RecordConfigService::enableTenantId();
    }

    public static function normalizeTenantId(mixed $tenantId): mixed
    {
        if (is_string($tenantId)) {
            return trim($tenantId);
        }

        return $tenantId;
    }

    public static function isTenantIdMissing(mixed $tenantId): bool
    {
        $tenantId = self::normalizeTenantId($tenantId);

        return null === $tenantId || '' === $tenantId;
    }

    public static function shouldApplyTenantId(object $tableSchema): bool
    {
        return self::isTenantIdEnabled() && (bool) ($tableSchema->hasTenantId ?? false);
    }

    public static function applyCompositeTypes(array $payload, array $columns): array
    {
        if ($columns === []) {
            return $payload;
        }

        $driver = DB::getDriverName();
        if ($driver !== 'pgsql') {
            return $payload;
        }

        $pdo = DB::connection()->getPdo();

        foreach ($columns as $column => $meta) {
            if (!array_key_exists($column, $payload)) {
                continue;
            }

            $type = strtolower((string) ($meta['type'] ?? ''));
            $udtName = $meta['udt_name'] ?? null;
            $udtSchema = $meta['udt_schema'] ?? null;
            $overrideType = $meta['compositeType'] ?? ($meta['composite_type'] ?? null);
            $fields = $meta['compositeFields'] ?? ($meta['composite_fields'] ?? []);

            $typeName = null;
            if (is_string($overrideType) && $overrideType !== '') {
                $typeName = $overrideType;
            } elseif ($type === 'user-defined' || $udtName) {
                if (is_string($udtName) && $udtName !== '') {
                    if (is_string($udtSchema) && $udtSchema !== '' && $udtSchema !== 'public') {
                        $typeName = $udtSchema . '.' . $udtName;
                    } else {
                        $typeName = $udtName;
                    }
                }
            }

            if (!is_string($typeName)) {
                continue;
            }

            if ($typeName === '') {
                continue;
            }

            $value = $payload[$column];
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (JSON_ERROR_NONE === json_last_error() && is_array($decoded)) {
                    $value = $decoded;
                }
            }

            if (is_object($value)) {
                $value = get_object_vars($value);
            }

            if (!is_array($value)) {
                continue;
            }

            if (!preg_match('/^[A-Za-z0-9_\.\"]+$/', $typeName)) {
                continue;
            }

            $orderedValues = self::orderCompositeValues($value, is_array($fields) ? $fields : []);
            $payload[$column] = DB::raw(self::buildCompositeSql($typeName, $orderedValues, $pdo));
        }

        return $payload;
    }

    private static function orderCompositeValues(array $value, array $fields): array
    {
        if ($fields !== []) {
            $ordered = [];
            foreach ($fields as $field) {
                $ordered[] = $value[$field] ?? null;
            }

            return $ordered;
        }

        return array_values($value);
    }

    private static function buildCompositeSql(string $typeName, array $values, PDO $pdo): string
    {
        $parts = array_map(static function (mixed $value) use ($pdo): string {
            if ($value === null) {
                return 'NULL';
            }

            if (is_bool($value)) {
                return $value ? 'TRUE' : 'FALSE';
            }

            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }

            return $pdo->quote((string) $value);
        }, $values);

        return 'ROW(' . implode(',', $parts) . ')::' . $typeName;
    }
}
