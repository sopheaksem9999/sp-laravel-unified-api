<?php

declare(strict_types=1);

namespace Sopheak\Core\Utilities;

use InvalidArgumentException;
use Illuminate\Foundation\Auth\User;
use Sopheak\Core\Enums\RecordRelationshipsEnum;
use Sopheak\Core\Types\RecordAassociationType;
use Sopheak\Core\Types\RecordBelongsToType;
use Sopheak\Core\Types\RecordHasManyThroughType;
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Types\RecordMetaBelongsToManyType;
use Sopheak\Core\Types\RecordMetaHasManyThroughType;
use Sopheak\Core\Types\RecordMorphHasManyType;
use Sopheak\Core\Types\RecordMorphToManyType;
use Sopheak\Core\Types\RecordSpatiePermissionType;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Sopheak\Core\Utilities\TimeUtils;
use Sopheak\Core\Enums\RecordApiJsonResponseEnum;
use Sopheak\Core\Services\AttachmentUrlService;
use Sopheak\Core\Services\RecordConfigService;

class RelationshipResolverUtils
{
    // Dynamic inference has been removed - all relationships must be statically defined
    // Cache resolved relationship configs per request to avoid expensive recomputation
    private static array $resolveCache = [];

    // Cache schema registry to avoid multiple calls during request lifecycle
    private static ?array $schemaCache = null;

    // Cache for subquery relationship configurations
    private static array $subqueryCache = [];

    /**
     * Clear the schema cache (useful for testing or when schema changes).
     */
    public static function clearSchemaCache(): void
    {
        self::$schemaCache = null;
        self::$resolveCache = [];
        self::$subqueryCache = [];
    }

    /**
     * Apply optimized subqueries for relationship loading using JSON array aggregation.
     * This method implements the pattern shown in the example to improve performance
     * by using subqueries instead of separate queries for relationships.
     *
     * @param Builder $builder  The main query builder
     * @param string  $table    The main table name
     * @param array   $includes Parsed relationship includes
     * @param mixed   $tenantId Tenant ID for filtering
     *
     * @return Builder The modified query builder with subquery selects
     */
    public static function applySubqueryRelationships(Builder $builder, string $table, array $includes, mixed $tenantId = null): Builder
    {
        $schema = self::getSchema();
        RecordConfigService::enableTenantId();

        // Get main table columns to detect conflicts
        $mainTableColumns = isset($schema[$table]) ? array_keys($schema[$table]->columns ?? []) : [];

        foreach ($includes as $alias => $include) {
            $hintTable = $include['table'] ?? null;
            $columns = $include['columns'] ?? ['*'];

            $config = self::resolveRelationship($table, $alias, $hintTable);
            if (!$config) {
                $declaredRelationships = isset($schema[$table]) ? ($schema[$table]->relationships ?? []) : [];
                if (!array_key_exists($alias, $declaredRelationships)) {
                    $validNames = array_keys($declaredRelationships);
                    sort($validNames);
                    throw new InvalidArgumentException(sprintf(
                        "Unknown relationship '%s' in select for table '%s'. Valid relationships: %s.",
                        $alias,
                        $table,
                        [] === $validNames ? 'none' : implode(', ', $validNames)
                    ));
                }

                continue;
            }

            $relatedTable = $config['table'];
            $type = $config['type'];

            // Validate related table exists in schema
            if (!isset($schema[$relatedTable])) {
                continue;
            }

            // Generate safe alias to avoid column conflicts
            $safeAlias = self::generateSafeAlias($alias, $mainTableColumns);

            switch ($type) {
                case 'belongsTo':
                    $builder = self::addBelongsToSubquery($builder, $table, $safeAlias, $config, $columns, $tenantId, $schema);

                    break;

                case 'belongsToMany':
                    $builder = self::addBelongsToManySubquery($builder, $table, $safeAlias, $config, $columns, $tenantId, $schema);

                    break;

                case 'morphToMany':
                    // Spatie Permission relationships (e.g., users -> roles) use morphToMany through model_has_roles
                    $builder = self::addMorphToManySubquery($builder, $table, $safeAlias, $config, $columns, $tenantId, $schema);

                    break;

                case 'hasMany':
                    $builder = self::addHasManySubquery($builder, $table, $safeAlias, $config, $columns, $tenantId, $schema);

                    break;

                case 'morphMany':
                    $builder = self::addMorphManySubquery($builder, $table, $safeAlias, $config, $columns, $tenantId, $schema);

                    break;

                case 'hasManyThrough':
                    $builder = self::addHasManyThroughSubquery($builder, $table, $safeAlias, $config, $columns, $tenantId, $schema);

                    break;
            }
        }

        return $builder;
    }

    /**
     * Process JSON relationship data and convert to proper types.
     */
    public static function processJsonRelationships(array $records, array $includes, string $table = ''): array
    {
        // Get main table columns to detect conflicts and generate safe aliases mapping
        $schema = self::getSchema();
        $mainTableColumns = isset($schema[$table]) ? array_keys($schema[$table]->columns ?? []) : [];

        // Create mapping from safe aliases back to original aliases
        $aliasMapping = [];
        foreach (array_keys($includes) as $originalAlias) {
            $safeAlias = self::generateSafeAlias($originalAlias, $mainTableColumns);
            $aliasMapping[$safeAlias] = $originalAlias;
        }

        foreach ($records as &$record) {
            $isArrayRecord = is_array($record);
            $recordArray = $isArrayRecord ? $record : (array) $record;

            // Process relationships using safe aliases but assign to original aliases
            foreach ($aliasMapping as $safeAlias => $originalAlias) {
                if (array_key_exists($safeAlias, $recordArray)) {
                    $jsonData = $recordArray[$safeAlias];

                    // Determine relationship type for defaulting behavior
                    $hintTable = $includes[$originalAlias]['table'] ?? null;
                    $relConfig = self::resolveRelationship($table, $originalAlias, $hintTable) ?: [];
                    $relType = $relConfig['type'] ?? null;

                    $decoded = null;
                    if (is_string($jsonData)) {
                        $decoded = json_decode($jsonData, true);
                    } elseif (is_array($jsonData) || is_object($jsonData)) {
                        $decoded = (array) $jsonData;
                    } elseif (null === $jsonData) {
                        // default based on relationship multiplicity
                        if (in_array($relType, ['hasMany', 'morphMany', 'belongsToMany', 'morphToMany', 'hasManyThrough'], true)) {
                            $decoded = [];
                        } else {
                            $decoded = null; // belongsTo / hasOne
                        }
                    }

                    // morphMany subquery results decode to assoc arrays; normalize to objects
                    // to match the shape produced by the non-subquery loading path
                    if ('morphMany' === $relType && is_array($decoded)) {
                        $decoded = array_map(static fn(mixed $item): mixed => is_array($item) ? (object) $item : $item, $decoded);
                    }

                    // Enrich embedded attachment rows with resolved URLs, mirroring
                    // the direct-read behavior of the AttachmentTrigger
                    $decoded = self::enrichEmbeddedAttachments($decoded, $relConfig);

                    // Assign decoded value back to record using original alias
                    if ($isArrayRecord) {
                        $record[$originalAlias] = $decoded;
                    } else {
                        $record->{$originalAlias} = $decoded;
                    }

                    // Remove the safe alias property if it's different from original
                    if ($safeAlias !== $originalAlias) {
                        if ($isArrayRecord) {
                            unset($record[$safeAlias]);
                        } else {
                            unset($record->{$safeAlias});
                        }
                    }
                }
            }
        }

        return $records;
    }

    /**
     * Determine whether a resolved relationship targets the attachment table.
     */
    private static function isAttachmentRelation(array $config): bool
    {
        // 'sp_attachments' is the table's config key everywhere else in this codebase
        // (AttachmentUploadController, AttachmentAccessService); it is not derived from
        // attachments.route_prefix, which only controls the attachment routes' URL segment.
        return ($config['table'] ?? null) === 'sp_attachments';
    }

    /**
     * Append resolved download/public URLs to embedded attachment records so that
     * nested attachment relations expose working urls, mirroring the behavior of
     * the AttachmentTrigger on direct attachment reads.
     */
    private static function enrichEmbeddedAttachments(mixed $value, array $config): mixed
    {
        if (!self::isAttachmentRelation($config)) {
            return $value;
        }

        $isList = is_array($value) && array_is_list($value);
        $items = $isList ? $value : (null !== $value ? [$value] : []);

        $enriched = array_map(static fn(mixed $item): mixed => self::enrichAttachmentItem($item), $items);

        if ($isList) {
            return $enriched;
        }

        return $enriched[0] ?? null;
    }

    private static function enrichAttachmentItem(mixed $item): mixed
    {
        if (null === $item || !(is_array($item) || is_object($item))) {
            return $item;
        }

        $isObject = is_object($item);
        $enriched = app(AttachmentUrlService::class)->appendUrls((array) $item);

        return $isObject ? (object) $enriched : $enriched;
    }

    /**
     * Parse PostgREST-style select syntax and include relationships.
     * Example: "id,ref_number,customer:customers(id,name),items:invoice_items(id,name,price,qty)"
     * Optimized for memory efficiency and reduced object retention.
     *
     * @param null|mixed $tenantId
     */
    public static function includeRelationships(array $records, string $table, ?string $selectParam = null, $tenantId = null): array
    {
        if (null === $selectParam || '' === $selectParam || '0' === $selectParam || [] === $records) {
            return $records;
        }

        // Build nested include AST and process recursively with max depth guard
        $includes = self::parseSelectForIncludes($selectParam);
        $maxDepth = max(1, RecordConfigService::maxDepth());

        // Process in chunks to reduce memory usage for large datasets
        $chunkSize = 100; // Process 100 records at a time
        if (count($records) > $chunkSize) {
            $result = [];
            foreach (array_chunk($records, $chunkSize) as $chunk) {
                $processedChunk = self::includeRelationshipsRecursive($chunk, $table, $includes, $tenantId, 1, $maxDepth);
                $result = array_merge($result, $processedChunk);

                // Force garbage collection for large datasets
                if (memory_get_usage() > 50 * 1024 * 1024) { // 50MB threshold
                    gc_collect_cycles();
                }
            }

            return $result;
        }

        return self::includeRelationshipsRecursive($records, $table, $includes, $tenantId, 1, $maxDepth);
    }

    /**
     * Extract main table columns from the select parameter, ignoring relationship segments.
     * Examples:
     * - "*,customer(*),items(id,qty)" => ['*']
     * - "id,ref_number,customer:customers(id,name)" => ['id','ref_number'].
     * @return string[]
     */
    public static function getMainTableColumns(?string $selectParam): array
    {
        if (null === $selectParam || '' === $selectParam || '0' === $selectParam) {
            return [];
        }

        $segments = self::parseSelectSegments($selectParam);
        $columns = [];
        foreach ($segments as $segment) {
            $segment = trim((string) $segment);
            if ('' === $segment) {
                continue;
            }

            if (str_starts_with($segment, 'with=')) {
                $segment = substr($segment, 5);
            }

            // Relationship segments (alias:table(inner)) or (alias(inner)) should be ignored
            if (preg_match('/^(\w+):(\w+)\((.*)\)$/', $segment)) {
                continue;
            }

            if (preg_match('/^(\w+)\((.*)\)$/', $segment)) {
                continue;
            }

            $columns[] = $segment;
        }

        return $columns;
    }

    /**
     * Throws if any requested main-table column is not a real column or a
     * declared computed attribute on the table's schema. '*' is always valid.
     * No-op for an empty column list or an unregistered table.
     *
     * @param string[] $columns
     */
    public static function validateMainTableColumns(string $table, array $columns): void
    {
        if ([] === $columns) {
            return;
        }

        $tableSchema = self::getSchema()[$table] ?? null;
        if (null === $tableSchema) {
            return;
        }

        $validNames = array_merge(
            array_keys($tableSchema->columns ?? []),
            array_keys($tableSchema->attributes ?? [])
        );

        foreach ($columns as $column) {
            if ('*' === $column || in_array($column, $validNames, true)) {
                continue;
            }

            sort($validNames);
            throw new InvalidArgumentException(sprintf(
                "Unknown column '%s' in select for table '%s'. Valid columns: %s.",
                $column,
                $table,
                implode(', ', $validNames)
            ));
        }
    }

    /**
     * Validate that every top-level key in a create/update payload is either a real
     * column or a declared relationship alias on the table. Without this, a typo'd or
     * invented field name is silently dropped by RecordPayloadExtractor/sanitizePayload
     * (columns) or processRelatedData (relationships) instead of failing loud, so the
     * client gets a 200/201 that quietly ignored part of what it sent.
     *
     * A column listed in `columnWriteDisabled` is still a *known* field — sending it is
     * deliberately a silent no-op (e.g. round-tripping a GET response back as a PUT body
     * without stripping server-computed fields), not an error. Only names that match
     * neither a column nor a relationship at all are rejected.
     *
     * @param array<string, mixed> $payload
     */
    public static function validatePayloadFields(string $table, array $payload): void
    {
        if ([] === $payload) {
            return;
        }

        $tableSchema = self::getSchema()[$table] ?? null;
        if (null === $tableSchema) {
            return;
        }

        $columns = array_keys($tableSchema->columns ?? []);
        $relationships = array_keys($tableSchema->relationships ?? []);

        foreach ($payload as $field => $value) {
            if (!is_string($field)) {
                continue;
            }

            if (in_array($field, $columns, true) || in_array($field, $relationships, true)) {
                continue;
            }

            sort($columns);
            sort($relationships);
            throw new InvalidArgumentException(sprintf(
                "Unknown field '%s' in payload for table '%s'. Valid columns: %s. Valid relationships: %s.",
                $field,
                $table,
                [] === $columns ? 'none' : implode(', ', $columns),
                [] === $relationships ? 'none' : implode(', ', $relationships)
            ));
        }
    }

    /**
     * Resolve relationship configuration for a given alias on a main table.
     * Only uses static configuration - no dynamic inference.
     */
    public static function resolveRelationship(string $mainTable, string $alias, ?string $hintTable = null)
    {
        $cacheKey = $mainTable . '|' . $alias . '|' . ($hintTable ?? '');
        if (array_key_exists($cacheKey, self::$resolveCache)) {
            return self::$resolveCache[$cacheKey] ?: null;
        }

        // 1) New config schema preferred
        $schema = self::getSchema();
        if (isset($schema[$mainTable], $schema[$mainTable]->relationships[$alias])) {
            $rel = $schema[$mainTable]->relationships[$alias];

            if ($rel) {
                $schema = self::getSchema();
                $localPk = $schema[$mainTable]->primaryKey ?? 'id';

                // Handle RecordBelongsToType
                if ($rel instanceof RecordBelongsToType) {
                    $result = [
                        'type' => 'belongsTo',
                        'table' => $rel->table,
                        'foreign_key' => $rel->foreignKey ?? (Str::singular($rel->table) . '_id'),
                        'owner_key' => $rel->ownerKey ?? 'id',
                        'local_key' => $localPk,
                        'selectable' => ['*'],
                    ];
                    self::$resolveCache[$cacheKey] = $result;

                    return $result;
                }

                // Handle legacy array configuration
                if (is_array($rel)) {
                    self::$resolveCache[$cacheKey] = $rel;

                    return $rel;
                }

                // Handle RecordHasManyType
                if ($rel instanceof RecordHasManyType) {
                    $result = [
                        'type' => 'hasMany',
                        'table' => $rel->table,
                        'foreign_key' => $rel->foreignKey ?? (Str::singular($mainTable) . '_id'),
                        'local_key' => $rel->localKey ?? $localPk,
                        'selectable' => ['*'],
                        'allow_create' => $rel->allowCreate,
                        'allow_update' => $rel->allowUpdate,
                        'allow_delete' => $rel->allowDelete,
                    ];
                    self::$resolveCache[$cacheKey] = $result;

                    return $result;
                }

                // Handle RecordMorphHasManyType (polymorphic hasMany)
                if ($rel instanceof RecordMorphHasManyType) {
                    $result = [
                        'type' => 'morphMany',
                        'table' => $rel->table,
                        'morph_type' => $rel->morphType,
                        'morph_id' => $rel->morphId,
                        'morph_class' => $rel->morphClass,
                        'local_key' => $rel->localKey ?? $localPk,
                        'selectable' => ['*'],
                        'allow_create' => $rel->allowCreate,
                        'allow_update' => $rel->allowUpdate,
                        'allow_delete' => $rel->allowDelete,
                    ];
                    self::$resolveCache[$cacheKey] = $result;

                    return $result;
                }

                if ($rel instanceof RecordAassociationType) {
                    if ($rel->type !== RecordRelationshipsEnum::HAS_MANY_THROUGH) {
                        self::$resolveCache[$cacheKey] = false;

                        return null;
                    }

                    $result = [
                        'type' => 'hasManyThrough',
                        'table' => $rel->toObjectType,
                        'through_table' => $rel->related,
                        'first_key' => $rel->fromObjectId ?? 'owner_id',
                        'second_key' => 'id',
                        'second_local_key' => $rel->toObjectId ?? 'target_id',
                        'local_key' => $localPk,
                        'order_by' => null,
                        'owner_column' => 'owner',
                        'owner_value' => $rel->fromObjectType,
                        'target_column' => 'target',
                        'target_value' => $rel->toObjectType,
                        'selectable' => ['*'],
                        'allow_create' => $rel->allowCreate,
                        'allow_update' => $rel->allowUpdate,
                        'allow_delete' => $rel->allowDelete,
                    ];
                    self::$resolveCache[$cacheKey] = $result;

                    return $result;
                }

                // Handle RecordMetaBelongsToManyType
                if ($rel instanceof RecordMetaBelongsToManyType) {
                    $result = [
                        'type' => 'belongsToMany',
                        'table' => $rel->related,
                        'pivot_table' => $rel->table,
                        'foreign_pivot_key' => $rel->foreignPivotKey ?? (Str::singular($mainTable) . '_id'),
                        'related_pivot_key' => $rel->relatedPivotKey ?? 'id',
                        'parent_key' => $rel->parentKey ?? $localPk,
                        'related_key' => $rel->relatedKey ?? 'id',
                        'relation' => $rel->relation ?? null,
                        'with_pivot' => $rel->withPivot ?? [],
                        'where_pivot' => $rel->wherePivot ?? [],
                        'with_timestamps' => $rel->withTimestamps ?? false,
                        'selectable' => ['*'],
                        'allow_create' => $rel->allowCreate,
                        'allow_update' => $rel->allowUpdate,
                        'allow_delete' => $rel->allowDelete,
                    ];
                    self::$resolveCache[$cacheKey] = $result;

                    return $result;
                }

                // Handle RecordSpatiePermissionType (external Spatie\Permission integration)
                if ($rel instanceof RecordSpatiePermissionType) {
                    $result = [
                        'type' => 'morphToMany',
                        'table' => $alias,
                        'pivot_table' => $rel->table,
                        'foreign_pivot_key' => $rel->foreignPivotKey ?? config('permissions.column_names.model_morph_key'),
                        'related_pivot_key' => $rel->relatedPivotKey ?? config('permissions.column_names.role_pivot_key', 'role_id'),
                        'parent_key' => $rel->parentKey ?? $localPk,
                        'related_key' => $rel->relatedKey ?? 'id',
                        'relation' => $rel->relation ?? 'model',
                        'morph_type' => 'model_type',
                        'morph_id' => config('permissions.column_names.model_morph_key'),
                        'with_pivot' => $rel->withPivot ?? ['model_type'],
                        'where_pivot' => $rel->wherePivot ?? [],
                        'with_timestamps' => $rel->withTimestamps ?? false,
                        'teams_enabled' => $rel->teamsEnabled ?? false,
                        'teams_key' => $rel->teamsKey ?? config('permissions.column_names.team_foreign_key', 'team_id'),
                        'selectable' => ['*'],
                    ];

                    if (!isset($result['relation']) || $result['relation'] === 'model') {
                        $result['relation'] = 'App\\Models\\' . Str::studly(Str::singular($mainTable));
                    }

                    self::$resolveCache[$cacheKey] = $result;

                    return $result;
                }

                // Handle RecordMorphToManyType (built-in morphToMany)
                if ($rel instanceof RecordMorphToManyType) {
                    $relatedTableName = $rel->related && class_exists($rel->related)
                        ? (new $rel->related())->getTable()
                        : $alias;

                    $result = [
                        'type' => 'morphToMany',
                        'table' => $relatedTableName,
                        'pivot_table' => $rel->table,
                        'foreign_pivot_key' => $rel->foreignPivotKey ?? 'model_id',
                        'related_pivot_key' => $rel->relatedPivotKey ?? 'role_id',
                        'parent_key' => $rel->parentKey ?? $localPk,
                        'related_key' => $rel->relatedKey ?? 'id',
                        'relation' => $rel->relation ?? 'model',
                        'morph_type' => 'model_type',
                        'morph_id' => 'model_id',
                        'with_pivot' => $rel->withPivot ?? ['model_type'],
                        'where_pivot' => $rel->wherePivot ?? [],
                        'with_timestamps' => $rel->withTimestamps ?? false,
                        'teams_enabled' => $rel->teamsEnabled ?? false,
                        'teams_key' => $rel->teamsKey ?? null,
                        'selectable' => ['*'],
                    ];

                    if (!isset($result['relation']) || $result['relation'] === 'model') {
                        $result['relation'] = 'App\\Models\\' . Str::studly(Str::singular($mainTable));
                    }

                    self::$resolveCache[$cacheKey] = $result;

                    return $result;
                }

                // Handle RecordMetaHasManyThroughType (global meta table pivot)
                if ($rel instanceof RecordMetaHasManyThroughType) {
                    $result = [
                        'type' => 'hasManyThrough',
                        'table' => $rel->table,
                        'through_table' => $rel->through,
                        'first_key' => $rel->firstKey ?? (Str::singular($mainTable) . '_id'),
                        'second_key' => $rel->secondKey ?? 'id',
                        'second_local_key' => $rel->secondLocalKey ?? 'target_id',
                        'local_key' => $rel->localKey ?? $localPk,
                        'order_by' => $rel->orderBy ?? null,
                        'owner_column' => $rel->ownerColumn ?? 'owner',
                        'owner_value' => $rel->owner,
                        'selectable' => ['*'],
                        'allow_create' => $rel->allowCreate,
                        'allow_update' => $rel->allowUpdate,
                        'allow_delete' => $rel->allowDelete,
                    ];

                    self::$resolveCache[$cacheKey] = $result;

                    return $result;
                }

                // Handle RecordHasManyThroughType
                if ($rel instanceof RecordHasManyThroughType) {
                    $result = [
                        'type' => 'hasManyThrough',
                        'table' => $rel->table,
                        'through_table' => $rel->through,
                        'first_key' => $rel->firstKey ?? (Str::singular($mainTable) . '_id'),
                        'second_key' => $rel->secondKey ?? 'id',
                        'second_local_key' => $rel->secondLocalKey ?? (Str::singular($rel->table) . '_id'),
                        'local_key' => $rel->localKey ?? $localPk,
                        'order_by' => $rel->orderBy ?? null,
                        'selectable' => ['*'],
                        'allow_create' => $rel->allowCreate,
                        'allow_update' => $rel->allowUpdate,
                        'allow_delete' => $rel->allowDelete,
                    ];
                    self::$resolveCache[$cacheKey] = $result;

                    return $result;
                }
            }
        }

        // All relationships are now defined as objects in the new schema

        // Backward compatibility (legacy config if exists)
        $override = config(sprintf('record.relationships.%s.%s', $mainTable, $alias));
        if (is_array($override)) {
            self::$resolveCache[$cacheKey] = $override;

            return $override;
        }

        // No relationship found in static configuration
        self::$resolveCache[$cacheKey] = false;

        return null;
    }

    /**
     * Process related data for create/update operations with optimized bulk operations.
     *
     * @param null|mixed $tenantId
     */
    public static function processRelatedData(string $table, array $payload, mixed $recordId, $tenantId = null, string $operation = 'create'): array
    {
        $schema = self::getSchema();
        $hasTenant = isset($schema[$table]->columns[RecordConfigService::tenantColumn()]);

        foreach ($payload as $alias => $relatedData) {
            if (!is_array($relatedData)) {
                continue;
            }

            $config = self::resolveRelationship($table, (string) $alias);
            if (!$config) {
                continue;
            }

            $relatedTable = $config['table'];
            $relatedSchema = $schema[$relatedTable] ?? null;
            $relatedPk = $relatedSchema->primaryKey ?? 'id';

            $type = $config['type'] ?? 'hasMany';
            $allowCreate = $config['allow_create'] ?? true;
            $allowUpdate = $config['allow_update'] ?? true;
            $allowDelete = $config['allow_delete'] ?? true;

            if ($type === 'belongsToMany' || $type === 'morphToMany') {
                self::processBelongsToManyOperation($table, (string) $alias, $relatedData, $recordId, $config, $schema, $tenantId, $allowCreate, $allowUpdate, $allowDelete);
                continue;
            }

            if ($type === 'hasManyThrough') {
                self::processHasManyThroughOperation($table, (string) $alias, $relatedData, $recordId, $config, $schema, $tenantId, $allowCreate, $allowUpdate, $allowDelete);
                continue;
            }

            $foreignKey = $config['foreign_key'] ?? ('morphMany' === $type ? ($config['morph_id'] ?? null) : null);
            $actualRelatedTableName = $schema[$relatedTable]->table ?? $relatedTable;

            if (!$foreignKey) {
                continue;
            }

            if (!$recordId) {
                abort((int) RecordApiJsonResponseEnum::VALIDATION_ERROR->value, 'Missing main record identifier for nested update');
            }

            // The child table's own tenant, not the parent's: a non-tenant
            // parent passes no tenant, and its tenant-scoped children were
            // written unscoped.
            $childTenant = self::childTenant($tenantId);
            $childHasTenant = RecordConfigService::enableTenantId() && isset($relatedSchema->columns[RecordConfigService::tenantColumn()]);

            // Allowed columns
            $allowedCols = array_keys($schema[$relatedTable]->columns ?? []);

            $writeDisabled = is_array($relatedSchema->columnWriteDisabled ?? null) ? $relatedSchema->columnWriteDisabled : [];
            if ([] !== $writeDisabled) {
                $allowedCols = array_values(array_diff($allowedCols, $writeDisabled));
            }

            // Only hasMany / morphMany arrays are strict. Any other type reaching this branch
            // (a belongsTo object echoed back from a `select=*,rel(*)` read) keeps ignoring
            // what it cannot write, so a GET -> PUT round trip still works.
            $strictItems = in_array($type, ['hasMany', 'morphMany'], true);

            foreach ($relatedData as $item) {
                if (!$strictItems && !is_array($item)) {
                    continue;
                }

                $item = self::normalizeNestedItem($item, $table, (string) $alias, $relatedPk, false);

                self::assertChildTenantResolved($table, (string) $alias, $relatedSchema, $childTenant);

                // Determine intended action before sanitization
                $hasPk = isset($item[$relatedPk]);
                $idVal = $hasPk ? $item[$relatedPk] : null;

                // Handle deletion
                if (($item['_delete'] ?? false) || ($item['_destroy'] ?? false)) {
                    if (!$hasPk) {
                        throw new InvalidArgumentException(sprintf(
                            "Cannot delete item in relationship '%s' for table '%s': '_delete' requires the related '%s' primary key.",
                            $alias,
                            $table,
                            $relatedPk
                        ));
                    }

                    self::assertRelationshipOperationAllowed($table, (string) $alias, 'delete', $allowDelete);
                    NestedWriteAuthorizer::authorizeChild($table, (string) $alias, $relatedTable, 'delete');

                    $deleteQuery = self::scopeChildRow(self::scopeToParent(
                        DB::table($actualRelatedTableName)->where($relatedPk, $idVal),
                        $foreignKey,
                        $recordId,
                        $type,
                        $config,
                    ), $relatedTable, $actualRelatedTableName, $childTenant, $schema);

                    if ($relatedSchema->softDeletes ?? false) {
                        $deleteQuery->update(['deleted_at' => TimeUtils::now()]);
                    } else {
                        $deleteQuery->delete();
                    }

                    continue;
                }

                self::assertRelationshipOperationAllowed(
                    $table,
                    (string) $alias,
                    $hasPk ? 'update' : 'create',
                    $hasPk ? $allowUpdate : $allowCreate
                );
                NestedWriteAuthorizer::authorizeChild($table, (string) $alias, $relatedTable, $hasPk ? 'update' : 'create');

                // Sanitize payload: only allowed columns; drop system/protected fields
                $item = array_intersect_key($item, array_flip($allowedCols));
                unset($item['id'], $item['created_at'], $item['updated_at'], $item['deleted_at']);
                if ($hasTenant || $childHasTenant) {
                    unset($item[RecordConfigService::tenantColumn()]);
                }

                // Ensure FK is set to parent ID (cannot be overridden by input)
                $item[$foreignKey] = $recordId;
                if ('morphMany' === $type && isset($config['morph_type'], $config['morph_class'])) {
                    // Force discriminator column to the configured morph class
                    $item[$config['morph_type']] = $config['morph_class'];
                }

                if ($childHasTenant && !RecordUtils::isTenantIdMissing($childTenant)) {
                    $item[RecordConfigService::tenantColumn()] = $childTenant;
                } elseif ($tenantId && $hasTenant && isset($relatedSchema->columns[RecordConfigService::tenantColumn()])) {
                    $item[RecordConfigService::tenantColumn()] = $tenantId;
                }

                $item = RecordUtils::applyCompositeTypes($item, $relatedSchema->columns ?? []);

                if ($hasPk) {
                    // Update path (already asserted allowUpdate above)
                    unset($item[$relatedPk]);
                    self::scopeChildRow(self::scopeToParent(
                        DB::table($actualRelatedTableName)->where($relatedPk, $idVal),
                        $foreignKey,
                        $recordId,
                        $type,
                        $config,
                    ), $relatedTable, $actualRelatedTableName, $childTenant, $schema)->update($item);
                } else {
                    // Create path (already asserted allowCreate above)
                    unset($item['id']);
                    if (!isset($item[$relatedPk]) && SchemaRegistryUtils::isUuidColumnType($relatedSchema->columns[$relatedPk] ?? null)) {
                        $item[$relatedPk] = (string) Str::uuid();
                    }

                    DB::table($actualRelatedTableName)->insert($item);
                }
            }
        }

        return $payload;
    }

    /**
     * Scope a nested update/delete query to rows that actually belong to the
     * parent record, so a client can't reference another parent's child row
     * by id to modify or delete it.
     *
     * @param array<string, mixed> $config
     */
    private static function scopeToParent(Builder $query, string $foreignKey, mixed $recordId, string $type, array $config): Builder
    {
        $query->where($foreignKey, $recordId);

        if ('morphMany' === $type && isset($config['morph_type'], $config['morph_class'])) {
            $query->where($config['morph_type'], $config['morph_class']);
        }

        return $query;
    }

    /**
     * The tenant a nested write's child rows belong to: the parent's tenant when
     * it has one, otherwise the request's. A non-tenant parent passes null, and
     * without this its tenant-scoped children were written unscoped.
     */
    private static function childTenant(mixed $tenantId): mixed
    {
        if (!RecordConfigService::enableTenantId()) {
            return null;
        }

        return RecordUtils::isTenantIdMissing($tenantId)
            ? RecordUtils::resolveTenantIdFromRequest(request())
            : $tenantId;
    }

    /**
     * Confine a nested write's child query to rows the caller could write
     * directly: the child table's own tenant, and its own-records scope.
     *
     * @param array<string, mixed> $schema
     */
    private static function scopeChildRow(Builder $query, string $relatedTable, string $qualifiedTable, mixed $childTenant, array $schema): Builder
    {
        $tenantCol = RecordConfigService::tenantColumn();
        if (RecordConfigService::enableTenantId() && !RecordUtils::isTenantIdMissing($childTenant) && isset($schema[$relatedTable]->columns[$tenantCol])) {
            $query->where($qualifiedTable . '.' . $tenantCol, $childTenant);
        }

        OwnRecordsScope::apply($query, $relatedTable, $qualifiedTable);

        return $query;
    }

    /**
     * Refuse a nested write to a tenant-scoped child when no tenant resolves.
     * The child table's own endpoint refuses such a write; without this the
     * nested path skipped the tenant filter and reached every tenant's rows.
     */
    private static function assertChildTenantResolved(string $table, string $alias, ?object $relatedSchema, mixed $childTenant): void
    {
        if (null === $relatedSchema || !RecordUtils::shouldApplyTenantId($relatedSchema) || !RecordUtils::isTenantIdMissing($childTenant)) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            "Header %s is required to write relationship '%s' on table '%s'.",
            RecordConfigService::tenantHeader(),
            $alias,
            $table
        ));
    }

    /**
     * Refuse to link an existing related row the caller could not read
     * directly — another tenant's, or another user's under viewOwn. A no-op
     * when the related table carries neither restriction.
     *
     * @param array<string, mixed> $schema
     */
    private static function assertRelatedRowVisible(string $table, string $alias, string $relatedTable, string $qualifiedTable, string $relatedPk, mixed $relatedId, mixed $childTenant, array $schema): void
    {
        $tenantApplies = RecordConfigService::enableTenantId()
            && !RecordUtils::isTenantIdMissing($childTenant)
            && isset($schema[$relatedTable]->columns[RecordConfigService::tenantColumn()]);

        if (!$tenantApplies && null === OwnRecordsScope::ownerColumn($relatedTable)) {
            return;
        }

        $visible = self::scopeChildRow(
            DB::table($qualifiedTable)->where($qualifiedTable . '.' . $relatedPk, $relatedId),
            $relatedTable,
            $qualifiedTable,
            $childTenant,
            $schema
        )->exists();

        if (!$visible) {
            throw new InvalidArgumentException(sprintf(
                "Related record '%s' not found for relationship '%s' on table '%s'.",
                (string) $relatedId,
                $alias,
                $table
            ));
        }
    }

    /**
     * Reject a nested relationship write outright when the relationship's
     * allowCreate/allowUpdate/allowDelete config disallows the action the payload is
     * asking for, instead of silently dropping that item and returning as if it had
     * succeeded.
     */
    private static function assertRelationshipOperationAllowed(string $table, string $alias, string $action, bool $allowed): void
    {
        if ($allowed) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            "Cannot %s item in relationship '%s' for table '%s': allow%s is disabled for this relationship.",
            $action,
            $alias,
            $table,
            ucfirst($action)
        ));
    }

    /**
     * A bare id in a many-to-many / hasManyThrough array means "attach this
     * record" and is rewritten to {pk: id}. Everywhere else a non-object item
     * used to be dropped silently, which hid client mistakes; it is a 422 now.
     *
     * @return array<string, mixed>
     */
    private static function normalizeNestedItem(mixed $item, string $table, string $alias, string $primaryKey, bool $attachable): array
    {
        if (is_array($item)) {
            return $item;
        }

        if (is_int($item) || is_string($item)) {
            if ('' === $item || 0 === $item || '0' === $item) {
                throw new InvalidArgumentException(sprintf("Relationship '%s' on table '%s' got an empty value; send a record id or an object.", $alias, $table));
            }

            if ($attachable) {
                return [$primaryKey => $item];
            }

            throw new InvalidArgumentException(sprintf(
                "Relationship '%s' on table '%s' expects objects, got scalar %s. Send {\"id\": ...} to update a child or {...fields} to create one.",
                $alias,
                $table,
                $item
            ));
        }

        throw new InvalidArgumentException(sprintf("Relationship '%s' on table '%s' got an empty value; send a record id or an object.", $alias, $table));
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function processBelongsToManyOperation(string $table, string $alias, array $data, mixed $mainId, array $config, array $schema, mixed $tenantId, bool $allowCreate, bool $allowUpdate, bool $allowDelete): void
    {
        $pivotTable = $config['pivot_table'];
        $foreignPivotKey = $config['foreign_pivot_key'];
        $relatedPivotKey = $config['related_pivot_key'];
        $relatedTable = $config['table'];
        $relatedSchema = $schema[$relatedTable] ?? null;
        $relatedPk = $relatedSchema->primaryKey ?? 'id';
        $actualRelatedTableName = $schema[$relatedTable]->table ?? $relatedTable;

        $allowedRelatedCols = array_keys($relatedSchema->columns ?? []);
        $writeDisabled = is_array($relatedSchema->columnWriteDisabled ?? null) ? $relatedSchema->columnWriteDisabled : [];
        if ([] !== $writeDisabled) {
            $allowedRelatedCols = array_values(array_diff($allowedRelatedCols, $writeDisabled));
        }

        $childTenant = self::childTenant($tenantId);

        foreach ($data as $item) {
            $item = self::normalizeNestedItem($item, $table, $alias, $relatedPk, true);

            $isDelete = ($item['_delete'] ?? false) || ($item['_destroy'] ?? false);
            $relatedId = $item[$relatedPk] ?? null;

            if ($isDelete) {
                if (!$relatedId) {
                    throw new InvalidArgumentException(sprintf(
                        "Cannot delete item in relationship '%s' for table '%s': '_delete' requires the related '%s' primary key.",
                        $alias,
                        $table,
                        $relatedPk
                    ));
                }

                self::assertRelationshipOperationAllowed($table, $alias, 'delete', $allowDelete);

                DB::table($pivotTable)
                    ->where($foreignPivotKey, $mainId)
                    ->where($relatedPivotKey, $relatedId)
                    ->delete();

                continue;
            }

            self::assertChildTenantResolved($table, $alias, $relatedSchema, $childTenant);

            if (!$relatedId) {
                self::assertRelationshipOperationAllowed($table, $alias, 'create', $allowCreate);
                NestedWriteAuthorizer::authorizeChild($table, $alias, $relatedTable, 'create');

                // Create new related record
                $relatedFields = array_intersect_key($item, array_flip($allowedRelatedCols));
                unset($relatedFields['id'], $relatedFields['created_at'], $relatedFields['updated_at'], $relatedFields['deleted_at']);

                if (!RecordUtils::isTenantIdMissing($childTenant) && isset($relatedSchema->columns[RecordConfigService::tenantColumn()])) {
                    $relatedFields[RecordConfigService::tenantColumn()] = $childTenant;
                } elseif ($tenantId && isset($relatedSchema->columns[RecordConfigService::tenantColumn()])) {
                    $relatedFields[RecordConfigService::tenantColumn()] = $tenantId;
                }

                if (isset($relatedSchema->columns['created_at'])) {
                    $relatedFields['created_at'] = TimeUtils::now();
                }

                if (isset($relatedSchema->columns['updated_at'])) {
                    $relatedFields['updated_at'] = TimeUtils::now();
                }

                $relatedId = DB::table($actualRelatedTableName)->insertGetId($relatedFields);
            } else {
                self::assertRelatedRowVisible($table, $alias, $relatedTable, $actualRelatedTableName, $relatedPk, $relatedId, $childTenant, $schema);
            }

            $pivotData = [];
            $pivotFields = $config['with_pivot'] ?? [];

            foreach ($pivotFields as $field) {
                if (array_key_exists($field, $item)) {
                    $pivotData[$field] = $item[$field];
                }
            }

            $exists = DB::table($pivotTable)
                ->where($foreignPivotKey, $mainId)
                ->where($relatedPivotKey, $relatedId)
                ->exists();

            if ($exists) {
                if ([] === $pivotData) {
                    // Already linked, nothing to change — a no-op regardless of allowUpdate.
                    continue;
                }

                self::assertRelationshipOperationAllowed($table, $alias, 'update', $allowUpdate);

                if (($config['with_timestamps'] ?? false)) {
                    $pivotData['updated_at'] = TimeUtils::now();
                }

                DB::table($pivotTable)
                    ->where($foreignPivotKey, $mainId)
                    ->where($relatedPivotKey, $relatedId)
                    ->update($pivotData);

                continue;
            }

            self::assertRelationshipOperationAllowed($table, $alias, 'create', $allowCreate);

            $pivotData[$foreignPivotKey] = $mainId;
            $pivotData[$relatedPivotKey] = $relatedId;
            // Polymorphic pivots (morphToMany, e.g. sp_model_has_roles) have a
            // NOT NULL discriminator column identifying which model table
            // $mainId belongs to. resolveRelationship() already computes the
            // model class for this ('relation', e.g. 'App\Models\User') —
            // the client payload has no way to supply it and shouldn't need
            // to, since it's implied entirely by which endpoint was called.
            if (isset($config['morph_type']) && !array_key_exists($config['morph_type'], $pivotData)) {
                $pivotData[$config['morph_type']] = $config['relation'] ?? null;
            }
            if (($config['with_timestamps'] ?? false)) {
                $pivotData['created_at'] = TimeUtils::now();
                $pivotData['updated_at'] = TimeUtils::now();
            }

            // Pivot tables created via MigrationIdHelper::primary() (e.g.
            // sp_model_has_roles) have their own uuid primary key with no
            // database default — same reasoning as RecordService::createRecord():
            // an insert with no id violates the NOT NULL constraint. Gated on
            // the column actually existing so this is a no-op for ordinary
            // two-column pivot tables that only have a composite key.
            if (
                !array_key_exists('id', $pivotData)
                && 'uuid' === RecordConfigService::idType()
                && Schema::hasColumn($pivotTable, 'id')
            ) {
                $pivotData['id'] = (string) Str::uuid();
            }

            DB::table($pivotTable)->insert($pivotData);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function processHasManyThroughOperation(string $table, string $alias, array $data, mixed $mainId, array $config, array $schema, mixed $tenantId, bool $allowCreate, bool $allowUpdate, bool $allowDelete): void
    {
        $throughTable = $config['through_table'];
        $firstKey = $config['first_key'];
        $secondLocalKey = $config['second_local_key'];
        $ownerColumn = $config['owner_column'] ?? null;
        $ownerValue = $config['owner_value'] ?? null;
        $targetColumn = $config['target_column'] ?? null;
        $targetValue = $config['target_value'] ?? null;
        $targetTable = $config['table'];
        $targetSchema = $schema[$targetTable] ?? null;
        $targetPk = $targetSchema->primaryKey ?? 'id';
        $actualTargetTableName = $schema[$targetTable]->table ?? $targetTable;
        $childTenant = self::childTenant($tenantId);

        foreach ($data as $item) {
            $item = self::normalizeNestedItem($item, $table, $alias, $targetPk, true);

            $isDelete = ($item['_delete'] ?? false) || ($item['_destroy'] ?? false);
            $targetId = $item[$targetPk] ?? null;

            if ($isDelete) {
                if (!$targetId) {
                    throw new InvalidArgumentException(sprintf(
                        "Cannot delete item in relationship '%s' for table '%s': '_delete' requires the related '%s' primary key.",
                        $alias,
                        $table,
                        $targetPk
                    ));
                }

                self::assertRelationshipOperationAllowed($table, $alias, 'delete', $allowDelete);

                $deleteQuery = DB::table($throughTable)
                    ->where($firstKey, $mainId)
                    ->where($secondLocalKey, $targetId);

                if ($ownerColumn && null !== $ownerValue) {
                    $deleteQuery->where($ownerColumn, $ownerValue);
                }

                if ($targetColumn && null !== $targetValue) {
                    $deleteQuery->where($targetColumn, $targetValue);
                }

                $deleteQuery->delete();

                continue;
            }

            self::assertChildTenantResolved($table, $alias, $targetSchema, $childTenant);

            if (!$targetId) {
                self::assertRelationshipOperationAllowed($table, $alias, 'create', $allowCreate);
                NestedWriteAuthorizer::authorizeChild($table, $alias, $targetTable, 'create');

                $targetFields = array_intersect_key($item, array_flip(array_keys($targetSchema->columns ?? [])));
                $writeDisabled = is_array($targetSchema->columnWriteDisabled ?? null) ? $targetSchema->columnWriteDisabled : [];
                if ([] !== $writeDisabled) {
                    $targetFields = array_diff_key($targetFields, array_flip($writeDisabled));
                }

                unset($targetFields['id'], $targetFields['created_at'], $targetFields['updated_at'], $targetFields['deleted_at']);

                if (!RecordUtils::isTenantIdMissing($childTenant) && isset($targetSchema->columns[RecordConfigService::tenantColumn()])) {
                    $targetFields[RecordConfigService::tenantColumn()] = $childTenant;
                } elseif ($tenantId && isset($targetSchema->columns[RecordConfigService::tenantColumn()])) {
                    $targetFields[RecordConfigService::tenantColumn()] = $tenantId;
                }

                if (isset($targetSchema->columns['created_at'])) {
                    $targetFields['created_at'] = TimeUtils::now();
                }

                if (isset($targetSchema->columns['updated_at'])) {
                    $targetFields['updated_at'] = TimeUtils::now();
                }

                $targetId = DB::table($actualTargetTableName)->insertGetId($targetFields);
            } else {
                self::assertRelatedRowVisible($table, $alias, $targetTable, $actualTargetTableName, $targetPk, $targetId, $childTenant, $schema);
            }

            $existsQuery = DB::table($throughTable)
                ->where($firstKey, $mainId)
                ->where($secondLocalKey, $targetId);

            if ($ownerColumn && null !== $ownerValue) {
                $existsQuery->where($ownerColumn, $ownerValue);
            }

            if ($targetColumn && null !== $targetValue) {
                $existsQuery->where($targetColumn, $targetValue);
            }

            if ($existsQuery->exists()) {
                // Already linked; this relationship type has no extra link-table fields
                // to update, so there is nothing left to do — a no-op regardless of allowUpdate.
                continue;
            }

            self::assertRelationshipOperationAllowed($table, $alias, 'create', $allowCreate);

            $insertData = [
                $firstKey => $mainId,
                $secondLocalKey => $targetId,
            ];

            if ($ownerColumn && null !== $ownerValue) {
                $insertData[$ownerColumn] = $ownerValue;
            }

            if ($targetColumn && null !== $targetValue) {
                $insertData[$targetColumn] = $targetValue;
            }

            DB::table($throughTable)->insert($insertData);
        }
    }

    /**
     * Strip relationship data from payload before main table insert/update using dynamic inference.
     */
    public static function stripRelationshipData(string $table, array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (!is_array($value) && !is_object($value)) {
                continue;
            }

            $config = self::resolveRelationship($table, (string) $key);
            if (!$config) {
                continue;
            }

            unset($payload[$key]);
        }

        return $payload;
    }

    /**
     * Parse select parameter into nested include structure.
     */
    public static function parseSelectForIncludes(string $selectParam): array
    {
        $segments = self::parseSelectSegments($selectParam);
        $includes = [];

        foreach ($segments as $segment) {
            $segment = trim((string) $segment);
            if ('' === $segment) {
                continue;
            }

            if ('*' === $segment) {
                continue;
            }

            if (str_starts_with($segment, 'with=')) {
                $segment = substr($segment, 5);
            }

            // alias:table(inner)
            if (preg_match('/^(\w+):(\w+)\((.*)\)$/', $segment, $matches)) {
                $alias = $matches[1];
                $table = $matches[2];
                $inner = trim($matches[3]);

                $columns = [];
                $children = [];
                if ('' !== $inner) {
                    $innerSegs = self::parseSelectSegments($inner);
                    foreach ($innerSegs as $innerSeg) {
                        $innerSeg = trim((string) $innerSeg);

                        if (str_starts_with($innerSeg, 'with=')) {
                            $innerSeg = substr($innerSeg, 5);
                        }

                        if ('*' === $innerSeg) {
                            $columns[] = '*';

                            continue;
                        }

                        if (
                            preg_match('/^(\w+):(\w+)\((.*)\)$/', $innerSeg)
                            || preg_match('/^(\w+)\((.*)\)$/', $innerSeg)
                        ) {
                            $child = self::parseSelectForIncludes($innerSeg);
                            $children = array_merge($children, $child);
                        } else {
                            $columns[] = $innerSeg;
                        }
                    }
                }

                if ([] === $columns) {
                    $columns = ['*'];
                }

                $includes[$alias] = ['table' => $table, 'columns' => $columns, 'children' => $children];

                continue;
            }

            // alias(inner)
            if (preg_match('/^(\w+)\((.*)\)$/', $segment, $matches)) {
                $alias = $matches[1];
                $inner = trim($matches[2]);

                $columns = [];
                $children = [];
                if ('' !== $inner) {
                    $innerSegs = self::parseSelectSegments($inner);
                    foreach ($innerSegs as $innerSeg) {
                        $innerSeg = trim((string) $innerSeg);

                        if (str_starts_with($innerSeg, 'with=')) {
                            $innerSeg = substr($innerSeg, 5);
                        }

                        if ('*' === $innerSeg) {
                            $columns[] = '*';

                            continue;
                        }

                        if (
                            preg_match('/^(\w+):(\w+)\((.*)\)$/', $innerSeg)
                            || preg_match('/^(\w+)\((.*)\)$/', $innerSeg)
                        ) {
                            $child = self::parseSelectForIncludes($innerSeg);
                            $children = array_merge($children, $child);
                        } else {
                            $columns[] = $innerSeg;
                        }
                    }
                }

                if ([] === $columns) {
                    $columns = ['*'];
                }

                $includes[$alias] = ['table' => null, 'columns' => $columns, 'children' => $children];

                continue;
            }

            // Skip simple column names without parentheses - these are main table columns, not relationships
            // Only process segments that contain parentheses (actual relationships)
        }

        return $includes;
    }

    /**
     * Generate a safe alias that doesn't conflict with main table columns.
     * @param int[]|string[] $mainTableColumns
     */
    private static function generateSafeAlias(string $alias, array $mainTableColumns): string
    {
        // If alias doesn't conflict with main table columns, use it as is
        if (!in_array($alias, $mainTableColumns, true)) {
            return $alias;
        }

        // Generate a unique alias by appending suffix
        $counter = 1;
        $safeAlias = $alias . '_rel';

        while (in_array($safeAlias, $mainTableColumns, true)) {
            $safeAlias = $alias . '_rel_' . $counter;
            ++$counter;
        }

        return $safeAlias;
    }

    /**
     * Add belongsTo relationship subquery.
     * @param array<string, mixed> $config
     */
    private static function addBelongsToSubquery(Builder $builder, string $table, string $alias, array $config, array $columns, mixed $tenantId, array $schema): Builder
    {
        $relatedTable = $config['table'];
        $foreignKey = $config['foreign_key'];
        $ownerKey = $config['owner_key'] ?? 'id';
        $enableTenantId = RecordConfigService::enableTenantId();
        $driver = DB::getDriverName();

        // Get actual table names from schema
        $actualMainTableName = $schema[$table]->table ?? $table;
        $actualRelatedTableName = $schema[$relatedTable]->table ?? $relatedTable;

        // Use alias for subquery to avoid conflicts when main table = related table
        $subAlias = $actualRelatedTableName === $actualMainTableName ? $actualRelatedTableName . '_sub' : $actualRelatedTableName;

        // Build column refs for the inner SELECT
        $validColumns = self::resolveJsonObjectColumns($columns, $schema[$relatedTable]->columns ?? [], $relatedTable);
        if ([] === $validColumns) {
            $validColumns = ['id'];
        }

        $innerCols = [];
        foreach ($validColumns as $col) {
            $innerCols[] = sprintf('%s.%s AS %s', $subAlias, $col, $col);
        }

        $innerSelect = implode(', ', $innerCols);

        // Build the inner query: SELECT cols FROM related AS alias WHERE correlation AND filters LIMIT 1
        $innerSql = sprintf('SELECT %s FROM %s AS %s', $innerSelect, $actualRelatedTableName, $subAlias)
                   . sprintf(' WHERE %s.%s = %s.%s', $subAlias, $ownerKey, $actualMainTableName, $foreignKey);

        $scopeSql = '';
        $scopeBindings = [];
        $tenantCol = RecordConfigService::tenantColumn();
        if ($enableTenantId && $tenantId && isset($schema[$relatedTable]->columns[$tenantCol])) {
            $scopeSql .= sprintf(' AND %s.%s = ?', $subAlias, $tenantCol);
            $scopeBindings[] = $tenantId;
        }

        // Own-records scope on the related table: a viewOwn user must not see
        // another user's row just because it is embedded in a child.
        [$ownSql, $ownBindings] = OwnRecordsScope::sqlCondition($relatedTable, $subAlias);
        $scopeSql .= $ownSql;
        array_push($scopeBindings, ...$ownBindings);

        $innerSql .= $scopeSql;

        if ($schema[$relatedTable]->softDeletes ?? false) {
            $innerSql .= sprintf(' AND %s.deleted_at IS NULL', $subAlias);
        }

        $innerSql .= ' LIMIT 1';

        // Wrap in a single-level scalar subquery: (SELECT row_to_json(__sp_obj) FROM (inner) AS __sp_obj)
        if ('pgsql' === $driver) {
            $rawSql = sprintf('(SELECT row_to_json(__sp_obj) FROM (%s) AS __sp_obj) AS "%s"', $innerSql, $alias);
        } elseif ('sqlite' === $driver) {
            $jsonPairs = [];
            foreach ($validColumns as $col) {
                $jsonPairs[] = sprintf("'%s', %s.%s", $col, $subAlias, $col);
            }

            $rawSql = "(SELECT json_object(" . implode(', ', $jsonPairs) . sprintf(') FROM %s AS %s', $actualRelatedTableName, $subAlias)
                    . sprintf(' WHERE %s.%s = %s.%s', $subAlias, $ownerKey, $actualMainTableName, $foreignKey);

            $rawSql .= $scopeSql;

            if ($schema[$relatedTable]->softDeletes ?? false) {
                $rawSql .= sprintf(' AND %s.deleted_at IS NULL', $subAlias);
            }

            $rawSql .= sprintf(' LIMIT 1) AS "%s"', $alias);
        } else {
            $jsonPairs = [];
            foreach ($validColumns as $col) {
                $jsonPairs[] = sprintf("'%s', %s.%s", $col, $subAlias, $col);
            }

            $rawSql = "(SELECT JSON_OBJECT(" . implode(', ', $jsonPairs) . sprintf(') FROM %s AS %s', $actualRelatedTableName, $subAlias)
                    . sprintf(' WHERE %s.%s = %s.%s', $subAlias, $ownerKey, $actualMainTableName, $foreignKey);

            $rawSql .= $scopeSql;

            if ($schema[$relatedTable]->softDeletes ?? false) {
                $rawSql .= sprintf(' AND %s.deleted_at IS NULL', $subAlias);
            }

            $rawSql .= sprintf(' LIMIT 1) AS "%s"', $alias);
        }

        $builder->selectRaw($rawSql, $scopeBindings);

        return $builder;
    }

    /**
     * Add hasMany relationship subquery with JSON array aggregation.
     * @param array<string, mixed> $config
     */
    private static function addHasManySubquery(Builder $builder, string $table, string $alias, array $config, array $columns, mixed $tenantId, array $schema): Builder
    {
        $bindings = [];
        $relatedTable = $config['table'];
        $foreignKey = $config['foreign_key'];
        $localKey = $config['local_key'] ?? 'id';
        $enableTenantId = RecordConfigService::enableTenantId();

        // Get actual table names from schema
        $actualMainTableName = $schema[$table]->table ?? $table;
        $actualRelatedTableName = $schema[$relatedTable]->table ?? $relatedTable;

        // Build column selection for JSON object
        $jsonArrayAggExpr = self::buildJsonArrayAggExpression($columns, $schema[$relatedTable]->columns ?? [], $actualRelatedTableName, $relatedTable);

        // Build the JSON array aggregation subquery
        $subqueryRaw = "(
            SELECT {$jsonArrayAggExpr}
            FROM {$actualRelatedTableName}
            WHERE {$actualRelatedTableName}.{$foreignKey} = {$actualMainTableName}.{$localKey}";

        // Add tenant filtering if enabled
        $tenantCol = RecordConfigService::tenantColumn();
        if ($enableTenantId && $tenantId && isset($schema[$relatedTable]->columns[$tenantCol])) {
            $subqueryRaw .= sprintf(' AND %s.%s = ?', $actualRelatedTableName, $tenantCol);
            $bindings[] = $tenantId;
        }

        // Own-records scope on the related table: a viewOwn user must not see
        // another user's row just because it is embedded in a parent.
        [$ownSql, $ownBindings] = OwnRecordsScope::sqlCondition($relatedTable, $actualRelatedTableName);
        $subqueryRaw .= $ownSql;
        array_push($bindings, ...$ownBindings);

        // Add soft delete filtering
        if ($schema[$relatedTable]->softDeletes ?? false) {
            $subqueryRaw .= sprintf(' AND %s.deleted_at IS NULL', $actualRelatedTableName);
        }

        $subqueryRaw .= '
        )';

        $builder->selectRaw(sprintf('%s as %s', $subqueryRaw, $alias), $bindings);

        return $builder;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function addMorphManySubquery(Builder $builder, string $table, string $alias, array $config, array $columns, mixed $tenantId, array $schema): Builder
    {
        $bindings = [];
        $relatedTable = $config['table'];
        $morphType = $config['morph_type'];
        $morphId = $config['morph_id'];
        $morphClass = $config['morph_class'];
        $localKey = $config['local_key'] ?? 'id';
        $enableTenantId = RecordConfigService::enableTenantId();

        // Get actual table names from schema
        $actualMainTableName = $schema[$table]->table ?? $table;
        $actualRelatedTableName = $schema[$relatedTable]->table ?? $relatedTable;

        // Build column selection for JSON object
        $jsonArrayAggExpr = self::buildJsonArrayAggExpression($columns, $schema[$relatedTable]->columns ?? [], $actualRelatedTableName, $relatedTable);

        // Build the JSON array aggregation subquery
        $subqueryRaw = "(
            SELECT {$jsonArrayAggExpr}
            FROM {$actualRelatedTableName}
            WHERE {$actualRelatedTableName}.{$morphType} = " . DB::getPdo()->quote($morphClass) . "
              AND {$actualRelatedTableName}.{$morphId} = {$actualMainTableName}.{$localKey}";

        // Add tenant filtering if enabled
        $tenantCol = RecordConfigService::tenantColumn();
        if ($enableTenantId && $tenantId && isset($schema[$relatedTable]->columns[$tenantCol])) {
            $subqueryRaw .= sprintf(' AND %s.%s = ?', $actualRelatedTableName, $tenantCol);
            $bindings[] = $tenantId;
        }

        // Own-records scope on the related table: a viewOwn user must not see
        // another user's row just because it is embedded in a parent.
        [$ownSql, $ownBindings] = OwnRecordsScope::sqlCondition($relatedTable, $actualRelatedTableName);
        $subqueryRaw .= $ownSql;
        array_push($bindings, ...$ownBindings);

        // Add soft delete filtering
        if ($schema[$relatedTable]->softDeletes ?? false) {
            $subqueryRaw .= sprintf(' AND %s.deleted_at IS NULL', $actualRelatedTableName);
        }

        $subqueryRaw .= '
        )';

        $builder->selectRaw(sprintf('%s as %s', $subqueryRaw, $alias), $bindings);

        return $builder;
    }

    /**
     * Add belongsToMany relationship subquery with JSON array aggregation.
     * Handles many-to-many relationships through pivot tables.
     * @param array<string, mixed> $config
     */
    private static function addBelongsToManySubquery(Builder $builder, string $table, string $alias, array $config, array $columns, mixed $tenantId, array $schema): Builder
    {
        $bindings = [];
        $relatedTable = $config['table'];
        $pivotTable = $config['pivot_table'];
        $foreignPivotKey = $config['foreign_pivot_key'];
        $relatedPivotKey = $config['related_pivot_key'];
        $parentKey = $config['parent_key'] ?? 'id';
        $relatedKey = $config['related_key'] ?? 'id';
        $relation = $config['relation'] ?? null;
        $wherePivot = $config['where_pivot'] ?? [];
        $enableTenantId = RecordConfigService::enableTenantId();

        // Get actual table names from schema
        $actualMainTableName = $schema[$table]->table ?? $table;
        $actualRelatedTableName = $schema[$relatedTable]->table ?? $relatedTable;
        $actualPivotTableName = $schema[$pivotTable]->table ?? $pivotTable;

        // Build column selection for JSON object
        $jsonArrayAggExpr = self::buildJsonArrayAggExpression($columns, $schema[$relatedTable]->columns ?? [], $actualRelatedTableName, $relatedTable);

        // Build the JSON array aggregation subquery for many-to-many
        $subqueryRaw = "(
            SELECT {$jsonArrayAggExpr}
            FROM {$actualRelatedTableName}
            INNER JOIN {$actualPivotTableName} ON {$actualPivotTableName}.{$relatedPivotKey} = {$actualRelatedTableName}.{$relatedKey}
            WHERE {$actualPivotTableName}.{$foreignPivotKey} = {$actualMainTableName}.{$parentKey}";

        // Handle morph relationships
        if ($relation) {
            $subqueryRaw .= sprintf(" AND %s.model_type = '%s'", $actualPivotTableName, $relation);
        }

        // Apply pivot where conditions
        foreach ($wherePivot as $condition) {
            if (is_array($condition) && isset($condition['column'], $condition['operator'], $condition['value'])) {
                $column = $condition['column'];
                $operator = $condition['operator'];
                $value = is_string($condition['value']) ? sprintf("'%s'", $condition['value']) : $condition['value'];
                $subqueryRaw .= sprintf(' AND %s.%s %s %s', $actualPivotTableName, $column, $operator, $value);
            }
        }

        // Add tenant filtering if enabled
        if ($enableTenantId && $tenantId) {
            $tenantCol = RecordConfigService::tenantColumn();
            if (isset($schema[$relatedTable]->columns[$tenantCol])) {
                $subqueryRaw .= sprintf(' AND %s.%s = ?', $actualRelatedTableName, $tenantCol);
                $bindings[] = $tenantId;
            }

            if (isset($schema[$pivotTable]->columns[$tenantCol])) {
                $subqueryRaw .= sprintf(' AND %s.%s = ?', $actualPivotTableName, $tenantCol);
                $bindings[] = $tenantId;
            } elseif (Schema::hasColumn($actualPivotTableName, $tenantCol)) {
                $subqueryRaw .= sprintf(' AND %s.%s = ?', $actualPivotTableName, $tenantCol);
                $bindings[] = $tenantId;
            }
        }

        // Own-records scope on the related table: a viewOwn user must not see
        // another user's row just because it is embedded in a parent.
        [$ownSql, $ownBindings] = OwnRecordsScope::sqlCondition($relatedTable, $actualRelatedTableName);
        $subqueryRaw .= $ownSql;
        array_push($bindings, ...$ownBindings);

        // Add soft delete filtering
        if ($schema[$relatedTable]->softDeletes ?? false) {
            $subqueryRaw .= sprintf(' AND %s.deleted_at IS NULL', $actualRelatedTableName);
        }

        if ($schema[$pivotTable]->softDeletes ?? false) {
            $subqueryRaw .= sprintf(' AND %s.deleted_at IS NULL', $actualPivotTableName);
        }

        $subqueryRaw .= '
        )';

        $builder->selectRaw(sprintf('%s as %s', $subqueryRaw, $alias), $bindings);

        return $builder;
    }

    /**
     * Add morphToMany relationship subquery with JSON array aggregation.
     * @param array<string, mixed> $config
     */
    private static function addMorphToManySubquery(Builder $builder, string $table, string $alias, array $config, array $columns, mixed $tenantId, array $schema): Builder
    {
        $bindings = [];
        $relatedTable = $config['table'];
        $pivotTable = $config['pivot_table'];
        $foreignPivotKey = $config['foreign_pivot_key']; // e.g., model_id
        $relatedPivotKey = $config['related_pivot_key']; // e.g., role_id
        $parentKey = $config['parent_key'] ?? 'id';
        $relatedKey = $config['related_key'] ?? 'id';
        $morphTypeColumn = $config['morph_type'] ?? 'model_type';
        $relation = $config['relation'] ?? null; // expected to be FQCN (e.g., App\\Models\\User)
        $wherePivot = $config['where_pivot'] ?? [];
        $enableTenantId = RecordConfigService::enableTenantId();

        // Determine model class fallback if relation is missing or not a FQCN
        if (!$relation || $relation === 'model') {
            // Derive FQCN from main table name as a sensible default (users -> App\Models\User)
            $relation = 'App\\Models\\' . Str::studly(Str::singular($table));
        }

        // Get actual table names from schema
        $actualMainTableName = $schema[$table]->table ?? $table;
        $actualRelatedTableName = $schema[$relatedTable]->table ?? $relatedTable;
        $actualPivotTableName = $schema[$pivotTable]->table ?? $pivotTable;

        // Build column selection for JSON object
        $jsonArrayAggExpr = self::buildJsonArrayAggExpression($columns, $schema[$relatedTable]->columns ?? [], $actualRelatedTableName, $relatedTable);

        // Build the JSON array aggregation subquery for morph-to-many
        // Use DB::raw with parameter binding to handle model_type correctly
        $subqueryRaw = "(
            SELECT {$jsonArrayAggExpr}
            FROM {$actualRelatedTableName}
            INNER JOIN {$actualPivotTableName} ON {$actualPivotTableName}.{$relatedPivotKey} = {$actualRelatedTableName}.{$relatedKey}
            WHERE {$actualPivotTableName}.{$foreignPivotKey} = {$actualMainTableName}.{$parentKey}
              AND {$actualPivotTableName}.{$morphTypeColumn} = '" . addslashes((string) $relation) . "'";

        // Apply pivot where conditions (e.g., team scoping, guards)
        foreach ($wherePivot as $condition) {
            if (is_array($condition) && isset($condition['column'], $condition['operator'], $condition['value'])) {
                $column = $condition['column'];
                $operator = $condition['operator'];
                $value = is_string($condition['value']) ? sprintf("'%s'", $condition['value']) : $condition['value'];
                $subqueryRaw .= sprintf(' AND %s.%s %s %s', $actualPivotTableName, $column, $operator, $value);
            }
        }

        // Add tenant filtering if enabled
        if ($enableTenantId && $tenantId) {
            $tenantCol = RecordConfigService::tenantColumn();
            if (isset($schema[$relatedTable]->columns[$tenantCol])) {
                $subqueryRaw .= sprintf(' AND %s.%s = ?', $actualRelatedTableName, $tenantCol);
                $bindings[] = $tenantId;
            }

            if (isset($schema[$pivotTable]->columns[$tenantCol])) {
                $subqueryRaw .= sprintf(' AND %s.%s = ?', $actualPivotTableName, $tenantCol);
                $bindings[] = $tenantId;
            } elseif (Schema::hasColumn($actualPivotTableName, $tenantCol)) {
                $subqueryRaw .= sprintf(' AND %s.%s = ?', $actualPivotTableName, $tenantCol);
                $bindings[] = $tenantId;
            }
        }

        // Own-records scope on the related table: a viewOwn user must not see
        // another user's row just because it is embedded in a parent.
        [$ownSql, $ownBindings] = OwnRecordsScope::sqlCondition($relatedTable, $actualRelatedTableName);
        $subqueryRaw .= $ownSql;
        array_push($bindings, ...$ownBindings);

        // Add soft delete filtering
        if ($schema[$relatedTable]->softDeletes ?? false) {
            $subqueryRaw .= sprintf(' AND %s.deleted_at IS NULL', $actualRelatedTableName);
        }

        if ($schema[$pivotTable]->softDeletes ?? false) {
            $subqueryRaw .= sprintf(' AND %s.deleted_at IS NULL', $actualPivotTableName);
        }

        $subqueryRaw .= '
        )';

        $builder->selectRaw(sprintf('%s as %s', $subqueryRaw, $alias), $bindings);

        return $builder;
    }

    /**
     * Add hasManyThrough relationship subquery with JSON array aggregation.
     * @param array<string, mixed> $config
     */
    private static function addHasManyThroughSubquery(Builder $builder, string $table, string $alias, array $config, array $columns, mixed $tenantId, array $schema): Builder
    {
        $bindings = [];
        $relatedTable = $config['table'];
        $throughTable = $config['through_table'];
        $firstKey = $config['first_key'];
        $secondKey = $config['second_key'];
        $localKey = $config['local_key'] ?? 'id';
        $secondLocalKey = $config['second_local_key'] ?? 'id';
        $enableTenantId = RecordConfigService::enableTenantId();

        // Get actual table names from schema
        $actualMainTableName = $schema[$table]->table ?? $table;
        $actualRelatedTableName = $schema[$relatedTable]->table ?? $relatedTable;
        $actualThroughTableName = $schema[$throughTable]->table ?? $throughTable;

        // Build column selection for JSON object
        $jsonArrayAggExpr = self::buildJsonArrayAggExpression($columns, $schema[$relatedTable]->columns ?? [], $actualRelatedTableName, $relatedTable);

        // Build the JSON array aggregation subquery with join
        $subqueryRaw = "(
            SELECT {$jsonArrayAggExpr}
            FROM {$actualRelatedTableName}
            INNER JOIN {$actualThroughTableName} ON {$actualThroughTableName}.{$secondLocalKey} = {$actualRelatedTableName}.{$secondKey}
            WHERE {$actualThroughTableName}.{$firstKey} = {$actualMainTableName}.{$localKey}";

        // Add tenant filtering if enabled
        if ($enableTenantId && $tenantId) {
            $tenantCol = RecordConfigService::tenantColumn();
            if (isset($schema[$relatedTable]->columns[$tenantCol])) {
                $subqueryRaw .= sprintf(' AND %s.%s = ?', $actualRelatedTableName, $tenantCol);
                $bindings[] = $tenantId;
            }

            if (isset($schema[$throughTable]->columns[$tenantCol])) {
                $subqueryRaw .= sprintf(' AND %s.%s = ?', $actualThroughTableName, $tenantCol);
                $bindings[] = $tenantId;
            }
        }

        // Own-records scope on the related table: a viewOwn user must not see
        // another user's row just because it is embedded in a parent.
        [$ownSql, $ownBindings] = OwnRecordsScope::sqlCondition($relatedTable, $actualRelatedTableName);
        $subqueryRaw .= $ownSql;
        array_push($bindings, ...$ownBindings);

        // Add soft delete filtering
        if ($schema[$relatedTable]->softDeletes ?? false) {
            $subqueryRaw .= sprintf(' AND %s.deleted_at IS NULL', $actualRelatedTableName);
        }

        if ($schema[$throughTable]->softDeletes ?? false) {
            $subqueryRaw .= sprintf(' AND %s.deleted_at IS NULL', $actualThroughTableName);
        }

        $subqueryRaw .= '
        )';

        $builder->selectRaw(sprintf('%s as %s', $subqueryRaw, $alias), $bindings);

        return $builder;
    }

    /**
     * Resolve and validate JSON object columns for subqueries.
     */
    private static function resolveJsonObjectColumns(array $columns, array $schemaColumns, string $relatedTable): array
    {
        if ($columns === ['*'] || [] === $columns) {
            $columns = array_keys($schemaColumns);
            $relatedConfig = self::getSchema()[$relatedTable] ?? SchemaRegistryUtils::getTable($relatedTable) ?? SchemaRegistryUtils::resolveTableSchema($relatedTable);
            $hiddenCols = $relatedConfig?->columnHiddens ?? [];
            if (!empty($hiddenCols)) {
                $columns = array_values(array_diff($columns, $hiddenCols));
            }
        }

        // Validate columns against schema
        foreach ($columns as $column) {
            if ('*' === $column || isset($schemaColumns[$column])) {
                continue;
            }

            $validNames = array_keys($schemaColumns);
            sort($validNames);
            throw new InvalidArgumentException(sprintf(
                "Unknown column '%s' in select for table '%s'. Valid columns: %s.",
                $column,
                $relatedTable,
                [] === $validNames ? 'none' : implode(', ', $validNames)
            ));
        }

        $validColumns = array_filter($columns, fn($column): bool => isset($schemaColumns[$column]));

        // Remove tenant_id if it's not enabled in configuration
        $enableTenantId = RecordConfigService::enableTenantId();
        if (!$enableTenantId) {
            $tenantCol = RecordConfigService::tenantColumn();
            $validColumns = array_filter($validColumns, fn($column): bool => $tenantCol !== $column);
        }

        return array_values($validColumns);
    }

    /**
     * Build database-specific JSON object expression from requested columns.
     */
    private static function buildJsonObjectExpression(array $columns, array $schemaColumns, string $tableName, string $relatedTable): string
    {
        $driver = DB::getDriverName();
        $validColumns = self::resolveJsonObjectColumns($columns, $schemaColumns, $relatedTable);
        if ([] === $validColumns) {
            $validColumns = ['id'];
        }

        if ('pgsql' === $driver) {
            // PostgreSQL limits function calls to 100 args. Build JSON from a row instead.
            $rowColumns = [];
            foreach ($validColumns as $validColumn) {
                $columnRef = $tableName !== '' && $tableName !== '0' ? sprintf('%s.%s', $tableName, $validColumn) : $validColumn;
                $rowColumns[] = sprintf('%s as %s', $columnRef, $validColumn);
            }

            return sprintf('(select row_to_json(__sp_obj) from (select %s) as __sp_obj)', implode(', ', $rowColumns));
        }

        $jsonPairs = [];
        foreach ($validColumns as $validColumn) {
            $columnRef = $tableName !== '' && $tableName !== '0' ? sprintf('%s.%s', $tableName, $validColumn) : $validColumn;
            $jsonPairs[] = sprintf("'%s', %s", $validColumn, $columnRef);
        }

        $jsonColumns = implode(', ', $jsonPairs);

        return 'sqlite' === $driver
            ? sprintf('json_object(%s)', $jsonColumns)
            : sprintf('JSON_OBJECT(%s)', $jsonColumns);
    }

    /**
     * Build database-specific JSON array aggregation expression of JSON objects.
     */
    private static function buildJsonArrayAggExpression(array $columns, array $schemaColumns, string $tableName, string $relatedTable): string
    {
        $jsonObjectExpr = self::buildJsonObjectExpression($columns, $schemaColumns, $tableName, $relatedTable);
        $driver = DB::getDriverName();

        return match ($driver) {
            'pgsql' => sprintf('json_agg(%s)', $jsonObjectExpr),
            'sqlite' => sprintf('json_group_array(%s)', $jsonObjectExpr),
            default => sprintf('JSON_ARRAYAGG(%s)', $jsonObjectExpr),
        };
    }

    /**
     * Get cached schema registry to avoid multiple SchemaRegistryUtils::get() calls.
     *
     * @return array The schema registry data
     */
    private static function getSchema(): array
    {
        if (null === self::$schemaCache) {
            self::$schemaCache = SchemaRegistryUtils::get();
        }

        return self::$schemaCache;
    }

    /**
     * Parse select segments, respecting parentheses nesting.
     * @return string[]
     */
    private static function parseSelectSegments(string $selectParam): array
    {
        $segments = [];
        $current = '';
        $depth = 0;
        $len = strlen($selectParam);

        for ($i = 0; $i < $len; ++$i) {
            $char = $selectParam[$i];
            if ('(' === $char) {
                ++$depth;
                $current .= $char;
            } elseif (')' === $char) {
                --$depth;
                $current .= $char;
            } elseif (',' === $char && 0 === $depth) {
                $segments[] = trim($current);
                $current = '';
            } else {
                $current .= $char;
            }
        }

        if ('' !== $current) {
            $segments[] = trim($current);
        }

        return $segments;
    }

    /**
     * Load related records based on relationship configuration.
     * Heavily optimized to eliminate N+1 queries using advanced bulk loading strategies.
     *
     * Performance optimizations:
     * - Single bulk query per relationship type
     * - Efficient memory management with early cleanup
     * - Optimized array operations and indexing
     * - Chunked processing for large datasets
     * - Smart caching of intermediate results
     *
     * @param null|mixed $tenantId
     * @param array<string, mixed> $config
     */
    private static function loadRelatedRecords(array $records, array $config, array $columns, $tenantId = null): array
    {
        $schema = self::getSchema();
        $type = $config['type'];
        $relatedTable = $config['table'];
        $foreignKey = $config['foreign_key'] ?? null;
        $localKey = $config['local_key'] ?? 'id';
        $ownerKey = $config['owner_key'] ?? 'id';

        // Early validation and security check
        if (!isset($schema[$relatedTable])) {
            // Try to resolve schema dynamically if not found (e.g. for implicit relationships)
            $resolved = SchemaRegistryUtils::resolveTableSchema($relatedTable);
            if ($resolved !== null) {
                $schema[$relatedTable] = $resolved;
            } else {
                return [];
            }
        }

        // Optimized value extraction with type-specific logic
        $matchValues = [];
        $recordCount = count($records);

        // Pre-allocate array for better memory efficiency
        $matchValues = array_fill(0, $recordCount, null);
        $validCount = 0;

        foreach ($records as $record) {
            $value = null;

            // Handle both array and object records properly
            if (is_array($record)) {
                $recordArray = $record;
            } else {
                // For Laravel models, use getAttributes() to get the actual data
                $recordArray = method_exists($record, 'getAttributes') ? $record->getAttributes() : (array) $record;
            }

            switch ($type) {
                case 'belongsTo':
                    $value = $recordArray[$foreignKey] ?? null;

                    break;

                case 'hasMany':
                case 'hasManyThrough':
                case 'morphMany':
                    $value = $recordArray[$localKey] ?? null;

                    break;

                case 'belongsToMany':
                case 'morphToMany':
                    $parentKey = $config['parent_key'] ?? 'id';
                    $value = $recordArray[$parentKey] ?? null;

                    break;
            }

            if (null !== $value) {
                $matchValues[$validCount++] = $value;
            }
        }

        // Trim array to actual size and remove duplicates efficiently
        $matchValues = array_values(array_unique(array_slice($matchValues, 0, $validCount)));

        if ([] === $matchValues) {
            return [];
        }

        // Clear records reference early for memory optimization
        unset($records);

        if ('hasManyThrough' === $type) {
            return self::loadHasManyThroughOptimized($config, $matchValues, $columns, $tenantId, $schema);
        }

        // Optimized non-through relationships with advanced bulk loading
        // For belongsToMany, we don't need foreignKey and ownerKey in the traditional sense
        $effectiveForeignKey = $foreignKey ?? ('morphMany' === $type ? ($config['morph_id'] ?? 'id') : 'id');
        $effectiveOwnerKey = $ownerKey ?? 'id';

        return self::loadStandardRelationshipOptimized($type, $relatedTable, $effectiveForeignKey, $effectiveOwnerKey, $matchValues, $columns, $tenantId, $schema, $config);
    }

    /**
     * Recursive relationship inclusion with optimized memory management and depth control.
     */
    private static function includeRelationshipsRecursive(array $records, string $table, array $includes, mixed $tenantId, int $depth, int $maxDepth): array
    {
        if ([] === $includes || $depth > $maxDepth) {
            return $records;
        }

        // Convert array records to objects if needed
        foreach ($records as &$record) {
            if (is_array($record)) {
                $record = (object) $record;
            }
        }

        unset($record);

        foreach ($includes as $alias => $include) {
            $hintTable = $include['table'] ?? null;
            $columns = $include['columns'] ?? ['*'];
            $children = $include['children'] ?? [];

            $config = self::resolveRelationship($table, $alias, $hintTable);
            if (!$config) {
                $schema = self::getSchema();
                $declaredRelationships = isset($schema[$table]) ? ($schema[$table]->relationships ?? []) : [];
                if (!array_key_exists($alias, $declaredRelationships)) {
                    $validNames = array_keys($declaredRelationships);
                    sort($validNames);
                    throw new InvalidArgumentException(sprintf(
                        "Unknown relationship '%s' in select for table '%s'. Valid relationships: %s.",
                        $alias,
                        $table,
                        [] === $validNames ? 'none' : implode(', ', $validNames)
                    ));
                }

                continue;
            }

            // When the relation has nested children, the parent-side key
            // columns those children match on must be selected too (e.g.
            // `video(id,title,profile_image(*))` still needs
            // `video.profile_image_id`), otherwise the child lookups find no
            // match values and resolve to empty/null.
            if ([] !== $children && [] !== $columns && !in_array('*', $columns, true)) {
                $parentKeys = [];
                foreach ($children as $childAlias => $childInclude) {
                    $childConfig = self::resolveRelationship($config['table'], $childAlias, $childInclude['table'] ?? null);
                    if (!$childConfig) {
                        continue;
                    }
                    $childType = $childConfig['type'] ?? null;
                    if ('belongsTo' === $childType) {
                        $parentKeys[] = $childConfig['foreign_key'] ?? null;
                    } elseif (in_array($childType, ['hasMany', 'morphMany'], true)) {
                        $parentKeys[] = $childConfig['local_key'] ?? 'id';
                    }
                }
                foreach (array_filter($parentKeys) as $parentKey) {
                    if (!in_array($parentKey, $columns, true)) {
                        $columns[] = $parentKey;
                    }
                }
            }

            // Load related records with optimized bulk query
            $relatedGrouped = self::loadRelatedRecords($records, $config, $columns, $tenantId);

            $flatRelated = [];

            // Attach to records with memory-efficient processing
            foreach ($records as &$record) {
                $recordArray = (array) $record;
                if ('belongsTo' === $config['type']) {
                    $foreign = $recordArray[$config['foreign_key']] ?? null;
                    $related = null !== $foreign ? ($relatedGrouped[$foreign] ?? null) : null;
                    $record->{$alias} = self::enrichEmbeddedAttachments($related, $config);
                    if ($related) {
                        $flatRelated[] = $related;
                    }
                } elseif ('hasMany' === $config['type'] || 'morphMany' === $config['type'] || 'hasManyThrough' === $config['type']) {
                    $local = $recordArray[$config['local_key'] ?? 'id'] ?? null;
                    $related = null !== $local ? ($relatedGrouped[$local] ?? []) : [];
                    $record->{$alias} = self::enrichEmbeddedAttachments($related, $config);

                    foreach ($related as $r) {
                        $flatRelated[] = $r;
                    }
                } elseif ('belongsToMany' === $config['type'] || 'morphToMany' === $config['type']) {
                    // For Laravel models, use the attribute accessor instead of array casting
                    $parentKey = $config['parent_key'] ?? 'id';
                    $local = is_object($record) && method_exists($record, 'getAttribute')
                        ? $record->getAttribute($parentKey)
                        : ($recordArray[$parentKey] ?? null);
                    $related = null !== $local ? ($relatedGrouped[$local] ?? []) : [];

                    $record->{$alias} = self::enrichEmbeddedAttachments($related, $config);
                    foreach ($related as $r) {
                        $flatRelated[] = $r;
                    }
                } else { // hasOne or unknown
                    $local = $recordArray[$config['local_key'] ?? 'id'] ?? null;
                    $related = null !== $local ? ($relatedGrouped[$local] ?? null) : null;
                    $record->{$alias} = self::enrichEmbeddedAttachments($related, $config);
                    if ($related) {
                        $flatRelated[] = $related;
                    }
                }
            }

            unset($record, $relatedGrouped); // Free memory after processing

            // Recurse into children if requested and within depth limits
            if (!empty($children) && $depth < $maxDepth && [] !== $flatRelated) {
                // Ensure we have a uniform array for recursion
                $flat = [];
                foreach ($flatRelated as $r) {
                    if (is_array($r)) {
                        foreach ($r as $rr) {
                            $flat[] = $rr;
                        }
                    } else {
                        $flat[] = $r;
                    }
                }

                self::includeRelationshipsRecursive($flat, $config['table'], $children, $tenantId, $depth + 1, $maxDepth);
                // Map enriched children back to records when hasMany/through with memory cleanup
                if ('hasMany' === $config['type'] || 'morphMany' === $config['type']) {
                    // Rebuild grouped map
                    $grouped = [];
                    foreach ($flat as $fr) {
                        $fa = (array) $fr;
                        $key = ('morphMany' === $config['type'])
                            ? ($fa[$config['morph_id']] ?? null)
                            : ($fa[$config['foreign_key']] ?? null);
                        if (null !== $key) {
                            $grouped[$key][] = $fr;
                        }
                    }

                    unset($flat); // Free memory after grouping
                    foreach ($records as &$record) {
                        $recordArray = (array) $record;
                        $local = $recordArray[$config['local_key'] ?? 'id'] ?? null;
                        if (null !== $local) {
                            $record->{$alias} = $grouped[$local] ?? [];
                        }
                    }

                    unset($record, $grouped); // Clean up after mapping
                }
            }
        }

        return $records;
    }

    /**
     * Optimized bulk loading for hasManyThrough relationships.
     *
     * This method implements several performance optimizations:
     * 1. Chunked processing to handle large datasets without memory issues
     * 2. Efficient mapping with pre-allocated arrays to reduce memory overhead
     * 3. Tenant-aware filtering based on configuration settings
     * 4. Soft delete filtering with proper schema validation
     * 5. Column selection optimization to reduce data transfer
     * 6. Memory cleanup to prevent memory leaks in long-running processes
     *
     * Performance Benefits:
     * - Reduces N+1 query problems by batching related record retrieval
     * - Uses chunking to avoid database query size limits
     * - Implements O(1) duplicate checking for target IDs
     * - Applies tenant filtering only when enabled in configuration
     *
     * @param array<string, mixed> $config Relationship configuration with through table details
     * @param array $matchValues Parent record IDs to match against
     * @param array $columns     Columns to select from related table (can include filters)
     * @param mixed $tenantId    Tenant ID for multi-tenant filtering (if enabled)
     * @param array $schema      Database schema information for validation
     * @return array Grouped related records indexed by parent record ID
     */
    private static function loadHasManyThroughOptimized(array $config, array $matchValues, array $columns, mixed $tenantId, array $schema): array
    {
        $throughTable = $config['through_table'];
        $firstKey = $config['first_key'];
        $secondLocalKey = $config['second_local_key'];
        $relatedTable = $config['table'];
        $secondKey = $config['second_key'] ?? 'id';

        // Get actual table names from schema
        $actualThroughTableName = $schema[$throughTable]->table ?? $throughTable;
        $actualRelatedTableName = $schema[$relatedTable]->table ?? $relatedTable;

        // Check if tenant_id functionality is enabled
        $enableTenantId = RecordConfigService::enableTenantId();

        // Step 1: Optimized through table query with chunking for large datasets
        $builder = DB::table($actualThroughTableName);

        if (isset($config['owner_column'], $config['owner_value'])) {
            $builder->where($config['owner_column'], $config['owner_value']);
        }

        if (isset($config['target_column'], $config['target_value'])) {
            $targetColumn = $config['target_column'];
            if (is_string($targetColumn) && isset($schema[$throughTable]->columns[$targetColumn])) {
                $builder->where($targetColumn, $config['target_value']);
            }
        }

        $tenantCol = RecordConfigService::tenantColumn();
        if ($enableTenantId && $tenantId && isset($schema[$throughTable]->columns[$tenantCol])) {
            $builder->where($tenantCol, $tenantId);
        }

        if ($schema[$throughTable]->softDeletes ?? false) {
            $builder->whereNull('deleted_at');
        }

        $throughColumns = array_keys($schema[$throughTable]->columns ?? []);
        $relatedColumns = array_keys($schema[$relatedTable]->columns ?? []);

        $selectColumns = [];
        $relatedFilters = [];

        foreach ($columns as $col) {
            $col = trim((string) $col);
            if ('' === $col || '*' === $col) {
                if ('*' === $col) {
                    $selectColumns[] = '*';
                }

                continue;
            }

            if (!str_contains($col, '=')) {
                $selectColumns[] = $col;

                continue;
            }

            [$rawFilterCol, $rawFilterExpr] = explode('=', $col, 2);
            $rawFilterCol = trim($rawFilterCol);
            $rawFilterExpr = trim($rawFilterExpr);
            if ('' === $rawFilterCol) {
                continue;
            }

            if ('' === $rawFilterExpr) {
                continue;
            }

            $filterTarget = null;
            $filterCol = $rawFilterCol;

            if (str_contains($rawFilterCol, '.')) {
                [$prefix, $realCol] = explode('.', $rawFilterCol, 2);
                $prefix = strtolower(trim($prefix));
                $realCol = trim($realCol);

                if (in_array($prefix, ['pivot', 'through', strtolower((string) $throughTable), strtolower((string) $actualThroughTableName)], true)) {
                    $filterTarget = 'through';
                    $filterCol = $realCol;
                } elseif (in_array($prefix, ['related', strtolower((string) $relatedTable), strtolower((string) $actualRelatedTableName)], true)) {
                    $filterTarget = 'related';
                    $filterCol = $realCol;
                } else {
                    $filterCol = $realCol;
                }
            }

            $operator = 'eq';
            $value = $rawFilterExpr;
            if (str_contains($rawFilterExpr, '.')) {
                [$operator, $value] = explode('.', $rawFilterExpr, 2);
                $operator = trim($operator);
                $value = trim($value);
            }

            if ('' === $filterCol) {
                continue;
            }

            if ('' === $operator) {
                continue;
            }

            if (null === $filterTarget) {
                if (in_array($filterCol, $relatedColumns, true)) {
                    $filterTarget = 'related';
                } elseif (in_array($filterCol, $throughColumns, true)) {
                    $filterTarget = 'through';
                } else {
                    continue;
                }
            }

            if ('through' === $filterTarget) {
                if (!in_array($filterCol, $throughColumns, true)) {
                    continue;
                }

                QueryBuilderFiltersUtils::applyOperatorToSubquery($builder, $actualThroughTableName, $filterCol, $operator, $value);
            } else {
                if (!in_array($filterCol, $relatedColumns, true)) {
                    continue;
                }

                $relatedFilters[] = ['column' => $filterCol, 'operator' => $operator, 'value' => $value];
            }
        }

        // Chunk processing for large match value sets
        $throughRows = collect();
        foreach (array_chunk($matchValues, 1000) as $chunk) {
            $chunkQuery = clone $builder;
            $throughRows = $throughRows->merge($chunkQuery->whereIn($firstKey, $chunk)->get());
        }

        if ($throughRows->isEmpty()) {
            return [];
        }

        // Step 2: Efficient mapping with pre-allocated arrays
        $mainToTargetIds = [];
        $targetIds = [];
        $targetIdSet = []; // For O(1) duplicate checking

        foreach ($throughRows as $throughRow) {
            $rowArray = (array) $throughRow;
            $mainId = $rowArray[$firstKey] ?? null;
            $targetId = $rowArray[$secondLocalKey] ?? null;

            if (null !== $mainId && null !== $targetId) {
                $mainToTargetIds[$mainId][] = $targetId;
                if (!isset($targetIdSet[$targetId])) {
                    $targetIds[] = $targetId;
                    $targetIdSet[$targetId] = true;
                }
            }
        }

        unset($throughRows, $targetIdSet);

        // Step 3: Fetch related records
        $relatedBuilder = DB::table($actualRelatedTableName);

        // Apply tenant scoping only if enabled
        if ($enableTenantId && $tenantId && isset($schema[$relatedTable]->columns[$tenantCol])) {
            $relatedBuilder->where($tenantCol, $tenantId);
        }

        OwnRecordsScope::apply($relatedBuilder, $relatedTable, $actualRelatedTableName);

        // Apply soft delete filtering
        if ($schema[$relatedTable]->softDeletes ?? false) {
            $relatedBuilder->whereNull('deleted_at');
        }

        foreach ($relatedFilters as $filter) {
            QueryBuilderFiltersUtils::applyOperatorToSubquery(
                $relatedBuilder,
                $actualRelatedTableName,
                $filter['column'],
                $filter['operator'],
                $filter['value']
            );
        }

        // Apply column selection if specific columns requested
        if (!empty($selectColumns) && !in_array('*', $selectColumns, true)) {
            // Ensure the primary key is always selected for mapping
            if (!in_array($secondKey, $selectColumns, true)) {
                $selectColumns[] = $secondKey;
            }

            $prefixedColumns = array_map(fn($col): mixed => str_contains((string) $col, '.') ? $col : $actualRelatedTableName . '.' . $col, $selectColumns);

            $relatedBuilder->select($prefixedColumns);
        }

        if (isset($config['order_by']) && is_array($config['order_by'])) {
            $orderBy = $config['order_by'];

            $applyOrderBy = function (mixed $column, mixed $direction) use ($relatedBuilder, $actualRelatedTableName): void {
                $column = trim((string) $column);
                $direction = strtolower(trim((string) $direction));

                if ('' === $column || '' === $direction) {
                    return;
                }

                if (!in_array($direction, ['asc', 'desc'], true)) {
                    return;
                }

                if (!str_contains($column, '.')) {
                    $column = $actualRelatedTableName . '.' . $column;
                }

                $relatedBuilder->orderBy($column, $direction);
            };

            if (function_exists('array_is_list') && array_is_list($orderBy)) {
                if (2 === count($orderBy)) {
                    $applyOrderBy($orderBy[0] ?? null, $orderBy[1] ?? null);
                } else {
                    foreach ($orderBy as $item) {
                        if (is_array($item) && function_exists('array_is_list') && array_is_list($item) && 2 === count($item)) {
                            $applyOrderBy($item[0] ?? null, $item[1] ?? null);
                        } elseif (is_array($item)) {
                            foreach ($item as $col => $dir) {
                                $applyOrderBy($col, $dir);
                            }
                        }
                    }
                }
            } else {
                foreach ($orderBy as $col => $dir) {
                    $applyOrderBy($col, $dir);
                }
            }
        }

        $relatedRecords = $relatedBuilder->whereIn($secondKey, $targetIds)->get()->keyBy($secondKey);

        // Step 4: Map back to main IDs
        $results = [];
        foreach ($mainToTargetIds as $mainId => $tIds) {
            $results[$mainId] = [];
            foreach ($tIds as $tId) {
                if ($record = $relatedRecords->get($tId)) {
                    $results[$mainId][] = $record;
                }
            }
        }

        return $results;
    }

    /**
     * Optimized loading for standard relationships (belongsTo, hasMany).
     *
     * This method provides optimized loading for the most common relationship types:
     * - belongsTo: Many-to-one relationships (e.g., invoice -> customer)
     * - hasMany: One-to-many relationships (e.g., customer -> invoices)
     *
     * Performance Optimizations:
     * 1. Chunked processing to handle large datasets efficiently
     * 2. Tenant-aware filtering based on configuration settings
     * 3. Soft delete filtering with schema validation
     * 4. Column selection optimization to reduce data transfer
     * 5. Efficient grouping with pre-allocated data structures
     * 6. Clone-based query building to avoid query object mutation
     *
     * Memory Management:
     * - Uses Laravel collections for efficient data manipulation
     * - Implements chunking to prevent memory exhaustion
     * - Optimizes grouping logic for different relationship types
     *
     * @param string $type         Relationship type ('belongsTo' or 'hasMany')
     * @param string $relatedTable Target table name for the relationship
     * @param string $foreignKey   Foreign key column name
     * @param string $ownerKey     Owner key column name (for belongsTo relationships)
     * @param array  $matchValues  Parent record IDs to match against
     * @param array  $columns      Columns to select from related table
     * @param mixed  $tenantId     Tenant ID for multi-tenant filtering (if enabled)
     * @param array  $schema       Database schema information for validation
     *
     * @return array Grouped related records indexed by relationship key
     * @param array<string, mixed> $relationshipConfig
     */
    private static function loadStandardRelationshipOptimized(string $type, string $relatedTable, string $foreignKey, string $ownerKey, array $matchValues, array $columns, mixed $tenantId, array $schema, array $relationshipConfig = []): array
    {
        // Parse nested filters from columns (e.g. "status=eq.published")
        $nestedFilters = [];
        $cleanColumns = [];
        foreach ($columns as $col) {
            if (str_contains((string) $col, '=')) {
                [$filterCol, $filterExpression] = explode('=', (string) $col, 2);
                if (str_contains($filterExpression, '.')) {
                    [$operator, $value] = explode('.', $filterExpression, 2);
                    $nestedFilters[] = [
                        'column' => trim($filterCol),
                        'operator' => $operator,
                        'value' => $value,
                    ];
                }
            } else {
                $cleanColumns[] = $col;
            }
        }

        $columns = $cleanColumns;

        // Ensure the grouping key column is selected, otherwise related rows
        // cannot be mapped back to their parents (e.g.
        // `translations(locale,field,value)` would drop `target_id` and every
        // row would fail grouping, yielding an empty relation).
        if ([] !== $columns && !in_array('*', $columns, true)) {
            $requiredKey = ('belongsTo' === $type) ? $ownerKey : $foreignKey;
            if (null !== $requiredKey && !in_array($requiredKey, $columns, true)) {
                $columns[] = $requiredKey;
            }
        }

        // Get actual table name from schema
        $actualRelatedTableName = $schema[$relatedTable]->table ?? $relatedTable;

        // Check if tenant_id functionality is enabled
        $enableTenantId = RecordConfigService::enableTenantId();

        $builder = DB::table($actualRelatedTableName);

        // Apply tenant scoping only if enabled
        $tenantCol = RecordConfigService::tenantColumn();
        if ($enableTenantId && $tenantId && isset($schema[$relatedTable]->columns[$tenantCol])) {
            $builder->where($actualRelatedTableName . '.' . $tenantCol, $tenantId);
        }

        OwnRecordsScope::apply($builder, $relatedTable, $actualRelatedTableName);

        // Apply soft delete filtering
        if ($schema[$relatedTable]->softDeletes ?? false) {
            $builder->whereNull('deleted_at');
        }

        // Apply nested filters
        foreach ($nestedFilters as $filter) {
            QueryBuilderFiltersUtils::applyOperatorToSubquery(
                $builder,
                $actualRelatedTableName,
                $filter['column'],
                $filter['operator'],
                $filter['value']
            );
        }

        // Apply column selection with validation
        self::applyColumnSelection($builder, $columns, $schema[$relatedTable]->columns ?? [], $relatedTable);

        // Handle belongsToMany and morphToMany relationships with pivot table
        if ('belongsToMany' === $type || 'morphToMany' === $type) {
            $pivotTable = $relationshipConfig['pivot_table'] ?? $relationshipConfig['table'] ?? null;
            $parentKey = $relationshipConfig['foreign_pivot_key'] ?? $relationshipConfig['foreignPivotKey'] ?? 'model_id';
            $relatedKey = $relationshipConfig['related_pivot_key'] ?? $relationshipConfig['relatedPivotKey'] ?? 'role_id';
            $relatedTableName = $relationshipConfig['related'] ?? $relatedTable;

            if (!$pivotTable) {
                return [];
            }

            // Use the related table from config for belongsToMany
            $builder = DB::table($relatedTableName);

            // Apply tenant scoping only if enabled
            $tenantCol = RecordConfigService::tenantColumn();
            if ($enableTenantId && $tenantId && isset($schema[$relatedTable]->columns[$tenantCol])) {
                $builder->where($relatedTableName . '.' . $tenantCol, $tenantId);
            }

            OwnRecordsScope::apply($builder, $relatedTable, $relatedTableName);

            // Apply soft delete filtering
            if ($schema[$relatedTable]->softDeletes ?? false) {
                $builder->whereNull('deleted_at');
            }

            // Apply nested filters
            foreach ($nestedFilters as $filter) {
                QueryBuilderFiltersUtils::applyOperatorToSubquery(
                    $builder,
                    $relatedTableName,
                    $filter['column'],
                    $filter['operator'],
                    $filter['value']
                );
            }

            // Compute effective related columns based on requested columns and schema
            $relatedColumnsMeta = $schema[$relatedTable]->columns ?? [];
            if ($columns === ['*'] || [] === $columns) {
                $effectiveColumns = ['*'];
            } else {
                $effectiveColumns = [];
                foreach ($columns as $col) {
                    if (!is_string($col)) {
                        continue;
                    }

                    if (str_contains($col, '=')) {
                        continue;
                    }

                    if (isset($relatedColumnsMeta[$col])) {
                        $effectiveColumns[] = $col;
                    }
                }

                if ([] === $effectiveColumns) {
                    $effectiveColumns = ['*'];
                }
            }

            $relatedRecords = collect();
            $chunkSize = 1000;

            foreach (array_chunk($matchValues, $chunkSize) as $chunk) {
                $chunkQuery = clone $builder;

                $chunkQuery->join($pivotTable, $relatedTableName . '.id', '=', $pivotTable . '.' . $relatedKey)
                    ->whereIn($pivotTable . '.' . $parentKey, $chunk);

                if ($enableTenantId && $tenantId && isset($schema[$pivotTable]->columns[$tenantCol])) {
                    $chunkQuery->where($pivotTable . '.' . $tenantCol, $tenantId);
                }

                $chunkQuery->addSelect($pivotTable . '.' . $parentKey . ' as pivot_parent_key');

                // Apply column selection for related table respecting requested columns
                if (in_array('*', $effectiveColumns, true)) {
                    $chunkQuery->addSelect($relatedTableName . '.*');
                } else {
                    foreach ($effectiveColumns as $col) {
                        $chunkQuery->addSelect($relatedTableName . '.' . $col . ' as ' . $col);
                    }
                }

                // Add model_type condition and pivot columns for morphToMany relationships
                if ('morphToMany' === $type && isset($relationshipConfig['morph_type'])) {
                    $morphType = $relationshipConfig['morph_type'];
                    $modelClass = config('auth.providers.users.model');
                    if (!is_string($modelClass) || '' === $modelClass) {
                        $modelClass = User::class;
                    }

                    $chunkQuery->where($pivotTable . '.' . $morphType, $modelClass);

                    // Add pivot columns to match the correct SQL structure
                    $chunkQuery->addSelect($pivotTable . '.' . $parentKey . ' as pivot_model_id')
                        ->addSelect($pivotTable . '.' . $relatedKey . ' as pivot_role_id')
                        ->addSelect($pivotTable . '.' . $morphType . ' as pivot_model_type');
                } elseif (str_contains((string) $pivotTable, 'model_has_')) {
                    // Fallback for legacy permission tables
                    $modelClass = config('auth.providers.users.model');
                    if (!is_string($modelClass) || '' === $modelClass) {
                        $modelClass = User::class;
                    }

                    $chunkQuery->where($pivotTable . '.model_type', $modelClass);

                    // Add pivot columns for legacy tables
                    $chunkQuery->addSelect($pivotTable . '.model_id as pivot_model_id')
                        ->addSelect($pivotTable . '.role_id as pivot_role_id')
                        ->addSelect($pivotTable . '.model_type as pivot_model_type');
                }

                $chunkResults = $chunkQuery->get();
                $relatedRecords = $relatedRecords->merge($chunkResults);
            }
        } else {
            // Determine the key to use for whereIn clause
            $queryKey = ('belongsTo' === $type) ? $ownerKey : $foreignKey;

            // Chunked processing for large datasets to avoid query size limits
            $relatedRecords = collect();
            $chunkSize = 1000; // Optimal chunk size for most databases

            foreach (array_chunk($matchValues, $chunkSize) as $chunk) {
                $chunkQuery = clone $builder;

                if ('morphMany' === $type && isset($relationshipConfig['morph_type'])) {
                    $chunkQuery->where($relationshipConfig['morph_type'], $relationshipConfig['morph_class']);
                }

                $chunkResults = $chunkQuery->whereIn($queryKey, $chunk)->get();
                $relatedRecords = $relatedRecords->merge($chunkResults);
            }
        }

        // Efficient grouping with pre-allocated structure
        $grouped = [];

        if ('belongsToMany' === $type || 'morphToMany' === $type) {
            foreach ($relatedRecords as $relatedRecord) {
                $recordArray = (array) $relatedRecord;
                $key = $recordArray['pivot_parent_key'] ?? null;

                if (null !== $key) {
                    // Remove the pivot key from the record
                    unset($recordArray['pivot_parent_key']);
                    $cleanRecord = (object) $recordArray;
                    $grouped[$key][] = $cleanRecord;
                }
            }
        } else {
            $groupKey = ('belongsTo' === $type) ? $ownerKey : $foreignKey;

            foreach ($relatedRecords as $relatedRecord) {
                $recordArray = (array) $relatedRecord;
                $key = $recordArray[$groupKey] ?? null;

                if (null !== $key) {
                    if ('hasMany' === $type || 'morphMany' === $type) {
                        $grouped[$key][] = $relatedRecord;
                    } else {
                        // belongsTo or hasOne - single record
                        $grouped[$key] = $relatedRecord;
                    }
                }
            }
        }

        return $grouped;
    }

    /**
     * Apply column selection with schema validation.
     *
     * This method optimizes database queries by selecting only the required columns,
     * reducing data transfer and improving query performance. It includes several
     * safety measures to prevent SQL injection and invalid column selection.
     *
     * Security Features:
     * - Validates all column names against the database schema
     * - Prevents SQL injection through column name validation
     * - Filters out non-existent columns to avoid database errors
     *
     * Performance Benefits:
     * - Reduces data transfer by selecting only needed columns
     * - Avoids redundant operations when all columns are requested
     * - Uses efficient array operations for column validation
     *
     * @param Builder $query         Query builder instance to modify
     * @param array   $columns       Requested columns to select
     * @param array   $schemaColumns Available columns from database schema
     */
    private static function applyColumnSelection($query, array $columns, array $schemaColumns, string $relatedTable): void
    {
        if ($columns === ['*'] || [] === $columns) {
            return; // No filtering needed
        }

        foreach ($columns as $column) {
            if ('*' === $column || isset($schemaColumns[$column])) {
                continue;
            }

            $validNames = array_keys($schemaColumns);
            sort($validNames);
            throw new InvalidArgumentException(sprintf(
                "Unknown column '%s' in select for table '%s'. Valid columns: %s.",
                $column,
                $relatedTable,
                [] === $validNames ? 'none' : implode(', ', $validNames)
            ));
        }

        $query->select($columns);
    }

}
