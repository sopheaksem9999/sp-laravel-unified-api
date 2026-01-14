<?php

namespace Sopheak\Core\Support;

use Illuminate\Foundation\Auth\User;
use Spatie\Permission\PermissionServiceProvider;
use RuntimeException;
use Sopheak\Core\Types\RecordBelongsToType;
use Sopheak\Core\Types\RecordHasManyThroughType;
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Types\RecordMetaBelongsToManyType;
use Sopheak\Core\Types\RecordSpatiePermissionType;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Sopheak\Core\Enums\RecordApiJsonResponseEnum;

class RelationshipResolver
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
        config('record.enable_tenant_id', false);

        // Get main table columns to detect conflicts
        $mainTableColumns = isset($schema[$table]) ? array_keys($schema[$table]->columns ?? []) : [];

        foreach ($includes as $alias => $include) {
            $hintTable = $include['table'] ?? null;
            $columns = $include['columns'] ?? ['*'];

            $config = self::resolveRelationship($table, $alias, $hintTable);
            if (!$config) {
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
                        if (in_array($relType, ['hasMany', 'belongsToMany', 'morphToMany', 'hasManyThrough'], true)) {
                            $decoded = [];
                        } else {
                            $decoded = null; // belongsTo / hasOne
                        }
                    }

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
        $maxDepth = max(1, (int) config('record.max_depth', 2));

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
                $localPk = $schema[$mainTable]->primary_key ?? 'id';

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

                // Handle RecordSpatiePermissionType
                if ($rel instanceof RecordSpatiePermissionType) {
                    if (!class_exists(PermissionServiceProvider::class)) {
                        throw new RuntimeException('Spatie permission relationship configured but spatie/laravel-permission is not installed.');
                    }

                    $result = [
                        'type' => 'morphToMany',
                        'table' => $rel->related,
                        'pivot_table' => $rel->table,
                        'foreign_pivot_key' => $rel->foreignPivotKey ?? config('permission.column_names.model_morph_key'),
                        'related_pivot_key' => $rel->relatedPivotKey ?? config('permission.column_names.role_pivot_key', 'role_id'),
                        'parent_key' => $rel->parentKey ?? $localPk,
                        'related_key' => $rel->relatedKey ?? 'id',
                        'relation' => $rel->relation ?? 'model',
                        'morph_type' => 'model_type',
                        'morph_id' => config('permission.column_names.model_morph_key'),
                        'with_pivot' => $rel->withPivot ?? ['model_type'],
                        'where_pivot' => $rel->wherePivot ?? [],
                        'with_timestamps' => $rel->withTimestamps ?? false,
                        'teams_enabled' => $rel->teamsEnabled ?? false,
                        'teams_key' => $rel->teamsKey ?? config('permission.column_names.team_foreign_key', 'team_id'),
                        'selectable' => ['*'],
                    ];

                    // Normalize relation to FQCN if provided as 'model' placeholder or missing
                    if (!isset($result['relation']) || $result['relation'] === 'model') {
                        $result['relation'] = 'App\\Models\\' . Str::studly(Str::singular($mainTable));
                    }

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
        $hasTenant = isset($schema[$table]->columns[config('record.tenant_column', 'tenant_id')]);

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
            $relatedPk = $relatedSchema->primary_key ?? 'id';

            $type = $config['type'] ?? 'hasMany';
            $allowCreate = $config['allow_create'] ?? true;
            $allowUpdate = $config['allow_update'] ?? true;
            $allowDelete = $config['allow_delete'] ?? true;

            if ($type === 'belongsToMany' || $type === 'morphToMany') {
                self::processBelongsToManyOperation($relatedData, $recordId, $config, $schema, $tenantId, $allowCreate, $allowUpdate, $allowDelete);
                continue;
            }

            if ($type === 'hasManyThrough') {
                self::processHasManyThroughOperation($relatedData, $recordId, $config, $schema, $tenantId, $allowCreate, $allowUpdate, $allowDelete);
                continue;
            }

            $foreignKey = $config['foreign_key'] ?? null;
            $actualRelatedTableName = $schema[$relatedTable]->table ?? $relatedTable;

            if (!$foreignKey) {
                continue;
            }

            if (!$recordId) {
                abort(RecordApiJsonResponseEnum::VALIDATION_ERROR->value, 'Missing main record identifier for nested update');
            }

            // Allowed columns
            $allowedCols = array_keys($schema[$relatedTable]->columns ?? []);

            foreach ($relatedData as $item) {
                if (!is_array($item)) {
                    continue;
                }

                // Determine intended action before sanitization
                $hasPk = isset($item[$relatedPk]);
                $idVal = $hasPk ? $item[$relatedPk] : null;

                // Handle deletion
                if (($item['_delete'] ?? false) || ($item['_destroy'] ?? false)) {
                    if ($hasPk && $allowDelete) {
                        if ($relatedSchema->soft_deletes ?? false) {
                            DB::table($actualRelatedTableName)
                                ->where($relatedPk, $idVal)
                                ->update(['deleted_at' => now()]);
                        } else {
                            DB::table($actualRelatedTableName)->where($relatedPk, $idVal)->delete();
                        }
                    }

                    continue;
                }

                // Sanitize payload: only allowed columns; drop system/protected fields
                $item = array_intersect_key($item, array_flip($allowedCols));
                unset($item['id'], $item['created_at'], $item['updated_at'], $item['deleted_at']);
                if ($hasTenant) {
                    unset($item[config('record.tenant_column', 'tenant_id')]);
                }

                // Ensure FK is set to parent ID (cannot be overridden by input)
                $item[$foreignKey] = $recordId;
                if ($tenantId && $hasTenant) {
                    $item[config('record.tenant_column', 'tenant_id')] = $tenantId;
                }

                // Permission check per related action
                // $action = ($hasPk && $allowUpdate) ? 'update' : 'create';
                // if (!PermissionHelper::isPublicAction($relatedTable, $action)) {
                //     $user = auth('api')->user();
                //     if (!$user) {
                //         abort(401, 'Unauthenticated');
                //     }

                //     $perm = PermissionHelper::mapPermission($relatedTable, $action);
                //     if (!$user->can($perm)) {
                //         abort(RecordApiJsonResponseEnum::FORBIDDEN->value, 'Forbidden');
                //     }
                // }

                if ($hasPk && $allowUpdate) {
                    // Upsert/update path
                    unset($item[$relatedPk]);
                    DB::table($actualRelatedTableName)->where($relatedPk, $idVal)->update($item);
                } elseif ('create' === $operation || $allowCreate) {
                    // Create path
                    unset($item['id']);
                    DB::table($actualRelatedTableName)->insert($item);
                }
            }
        }

        return $payload;
    }

    private static function processBelongsToManyOperation(array $data, mixed $mainId, array $config, array $schema, mixed $tenantId, bool $allowCreate, bool $allowUpdate, bool $allowDelete): void
    {
        $pivotTable = $config['pivot_table'];
        $foreignPivotKey = $config['foreign_pivot_key'];
        $relatedPivotKey = $config['related_pivot_key'];
        $relatedTable = $config['table'];
        $relatedSchema = $schema[$relatedTable] ?? null;
        $relatedPk = $relatedSchema->primary_key ?? 'id';
        $actualRelatedTableName = $schema[$relatedTable]->table ?? $relatedTable;

        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }

            $isDelete = ($item['_delete'] ?? false) || ($item['_destroy'] ?? false);
            $relatedId = $item[$relatedPk] ?? null;

            if ($isDelete) {
                if ($allowDelete && $relatedId) {
                    DB::table($pivotTable)
                        ->where($foreignPivotKey, $mainId)
                        ->where($relatedPivotKey, $relatedId)
                        ->delete();
                }

                continue;
            }

            if (!$relatedId && $allowCreate) {
                // Create new related record
                $relatedFields = array_intersect_key($item, array_flip(array_keys($relatedSchema->columns ?? [])));
                unset($relatedFields['id'], $relatedFields['created_at'], $relatedFields['updated_at'], $relatedFields['deleted_at']);

                if ($tenantId && isset($relatedSchema->columns[config('record.tenant_column', 'tenant_id')])) {
                    $relatedFields[config('record.tenant_column', 'tenant_id')] = $tenantId;
                }

                if (isset($relatedSchema->columns['created_at'])) {
                    $relatedFields['created_at'] = now();
                }

                if (isset($relatedSchema->columns['updated_at'])) {
                    $relatedFields['updated_at'] = now();
                }

                $relatedId = DB::table($actualRelatedTableName)->insertGetId($relatedFields);
            }

            if ($relatedId && ($allowCreate || $allowUpdate)) {
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
                    if ($allowUpdate && !empty($pivotData)) {
                        if (($config['with_timestamps'] ?? false)) {
                            $pivotData['updated_at'] = now();
                        }

                        DB::table($pivotTable)
                            ->where($foreignPivotKey, $mainId)
                            ->where($relatedPivotKey, $relatedId)
                            ->update($pivotData);
                    }
                } elseif ($allowCreate) {
                    $pivotData[$foreignPivotKey] = $mainId;
                    $pivotData[$relatedPivotKey] = $relatedId;
                    if (($config['with_timestamps'] ?? false)) {
                        $pivotData['created_at'] = now();
                        $pivotData['updated_at'] = now();
                    }

                    DB::table($pivotTable)->insert($pivotData);
                }
            }
        }
    }

    private static function processHasManyThroughOperation(array $data, mixed $mainId, array $config, array $schema, mixed $tenantId, bool $allowCreate, bool $allowUpdate, bool $allowDelete): void
    {
        $throughTable = $config['through_table'];
        $firstKey = $config['first_key'];
        $secondLocalKey = $config['second_local_key'];
        $targetTable = $config['table'];
        $targetSchema = $schema[$targetTable] ?? null;
        $targetPk = $targetSchema->primary_key ?? 'id';
        $actualTargetTableName = $schema[$targetTable]->table ?? $targetTable;

        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }

            $isDelete = ($item['_delete'] ?? false) || ($item['_destroy'] ?? false);
            $targetId = $item[$targetPk] ?? null;

            if ($isDelete) {
                if ($allowDelete && $targetId) {
                    DB::table($throughTable)
                        ->where($firstKey, $mainId)
                        ->where($secondLocalKey, $targetId)
                        ->delete();
                }

                continue;
            }

            if (!$targetId && $allowCreate) {
                $targetFields = array_intersect_key($item, array_flip(array_keys($targetSchema->columns ?? [])));
                unset($targetFields['id'], $targetFields['created_at'], $targetFields['updated_at'], $targetFields['deleted_at']);

                if ($tenantId && isset($targetSchema->columns[config('record.tenant_column', 'tenant_id')])) {
                    $targetFields[config('record.tenant_column', 'tenant_id')] = $tenantId;
                }

                if (isset($targetSchema->columns['created_at'])) {
                    $targetFields['created_at'] = now();
                }

                if (isset($targetSchema->columns['updated_at'])) {
                    $targetFields['updated_at'] = now();
                }

                $targetId = DB::table($actualTargetTableName)->insertGetId($targetFields);
            }

            if ($targetId && ($allowCreate || $allowUpdate)) {
                $exists = DB::table($throughTable)
                    ->where($firstKey, $mainId)
                    ->where($secondLocalKey, $targetId)
                    ->exists();

                if (!$exists && $allowCreate) {
                    $insertData = [
                        $firstKey => $mainId,
                        $secondLocalKey => $targetId,
                    ];

                    DB::table($throughTable)->insert($insertData);
                }
            }
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
     */
    private static function addBelongsToSubquery(Builder $builder, string $table, string $alias, array $config, array $columns, mixed $tenantId, array $schema): Builder
    {
        $relatedTable = $config['table'];
        $foreignKey = $config['foreign_key'];
        $ownerKey = $config['owner_key'] ?? 'id';
        $enableTenantId = config('record.enable_tenant_id', false);

        // Get actual table names from schema
        $actualMainTableName = $schema[$table]->table ?? $table;
        $actualRelatedTableName = $schema[$relatedTable]->table ?? $relatedTable;

        // Build column selection for JSON object
        $jsonColumns = self::buildJsonObjectColumns($columns, $schema[$relatedTable]->columns ?? [], $actualRelatedTableName);
        $jsonObjectExpr = self::buildJsonObjectExpression($jsonColumns);

        // Use alias for subquery to avoid conflicts when main table = related table
        $subqueryAlias = $actualRelatedTableName === $actualMainTableName ? $actualRelatedTableName . '_sub' : $actualRelatedTableName;

        $subquery = DB::table($actualRelatedTableName . ' as ' . $subqueryAlias)
            ->selectRaw($jsonObjectExpr)
            ->whereColumn(sprintf('%s.%s', $subqueryAlias, $ownerKey), sprintf('%s.%s', $actualMainTableName, $foreignKey));

        // Apply tenant filtering if enabled
        $tenantCol = config('record.tenant_column', 'tenant_id');
        if ($enableTenantId && $tenantId && isset($schema[$relatedTable]->columns[$tenantCol])) {
            $subquery->where($subqueryAlias . '.' . $tenantCol, $tenantId);
        }

        // Apply soft delete filtering
        if ($schema[$relatedTable]->soft_deletes ?? false) {
            $subquery->whereNull($subqueryAlias . '.deleted_at');
        }

        $subquery->limit(1);

        $builder->addSelect([$alias => $subquery]);

        return $builder;
    }

    /**
     * Add hasMany relationship subquery with JSON array aggregation.
     */
    private static function addHasManySubquery(Builder $builder, string $table, string $alias, array $config, array $columns, mixed $tenantId, array $schema): Builder
    {
        $relatedTable = $config['table'];
        $foreignKey = $config['foreign_key'];
        $localKey = $config['local_key'] ?? 'id';
        $enableTenantId = config('record.enable_tenant_id', false);

        // Get actual table names from schema
        $actualMainTableName = $schema[$table]->table ?? $table;
        $actualRelatedTableName = $schema[$relatedTable]->table ?? $relatedTable;

        // Build column selection for JSON object
        $jsonColumns = self::buildJsonObjectColumns($columns, $schema[$relatedTable]->columns ?? [], $actualRelatedTableName);
        $jsonArrayAggExpr = self::buildJsonArrayAggExpression($jsonColumns);

        // Build the JSON array aggregation subquery
        $subqueryRaw = "(
            SELECT {$jsonArrayAggExpr}
            FROM {$actualRelatedTableName}
            WHERE {$actualRelatedTableName}.{$foreignKey} = {$actualMainTableName}.{$localKey}";

        // Add tenant filtering if enabled
        $tenantCol = config('record.tenant_column', 'tenant_id');
        if ($enableTenantId && $tenantId && isset($schema[$relatedTable]->columns[$tenantCol])) {
            $subqueryRaw .= sprintf(' AND %s.' . $tenantCol . ' = %s', $actualRelatedTableName, $tenantId);
        }

        // Add soft delete filtering
        if ($schema[$relatedTable]->soft_deletes ?? false) {
            $subqueryRaw .= sprintf(' AND %s.deleted_at IS NULL', $actualRelatedTableName);
        }

        $subqueryRaw .= '
        )';

        $builder->addSelect([DB::raw(sprintf('%s as %s', $subqueryRaw, $alias))]);

        return $builder;
    }

    /**
     * Add belongsToMany relationship subquery with JSON array aggregation.
     * Handles many-to-many relationships through pivot tables.
     */
    private static function addBelongsToManySubquery(Builder $builder, string $table, string $alias, array $config, array $columns, mixed $tenantId, array $schema): Builder
    {
        $relatedTable = $config['table'];
        $pivotTable = $config['pivot_table'];
        $foreignPivotKey = $config['foreign_pivot_key'];
        $relatedPivotKey = $config['related_pivot_key'];
        $parentKey = $config['parent_key'] ?? 'id';
        $relatedKey = $config['related_key'] ?? 'id';
        $relation = $config['relation'] ?? null;
        $wherePivot = $config['where_pivot'] ?? [];
        $enableTenantId = config('record.enable_tenant_id', false);

        // Get actual table names from schema
        $actualMainTableName = $schema[$table]->table ?? $table;
        $actualRelatedTableName = $schema[$relatedTable]->table ?? $relatedTable;
        $actualPivotTableName = $schema[$pivotTable]->table ?? $pivotTable;

        // Build column selection for JSON object
        $jsonColumns = self::buildJsonObjectColumns($columns, $schema[$relatedTable]->columns ?? [], $actualRelatedTableName);
        $jsonArrayAggExpr = self::buildJsonArrayAggExpression($jsonColumns);

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
            $tenantCol = config('record.tenant_column', 'tenant_id');
            if (isset($schema[$relatedTable]->columns[$tenantCol])) {
                $subqueryRaw .= sprintf(' AND %s.' . $tenantCol . ' = %s', $actualRelatedTableName, $tenantId);
            }

            if (isset($schema[$pivotTable]->columns[$tenantCol])) {
                $subqueryRaw .= sprintf(' AND %s.' . $tenantCol . ' = %s', $actualPivotTableName, $tenantId);
            }
        }

        // Add soft delete filtering
        if ($schema[$relatedTable]->soft_deletes ?? false) {
            $subqueryRaw .= sprintf(' AND %s.deleted_at IS NULL', $actualRelatedTableName);
        }

        if ($schema[$pivotTable]->soft_deletes ?? false) {
            $subqueryRaw .= sprintf(' AND %s.deleted_at IS NULL', $actualPivotTableName);
        }

        $subqueryRaw .= '
        )';

        $builder->addSelect([DB::raw(sprintf('%s as %s', $subqueryRaw, $alias))]);

        return $builder;
    }

    /**
     * Add morphToMany relationship subquery with JSON array aggregation.
     * Specifically supports Spatie Permission-style tables (model_has_roles, etc.).
     */
    private static function addMorphToManySubquery(Builder $builder, string $table, string $alias, array $config, array $columns, mixed $tenantId, array $schema): Builder
    {
        $relatedTable = $config['table'];
        $pivotTable = $config['pivot_table'];
        $foreignPivotKey = $config['foreign_pivot_key']; // e.g., model_id
        $relatedPivotKey = $config['related_pivot_key']; // e.g., role_id
        $parentKey = $config['parent_key'] ?? 'id';
        $relatedKey = $config['related_key'] ?? 'id';
        $morphTypeColumn = $config['morph_type'] ?? 'model_type';
        $relation = $config['relation'] ?? null; // expected to be FQCN (e.g., App\\Models\\User)
        $wherePivot = $config['where_pivot'] ?? [];
        $enableTenantId = config('record.enable_tenant_id', false);

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
        $jsonColumns = self::buildJsonObjectColumns($columns, $schema[$relatedTable]->columns ?? [], $actualRelatedTableName);
        $jsonArrayAggExpr = self::buildJsonArrayAggExpression($jsonColumns);

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
            $tenantCol = config('record.tenant_column', 'tenant_id');
            if (isset($schema[$relatedTable]->columns[$tenantCol])) {
                $subqueryRaw .= sprintf(' AND %s.' . $tenantCol . ' = %s', $actualRelatedTableName, $tenantId);
            }

            if (isset($schema[$pivotTable]->columns[$tenantCol])) {
                $subqueryRaw .= sprintf(' AND %s.' . $tenantCol . ' = %s', $actualPivotTableName, $tenantId);
            }
        }

        // Add soft delete filtering
        if ($schema[$relatedTable]->soft_deletes ?? false) {
            $subqueryRaw .= sprintf(' AND %s.deleted_at IS NULL', $actualRelatedTableName);
        }

        if ($schema[$pivotTable]->soft_deletes ?? false) {
            $subqueryRaw .= sprintf(' AND %s.deleted_at IS NULL', $actualPivotTableName);
        }

        $subqueryRaw .= '
        )';

        $builder->addSelect([DB::raw(sprintf('%s as %s', $subqueryRaw, $alias))]);

        return $builder;
    }

    /**
     * Add hasManyThrough relationship subquery with JSON array aggregation.
     */
    private static function addHasManyThroughSubquery(Builder $builder, string $table, string $alias, array $config, array $columns, mixed $tenantId, array $schema): Builder
    {
        $relatedTable = $config['table'];
        $throughTable = $config['through_table'];
        $firstKey = $config['first_key'];
        $secondKey = $config['second_key'];
        $localKey = $config['local_key'] ?? 'id';
        $secondLocalKey = $config['second_local_key'] ?? 'id';
        $enableTenantId = config('record.enable_tenant_id', false);

        // Get actual table names from schema
        $actualMainTableName = $schema[$table]->table ?? $table;
        $actualRelatedTableName = $schema[$relatedTable]->table ?? $relatedTable;
        $actualThroughTableName = $schema[$throughTable]->table ?? $throughTable;

        // Build column selection for JSON object
        $jsonColumns = self::buildJsonObjectColumns($columns, $schema[$relatedTable]->columns ?? [], $actualRelatedTableName);
        $jsonArrayAggExpr = self::buildJsonArrayAggExpression($jsonColumns);

        // Build the JSON array aggregation subquery with join
        $subqueryRaw = "(
            SELECT {$jsonArrayAggExpr}
            FROM {$actualRelatedTableName}
            INNER JOIN {$actualThroughTableName} ON {$actualThroughTableName}.{$secondLocalKey} = {$actualRelatedTableName}.{$secondKey}
            WHERE {$actualThroughTableName}.{$firstKey} = {$actualMainTableName}.{$localKey}";

        // Add tenant filtering if enabled
        if ($enableTenantId && $tenantId) {
            $tenantCol = config('record.tenant_column', 'tenant_id');
            if (isset($schema[$relatedTable]->columns[$tenantCol])) {
                $subqueryRaw .= sprintf(' AND %s.' . $tenantCol . ' = %s', $actualRelatedTableName, $tenantId);
            }

            if (isset($schema[$throughTable]->columns[$tenantCol])) {
                $subqueryRaw .= sprintf(' AND %s.' . $tenantCol . ' = %s', $actualThroughTableName, $tenantId);
            }
        }

        // Add soft delete filtering
        if ($schema[$relatedTable]->soft_deletes ?? false) {
            $subqueryRaw .= sprintf(' AND %s.deleted_at IS NULL', $actualRelatedTableName);
        }

        if ($schema[$throughTable]->soft_deletes ?? false) {
            $subqueryRaw .= sprintf(' AND %s.deleted_at IS NULL', $actualThroughTableName);
        }

        $subqueryRaw .= '
        )';

        $builder->addSelect([DB::raw(sprintf('%s as %s', $subqueryRaw, $alias))]);

        return $builder;
    }

    /**
     * Build JSON_OBJECT column specification for subqueries.
     */
    private static function buildJsonObjectColumns(array $columns, array $schemaColumns, string $tableName = ''): string
    {
        if ($columns === ['*'] || [] === $columns) {
            $columns = array_keys($schemaColumns);
        }

        // Validate columns against schema
        $validColumns = array_filter($columns, fn($column): bool => isset($schemaColumns[$column]));

        // Remove tenant_id if it's not enabled in configuration
        $enableTenantId = config('record.enable_tenant_id', false);
        if (!$enableTenantId) {
            $tenantCol = config('record.tenant_column', 'tenant_id');
            $validColumns = array_filter($validColumns, fn($column): bool => $tenantCol !== $column);
        }

        if ([] === $validColumns) {
            $columnRef = $tableName !== '' && $tableName !== '0' ? sprintf('%s.id', $tableName) : 'id';

            return "'id', " . $columnRef; // Fallback to id column
        }

        $jsonPairs = [];
        foreach ($validColumns as $validColumn) {
            $columnRef = $tableName !== '' && $tableName !== '0' ? sprintf('%s.%s', $tableName, $validColumn) : $validColumn;
            $jsonPairs[] = sprintf("'%s', %s", $validColumn, $columnRef);
        }

        return implode(', ', $jsonPairs);
    }

    /**
     * Build database-specific JSON object expression from column specification.
     */
    private static function buildJsonObjectExpression(string $jsonColumns): string
    {
        $driver = DB::getDriverName();

        return match ($driver) {
            'pgsql' => sprintf('json_build_object(%s)', $jsonColumns),
            'sqlite' => sprintf('json_object(%s)', $jsonColumns),
            default => sprintf('JSON_OBJECT(%s)', $jsonColumns),
        };
    }

    /**
     * Build database-specific JSON array aggregation expression of JSON objects.
     */
    private static function buildJsonArrayAggExpression(string $jsonColumns): string
    {
        $jsonObjectExpr = self::buildJsonObjectExpression($jsonColumns);
        $driver = DB::getDriverName();

        return match ($driver) {
            'pgsql' => sprintf('json_agg(%s)', $jsonObjectExpr),
            'sqlite' => sprintf('json_group_array(%s)', $jsonObjectExpr),
            default => sprintf('JSON_ARRAYAGG(%s)', $jsonObjectExpr),
        };
    }

    /**
     * Get cached schema registry to avoid multiple SchemaRegistry::get() calls.
     *
     * @return array The schema registry data
     */
    private static function getSchema(): array
    {
        if (null === self::$schemaCache) {
            self::$schemaCache = SchemaRegistry::get();
        }

        return self::$schemaCache;
    }

    /**
     * Parse select segments, respecting parentheses nesting.
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
            $resolved = SchemaRegistry::resolveTableSchema($relatedTable);
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
        $effectiveForeignKey = $foreignKey ?? 'id';
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
                continue;
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
                    $record->{$alias} = $related;
                    if ($related) {
                        $flatRelated[] = $related;
                    }
                } elseif ('hasMany' === $config['type'] || 'hasManyThrough' === $config['type']) {
                    $local = $recordArray[$config['local_key'] ?? 'id'] ?? null;
                    $related = null !== $local ? ($relatedGrouped[$local] ?? []) : [];
                    $record->{$alias} = $related;

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

                    $record->{$alias} = $related;
                    foreach ($related as $r) {
                        $flatRelated[] = $r;
                    }
                } else { // hasOne or unknown
                    $local = $recordArray[$config['local_key'] ?? 'id'] ?? null;
                    $related = null !== $local ? ($relatedGrouped[$local] ?? null) : null;
                    $record->{$alias} = $related;
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
                if ('hasMany' === $config['type'] || 'hasManyThrough' === $config['type']) {
                    // Rebuild grouped map
                    $grouped = [];
                    foreach ($flat as $fr) {
                        $fa = (array) $fr;
                        $key = 'hasManyThrough' === $config['type'] ? ($fa[$config['second_key']] ?? null) : ($fa[$config['foreign_key']] ?? null);
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
     * @param array $config      Relationship configuration with through table details
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
        $enableTenantId = config('record.enable_tenant_id', false);

        // Step 1: Optimized through table query with chunking for large datasets
        $builder = DB::table($actualThroughTableName);

        $tenantCol = config('record.tenant_column', 'tenant_id');
        if ($enableTenantId && $tenantId && isset($schema[$throughTable]->columns[$tenantCol])) {
            $builder->where($tenantCol, $tenantId);
        }

        if ($schema[$throughTable]->soft_deletes ?? false) {
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

                QueryBuilderFilters::applyOperatorToSubquery($builder, $actualThroughTableName, $filterCol, $operator, $value);
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

        // Apply soft delete filtering
        if ($schema[$relatedTable]->soft_deletes ?? false) {
            $relatedBuilder->whereNull('deleted_at');
        }

        foreach ($relatedFilters as $filter) {
            QueryBuilderFilters::applyOperatorToSubquery(
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

            $prefixedColumns = array_map(fn($col) => str_contains((string) $col, '.') ? $col : $actualRelatedTableName . '.' . $col, $selectColumns);

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
                        'value' => $value
                    ];
                }
            } else {
                $cleanColumns[] = $col;
            }
        }

        $columns = $cleanColumns;

        // Get actual table name from schema
        $actualRelatedTableName = $schema[$relatedTable]->table ?? $relatedTable;

        // Check if tenant_id functionality is enabled
        $enableTenantId = config('record.enable_tenant_id', false);

        $builder = DB::table($actualRelatedTableName);

        // Apply tenant scoping only if enabled
        $tenantCol = config('record.tenant_column', 'tenant_id');
        if ($enableTenantId && $tenantId && isset($schema[$relatedTable]->columns[$tenantCol])) {
            $builder->where($tenantCol, $tenantId);
        }

        // Apply soft delete filtering
        if ($schema[$relatedTable]->soft_deletes ?? false) {
            $builder->whereNull('deleted_at');
        }

        // Apply nested filters
        foreach ($nestedFilters as $filter) {
            QueryBuilderFilters::applyOperatorToSubquery(
                $builder,
                $actualRelatedTableName,
                $filter['column'],
                $filter['operator'],
                $filter['value']
            );
        }

        // Apply column selection with validation
        self::applyColumnSelection($builder, $columns, $schema[$relatedTable]->columns ?? []);

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
            $tenantCol = config('record.tenant_column', 'tenant_id');
            if ($enableTenantId && $tenantId && isset($schema[$relatedTable]->columns[$tenantCol])) {
                $builder->where($tenantCol, $tenantId);
            }

            // Apply soft delete filtering
            if ($schema[$relatedTable]->soft_deletes ?? false) {
                $builder->whereNull('deleted_at');
            }

            // Apply nested filters
            foreach ($nestedFilters as $filter) {
                QueryBuilderFilters::applyOperatorToSubquery(
                    $builder,
                    $relatedTableName,
                    $filter['column'],
                    $filter['operator'],
                    $filter['value']
                );
            }

            // Apply column selection with validation
            self::applyColumnSelection($builder, $columns, $schema[$relatedTable]->columns ?? []);

            $relatedRecords = collect();
            $chunkSize = 1000;

            foreach (array_chunk($matchValues, $chunkSize) as $chunk) {
                $chunkQuery = clone $builder;

                $chunkQuery->join($pivotTable, $relatedTableName . '.id', '=', $pivotTable . '.' . $relatedKey)
                    ->whereIn($pivotTable . '.' . $parentKey, $chunk)
                    ->addSelect($relatedTableName . '.*')
                    ->addSelect($pivotTable . '.' . $parentKey . ' as pivot_parent_key')
                ;

                // Add model_type condition and pivot columns for morphToMany relationships (like Spatie permission system)
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
                    // Fallback for legacy Spatie permission tables
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
                    if ('hasMany' === $type) {
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
    private static function applyColumnSelection($query, array $columns, array $schemaColumns): void
    {
        if ($columns === ['*'] || [] === $columns) {
            return; // No filtering needed
        }

        // Validate and filter columns against schema
        $validColumns = array_values(array_filter($columns, fn($column): bool => '*' === $column || isset($schemaColumns[$column])));

        if ([] !== $validColumns) {
            $query->select($validColumns);
        }
    }
}
