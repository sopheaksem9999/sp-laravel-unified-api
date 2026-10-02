<?php

declare(strict_types=1);

namespace Sopheak\Core\Mcp\Guidance;

use Sopheak\Core\Enums\RecordRelationshipsEnum;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\DefaultValidationUtils;
use Sopheak\Core\Utilities\RelationshipResolverUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Throwable;

/**
 * Describes one relationship of an endpoint (`includes[]`): what it is, which
 * table it points at, and — when it can be written inline — a payload example
 * that really writes, the permissions its child rows need, and for
 * many-to-many types the pivot details.
 */
final class IncludeGuide
{
    private const WRITABLE = [
        'hasMany', 'belongsToMany', 'hasManyThrough', 'morphMany', 'morphToMany', 'morphByMany', 'spatiePermission',
    ];

    /** Relationship config keys naming columns the API sets itself. */
    private const SERVER_SET_KEYS = ['foreignKey', 'localKey', 'morphId', 'morphType', 'morph_id', 'morph_type'];

    /**
     * @return array<string, mixed>
     */
    public function describe(string $tableKey, RecordTableType $config, string $alias, mixed $relationship): array
    {
        $relConfig = is_object($relationship) && method_exists($relationship, 'toArray') ? $relationship->toArray() : (array) $relationship;
        $type = $relConfig['type'] ?? 'unknown';
        $typeValue = $type instanceof RecordRelationshipsEnum ? $type->value : (string) $type;
        $foreignKey = $relConfig['foreignKey'] ?? ($relConfig['foreignPivotKey'] ?? null);
        $attaching = PayloadSchemaBuilder::isAttaching($typeValue);
        $resolved = $attaching ? $this->resolve($tableKey, $alias) : null;

        $include = [
            'name' => $alias,
            'type' => $typeValue,
            'table' => $resolved['table'] ?? ($relConfig['table'] ?? ($relConfig['relatedTable'] ?? null)),
            'foreignKey' => $foreignKey,
            // Whether this relationship can be sent inline in the parent's create/update
            // payload — i.e. written in the SAME request instead of a separate follow-up
            // request per child table. See payloadHint for the exact shape.
            'writable' => in_array($typeValue, self::WRITABLE, true),
        ];

        if (is_array($resolved) && isset($resolved['pivot_table'])) {
            $include['pivotTable'] = $resolved['pivot_table'];
            $include['relatedPivotKey'] = $resolved['related_pivot_key'] ?? null;
            $include['pivotFields'] = array_values(array_diff(
                (array) ($resolved['with_pivot'] ?? []),
                array_filter([$resolved['morph_type'] ?? null]),
            ));
        }

        if ($include['writable']) {
            $include['allowCreate'] = $relConfig['allowCreate'] ?? true;
            $include['allowUpdate'] = $relConfig['allowUpdate'] ?? true;
            $include['allowDelete'] = $relConfig['allowDelete'] ?? true;

            // The hint must name the related table's real primary key: {"id": 2} for a
            // child keyed `line_id` would create the row again instead of updating it.
            $primaryKey = $this->primaryKey((string) ($include['table'] ?? ''));
            $example = $attaching ? $this->attachingExample($include, $relConfig, $primaryKey) : $this->childrenExample($include, $relConfig, $primaryKey);
            $include['payloadHint'] = sprintf(
                '"%s": %s — in the parent\'s create/update payload. %s',
                $alias,
                json_encode($example, JSON_UNESCAPED_SLASHES),
                $attaching
                    ? sprintf('{"%s": N} attaches (bare ids too); pivot fields update the pivot; no %1$s creates a row; "_delete": true detaches. Omitted links are kept.', $primaryKey)
                    : sprintf('No "%s" creates a child, a "%1$s" updates it, "_delete": true deletes it. Omitted children are kept.', $primaryKey)
            );
        } elseif ('belongsTo' === $typeValue) {
            $include['payloadHint'] = sprintf(
                'Set the root field "%s": <id> on the parent — do not nest a "%s" object',
                $foreignKey ?? ($alias . '_id'),
                $alias
            );
        } else {
            $include['payloadHint'] = 'Not a nested-write alias — set the underlying columns directly on the parent, or use a custom function';
        }

        return $include;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolve(string $tableKey, string $alias): ?array
    {
        try {
            $resolved = RelationshipResolverUtils::resolveRelationship($tableKey, $alias);
        } catch (Throwable) {
            return null;
        }

        return is_array($resolved) ? $resolved : null;
    }

    /**
     * hasMany / morphMany: create, update, delete.
     *
     * @param array<string, mixed> $include
     * @param array<string, mixed> $relConfig
     * @return array<int, array<string, mixed>>
     */
    private function childrenExample(array $include, array $relConfig, string $primaryKey): array
    {
        $fields = $this->sampleFields((string) ($include['table'] ?? ''), $this->serverSet($relConfig));
        $items = [];
        if ($include['allowCreate']) {
            $items[] = $fields;
        }

        if ($include['allowUpdate']) {
            $items[] = [$primaryKey => 2] + array_slice($fields, 0, 1, true);
        }

        if ($include['allowDelete']) {
            $items[] = [$primaryKey => 5, '_delete' => true];
        }

        return $items;
    }

    /**
     * belongsToMany / morphToMany / hasManyThrough: attach, update the pivot,
     * create a related row, detach.
     *
     * @param array<string, mixed> $include
     * @param array<string, mixed> $relConfig
     * @return array<int, array<string, mixed>>
     */
    private function attachingExample(array $include, array $relConfig, string $primaryKey): array
    {
        $pivot = [];
        foreach (array_slice((array) ($include['pivotFields'] ?? []), 0, 2) as $field) {
            $pivot[(string) $field] = 'example';
        }

        $items = [];
        if ($include['allowCreate']) {
            $items[] = [$primaryKey => 1];
        }

        if ($include['allowUpdate'] && [] !== $pivot) {
            $items[] = [$primaryKey => 2] + $pivot;
        }

        if ($include['allowCreate']) {
            $items[] = $this->sampleFields((string) ($include['table'] ?? ''), $this->serverSet($relConfig));
        }

        if ($include['allowDelete']) {
            $items[] = [$primaryKey => 5, '_delete' => true];
        }

        return $items;
    }

    private function primaryKey(string $relatedTable): string
    {
        $schema = SchemaRegistryUtils::getTable($relatedTable);

        return $schema instanceof RecordTableType ? (string) ($schema->primaryKey ?? 'id') : 'id';
    }

    /**
     * @param array<string, mixed> $relConfig
     * @return array<int, string>
     */
    private function serverSet(array $relConfig): array
    {
        $skip = [];
        foreach (self::SERVER_SET_KEYS as $key) {
            if (isset($relConfig[$key]) && is_string($relConfig[$key])) {
                $skip[] = $relConfig[$key];
            }
        }

        return $skip;
    }

    /**
     * Writable columns of the related table with valid sample values: every
     * required column first (so the create succeeds), plus one optional column.
     *
     * @param array<int, string> $skip
     * @return array<string, mixed>
     */
    private function sampleFields(string $relatedTable, array $skip): array
    {
        $schema = SchemaRegistryUtils::getTable($relatedTable);
        if (!$schema instanceof RecordTableType) {
            return [];
        }

        $primaryKey = (string) ($schema->primaryKey ?? 'id');
        $writeDisabled = (array) ($schema->columnWriteDisabled ?? []);
        $required = [];
        $optional = [];
        foreach ((array) $schema->columns as $name => $definition) {
            $name = (string) $name;
            $definition = is_array($definition) ? $definition : ['type' => (string) $definition];
            if ($name === $primaryKey) {
                continue;
            }

            if (in_array($name, $skip, true)) {
                continue;
            }

            if (in_array($name, $writeDisabled, true)) {
                continue;
            }

            if (!PayloadSchemaBuilder::writableColumn($schema, $name, 'create')) {
                continue;
            }

            if ('json' === ColumnTypes::family((string) ($definition['type'] ?? 'string'))) {
                continue;
            }

            $sample = ColumnTypes::sample((string) ($definition['type'] ?? 'string'));
            if (DefaultValidationUtils::isRequiredColumn($definition)) {
                $required[$name] = $sample;
            } else {
                $optional[$name] = $sample;
            }
        }

        return $required + array_slice($optional, 0, max(0, 1 - count($required)), true);
    }
}
