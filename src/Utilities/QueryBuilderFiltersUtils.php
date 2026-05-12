<?php

namespace Sopheak\Core\Utilities;

use InvalidArgumentException;
use Sopheak\Core\Types\RecordTableType;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Utilities\RelationshipResolverUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class QueryBuilderFiltersUtils
{
    private const FILTER_OPERATORS = [
        'is', 'eq', 'neq', 'like', 'ilike', 'gt', 'lt', 'gte', 'lte', 'in', 'contains',
        'between', 'not_between', 'starts_with', 'ends_with', 'not_like', 'not_in', 'is_not',
        'regex', 'not_regex', 'match', 'not_match', 'imatch', 'not_imatch', 'ilike', 'not_ilike',
        'date_eq', 'date_gt', 'date_lt', 'date_gte', 'date_lte', 'empty', 'not_empty',
        'fts', 'not_fts', 'plfts', 'not_plfts', 'phfts', 'not_phfts', 'wfts', 'not_wfts',
        'cs', 'not_cs', 'cd', 'not_cd', 'ov', 'not_ov', 'sl', 'not_sl', 'sr', 'not_sr',
        'nxl', 'not_nxl', 'nxr', 'not_nxr', 'adj', 'not_adj',
    ];

    private static array $columnCache = [];

    private static array $operatorCache = [];

    private static array $lazyOperations = [];

    private static array $lazyCache = [];

    private static array $searchableCache = [];

    private static string $lazyMarkerPrefix = 'LAZY_OP_';

    private static array $lazyBuilders = [];

    /**
     * Clear the column cache for testing/runtime updates.
     */
    public static function clearColumnCache(): void
    {
        self::$columnCache = [];
        self::$operatorCache = [];
    }

    /**
     * Apply filters, selects, ordering, pagination to Query Builder based on request.
     * Optimized for performance with caching and reduced query complexity.
     *
     * Available operators:
     * - is: Check if column is null
     * - eq: Equal to value (supports comma-separated values for IN)
     * - neq: Not equal to value (supports comma-separated values for NOT IN)
     * - like/contains: Contains value (LIKE %value%)
     * - gt: Greater than
     * - lt: Less than
     * - gte: Greater than or equal
     * - lte: Less than or equal
     * - in: Value in list (comma-separated)
     * - between: Value between two values (comma-separated)
     * - not_between: Value not between two values (comma-separated)
     * - starts_with: Value starts with string (LIKE value%)
     * - ends_with: Value ends with string (LIKE %value)
     * - not_like: Does not contain value (NOT LIKE %value%)
     * - not_in: Value not in list (comma-separated)
     * - is_not: Check if column is not null
     * - regex: Regular expression match (MySQL REGEXP)
     * - date_eq: Date equals (ignores time)
     * - date_gt: Date greater than (ignores time)
     * - date_lt: Date less than (ignores time)
     * - date_gte: Date greater than or equal (ignores time)
     * - date_lte: Date less than or equal (ignores time)
     * - empty: Column is null or empty string
     * - not_empty: Column is not null and not empty string
     *
     * LAZY LOADING SUPPORT:
     * =====================
     * Add lazy=true parameter to defer query execution for performance optimization.
     * Lazy operators are executed only when needed, reducing database load and improving response times.
     *
     * Benefits of Lazy Loading:
     * - Deferred execution: Operations are stored and executed only when the query is actually run
     * - Caching: Results are cached to avoid repeated expensive operations
     * - Performance optimization: Reduces unnecessary database queries
     * - Memory efficiency: Operations are batched and optimized
     * - Debugging support: Provides statistics and operation tracking
     *
     * Lazy Loading Methods:
     * - getPendingLazyOperations(): Get all pending lazy operations
     * - forceExecuteLazyOperations(): Force execution of all lazy operations
     * - executeLazyOperationById(): Execute specific lazy operation by ID
     * - getLazyStats(): Get performance statistics for debugging
     * - isLazyEnabled(): Check if lazy loading is enabled
     *
     * Standard Usage Examples:
     * - ?name=eq.john (name equals 'john')
     * - ?status=in.active,pending (status in ['active', 'pending'])
     * - ?email=starts_with.admin (email starts with 'admin')
     * - ?created_at=date_gte.2023-01-01 (created_at >= '2023-01-01')
     * - ?description=not_empty.null (description is not null and not empty)
     *
     * Lazy Loading Examples:
     * - ?lazy=true&name=eq.john (lazy evaluation of name filter)
     * - ?lazy=true&status=in.active,pending (lazy evaluation of status filter)
     * - ?lazy=true&created_at=date_gte.2023-01-01&status=eq.active (multiple lazy filters)
     * - ?lazy=1&email=starts_with.admin&role=neq.guest (lazy with multiple conditions)
     *
     * Performance Comparison:
     * Standard: Immediate execution, higher database load
     * Lazy: Deferred execution, optimized batching, reduced database queries
     *
     * Use lazy=true when:
     * - Dealing with large datasets
     * - Complex filtering operations
     * - Multiple conditional filters
     * - Performance is critical
     * - Caching benefits are desired
     */
    public static function apply(Builder $builder, Request $request, string $table, string $defaultOrderBy = 'id'): Builder
    {
        $allowedCols = self::getAllowedColumns($table);

        // Basic search across columns (only allowed columns) - optimized for performance
        if ($request->has('s') && [] !== $allowedCols) {
            $keyword = (string) $request->query('s');
            $searchableCols = self::getSearchableColumns($table, $allowedCols);
            $numericCols = self::getNumericSearchableColumns($table, $allowedCols);
            $isNumeric = is_numeric($keyword);
            $likeOperator = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';

            if ([] !== $searchableCols || ($isNumeric && [] !== $numericCols)) {
                $builder->where(function ($q) use ($searchableCols, $numericCols, $keyword, $table, $isNumeric, $likeOperator): void {
                    if ([] !== $searchableCols) {
                        $hasFullText = self::hasFullTextIndex($table, $searchableCols);
                        if ($hasFullText && strlen($keyword) >= 3) {
                            $columns = implode(',', array_map(fn($col): string => sprintf('%s.%s', $table, $col), $searchableCols));
                            $q->whereRaw(sprintf('MATCH(%s) AGAINST(? IN BOOLEAN MODE)', $columns), [sprintf('+%s*', $keyword)]);
                        } else {
                            foreach ($searchableCols as $searchableCol) {
                                $q->orWhere($table . '.' . $searchableCol, $likeOperator, sprintf('%%%s%%', $keyword));
                            }
                        }
                    }

                    if ($isNumeric && [] !== $numericCols) {
                        foreach ($numericCols as $numericCol) {
                            $q->orWhere($table . '.' . $numericCol, '=', $keyword);
                        }
                    }
                });
            }
        }

        // Select
        if ($request->has('select')) {
            $requested = self::parseSelectColumns($request->query('select'), $table, $allowedCols);
            if (!empty($requested['main'])) {
                $builder->select($requested['main']);
            }
        }

        // Sort (validate against schema to avoid injection / invalid columns)
        self::applySort($builder, $request, $table, $defaultOrderBy);

        // Handle check permission query only own user created record
        $recordConfig = RecordConfigService::table($table);
        $pmsName = $recordConfig->pmsName ?? null;

        if ($pmsName && Auth::check() && RecordConfigService::ownRecordsPermissionPrefix()) {
            $pmsNames = is_array($pmsName) ? $pmsName : [$pmsName];
            $prefix = RecordConfigService::ownRecordsPermissionPrefix();
            $separator = RecordConfigService::permissionSeparator();

            $shouldRestrictToOwn = false;
            foreach ($pmsNames as $candidate) {
                if (!is_string($candidate)) {
                    continue;
                }

                $candidate = trim($candidate);
                if ('' === $candidate) {
                    continue;
                }

                $permission = $prefix . $separator . $candidate;
                if (Gate::check($permission)) {
                    $shouldRestrictToOwn = true;
                    break;
                }
            }

            if ($shouldRestrictToOwn) {
                $builder->where($table . '.created_by', Auth::user()->id);
            }
        }

        // Operators (validate keys against allowed columns) - optimized parsing
        $queryString = $request->getQueryString();
        if (null !== $queryString && '' !== $queryString && '0' !== $queryString) {
            // Enhanced caching with request fingerprinting
            $cacheKey = md5($queryString . $table . serialize($allowedCols));
            if (!isset(self::$operatorCache[$cacheKey])) {
                // Preserve dots in parameter keys to support dot notation (e.g. relationship.column)
                // PHP's parse_str automatically converts dots to underscores
                $preservedQueryString = preg_replace_callback(
                    '/(^|&)([^=]+)=/',
                    fn($m): string => $m[1] . str_replace('.', '___DOT___', $m[2]) . '=',
                    $queryString
                );

                parse_str((string) $preservedQueryString, $params);

                // Restore dots in keys
                $restoredParams = [];
                foreach ($params as $key => $value) {
                    $newKey = str_replace('___DOT___', '.', (string) $key);
                    $restoredParams[$newKey] = $value;
                }

                $params = $restoredParams;

                self::$operatorCache[$cacheKey] = $params;
            } else {
                $params = self::$operatorCache[$cacheKey];
            }

            // Check for lazy loading parameter
            $isLazy = isset($params['lazy']) && ('true' === $params['lazy'] || '1' === $params['lazy']);

            if ($isLazy) {
                $lazyOperationId = self::createLazyOperation($table, $allowedCols, $params);
                // Store lazy operation for deferred execution with enhanced metadata
                self::$lazyOperations[$lazyOperationId] = [
                    'table' => $table,
                    'allowedCols' => $allowedCols,
                    'params' => $params,
                    'executed' => false,
                    'query' => null,
                    'created_at' => microtime(true),
                    'priority' => 5, // Default priority for lazy operations
                ];

                // Return query with lazy operation marker
                self::registerLazyOperationForBuilder($builder, $lazyOperationId);
                $builder->where(function ($q) use ($lazyOperationId): void {
                    $q->whereRaw('1=1 /* ' . self::$lazyMarkerPrefix . $lazyOperationId . ' */');
                });
            } else {
                // Execute operators immediately with optimized batch processing
                self::executeOperatorsOptimized($builder, $table, $allowedCols, $params);
            }
        }

        // Execute lazy operations if any are pending with priority ordering
        if ([] !== self::$lazyOperations) {
            self::executeLazyOperationsOptimized($builder);
            self::cleanupExecutedLazyOperations();
        }

        return $builder;
    }

    /**
     * Get lazy operation by ID for manual execution.
     */
    public static function executeLazyOperationById(Builder $builder, string $operationId): bool
    {
        if (isset(self::$lazyOperations[$operationId]) && !self::$lazyOperations[$operationId]['executed']) {
            $operation = self::$lazyOperations[$operationId];
            self::executeOperators($builder, $operation['table'], $operation['allowedCols'], $operation['params']);
            self::$lazyOperations[$operationId]['executed'] = true;

            return true;
        }

        return false;
    }

    /**
     * Get all pending lazy operations.
     */
    public static function getPendingLazyOperations(): array
    {
        return array_filter(self::$lazyOperations, fn(array $op): bool => !$op['executed']);
    }

    /**
     * Force execution of all lazy operations.
     */
    public static function forceExecuteLazyOperations(Builder $builder): void
    {
        foreach (self::$lazyOperations as $operationId => $operation) {
            if (!$operation['executed']) {
                if (isset($operation['key'])) {
                    // Single operation
                    self::applyOperator(
                        $builder,
                        $operation['table'],
                        $operation['allowedCols'],
                        $operation['key'],
                        $operation['operator'],
                        $operation['value']
                    );
                } else {
                    // Batch operation
                    self::executeOperators($builder, $operation['table'], $operation['allowedCols'], $operation['params']);
                }

                self::$lazyOperations[$operationId]['executed'] = true;
            }
        }
    }

    /**
     * Check if lazy loading is enabled for current request.
     */
    public static function isLazyEnabled(array $params): bool
    {
        return isset($params['lazy']) && ('true' === $params['lazy'] || '1' === $params['lazy']);
    }

    /**
     * Get lazy operation statistics for debugging.
     */
    public static function getLazyStats(): array
    {
        $total = count(self::$lazyOperations);
        $executed = count(array_filter(self::$lazyOperations, fn(array $op) => $op['executed']));
        $pending = $total - $executed;
        $cacheHits = count(self::$lazyCache);

        return [
            'total_operations' => $total,
            'executed_operations' => $executed,
            'pending_operations' => $pending,
            'cache_hits' => $cacheHits,
            'cache_efficiency' => $total > 0 ? round(($cacheHits / $total) * 100, 2) : 0,
        ];
    }

    /**
     * Clear the internal caches (useful for testing or when schema changes).
     */
    public static function clearCache(): void
    {
        self::$columnCache = [];
        self::$operatorCache = [];
        self::$lazyOperations = [];
        self::$lazyCache = [];
        self::$lazyBuilders = [];
    }

    /**
     * Apply relationship filters using optimized subqueries.
     * This method enables filtering on related table columns using EXISTS subqueries
     * for better performance compared to JOINs.
     *
     * Example usage:
     * ?customer.name=eq.John (filter by customer name)
     * ?items.price=gt.100 (filter by item price)
     *
     * @param Builder $builder             The main query builder
     * @param string  $table               The main table name
     * @param array   $relationshipFilters Array of relationship filters
     * @param mixed   $tenantId            Tenant ID for filtering
     */
    public static function applyRelationshipFilters(Builder $builder, string $table, array $relationshipFilters, mixed $tenantId = null): void
    {
        $schema = SchemaRegistryUtils::get();
        RecordConfigService::enableTenantId();

        foreach ($relationshipFilters as $relationshipColumn => $filters) {
            // Parse relationship.column format
            if (!str_contains((string) $relationshipColumn, '.')) {
                continue;
            }

            [$relationshipAlias, $column] = explode('.', (string) $relationshipColumn, 2);

            // Resolve relationship configuration
            $config = RelationshipResolverUtils::resolveRelationship($table, $relationshipAlias);

            if (!$config) {
                continue;
            }

            $relatedTable = $config['table'];
            $type = $config['type'];

            // Validate related table exists in schema
            if (!isset($schema[$relatedTable])) {
                $resolved = SchemaRegistryUtils::resolveTableSchema($relatedTable);
                if ($resolved !== null) {
                    $schema[$relatedTable] = $resolved;
                } else {
                    continue;
                }
            }

            // Validate column exists in related table
            $relatedColumns = array_keys($schema[$relatedTable]->columns ?? []);
            if (!in_array($column, $relatedColumns)) {
                continue;
            }

            // Apply filters for each operator on this relationship column
            if (array_is_list($filters)) {
                foreach ($filters as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }

                    $operator = $entry['operator'] ?? null;
                    $value = $entry['value'] ?? null;
                    $modifier = $entry['modifier'] ?? null;
                    if (!is_string($operator)) {
                        continue;
                    }

                    self::applyRelationshipFilter($builder, $table, $config, $column, $operator, is_string($value) ? $value : null, $tenantId, $schema, is_string($modifier) ? $modifier : null);
                }
            } else {
                foreach ($filters as $operator => $value) {
                    self::applyRelationshipFilter($builder, $table, $config, $column, $operator, is_string($value) ? $value : null, $tenantId, $schema);
                }
            }
        }
    }

    /**
     * Apply a single relationship filter using EXISTS subquery.
     */
    private static function applyRelationshipFilter(Builder $builder, string $table, array $config, string $column, string $operator, ?string $value, mixed $tenantId, array $schema, ?string $modifier = null): void
    {
        $relatedTable = $config['table'];
        $type = $config['type'];
        $enableTenantId = RecordConfigService::enableTenantId();

        $builder->where(function ($query) use ($table, $config, $column, $operator, $value, $tenantId, $schema, $enableTenantId, $modifier): void {
            $relatedTable = $config['table'];
            $type = $config['type'];

            switch ($type) {
                case 'belongsTo':
                    $foreignKey = $config['foreign_key'];
                    $ownerKey = $config['owner_key'] ?? 'id';

                    $query->whereExists(function ($subquery) use ($relatedTable, $table, $foreignKey, $ownerKey, $column, $operator, $value, $tenantId, $schema, $enableTenantId, $modifier): void {
                        $subquery->select(DB::raw('1'))
                            ->from($relatedTable)
                            ->whereColumn(sprintf('%s.%s', $relatedTable, $ownerKey), sprintf('%s.%s', $table, $foreignKey))
                        ;

                        // Apply the filter condition
                        self::applyOperatorToSubquery($subquery, $relatedTable, $column, $operator, $value, $modifier);

                        // Apply tenant filtering if enabled
                        if ($enableTenantId && $tenantId && isset($schema[$relatedTable]->columns[RecordConfigService::tenantColumn()])) {
                            $subquery->where($relatedTable . '.' . RecordConfigService::tenantColumn(), $tenantId);
                        }

                        // Apply soft delete filtering
                        if ($schema[$relatedTable]->softDeletes ?? false) {
                            $subquery->whereNull($relatedTable . '.deleted_at');
                        }
                    });

                    break;

                case 'hasMany':
                    $foreignKey = $config['foreign_key'];
                    $localKey = $config['local_key'] ?? 'id';

                    $query->whereExists(function ($subquery) use ($relatedTable, $table, $foreignKey, $localKey, $column, $operator, $value, $tenantId, $schema, $enableTenantId, $modifier): void {
                        $subquery->select(DB::raw('1'))
                            ->from($relatedTable)
                            ->whereColumn(sprintf('%s.%s', $relatedTable, $foreignKey), sprintf('%s.%s', $table, $localKey))
                        ;

                        // Apply the filter condition
                        self::applyOperatorToSubquery($subquery, $relatedTable, $column, $operator, $value, $modifier);

                        // Apply tenant filtering if enabled
                        if ($enableTenantId && $tenantId && isset($schema[$relatedTable]->columns[RecordConfigService::tenantColumn()])) {
                            $subquery->where($relatedTable . '.' . RecordConfigService::tenantColumn(), $tenantId);
                        }

                        // Apply soft delete filtering
                        if ($schema[$relatedTable]->softDeletes ?? false) {
                            $subquery->whereNull($relatedTable . '.deleted_at');
                        }
                    });

                    break;

                case 'hasManyThrough':
                    $throughTable = $config['through_table'];
                    $firstKey = $config['first_key'];
                    $secondKey = $config['second_key'];
                    $localKey = $config['local_key'] ?? 'id';
                    $secondLocalKey = $config['second_local_key'] ?? 'id';

                    $query->whereExists(function ($subquery) use ($relatedTable, $throughTable, $table, $firstKey, $secondKey, $localKey, $secondLocalKey, $column, $operator, $value, $tenantId, $schema, $enableTenantId, $modifier): void {
                        $subquery->select(DB::raw('1'))
                            ->from($relatedTable)
                            ->join($throughTable, sprintf('%s.%s', $throughTable, $secondLocalKey), '=', sprintf('%s.%s', $relatedTable, $secondKey))
                            ->whereColumn(sprintf('%s.%s', $throughTable, $firstKey), sprintf('%s.%s', $table, $localKey))
                        ;

                        // Apply the filter condition
                        self::applyOperatorToSubquery($subquery, $relatedTable, $column, $operator, $value, $modifier);

                        // Apply tenant filtering if enabled
                        if ($enableTenantId && $tenantId) {
                            if (isset($schema[$relatedTable]->columns[RecordConfigService::tenantColumn()])) {
                                $subquery->where($relatedTable . '.' . RecordConfigService::tenantColumn(), $tenantId);
                            }

                            if (isset($schema[$throughTable]->columns[RecordConfigService::tenantColumn()])) {
                                $subquery->where($throughTable . '.' . RecordConfigService::tenantColumn(), $tenantId);
                            }
                        }

                        // Apply soft delete filtering
                        if ($schema[$relatedTable]->softDeletes ?? false) {
                            $subquery->whereNull($relatedTable . '.deleted_at');
                        }

                        if ($schema[$throughTable]->softDeletes ?? false) {
                            $subquery->whereNull($throughTable . '.deleted_at');
                        }
                    });

                    break;

                case 'belongsToMany':
                    $pivotTable = $config['pivot_table'];
                    $foreignPivotKey = $config['foreign_pivot_key'];
                    $relatedPivotKey = $config['related_pivot_key'];
                    $parentKey = $config['parent_key'];
                    $relatedKey = $config['related_key'];

                    $query->whereExists(function ($subquery) use ($relatedTable, $pivotTable, $table, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey, $column, $operator, $value, $tenantId, $schema, $enableTenantId, $modifier): void {
                        $subquery->select(DB::raw('1'))
                            ->from($relatedTable)
                            ->join($pivotTable, sprintf('%s.%s', $pivotTable, $relatedPivotKey), '=', sprintf('%s.%s', $relatedTable, $relatedKey))
                            ->whereColumn(sprintf('%s.%s', $pivotTable, $foreignPivotKey), sprintf('%s.%s', $table, $parentKey));

                        // Apply the filter condition
                        self::applyOperatorToSubquery($subquery, $relatedTable, $column, $operator, $value, $modifier);

                        // Apply tenant filtering if enabled
                        if ($enableTenantId && $tenantId && isset($schema[$relatedTable]->columns[RecordConfigService::tenantColumn()])) {
                            $subquery->where($relatedTable . '.' . RecordConfigService::tenantColumn(), $tenantId);
                        }

                        // Apply soft delete filtering
                        if ($schema[$relatedTable]->softDeletes ?? false) {
                            $subquery->whereNull($relatedTable . '.deleted_at');
                        }
                    });

                    break;
            }
        });
    }

    /**
     * Apply operator conditions to subquery for relationship filtering.
     */
    public static function applyOperatorToSubquery(mixed $subquery, string $table, string $column, string $operator, ?string $value, ?string $modifier = null): void
    {
        $fullColumn = sprintf('%s.%s', $table, $column);

        if (null !== $modifier) {
            $modifierValues = self::parseModifierValues($value);
            if ([] === $modifierValues) {
                return;
            }

            $subquery->where(function ($group) use ($modifierValues, $table, $column, $operator, $modifier): void {
                foreach ($modifierValues as $index => $modifierValue) {
                    $method = ('any' === $modifier && $index > 0) ? 'orWhere' : 'where';
                    $group->{$method}(function ($nested) use ($table, $column, $operator, $modifierValue): void {
                        self::applyOperatorToSubquery($nested, $table, $column, $operator, $modifierValue);
                    });
                }
            });

            return;
        }

        switch ($operator) {
            case 'eq':
                $value = self::normalizePostgrestListValue($value);
                if (str_contains((string) $value, ',')) {
                    $subquery->whereIn($fullColumn, array_map(trim(...), explode(',', (string) $value)));
                } else {
                    $subquery->where($fullColumn, '=', $value);
                }

                break;

            case 'neq':
                $value = self::normalizePostgrestListValue($value);
                if (str_contains((string) $value, ',')) {
                    $subquery->whereNotIn($fullColumn, array_map(trim(...), explode(',', (string) $value)));
                } else {
                    $subquery->where($fullColumn, '!=', $value);
                }

                break;

            case 'like':
            case 'contains':
                $subquery->where($fullColumn, 'like', '%' . $value . '%');

                break;

            case 'ilike':
                if ('pgsql' === DB::getDriverName()) {
                    $subquery->where($fullColumn, 'ilike', '%' . $value . '%');
                } else {
                    $subquery->whereRaw('LOWER(' . $fullColumn . ') like ?', ['%' . mb_strtolower((string) $value) . '%']);
                }

                break;

            case 'gt':
                $subquery->where($fullColumn, '>', $value);

                break;

            case 'lt':
                $subquery->where($fullColumn, '<', $value);

                break;

            case 'gte':
                $subquery->where($fullColumn, '>=', $value);

                break;

            case 'lte':
                $subquery->where($fullColumn, '<=', $value);

                break;

            case 'in':
                $value = self::normalizePostgrestListValue($value);
                $values = array_map(trim(...), explode(',', (string) $value));
                $subquery->whereIn($fullColumn, $values);

                break;

            case 'not_in':
                $value = self::normalizePostgrestListValue($value);
                $values = array_map(trim(...), explode(',', (string) $value));
                $subquery->whereNotIn($fullColumn, $values);

                break;

            case 'is':
                $subquery->whereNull($fullColumn);

                break;

            case 'is_not':
                $subquery->whereNotNull($fullColumn);

                break;

            case 'starts_with':
                $subquery->where($fullColumn, 'like', $value . '%');

                break;

            case 'ends_with':
                $subquery->where($fullColumn, 'like', '%' . $value);

                break;

            case 'between':
                $values = array_map(trim(...), explode(',', (string) $value));
                if (2 === count($values)) {
                    $subquery->whereBetween($fullColumn, $values);
                }

                break;

            case 'not_between':
                $values = array_map(trim(...), explode(',', (string) $value));
                if (2 === count($values)) {
                    $subquery->whereNotBetween($fullColumn, $values);
                }

                break;

            case 'date_eq':
                $subquery->whereDate($fullColumn, $value);

                break;

            case 'date_gt':
                $subquery->whereDate($fullColumn, '>', $value);

                break;

            case 'date_lt':
                $subquery->whereDate($fullColumn, '<', $value);

                break;

            case 'date_gte':
                $subquery->whereDate($fullColumn, '>=', $value);

                break;

            case 'date_lte':
                $subquery->whereDate($fullColumn, '<=', $value);

                break;

            case 'match':
                self::assertOperatorDriverSupported('match', ['mysql', 'mariadb', 'pgsql']);
                if ('pgsql' === DB::getDriverName()) {
                    $subquery->whereRaw($fullColumn . ' ~ ?', [(string) $value]);
                } else {
                    $subquery->whereRaw($fullColumn . ' REGEXP ?', [(string) $value]);
                }

                break;

            case 'imatch':
                self::assertOperatorDriverSupported('imatch', ['mysql', 'mariadb', 'pgsql']);
                if ('pgsql' === DB::getDriverName()) {
                    $subquery->whereRaw($fullColumn . ' ~* ?', [(string) $value]);
                } else {
                    $subquery->whereRaw('LOWER(' . $fullColumn . ') REGEXP ?', [mb_strtolower((string) $value)]);
                }

                break;

            case 'not_ilike':
                if ('pgsql' === DB::getDriverName()) {
                    $subquery->where($fullColumn, 'not ilike', '%' . $value . '%');
                } else {
                    $subquery->whereRaw('LOWER(' . $fullColumn . ') not like ?', ['%' . mb_strtolower((string) $value) . '%']);
                }

                break;

            case 'not_regex':
            case 'not_match':
            case 'not_imatch':
                self::assertOperatorDriverSupported($operator, ['mysql', 'mariadb', 'pgsql']);
                if ('pgsql' === DB::getDriverName()) {
                    $pgOperator = 'not_regex' === $operator || 'not_match' === $operator ? '!~' : '!~*';
                    $subquery->whereRaw($fullColumn . ' ' . $pgOperator . ' ?', [(string) $value]);
                } else {
                    $subquery->whereRaw($fullColumn . ' NOT REGEXP ?', ['not_imatch' === $operator ? mb_strtolower((string) $value) : (string) $value]);
                }

                break;

            case 'fts':
            case 'not_fts':
            case 'plfts':
            case 'not_plfts':
            case 'phfts':
            case 'not_phfts':
            case 'wfts':
            case 'not_wfts':
                self::assertOperatorDriverSupported($operator, ['pgsql']);
                self::applyPgsqlFullTextToSubquery($subquery, $fullColumn, (string) $value, $operator);

                break;

            case 'cs':
            case 'not_cs':
            case 'cd':
            case 'not_cd':
            case 'ov':
            case 'not_ov':
            case 'sl':
            case 'not_sl':
            case 'sr':
            case 'not_sr':
            case 'nxl':
            case 'not_nxl':
            case 'nxr':
            case 'not_nxr':
            case 'adj':
            case 'not_adj':
                self::assertOperatorDriverSupported($operator, ['pgsql']);
                self::applyPgsqlNativeToSubquery($subquery, $fullColumn, (string) $value, $operator);

                break;

            default:
                throw new InvalidArgumentException(sprintf("Operator '%s' is not supported.", $operator));
        }
    }

    private static function applyOperator(Builder $builder, string $table, array $allowedCols, string $key, string $operator, ?string $value, ?string $modifier = null): void
    {
        $isMultiple = str_contains($key, ',');
        $columns = $isMultiple ? array_map(trim(...), explode(',', $key)) : [$key];
        // Normalize and keep only allowed columns
        $columns = array_values(array_filter(array_map(function (string $c) use ($allowedCols): ?string {
            if (str_contains($c, '.')) {
                $c = explode('.', $c)[1];
            }

            return in_array($c, $allowedCols, true) ? $c : null;
        }, $columns)));
        if ([] === $columns) {
            return;
        }

        // For most operators we require a non-null value. Operators that are
        // intrinsically value-less (null/empty checks) are handled explicitly
        // below and are allowed to receive a null value.
        if (null === $value && !in_array($operator, ['is', 'is_not', 'empty', 'not_empty'], true)) {
            return;
        }

        if (null !== $modifier) {
            $modifierValues = self::parseModifierValues($value);
            if ([] === $modifierValues) {
                return;
            }

            $builder->where(function (Builder $group) use ($modifierValues, $table, $allowedCols, $key, $operator, $modifier): void {
                foreach ($modifierValues as $index => $modifierValue) {
                    $method = ('any' === $modifier && $index > 0) ? 'orWhere' : 'where';
                    $group->{$method}(function (Builder $subQuery) use ($table, $allowedCols, $key, $operator, $modifierValue): void {
                        self::applyOperator($subQuery, $table, $allowedCols, $key, $operator, $modifierValue);
                    });
                }
            });

            return;
        }

        switch ($operator) {
            case 'is':
                if ($isMultiple) {
                    $builder->where(function ($q) use ($columns, $table): void {
                        foreach ($columns as $column) {
                            $q->orWhereNull($table . '.' . $column);
                        }
                    });
                } elseif (is_string($value) && str_contains($value, ',')) {
                    $vals = array_map(trim(...), explode(',', $value));
                    $builder->where(function ($q) use ($table, $columns, $vals): void {
                        $key = $columns[0];
                        foreach ($vals as $val) {
                            if ('null' === $val) {
                                $q->orWhereNull($table . '.' . $key);
                            }
                        }
                    });
                } else {
                    $builder->whereNull($table . '.' . $columns[0]);
                }

                break;

            case 'eq':
                $value = self::normalizePostgrestListValue($value);
                if ($isMultiple) {
                    $builder->where(function ($q) use ($columns, $value, $table): void {
                        foreach ($columns as $column) {
                            if (str_contains((string) $value, ',')) {
                                $q->orWhereIn($table . '.' . $column, array_map(trim(...), explode(',', (string) $value)));
                            } else {
                                $q->orWhere($table . '.' . $column, '=', $value);
                            }
                        }
                    });
                } else {
                    $keyCol = $columns[0];
                    if (str_contains((string) $value, ',')) {
                        $builder->whereIn($table . '.' . $keyCol, array_map(trim(...), explode(',', (string) $value)));
                    } else {
                        $builder->where($table . '.' . $keyCol, '=', $value);
                    }
                }

                break;

            case 'neq':
                $value = self::normalizePostgrestListValue($value);
                if ($isMultiple) {
                    $builder->where(function ($q) use ($columns, $value, $table): void {
                        foreach ($columns as $column) {
                            if (str_contains((string) $value, ',')) {
                                $q->orWhereNotIn($table . '.' . $column, array_map(trim(...), explode(',', (string) $value)));
                            } else {
                                $q->orWhere($table . '.' . $column, '!=', $value);
                            }
                        }
                    });
                } else {
                    $keyCol = $columns[0];
                    if (str_contains((string) $value, ',')) {
                        $builder->whereNotIn($table . '.' . $keyCol, array_map(trim(...), explode(',', (string) $value)));
                    } else {
                        $builder->where($table . '.' . $keyCol, '!=', $value);
                    }
                }

                break;

            case 'like':
            case 'contains':
                if ($isMultiple) {
                    $builder->where(function ($q) use ($columns, $value, $table): void {
                        foreach ($columns as $column) {
                            $q->orWhere($table . '.' . $column, 'like', '%' . $value . '%');
                        }
                    });
                } else {
                    $builder->where($table . '.' . $columns[0], 'like', '%' . $value . '%');
                }

                break;

            case 'ilike':
                if ($isMultiple) {
                    $builder->where(function (Builder $q) use ($columns, $value, $table): void {
                        foreach ($columns as $column) {
                            self::applyCaseInsensitiveLike($q, $table . '.' . $column, (string) $value);
                        }
                    });
                } else {
                    self::applyCaseInsensitiveLike($builder, $table . '.' . $columns[0], (string) $value);
                }

                break;

            case 'not_ilike':
                if ($isMultiple) {
                    $builder->where(function (Builder $q) use ($columns, $value, $table): void {
                        foreach ($columns as $column) {
                            self::applyCaseInsensitiveLike($q, $table . '.' . $column, (string) $value, true);
                        }
                    });
                } else {
                    self::applyCaseInsensitiveLike($builder, $table . '.' . $columns[0], (string) $value, true);
                }

                break;

            case 'gt':
                self::applyCompare($builder, $table, $columns, '>', $value, $isMultiple);

                break;

            case 'lt':
                self::applyCompare($builder, $table, $columns, '<', $value, $isMultiple);

                break;

            case 'gte':
                self::applyCompare($builder, $table, $columns, '>=', $value, $isMultiple);

                break;

            case 'lte':
                self::applyCompare($builder, $table, $columns, '<=', $value, $isMultiple);

                break;

            case 'between':
                $parts = is_string($value) ? explode(',', $value, 2) : [];
                if (2 === count($parts)) {
                    [$a, $b] = [trim($parts[0]), trim($parts[1])];
                    if ($isMultiple) {
                        $builder->where(function ($q) use ($columns, $a, $b, $table): void {
                            foreach ($columns as $column) {
                                $q->orWhereBetween($table . '.' . $column, [$a, $b]);
                            }
                        });
                    } else {
                        $builder->whereBetween($table . '.' . $columns[0], [$a, $b]);
                    }
                }

                break;

            case 'not_between':
                $parts = is_string($value) ? explode(',', $value, 2) : [];
                if (2 === count($parts)) {
                    [$a, $b] = [trim($parts[0]), trim($parts[1])];
                    if ($isMultiple) {
                        $builder->where(function ($q) use ($columns, $a, $b, $table): void {
                            foreach ($columns as $column) {
                                $q->orWhereNotBetween($table . '.' . $column, [$a, $b]);
                            }
                        });
                    } else {
                        $builder->whereNotBetween($table . '.' . $columns[0], [$a, $b]);
                    }
                }

                break;

            case 'in':
                $value = self::normalizePostgrestListValue($value);
                $vals = array_map(trim(...), explode(',', (string) $value));
                if ($isMultiple) {
                    $builder->where(function ($q) use ($columns, $vals, $table): void {
                        foreach ($columns as $column) {
                            $q->orWhereIn($table . '.' . $column, $vals);
                        }
                    });
                } else {
                    $builder->whereIn($table . '.' . $columns[0], $vals);
                }

                break;

            case 'starts_with':
                if ($isMultiple) {
                    $builder->where(function ($q) use ($columns, $value, $table): void {
                        foreach ($columns as $column) {
                            $q->orWhere($table . '.' . $column, 'like', $value . '%');
                        }
                    });
                } else {
                    $builder->where($table . '.' . $columns[0], 'like', $value . '%');
                }

                break;

            case 'ends_with':
                if ($isMultiple) {
                    $builder->where(function ($q) use ($columns, $value, $table): void {
                        foreach ($columns as $column) {
                            $q->orWhere($table . '.' . $column, 'like', '%' . $value);
                        }
                    });
                } else {
                    $builder->where($table . '.' . $columns[0], 'like', '%' . $value);
                }

                break;

            case 'not_like':
                if ($isMultiple) {
                    $builder->where(function ($q) use ($columns, $value, $table): void {
                        foreach ($columns as $column) {
                            $q->orWhere($table . '.' . $column, 'not like', '%' . $value . '%');
                        }
                    });
                } else {
                    $builder->where($table . '.' . $columns[0], 'not like', '%' . $value . '%');
                }

                break;

            case 'not_in':
                $value = self::normalizePostgrestListValue($value);
                $vals = array_map(trim(...), explode(',', (string) $value));
                if ($isMultiple) {
                    $builder->where(function ($q) use ($columns, $vals, $table): void {
                        foreach ($columns as $column) {
                            $q->orWhereNotIn($table . '.' . $column, $vals);
                        }
                    });
                } else {
                    $builder->whereNotIn($table . '.' . $columns[0], $vals);
                }

                break;

            case 'is_not':
                if ($isMultiple) {
                    $builder->where(function ($q) use ($columns, $table): void {
                        foreach ($columns as $column) {
                            $q->orWhereNotNull($table . '.' . $column);
                        }
                    });
                } else {
                    $builder->whereNotNull($table . '.' . $columns[0]);
                }

                break;

            case 'regex':
                self::assertOperatorDriverSupported('regex', ['mysql', 'mariadb', 'pgsql']);
                if ($isMultiple) {
                    $builder->where(function ($q) use ($columns, $value, $table): void {
                        foreach ($columns as $column) {
                            if ('pgsql' === DB::getDriverName()) {
                                $q->orWhereRaw($table . '.' . $column . ' ~ ?', [$value]);
                            } else {
                                $q->orWhereRaw($table . '.' . $column . ' REGEXP ?', [$value]);
                            }
                        }
                    });
                } elseif ('pgsql' === DB::getDriverName()) {
                    $builder->whereRaw($table . '.' . $columns[0] . ' ~ ?', [$value]);
                } else {
                    $builder->whereRaw($table . '.' . $columns[0] . ' REGEXP ?', [$value]);
                }

                break;

            case 'not_regex':
            case 'match':
            case 'not_match':
            case 'imatch':
            case 'not_imatch':
                self::assertOperatorDriverSupported($operator, ['mysql', 'mariadb', 'pgsql']);
                self::applyRegexOperator($builder, $table, $columns, (string) $value, $operator, $isMultiple);

                break;

            case 'fts':
            case 'not_fts':
            case 'plfts':
            case 'not_plfts':
            case 'phfts':
            case 'not_phfts':
            case 'wfts':
            case 'not_wfts':
                self::assertOperatorDriverSupported($operator, ['pgsql']);
                self::applyPgsqlFullTextOperator($builder, $table, $columns, (string) $value, $operator, $isMultiple);

                break;

            case 'cs':
            case 'not_cs':
            case 'cd':
            case 'not_cd':
            case 'ov':
            case 'not_ov':
            case 'sl':
            case 'not_sl':
            case 'sr':
            case 'not_sr':
            case 'nxl':
            case 'not_nxl':
            case 'nxr':
            case 'not_nxr':
            case 'adj':
            case 'not_adj':
                self::assertOperatorDriverSupported($operator, ['pgsql']);
                self::applyPgsqlNativeOperator($builder, $table, $columns, (string) $value, $operator, $isMultiple);

                break;

            case 'date_eq':
                if ($isMultiple) {
                    $builder->where(function ($q) use ($columns, $value, $table): void {
                        foreach ($columns as $column) {
                            $q->orWhereDate($table . '.' . $column, '=', $value);
                        }
                    });
                } else {
                    $builder->whereDate($table . '.' . $columns[0], '=', $value);
                }

                break;

            case 'date_gt':
                if ($isMultiple) {
                    $builder->where(function ($q) use ($columns, $value, $table): void {
                        foreach ($columns as $column) {
                            $q->orWhereDate($table . '.' . $column, '>', $value);
                        }
                    });
                } else {
                    $builder->whereDate($table . '.' . $columns[0], '>', $value);
                }

                break;

            case 'date_lt':
                if ($isMultiple) {
                    $builder->where(function ($q) use ($columns, $value, $table): void {
                        foreach ($columns as $column) {
                            $q->orWhereDate($table . '.' . $column, '<', $value);
                        }
                    });
                } else {
                    $builder->whereDate($table . '.' . $columns[0], '<', $value);
                }

                break;

            case 'date_gte':
                if ($isMultiple) {
                    $builder->where(function ($q) use ($columns, $value, $table): void {
                        foreach ($columns as $column) {
                            $q->orWhereDate($table . '.' . $column, '>=', $value);
                        }
                    });
                } else {
                    $builder->whereDate($table . '.' . $columns[0], '>=', $value);
                }

                break;

            case 'date_lte':
                if ($isMultiple) {
                    $builder->where(function ($q) use ($columns, $value, $table): void {
                        foreach ($columns as $column) {
                            $q->orWhereDate($table . '.' . $column, '<=', $value);
                        }
                    });
                } else {
                    $builder->whereDate($table . '.' . $columns[0], '<=', $value);
                }

                break;

            case 'empty':
                // For text columns: NULL or '' is considered empty.
                // For non-text columns: only NULL is considered empty to avoid invalid casts
                // on databases like PostgreSQL (e.g. comparing integer column to '').
                $searchableCols = self::getSearchableColumns($table, $allowedCols);

                if ($isMultiple) {
                    $builder->where(function ($q) use ($columns, $table, $searchableCols): void {
                        foreach ($columns as $column) {
                            $q->orWhere(function ($subQ) use ($table, $column, $searchableCols): void {
                                if (in_array($column, $searchableCols, true)) {
                                    $subQ->whereNull($table . '.' . $column)->orWhere($table . '.' . $column, '=', '');
                                } else {
                                    $subQ->whereNull($table . '.' . $column);
                                }
                            });
                        }
                    });
                } else {
                    $builder->where(function ($q) use ($table, $columns, $searchableCols): void {
                        $column = $columns[0];
                        if (in_array($column, $searchableCols, true)) {
                            $q->whereNull($table . '.' . $column)->orWhere($table . '.' . $column, '=', '');
                        } else {
                            $q->whereNull($table . '.' . $column);
                        }
                    });
                }

                break;

            case 'not_empty':
                // For text columns: NOT NULL and != '' is considered not empty.
                // For non-text columns: only NOT NULL; comparing to '' would break on
                // strict type databases like PostgreSQL.
                $searchableCols = self::getSearchableColumns($table, $allowedCols);

                if ($isMultiple) {
                    $builder->where(function ($q) use ($columns, $table, $searchableCols): void {
                        foreach ($columns as $column) {
                            $q->orWhere(function ($subQ) use ($table, $column, $searchableCols): void {
                                if (in_array($column, $searchableCols, true)) {
                                    $subQ->whereNotNull($table . '.' . $column)->where($table . '.' . $column, '!=', '');
                                } else {
                                    $subQ->whereNotNull($table . '.' . $column);
                                }
                            });
                        }
                    });
                } else {
                    $builder->where(function ($q) use ($table, $columns, $searchableCols): void {
                        $column = $columns[0];
                        if (in_array($column, $searchableCols, true)) {
                            $q->whereNotNull($table . '.' . $column)->where($table . '.' . $column, '!=', '');
                        } else {
                            $q->whereNotNull($table . '.' . $column);
                        }
                    });
                }

                break;

            default:
                throw new InvalidArgumentException(sprintf("Operator '%s' is not supported.", $operator));
        }
    }

    private static function applyCompare(Builder $builder, string $table, array $columns, string $op, string $value, bool $isMultiple): void
    {
        if ($isMultiple) {
            $builder->where(function ($q) use ($columns, $op, $value, $table): void {
                foreach ($columns as $column) {
                    $q->orWhere($table . '.' . $column, $op, $value);
                }
            });
        } else {
            $builder->where($table . '.' . $columns[0], $op, $value);
        }
    }

    private static function parseSelectColumns(string $selectParam, string $table, array $allowedCols): array
    {
        // Only include main table columns; ignore relationship segments like alias:table(col,...)
        $mainCols = RelationshipResolverUtils::getMainTableColumns($selectParam);
        $prefixed = [];
        foreach ($mainCols as $mainCol) {
            $mainCol = trim((string) $mainCol);
            if ('*' === $mainCol) {
                $prefixed[] = $table . '.*';

                continue;
            }

            // If column is qualified, strip table part for validation
            $rawCol = $mainCol;
            if (str_contains($rawCol, '.')) {
                $rawCol = explode('.', $rawCol)[1];
            }

            if (!in_array($rawCol, $allowedCols, true)) {
                continue;
            }

            // Prefixed output
            $prefixed[] = str_contains($mainCol, '.') ? $mainCol : $table . '.' . $mainCol;
        }

        return ['main' => $prefixed];
    }

    /**
     * Apply sorting to the query builder.
     */
    public static function applySort(Builder $builder, Request $request, string $table, string $defaultOrderBy = 'id'): void
    {
        $allowedCols = self::getAllowedColumns($table);

        $sortByParam = $request->query('sortby');

        // Default logic: prefer created_at if available and no sort specified
        if (!$sortByParam) {
            $sortByParam = in_array('created_at', $allowedCols, true) ? 'created_at' : $defaultOrderBy;
        }

        $sortOrder = strtolower($request->query('order', 'desc'));
        $sortOrder = in_array($sortOrder, ['asc', 'desc']) ? $sortOrder : 'desc';

        // support table-qualified input like table.column
        $requestedCol = $sortByParam;
        if (str_contains($requestedCol, '.')) {
            $parts = explode('.', $requestedCol);
            $requestedCol = end($parts);
        }

        if (!in_array($requestedCol, $allowedCols, true)) {
            if (in_array('created_at', $allowedCols, true)) {
                $requestedCol = 'created_at';
            } else {
                $requestedCol = in_array($defaultOrderBy, $allowedCols, true) ? $defaultOrderBy : ($allowedCols[0] ?? 'id');
            }
        }

        $builder->orderBy($table . '.' . $requestedCol, $sortOrder);
    }

    /**
     * Get allowed columns for a table.
     */
    public static function getAllowedColumns(string $table): array
    {
        // Cache allowed columns to avoid repeated schema lookups
        if (!isset(self::$columnCache[$table])) {
            $schema = SchemaRegistryUtils::get();
            self::$columnCache[$table] = array_keys($schema[$table]->columns ?? []);
        }

        // If cache is set but empty, try fetching again if schema has columns
        // This handles race conditions where cache was set before columns were populated
        if (empty(self::$columnCache[$table])) {
            $schema = SchemaRegistryUtils::get();
            if (isset($schema[$table]) && !empty($schema[$table]->columns)) {
                self::$columnCache[$table] = array_keys($schema[$table]->columns);
            }
        }

        return self::$columnCache[$table];
    }

    public static function applyAggregateAndGroupBy(Builder $builder, Request $request, string $table): ?array
    {
        $aggregateParam = $request->query('aggregate');
        if (!$aggregateParam) {
            return null;
        }

        $builder->orders = null;
        $builder->columns = null;

        $groupByParam = $request->query('group_by');
        $allowedCols = QueryBuilderFiltersUtils::getAllowedColumns($table);

        $groupByCols = [];
        if ($groupByParam) {
            $candidates = array_map(trim(...), explode(',', (string) $groupByParam));
            foreach ($candidates as $candidate) {
                if ('' === $candidate) {
                    continue;
                }

                $col = $candidate;
                if (str_contains($col, '.')) {
                    $parts = explode('.', $col);
                    $col = end($parts);
                }

                if (in_array($col, $allowedCols, true) && !in_array($col, $groupByCols, true)) {
                    $groupByCols[] = $col;
                }
            }
        }

        $selects = [];
        foreach ($groupByCols as $col) {
            $selects[] = $table . '.' . $col . ' as ' . $col;
        }

        $aggregateMeta = [];

        $tokens = array_map(trim(...), explode(',', (string) $aggregateParam));
        foreach ($tokens as $token) {
            if ('' === $token) {
                continue;
            }

            $func = $token;
            $column = null;

            if (str_contains($token, ':')) {
                [$func, $column] = explode(':', $token, 2);
            } elseif (str_contains($token, '.')) {
                [$func, $column] = explode('.', $token, 2);
            }

            $func = strtolower((string) $func);
            $column = null !== $column ? trim($column) : null;

            if (!in_array($func, ['count', 'sum', 'avg', 'min', 'max'], true)) {
                continue;
            }

            $colName = $column;
            if ($colName && str_contains($colName, '.')) {
                $parts = explode('.', $colName);
                $colName = end($parts);
            }

            if ($colName && !in_array($colName, $allowedCols, true)) {
                continue;
            }

            if ('count' !== $func && !$colName) {
                continue;
            }

            $alias = $func . ($colName ? '_' . $colName : '');

            if ('count' === $func && !$colName) {
                $selects[] = 'COUNT(*) as ' . $alias;
            } else {
                $target = $table . '.' . $colName;
                $selects[] = strtoupper($func) . '(' . $target . ') as ' . $alias;
            }

            $aggregateMeta[] = ['function' => $func, 'column' => $colName, 'alias' => $alias];
        }

        if (empty($selects)) {
            return null;
        }

        $builder->selectRaw(implode(', ', $selects));

        foreach ($groupByCols as $col) {
            $builder->groupBy($table . '.' . $col);
        }

        $rows = $builder->get()->map(static fn($row): array => (array) $row)->all();
        $total = count($rows);

        $headers = [
            'X-Total-Count' => (string) $total,
        ];

        $meta = [
            'total' => $total,
        ];

        if (!empty($groupByCols)) {
            $meta['group_by'] = $groupByCols;
        }

        if (!empty($aggregateMeta)) {
            $meta['aggregate'] = $aggregateMeta;
        }

        return [
            'data' => $rows,
            'meta' => $meta,
            'headers' => $headers,
        ];
    }

    /**
     * Get searchable columns (text/varchar types) for better search performance.
     */
    private static function getSearchableColumns(string $table, array $allowedCols): array
    {
        $cacheKey = $table . '_searchable';
        if (!isset(self::$columnCache[$cacheKey])) {
            $searchableCols = [];
            $tableConfig = SchemaRegistryUtils::getTable($table);
            $columns = [];
            if ($tableConfig instanceof RecordTableType) {
                $columns = $tableConfig->columns ?? [];
            } elseif (is_array($tableConfig)) {
                $columns = $tableConfig['columns'] ?? [];
            }

            if ($columns === null || $columns === []) {
                self::$columnCache[$cacheKey] = [];

                return [];
            }

            foreach ($allowedCols as $allowedCol) {
                $columnInfo = $columns[$allowedCol] ?? [];
                $rawType = strtolower((string) ($columnInfo['type'] ?? $columnInfo['udt_name'] ?? ''));

                // Extract base type by removing length specifications (e.g., varchar(191) -> varchar)
                $type = preg_replace('/\([^)]*\)/', '', $rawType);
                $type = str_replace('character varying', 'varchar', $type);
                $type = str_replace('character', 'char', $type);

                // Only include text-based columns for search
                if (in_array($type, ['varchar', 'text', 'char', 'string', 'longtext', 'mediumtext', 'citext', 'uuid', 'json', 'jsonb'], true)) {
                    $searchableCols[] = $allowedCol;
                }
            }

            self::$columnCache[$cacheKey] = $searchableCols;
        }

        return self::$columnCache[$cacheKey];
    }

    private static function getNumericSearchableColumns(string $table, array $allowedCols): array
    {
        $cacheKey = $table . '_numeric_searchable';
        if (!isset(self::$columnCache[$cacheKey])) {
            $numericCols = [];
            $tableConfig = SchemaRegistryUtils::getTable($table);
            $columns = [];
            if ($tableConfig instanceof RecordTableType) {
                $columns = $tableConfig->columns ?? [];
            } elseif (is_array($tableConfig)) {
                $columns = $tableConfig['columns'] ?? [];
            }

            if ($columns === null || $columns === []) {
                self::$columnCache[$cacheKey] = [];

                return [];
            }

            foreach ($allowedCols as $allowedCol) {
                $columnInfo = $columns[$allowedCol] ?? [];
                $rawType = strtolower((string) ($columnInfo['type'] ?? $columnInfo['udt_name'] ?? ''));
                $type = preg_replace('/\([^)]*\)/', '', $rawType);

                if (in_array($type, ['int2', 'int4', 'int8', 'integer', 'bigint', 'smallint', 'serial', 'bigserial', 'numeric', 'decimal', 'float4', 'float8', 'real', 'double precision'], true)) {
                    $numericCols[] = $allowedCol;
                }
            }

            self::$columnCache[$cacheKey] = $numericCols;
        }

        return self::$columnCache[$cacheKey];
    }

    /**
     * Check if a table has full-text index for optimized search.
     *
     * This method determines whether a table has full-text indexing available
     * for the specified columns, enabling the use of MATCH AGAINST queries
     * for significantly better performance on text searches.
     *
     * Performance Impact:
     * - MATCH AGAINST is 10-100x faster than LIKE for text searches
     * - Enables relevance scoring for better search results
     * - Reduces CPU usage for large text searches
     *
     * Fallback Strategy:
     * - Returns false if no full-text index is detected
     * - Allows graceful fallback to LIKE-based searches
     * - Maintains compatibility with all table configurations
     *
     * @param string $table   Table name to check for full-text indexes
     * @param array  $columns Columns to check for full-text indexing
     *
     * @return bool True if full-text index exists, false otherwise
     */
    private static function hasFullTextIndex(string $table, array $columns): bool
    {
        $cacheKey = $table . '_fulltext_' . implode('_', $columns);
        if (!isset(self::$searchableCache[$cacheKey])) {
            $tableConfig = SchemaRegistryUtils::getTable($table);
            $indexes = [];
            if ($tableConfig instanceof RecordTableType) {
                $indexes = $tableConfig->columnIndexes ?? [];
            } elseif (is_array($tableConfig)) {
                $indexes = $tableConfig['columnIndexes'] ?? [];
            }

            $hasFullText = is_array($indexes) && in_array($columns, $indexes, true);

            self::$searchableCache[$cacheKey] = $hasFullText;
        }

        return self::$searchableCache[$cacheKey];
    }

    /**
     * Create a unique identifier for lazy operation.
     */
    private static function createLazyOperation(string $table, array $allowedCols, array $params): string
    {
        return 'lazy_' . md5($table . serialize($allowedCols) . serialize($params) . microtime(true));
    }

    /**
     * Execute operators immediately (extracted from original logic).
     */
    private static function executeOperators(Builder $builder, string $table, array $allowedCols, array $params): void
    {
        $groupedFilters = array_merge(
            self::extractConfiguredSearchFilters($params, $table),
            self::extractGroupedFilters($params)
        );
        if ([] !== $groupedFilters) {
            self::applyGroupedFilters($builder, $table, $allowedCols, $groupedFilters, null);
        }

        foreach ($params as $key => $values) {
            // Skip lazy parameter
            if ('lazy' === $key) {
                continue;
            }

            $values = is_array($values) ? $values : [$values];
            foreach ($values as $value) {
                $raw = (string) $value;
                $parsedOperator = self::parseOperatorExpression($raw);
                if (null !== $parsedOperator) {
                    self::applyOperator(
                        builder: $builder,
                        table: $table,
                        allowedCols: $allowedCols,
                        key: $key,
                        operator: $parsedOperator['operator'],
                        value: $parsedOperator['value'],
                        modifier: $parsedOperator['modifier']
                    );
                } elseif (preg_match('/^compare\.(eq|neq|gt|lt|gte|lte)\.(.+)$/', $raw, $m)) {
                    $left = $key;
                    $right = $m[2];
                    if (str_contains((string) $left, '.')) {
                        $left = explode('.', (string) $left)[1];
                    }

                    if (str_contains($right, '.')) {
                        $right = explode('.', $right)[1];
                    }

                    $map = ['eq' => '=', 'neq' => '!=', 'gt' => '>', 'lt' => '<', 'gte' => '>=', 'lte' => '<='];
                    if (isset($map[$m[1]]) && in_array($left, $allowedCols, true) && in_array($right, $allowedCols, true)) {
                        $builder->whereColumn($table . '.' . $left, $map[$m[1]], $table . '.' . $right);
                    }
                }
            }
        }
    }

    /**
     * Optimized operator execution with batch processing and tenant_id integration.
     *
     * This method implements several performance optimizations for query filtering:
     * 1. Operation categorization for batch processing efficiency
     * 2. Tenant-aware filtering based on configuration settings
     * 3. Pre-validation to reduce runtime errors
     * 4. Optimized execution order (equality -> range -> text -> complex)
     * 5. Subquery grouping to maintain proper SQL logic
     *
     * Performance Benefits:
     * - Reduces query complexity by grouping similar operations
     * - Applies tenant filtering only when enabled in configuration
     * - Uses optimized execution order for better database performance
     * - Implements proper subquery grouping for complex conditions
     *
     * Operation Categories:
     * - Equality: eq, neq, is, is_not, in, not_in (fastest operations)
     * - Range: gt, lt, gte, lte, between, not_between, date_* (indexed operations)
     * - Text: like, contains, starts_with, ends_with, not_like (slower operations)
     * - Complex: regex, empty, not_empty (most expensive operations)
     *
     * @param Builder $builder     Laravel query builder instance
     * @param string  $table       Target table name for schema validation
     * @param array   $allowedCols Allowed columns for security validation
     * @param array   $params      Query parameters containing filter operations
     */
    private static function executeOperatorsOptimized(Builder $builder, string $table, array $allowedCols, array $params): void
    {
        $enableTenantId = RecordConfigService::enableTenantId();
        $tenantCol = RecordConfigService::tenantColumn();
        $tenantFilterValue = null;
        $tenantHasOperator = false;
        $tenantId = null;
        if ($enableTenantId && isset($params[$tenantCol])) {
            $tenantValues = is_array($params[$tenantCol]) ? $params[$tenantCol] : [$params[$tenantCol]];
            foreach ($tenantValues as $value) {
                if (self::isOperatorExpression((string) $value)) {
                    $tenantHasOperator = true;
                    break;
                }
            }

            $tenantFilterValue = is_array($params[$tenantCol]) ? ($params[$tenantCol][0] ?? null) : $params[$tenantCol];
            if (!$tenantHasOperator) {
                $tenantId = $tenantFilterValue;
            }
        }

        $groupedFilters = array_merge(
            self::extractConfiguredSearchFilters($params, $table),
            self::extractGroupedFilters($params)
        );
        if ([] !== $groupedFilters) {
            self::applyGroupedFilters($builder, $table, $allowedCols, $groupedFilters, $tenantId);
        }

        // Separate relationship filters from regular column filters
        $relationshipFilters = [];
        $regularOperations = [
            'equality' => [],
            'range' => [],
            'text' => [],
            'complex' => [],
        ];

        // Pre-validate and categorize operations
        foreach ($params as $key => $values) {
            if ('lazy' === $key) {
                continue;
            }

            if ($enableTenantId && $tenantCol === $key && !$tenantHasOperator) {
                continue;
            }

            $values = is_array($values) ? $values : [$values];
            foreach ($values as $value) {
                $raw = (string) $value;
                $parsedOperator = self::parseOperatorExpression($raw);
                if (null !== $parsedOperator) {
                    $operator = $parsedOperator['operator'];
                    $operatorValue = $parsedOperator['value'];
                    $modifier = $parsedOperator['modifier'];

                    // Check if this is a relationship filter (contains dot)
                    if (str_contains((string) $key, '.')) {
                        // This is a relationship filter
                        if (!isset($relationshipFilters[$key])) {
                            $relationshipFilters[$key] = [];
                        }

                        $relationshipFilters[$key][] = [
                            'operator' => $operator,
                            'value' => $operatorValue,
                            'modifier' => $modifier,
                        ];
                    } elseif (in_array($operator, ['eq', 'neq', 'is', 'is_not', 'in', 'not_in'], true)) {
                        // Regular column filter - categorize operations for batch processing
                        $regularOperations['equality'][] = [$key, $operator, $operatorValue, $modifier];
                    } elseif (in_array($operator, ['gt', 'lt', 'gte', 'lte', 'between', 'not_between', 'date_gt', 'date_lt', 'date_gte', 'date_lte'], true)) {
                        $regularOperations['range'][] = [$key, $operator, $operatorValue, $modifier];
                    } elseif (in_array($operator, ['like', 'contains', 'starts_with', 'ends_with', 'not_like'], true)) {
                        $regularOperations['text'][] = [$key, $operator, $operatorValue, $modifier];
                    } else {
                        $regularOperations['complex'][] = [$key, $operator, $operatorValue, $modifier];
                    }
                }
            }
        }

        // Apply relationship filters using optimized subqueries
        if ($relationshipFilters !== []) {
            self::applyRelationshipFilters($builder, $table, $relationshipFilters, $tenantId);
        }

        // Execute regular operations in optimized order (equality first, then range, text, complex)
        foreach (['equality', 'range', 'text', 'complex'] as $type) {
            if (isset($regularOperations[$type]) && [] !== $regularOperations[$type]) {
                $builder->where(function (Builder $subQuery) use ($regularOperations, $type, $table, $allowedCols): void {
                    foreach ($regularOperations[$type] as [$key, $operator, $value, $modifier]) {
                        self::applyOperator($subQuery, $table, $allowedCols, $key, $operator, $value, $modifier);
                    }
                });
            }
        }

        // Apply tenant_id filtering if enabled and available
        if ($enableTenantId && null !== $tenantFilterValue && '' !== $tenantFilterValue && !$tenantHasOperator) {
            $schema = SchemaRegistryUtils::get();
            if (isset($schema[$table]->columns[$tenantCol])) {
                $builder->where($table . '.' . $tenantCol, $tenantFilterValue);
            }
        }
    }

    private static function extractGroupedFilters(array &$params): array
    {
        $grouped = [];
        foreach (['and', 'or'] as $logic) {
            if (!array_key_exists($logic, $params)) {
                continue;
            }

            $values = is_array($params[$logic]) ? $params[$logic] : [$params[$logic]];
            unset($params[$logic]);

            foreach ($values as $value) {
                if (!is_string($value)) {
                    continue;
                }

                $value = trim($value);
                if ('' === $value) {
                    continue;
                }

                if (!str_starts_with($value, '(')) {
                    continue;
                }

                if (!str_ends_with($value, ')')) {
                    continue;
                }

                $parsed = self::parseGroupedLogicNode($logic, $value);
                if (null !== $parsed) {
                    $grouped[] = $parsed;
                }
            }
        }

        return $grouped;
    }

    private static function extractConfiguredSearchFilters(array &$params, string $table): array
    {
        if (!array_key_exists('search', $params)) {
            return [];
        }

        $rawSearch = $params['search'];
        unset($params['search']);

        if (is_array($rawSearch)) {
            $rawSearch = $rawSearch[0] ?? null;
        }

        if (!is_string($rawSearch)) {
            return [];
        }

        $keyword = trim($rawSearch);
        if ('' === $keyword) {
            return [];
        }

        $tableConfig = SchemaRegistryUtils::getTable($table);
        $searchable = $tableConfig instanceof RecordTableType
            ? ($tableConfig->searchable ?? [])
            : ($tableConfig['searchable'] ?? []);

        if (!is_array($searchable) || [] === $searchable) {
            return [];
        }

        $operator = 'pgsql' === DB::getDriverName() ? 'ilike' : 'like';
        $children = [];

        foreach ($searchable as $field) {
            if (!is_string($field)) {
                continue;
            }

            $field = trim($field);
            if ('' === $field) {
                continue;
            }

            $children[] = [
                'type' => 'condition',
                'column' => $field,
                'operator' => $operator,
                'value' => $keyword,
                'modifier' => null,
            ];
        }

        if ([] === $children) {
            return [];
        }

        return [[
            'type' => 'group',
            'logic' => 'or',
            'children' => $children,
        ]];
    }

    private static function parseGroupedLogicNode(string $logic, string $value): ?array
    {
        $inner = trim(substr($value, 1, -1));
        if ('' === $inner) {
            return null;
        }

        $parts = self::splitGroupedLogicParts($inner);
        $children = [];
        foreach ($parts as $part) {
            $node = self::parseGroupedLogicExpression($part);
            if (null !== $node) {
                $children[] = $node;
            }
        }

        if ([] === $children) {
            return null;
        }

        return [
            'type' => 'group',
            'logic' => strtolower($logic),
            'children' => $children,
        ];
    }

    private static function parseGroupedLogicExpression(string $expression): ?array
    {
        $expression = trim($expression);
        if ('' === $expression) {
            return null;
        }

        if (preg_match('/^(and|or)\((.*)\)$/i', $expression, $matches)) {
            return self::parseGroupedLogicNode(strtolower($matches[1]), '(' . $matches[2] . ')');
        }

        if (!preg_match('/^(.+?)\.(.+)$/', $expression, $matches)) {
            return null;
        }

        $column = trim($matches[1]);
        $parsedOperator = self::parseOperatorExpression(trim($matches[2]));
        if (null === $parsedOperator) {
            return null;
        }

        return [
            'type' => 'condition',
            'column' => $column,
            'operator' => $parsedOperator['operator'],
            'value' => $parsedOperator['value'],
            'modifier' => $parsedOperator['modifier'],
        ];
    }

    private static function splitGroupedLogicParts(string $value): array
    {
        $parts = [];
        $buffer = '';
        $depth = 0;
        $length = strlen($value);

        for ($i = 0; $i < $length; ++$i) {
            $char = $value[$i];
            if ('(' === $char) {
                ++$depth;
            } elseif (')' === $char && $depth > 0) {
                --$depth;
            }

            if (',' === $char && 0 === $depth) {
                $part = trim($buffer);
                if ('' !== $part) {
                    $parts[] = $part;
                }

                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $last = trim($buffer);
        if ('' !== $last) {
            $parts[] = $last;
        }

        return $parts;
    }

    private static function applyGroupedFilters(Builder $builder, string $table, array $allowedCols, array $groupedFilters, mixed $tenantId): void
    {
        foreach ($groupedFilters as $groupedFilter) {
            self::applyGroupedLogicNode($builder, $table, $allowedCols, $groupedFilter, 'and', $tenantId);
        }
    }

    private static function applyGroupedLogicNode(Builder $builder, string $table, array $allowedCols, array $node, string $boolean, mixed $tenantId): void
    {
        if (($node['type'] ?? null) === 'condition') {
            self::applyGroupedCondition($builder, $table, $allowedCols, $node, $boolean, $tenantId);
            return;
        }

        if (($node['type'] ?? null) !== 'group') {
            return;
        }

        $method = 'or' === $boolean ? 'orWhere' : 'where';
        $logic = strtolower((string) ($node['logic'] ?? 'and'));
        $children = $node['children'] ?? [];
        if (!is_array($children) || [] === $children) {
            return;
        }

        $builder->{$method}(function (Builder $subQuery) use ($table, $allowedCols, $logic, $children, $tenantId): void {
            foreach ($children as $index => $child) {
                if (!is_array($child)) {
                    continue;
                }

                $childBoolean = ($index > 0 && 'or' === $logic) ? 'or' : 'and';
                self::applyGroupedLogicNode($subQuery, $table, $allowedCols, $child, $childBoolean, $tenantId);
            }
        });
    }

    private static function applyGroupedCondition(Builder $builder, string $table, array $allowedCols, array $condition, string $boolean, mixed $tenantId): void
    {
        $column = $condition['column'] ?? null;
        $operator = $condition['operator'] ?? null;
        $value = $condition['value'] ?? null;
        $modifier = $condition['modifier'] ?? null;

        if (!is_string($column) || !is_string($operator)) {
            return;
        }

        $method = 'or' === $boolean ? 'orWhere' : 'where';

        $builder->{$method}(function (Builder $subQuery) use ($table, $allowedCols, $column, $operator, $value, $tenantId, $modifier): void {
            if (str_contains($column, '.')) {
                self::applyRelationshipFilters($subQuery, $table, [
                    $column => [[
                        'operator' => $operator,
                        'value' => $value,
                        'modifier' => $modifier,
                    ]],
                ], $tenantId);
                return;
            }

            self::applyOperator($subQuery, $table, $allowedCols, $column, $operator, is_string($value) ? $value : null, is_string($modifier) ? $modifier : null);
        });
    }

    private static function normalizePostgrestListValue(?string $value): ?string
    {
        if (!is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);
        if (str_starts_with($trimmed, '(') && str_ends_with($trimmed, ')') && strlen($trimmed) >= 2) {
            return substr($trimmed, 1, -1);
        }

        return $trimmed;
    }

    private static function parseOperatorExpression(string $raw): ?array
    {
        $raw = trim($raw);
        if ('' === $raw) {
            return null;
        }

        if (in_array($raw, ['is', 'is_not', 'empty', 'not_empty'], true)) {
            return ['operator' => $raw, 'value' => null, 'modifier' => null];
        }

        if (preg_match('/^not\.([a-z_]+(?:\((?:any|all)\))?)\.(.+)$/i', $raw, $match)) {
            $parsed = self::parseOperatorNameWithModifier($match[1]);
            if (null === $parsed) {
                return null;
            }

            $mappedOperator = self::mapNegatedOperator($parsed['operator']);
            if (null === $mappedOperator) {
                return null;
            }

            return [
                'operator' => $mappedOperator,
                'value' => 'null' === $match[2] ? null : $match[2],
                'modifier' => $parsed['modifier'],
            ];
        }

        if (!preg_match('/^([a-z_]+(?:\((?:any|all)\))?)\.(.+)$/i', $raw, $match)) {
            return null;
        }

        $parsed = self::parseOperatorNameWithModifier($match[1]);
        if (null === $parsed) {
            return null;
        }

        return [
            'operator' => $parsed['operator'],
            'value' => 'null' === $match[2] ? null : $match[2],
            'modifier' => $parsed['modifier'],
        ];
    }

    private static function parseOperatorNameWithModifier(string $rawOperator): ?array
    {
        if (!preg_match('/^([a-z_]+)(?:\((any|all)\))?$/i', trim($rawOperator), $matches)) {
            return null;
        }

        $operator = strtolower($matches[1]);
        $modifier = strtolower($matches[2] ?? '');
        if (!in_array($operator, self::FILTER_OPERATORS, true)) {
            return null;
        }

        return [
            'operator' => $operator,
            'modifier' => '' !== $modifier ? $modifier : null,
        ];
    }

    private static function mapNegatedOperator(string $operator): ?string
    {
        $map = [
            'eq' => 'neq',
            'neq' => 'eq',
            'in' => 'not_in',
            'not_in' => 'in',
            'like' => 'not_like',
            'ilike' => 'not_ilike',
            'is' => 'is_not',
            'is_not' => 'is',
            'gt' => 'lte',
            'gte' => 'lt',
            'lt' => 'gte',
            'lte' => 'gt',
            'between' => 'not_between',
            'not_between' => 'between',
            'empty' => 'not_empty',
            'not_empty' => 'empty',
            'regex' => 'not_regex',
            'match' => 'not_match',
            'imatch' => 'not_imatch',
            'fts' => 'not_fts',
            'plfts' => 'not_plfts',
            'phfts' => 'not_phfts',
            'wfts' => 'not_wfts',
            'cs' => 'not_cs',
            'cd' => 'not_cd',
            'ov' => 'not_ov',
            'sl' => 'not_sl',
            'sr' => 'not_sr',
            'nxl' => 'not_nxl',
            'nxr' => 'not_nxr',
            'adj' => 'not_adj',
        ];

        return $map[$operator] ?? null;
    }

    private static function parseModifierValues(?string $value): array
    {
        if (!is_string($value)) {
            return [];
        }

        $trimmed = trim($value);
        if (!str_starts_with($trimmed, '{') || !str_ends_with($trimmed, '}')) {
            return [];
        }

        $inner = substr($trimmed, 1, -1);
        if ('' === $inner) {
            return [];
        }

        $parts = array_map(trim(...), explode(',', $inner));
        return array_values(array_filter($parts, static fn(string $part): bool => '' !== $part));
    }

    private static function assertOperatorDriverSupported(string $operator, array $supportedDrivers): void
    {
        $driver = DB::getDriverName();
        if ('mysql' === $driver) {
            $version = strtolower((string) DB::selectOne('select version() as v')->v ?? '');
            if (str_contains($version, 'mariadb')) {
                $driver = 'mariadb';
            }
        }

        if (!in_array($driver, $supportedDrivers, true)) {
            throw new InvalidArgumentException(sprintf(
                "Operator '%s' is not supported on current driver '%s'.",
                $operator,
                $driver
            ));
        }
    }

    private static function applyCaseInsensitiveLike(Builder $builder, string $column, string $value, bool $negated = false): void
    {
        $pattern = '%' . str_replace('*', '%', $value) . '%';
        if ('pgsql' === DB::getDriverName()) {
            $builder->where($column, $negated ? 'not ilike' : 'ilike', $pattern);
            return;
        }

        $builder->whereRaw(
            'LOWER(' . $column . ') ' . ($negated ? 'not like' : 'like') . ' ?',
            [mb_strtolower($pattern)]
        );
    }

    private static function applyRegexOperator(Builder $builder, string $table, array $columns, string $value, string $operator, bool $isMultiple): void
    {
        $driver = DB::getDriverName();
        $pgsqlOperator = match ($operator) {
            'regex', 'match' => '~',
            'imatch' => '~*',
            'not_regex', 'not_match' => '!~',
            'not_imatch' => '!~*',
            default => '~',
        };
        $mysqlNegated = in_array($operator, ['not_regex', 'not_match', 'not_imatch'], true);
        $mysqlValue = 'imatch' === $operator || 'not_imatch' === $operator ? mb_strtolower($value) : $value;

        $apply = function (Builder $query, string $column) use ($driver, $pgsqlOperator, $mysqlNegated, $mysqlValue): void {
            if ('pgsql' === $driver) {
                $query->whereRaw($column . ' ' . $pgsqlOperator . ' ?', [$mysqlValue]);
                return;
            }

            $query->whereRaw(
                ($mysqlNegated ? 'LOWER(' . $column . ') NOT REGEXP ?' : $column . ' REGEXP ?'),
                [$mysqlValue]
            );
        };

        if ($isMultiple) {
            $builder->where(function (Builder $group) use ($columns, $table, $apply): void {
                foreach ($columns as $column) {
                    $group->orWhere(function (Builder $sub) use ($table, $column, $apply): void {
                        $apply($sub, $table . '.' . $column);
                    });
                }
            });
            return;
        }

        $apply($builder, $table . '.' . $columns[0]);
    }

    private static function applyPgsqlFullTextOperator(Builder $builder, string $table, array $columns, string $value, string $operator, bool $isMultiple): void
    {
        $negated = str_starts_with($operator, 'not_');
        $baseOperator = $negated ? substr($operator, 4) : $operator;
        $tsFunction = match ($baseOperator) {
            'fts' => 'to_tsquery',
            'plfts' => 'plainto_tsquery',
            'phfts' => 'phraseto_tsquery',
            'wfts' => 'websearch_to_tsquery',
            default => 'to_tsquery',
        };

        $apply = function (Builder $query, string $column) use ($value, $tsFunction, $negated): void {
            $query->whereRaw(
                ($negated ? 'NOT ' : '') . "to_tsvector('simple', COALESCE(" . $column . "::text, '')) @@ " . $tsFunction . "('simple', ?)",
                [$value]
            );
        };

        if ($isMultiple) {
            $builder->where(function (Builder $group) use ($columns, $table, $apply): void {
                foreach ($columns as $column) {
                    $group->orWhere(function (Builder $sub) use ($table, $column, $apply): void {
                        $apply($sub, $table . '.' . $column);
                    });
                }
            });
            return;
        }

        $apply($builder, $table . '.' . $columns[0]);
    }

    private static function applyPgsqlNativeOperator(Builder $builder, string $table, array $columns, string $value, string $operator, bool $isMultiple): void
    {
        $negated = str_starts_with($operator, 'not_');
        $baseOperator = $negated ? substr($operator, 4) : $operator;
        $pgsqlOperator = match ($baseOperator) {
            'cs' => '@>',
            'cd' => '<@',
            'ov' => '&&',
            'sl' => '<<',
            'sr' => '>>',
            'nxl' => '&<',
            'nxr' => '&>',
            'adj' => '-|-',
            default => '@>',
        };

        $apply = function (Builder $query, string $column) use ($value, $pgsqlOperator, $negated): void {
            $query->whereRaw(($negated ? 'NOT ' : '') . $column . ' ' . $pgsqlOperator . ' ?', [$value]);
        };

        if ($isMultiple) {
            $builder->where(function (Builder $group) use ($columns, $table, $apply): void {
                foreach ($columns as $column) {
                    $group->orWhere(function (Builder $sub) use ($table, $column, $apply): void {
                        $apply($sub, $table . '.' . $column);
                    });
                }
            });
            return;
        }

        $apply($builder, $table . '.' . $columns[0]);
    }

    private static function applyPgsqlFullTextToSubquery(mixed $subquery, string $column, string $value, string $operator): void
    {
        $negated = str_starts_with($operator, 'not_');
        $baseOperator = $negated ? substr($operator, 4) : $operator;
        $tsFunction = match ($baseOperator) {
            'fts' => 'to_tsquery',
            'plfts' => 'plainto_tsquery',
            'phfts' => 'phraseto_tsquery',
            'wfts' => 'websearch_to_tsquery',
            default => 'to_tsquery',
        };

        $subquery->whereRaw(
            ($negated ? 'NOT ' : '') . "to_tsvector('simple', COALESCE(" . $column . "::text, '')) @@ " . $tsFunction . "('simple', ?)",
            [$value]
        );
    }

    private static function applyPgsqlNativeToSubquery(mixed $subquery, string $column, string $value, string $operator): void
    {
        $negated = str_starts_with($operator, 'not_');
        $baseOperator = $negated ? substr($operator, 4) : $operator;
        $pgsqlOperator = match ($baseOperator) {
            'cs' => '@>',
            'cd' => '<@',
            'ov' => '&&',
            'sl' => '<<',
            'sr' => '>>',
            'nxl' => '&<',
            'nxr' => '&>',
            'adj' => '-|-',
            default => '@>',
        };

        $subquery->whereRaw(($negated ? 'NOT ' : '') . $column . ' ' . $pgsqlOperator . ' ?', [$value]);
    }

    private static function isOperatorExpression(string $value): bool
    {
        return null !== self::parseOperatorExpression($value);
    }

    /**
     * Optimized lazy operations execution with priority ordering and batch processing.
     */
    private static function executeLazyOperationsOptimized(Builder $builder): void
    {
        // Sort operations by priority (higher priority first)
        $sortedOperations = self::$lazyOperations;
        uasort($sortedOperations, fn($a, $b): int => ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0));

        // Group operations by table for batch processing
        $operationsByTable = [];
        foreach ($sortedOperations as $operationId => $operation) {
            if (!$operation['executed'] && self::shouldExecuteLazyOperation($builder, $operationId)) {
                $operationsByTable[$operation['table']][] = [$operationId, $operation];
            }
        }

        // Execute operations grouped by table for better performance
        foreach ($operationsByTable as $operations) {
            $builder->where(function ($subQuery) use ($operations): void {
                foreach ($operations as [$operationId, $operation]) {
                    $subQuery->where(function (Builder $opQuery) use ($operation): void {
                        self::executeOperatorsOptimized($opQuery, $operation['table'], $operation['allowedCols'], $operation['params']);
                    });

                    // Mark as executed
                    self::$lazyOperations[$operationId]['executed'] = true;
                }
            });
        }
    }

    /**
     * Determine if a lazy operation should be executed based on query context.
     */
    private static function shouldExecuteLazyOperation(Builder $builder, string $operationId): bool
    {
        $builderId = spl_object_id($builder);

        return isset(self::$lazyBuilders[$builderId][$operationId]);
    }

    private static function registerLazyOperationForBuilder(Builder $builder, string $operationId): void
    {
        $builderId = spl_object_id($builder);
        self::$lazyBuilders[$builderId] ??= [];
        self::$lazyBuilders[$builderId][$operationId] = true;
    }

    private static function cleanupExecutedLazyOperations(): void
    {
        foreach (self::$lazyOperations as $operationId => $operation) {
            if (!($operation['executed'] ?? false)) {
                continue;
            }

            unset(self::$lazyOperations[$operationId]);

            foreach (self::$lazyBuilders as $builderId => $operationIds) {
                if (isset($operationIds[$operationId])) {
                    unset(self::$lazyBuilders[$builderId][$operationId]);
                }

                if ([] === self::$lazyBuilders[$builderId]) {
                    unset(self::$lazyBuilders[$builderId]);
                }
            }
        }
    }
}
