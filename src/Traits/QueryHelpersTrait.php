<?php

namespace Sopheak\Core\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\QueryBuilderFiltersUtils;
use Sopheak\Core\Utilities\RecordUtils;
use Sopheak\Core\Utilities\RelationshipResolverUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

trait QueryHelpersTrait
{
    /**
     * Apply common queries to a Laravel Eloquent query builder.
     *
     * This method provides a flexible way to apply various filters and relations to your queries.
     *
     * @param Builder $builder The query builder instance
     * @param Request $request The HTTP request object containing query parameters
     * @param bool    $isArray Whether to return the result as an array
     * @param string  $orderBy The column to use for ordering results (default: 'id')
     *
     * Supported query parameters:
     * * s or search: search all fields in table (e.g., ?s=cambodia or ?search=cambodia)
     * * select: Specify columns to retrieve including relationship columns (e.g., ?select=id,name,customer:id,name,location:id,name)
     * * with: Load related models (e.g., ?with=user,posts.comments)
     * * sortby: Specify column to sort by (e.g., ?sortby=name, default: id)
     * * order: Specify sort direction - asc or desc (e.g., ?order=asc, default: desc)
     *
     * Performance Parameters:
     * * lazy: Enable lazy loading (default: false) (e.g., ?lazy=true)
     * * per_page: Enable pagination (e.g., ?per_page=20)
     * * limit: Limit results when isArray=true (max: 20000) (e.g., ?limit=1000)
     * * is.{value}: Filter where column is null (e.g., ?name=is.null) - supports multiple fields with OR: ?title,description=is.null
     * * eq.{value}: Filter where column equals value (e.g., ?name=eq.John) - supports multiple fields with OR: ?title,description=eq.test
     * * neq.{value}: Filter where column does not equal value (e.g., ?status=neq.inactive) - supports multiple fields with OR: ?title,description=neq.test
     * * like.{value}: Filter using LIKE operator (e.g., ?name=like.John) - supports multiple fields with OR: ?title,description=like.search
     * * gt.{value}: Filter where column is greater than value (e.g., ?age=gt.18) - supports multiple fields with OR: ?price,cost=gt.100
     * * lt.{value}: Filter where column is less than value (e.g., ?price=lt.100) - supports multiple fields with OR: ?price,cost=lt.100
     * * lte.{value}: Filter where column is less or equal than value (e.g., ?price=lte.100) - supports multiple fields with OR: ?price,cost=lte.100
     * * gte.{value}: Filter where column is greater than or equal to value (e.g., ?score=gte.75) - supports multiple fields with OR: ?score,rating=gte.75
     * * in.{value}: Filter where column is in a list of values (e.g., ?category=in.electronics,clothing) - supports multiple fields with OR: ?category,type=in.electronics,clothing
     * * contains.{value}: Filter where column contains a substring (e.g., ?name=contains.john) - supports multiple fields with OR: ?title,description=contains.search
     * * between.{value},{value}: Filter where column is between values (e.g., ?date=between.2025-06-01,2025-06-20) - supports multiple fields with OR: ?created_at,updated_at=between.2025-06-01,2025-06-20
     * * not_between.{value},{value}: Filter where column is not between values (e.g., ?date=not_between.2025-06-01,2025-06-20) - supports multiple fields with OR: ?created_at,updated_at=not_between.2025-06-01,2025-06-20
     *
     * * compare.{field2}: Compare two fields (e.g., ?total_amount=compare.neq.balance)
     *
     * * join: Join related models (e.g., ?join=user.posts)
     * * select_join: Select specific columns from joined models (e.g., ?select_join=user.id,user.name)
     * * total_record: Get total count of records based on query (e.g., ?total_record=true&limit=500)
     *   Returns: {data: [limited results], total: total_count}
     * * lazy: Use lazy loading for memory-efficient processing of large datasets (e.g., ?lazy=true) [RECOMMENDED]
     *   Returns: LazyCollection that loads records in chunks automatically
     *
     * @return array|Builder|LazyCollection|LengthAwarePaginator The modified query builder, paginated results, array with data and total, or LazyCollection for memory-efficient processing
     *
     * @see https://laravel.com/docs/12.x/eloquent#query-scopes
     * @see https://laravel.com/docs/12.x/queries
     * @see https://laravel.com/docs/12.x/eloquent-relationships#eager-loading
     * @see https://laravel.com/docs/12.x/eloquent#chunking-results
     */
    public function scopeApplyRequestFilters(Builder $builder, Request $request, bool $isArray = false, string $orderBy = 'id')
    {
        $this->normalizeSearchParameter($request);
        $isTenantEnabled = RecordConfigService::enableTenantId();
        $tenantColumn = RecordConfigService::tenantColumn();

        $tableName = $builder->getModel()->getTable();
        $this->ensureSchemaForTable($tableName);

        $configuredTable = RecordConfigService::table($tableName);
        $configuredHasTenantId = (bool) ($configuredTable->hasTenantId ?? false);
        $tableSchema = SchemaRegistryUtils::getTable($tableName);
        $modelFillable = $builder->getModel()->getFillable();
        $hasTenantColumn = in_array($tenantColumn, $modelFillable, true)
            || ($tableSchema instanceof RecordTableType && isset($tableSchema->columns[$tenantColumn]))
            || Schema::hasColumn($tableName, $tenantColumn);
        $shouldApplyTenantFilter = $isTenantEnabled && ($configuredHasTenantId || $hasTenantColumn);

        $commonQuery = $builder->when($shouldApplyTenantFilter, function ($query) use ($request, $tenantColumn) {
            $tenantId = RecordUtils::resolveTenantIdFromRequest($request);
            if (RecordUtils::isTenantIdMissing($tenantId)) {
                return $query;
            }

            return $query->where($tenantColumn, $tenantId);
        });

        $withMap = $request->has('with') ? $this->parseWithRelations($request->query('with')) : [];

        if ($request->has('select')) {
            $selectColumns = $this->parseSelectColumns($request->query('select'), $tableName);
            $hasRelationshipSelects = [] !== $selectColumns['includes'];
            if ([] !== $selectColumns['main']) {
                $mainSelect = $this->ensurePrimaryKeyInSelect($builder, $selectColumns['main'], $hasRelationshipSelects);
                $commonQuery = $commonQuery->select($mainSelect);
            }

            $selectRelations = $this->buildWithMapFromIncludes($selectColumns['includes']);
            $withMap = $this->mergeWithRelationMaps($withMap, $selectRelations);
        }

        if ([] !== $withMap) {
            $commonQuery = $commonQuery->with($this->buildWithArray($withMap));
        }

        /*
         * Select columns from joined tables
         * ex: select_join={"users":["name","email"],"categories":["title"]}
         */
        if ($request->has('select_join')) {
            $selectJoins = json_decode($request->query('select_join'), true);
            if (is_array($selectJoins)) {
                foreach ($selectJoins as $table => $columns) {
                    if (is_array($columns)) {
                        foreach ($columns as $column) {
                            $commonQuery = $commonQuery->addSelect(sprintf('%s.%s', $table, $column));
                        }
                    }
                }
            }
        }

        /*
         * Join relationships
         * ex: ?join=user.posts
         */
        if ($request->has('join')) {
            $joins = $this->castStringToArray($request->query('join'));
            foreach ($joins as $join) {
                $join = trim((string) $join);
                $commonQuery = $commonQuery->join($join, $join . '.id', '=', sprintf('%s.%s_id', $tableName, $join));
            }
        }

        // Apply soft delete filter if model uses soft deletes
        $commonQuery = $commonQuery->when(
            method_exists($commonQuery->getModel(), 'getDeletedAtColumn'),
            fn($query) => $query->whereNull($tableName . '.' . $commonQuery->getModel()->getDeletedAtColumn())
        );

        QueryBuilderFiltersUtils::apply($commonQuery->getQuery(), $request, $tableName, $orderBy);

        // Handle pagination, chunking, and result formatting
        if ($request->has('per_page')) {
            return $commonQuery->paginate($request->query('per_page', 15));
        }

        // Handle total_record parameter
        if ($request->has('total_record') && $request->boolean('total_record')) {
            // Clone the query to get total count without limit
            $totalQuery = clone $commonQuery;
            $total = $totalQuery->count();

            // Get limited data
            $limit = $request->query('limit', 1000);
            $data = $commonQuery->limit($limit)->get();

            return [
                'data' => $isArray ? $data->toArray() : $data,
                'total' => $total,
            ];
        }

        // Handle lazy loading for large datasets
        if ($request->has('lazy') && $request->boolean('lazy')) {
            // Preferred httpMethod: Memory-efficient lazy loading with generators
            return $commonQuery->lazy();
        }

        if ($isArray) {
            $limit = min((int) $request->query('limit', 10000), 20000); // Safety limit set to 20,000

            return $commonQuery->limit($limit)->get();
        }

        return $commonQuery;
    }

    private function normalizeSearchParameter(Request $request): void
    {
        if (!$request->has('s') && $request->has('search')) {
            $request->query->set('s', $request->query('search'));
            $this->syncQueryString($request);
        }
    }

    private function syncQueryString(Request $request): void
    {
        $params = $request->query->all();
        $request->server->set('QUERY_STRING', http_build_query($params));
    }

    private function ensureSchemaForTable(string $tableName): void
    {
        $schema = SchemaRegistryUtils::getTable($tableName);
        if ($schema instanceof RecordTableType && !empty($schema->columns)) {
            return;
        }

        $config = $schema instanceof RecordTableType ? $schema : new RecordTableType(table: $tableName);
        SchemaRegistryUtils::register($tableName, $config);
    }

    /**
     * Apply filter operators to the query.
     *
     * @param Builder $builder    The query builder instance
     * @param string  $key        The column name (can be multiple columns separated by comma for OR conditions)
     * @param string  $operator   The filter operator
     * @param mixed   $queryValue The filter value
     * @param string  $tableName  The table name for prefixing columns
     */
    private function applyFilterOperator(Builder $builder, string $key, string $operator, string $queryValue, string $tableName): void
    {
        // Check if multiple columns are specified for OR conditions
        $isMultipleColumns = str_contains($key, ',');
        $columns = $isMultipleColumns ? array_map(trim(...), explode(',', $key)) : [$key];

        // Prefix columns with table name if they don't already contain a table prefix
        $columns = array_map(fn(string $column): string => str_contains($column, '.') ? $column : $tableName . '.' . $column, $columns);

        // Update the key for single column operations
        if (!$isMultipleColumns) {
            $key = $columns[0];
        }

        switch ($operator) {
            case 'is':
                if ($isMultipleColumns) {
                    $builder->where(function ($q) use ($columns): void {
                        foreach ($columns as $column) {
                            $q->orWhereNull($column);
                        }
                    });
                } elseif (is_string($queryValue) && strpos($queryValue, ',')) {
                    $values = explode(',', $queryValue);
                    $builder->where(function ($q) use ($key, $values): void {
                        foreach ($values as $value) {
                            if ('null' === trim($value)) {
                                $q->orWhereNull($key);
                            }
                        }
                    });
                } else {
                    $builder->whereNull($key);
                }

                break;

            case 'eq':
                if ($isMultipleColumns) {
                    $builder->where(function ($q) use ($columns, $queryValue): void {
                        foreach ($columns as $column) {
                            if (strpos($queryValue, ',')) {
                                $values = explode(',', $queryValue);
                                $q->orWhereIn($column, $values);
                            } else {
                                $q->orWhere($column, '=', $queryValue);
                            }
                        }
                    });
                } elseif (is_string($queryValue) && strpos($queryValue, ',')) {
                    $values = explode(',', $queryValue);
                    $builder->whereIn($key, $values);
                } else {
                    $builder->where($key, '=', $queryValue);
                }

                break;

            case 'neq':
                if ($isMultipleColumns) {
                    $builder->where(function ($q) use ($columns, $queryValue): void {
                        foreach ($columns as $column) {
                            if (strpos($queryValue, ',')) {
                                $values = explode(',', $queryValue);
                                $q->orWhereNotIn($column, $values);
                            } else {
                                $q->orWhere($column, '!=', $queryValue);
                            }
                        }
                    });
                } elseif (is_string($queryValue) && strpos($queryValue, ',')) {
                    $values = explode(',', $queryValue);
                    $builder->whereNotIn($key, $values);
                } else {
                    $builder->where($key, '!=', $queryValue);
                }

                break;

            case 'like':
            case 'contains':
                if ($isMultipleColumns) {
                    $builder->where(function ($q) use ($columns, $queryValue): void {
                        foreach ($columns as $column) {
                            $q->orWhere($column, 'like', '%' . $queryValue . '%');
                        }
                    });
                } else {
                    $builder->where($key, 'like', '%' . $queryValue . '%');
                }

                break;

            case 'gt':
                if ($isMultipleColumns) {
                    $builder->where(function ($q) use ($columns, $queryValue): void {
                        foreach ($columns as $column) {
                            $q->orWhere($column, '>', $queryValue);
                        }
                    });
                } else {
                    $builder->where($key, '>', $queryValue);
                }

                break;

            case 'lt':
                if ($isMultipleColumns) {
                    $builder->where(function ($q) use ($columns, $queryValue): void {
                        foreach ($columns as $column) {
                            $q->orWhere($column, '<', $queryValue);
                        }
                    });
                } else {
                    $builder->where($key, '<', $queryValue);
                }

                break;

            case 'gte':
                if ($isMultipleColumns) {
                    $builder->where(function ($q) use ($columns, $queryValue): void {
                        foreach ($columns as $column) {
                            $q->orWhere($column, '>=', $queryValue);
                        }
                    });
                } else {
                    $builder->where($key, '>=', $queryValue);
                }

                break;

            case 'lte':
                if ($isMultipleColumns) {
                    $builder->where(function ($q) use ($columns, $queryValue): void {
                        foreach ($columns as $column) {
                            $q->orWhere($column, '<=', $queryValue);
                        }
                    });
                } else {
                    $builder->where($key, '<=', $queryValue);
                }

                break;

            case 'between':
                if (strpos($queryValue, ',')) {
                    $values = explode(',', $queryValue, 2);
                    if (2 === count($values)) {
                        $startValue = trim($values[0]);
                        $endValue = trim($values[1]);

                        if ($isMultipleColumns) {
                            $builder->where(function ($q) use ($columns, $startValue, $endValue): void {
                                foreach ($columns as $column) {
                                    $q->orWhereBetween($column, [$startValue, $endValue]);
                                }
                            });
                        } else {
                            $builder->whereBetween($key, [$startValue, $endValue]);
                        }
                    }
                }

                break;

            case 'not_between':
                if (strpos($queryValue, ',')) {
                    $values = explode(',', $queryValue, 2);
                    if (2 === count($values)) {
                        $startValue = trim($values[0]);
                        $endValue = trim($values[1]);

                        if ($isMultipleColumns) {
                            $builder->where(function ($q) use ($columns, $startValue, $endValue): void {
                                foreach ($columns as $column) {
                                    $q->orWhereNotBetween($column, [$startValue, $endValue]);
                                }
                            });
                        } else {
                            $builder->whereNotBetween($key, [$startValue, $endValue]);
                        }
                    }
                }

                break;

            case 'in':
                $values = explode(',', $queryValue);
                $trimmedValues = array_map(trim(...), $values);

                if ($isMultipleColumns) {
                    $builder->where(function ($q) use ($columns, $trimmedValues): void {
                        foreach ($columns as $column) {
                            $q->orWhereIn($column, $trimmedValues);
                        }
                    });
                } else {
                    $builder->whereIn($key, $trimmedValues);
                }

                break;
        }
    }

    /**
     * Parse select parameter to separate main table columns from relationship columns.
     *
     * @param string $selectParam The select parameter value
     * @param string $tableName   The main table name for prefixing columns
     *
     * @return array Array containing 'main' columns and 'relationships' data
     */
    private function parseSelectColumns(string $selectParam, string $tableName): array
    {
        $mainColumns = [];
        $mainCandidates = RelationshipResolverUtils::getMainTableColumns($selectParam);
        foreach ($mainCandidates as $column) {
            $column = trim((string) $column);
            if ('' === $column) {
                continue;
            }

            if ('*' === $column) {
                $mainColumns[] = $tableName . '.*';
                continue;
            }

            $mainColumns[] = str_contains($column, '.') ? $column : $tableName . '.' . $column;
        }

        return [
            'main' => $mainColumns,
            'includes' => RelationshipResolverUtils::parseSelectForIncludes($selectParam),
        ];
    }

    /**
     * Parse with relations and apply column selection for relationships.
     *
     * @param string $withParam The with parameter value
     *
     * @return array Array of relations with column constraints
     */
    private function parseWithRelations(string $withParam): array
    {
        $relations = $this->castStringToArray($withParam);
        $withRelations = [];

        foreach ($relations as $relation) {
            $relation = trim((string) $relation);
            // Check if relation contains column specification (e.g., "customer(id,name)" or "customer(*)")
            if (preg_match('/^([a-zA-Z_]\w*)\((.*)\)$/', $relation, $matches)) {
                $relationName = trim($matches[1]);
                $columns = trim($matches[2]);

                if ('*' === $columns) {
                    $withRelations[$relationName] = null;
                } else {
                    $columnArray = array_map(trim(...), explode(',', $columns));
                    $columnArray = $this->normalizeRelationColumns($columnArray);
                    if (['*'] === $columnArray) {
                        $withRelations[$relationName] = null;
                    } else {
                        $withRelations[$relationName] = function ($query) use ($columnArray): void {
                            $query->select($this->qualifyRelationColumns($query, $columnArray));
                        };
                    }
                }
            } else {
                $withRelations[$relation] = null;
            }
        }

        return $withRelations;
    }

    private function buildWithMapFromIncludes(array $includes, string $prefix = ''): array
    {
        $map = [];
        foreach ($includes as $relation => $config) {
            $relationKey = '' === $prefix ? $relation : $prefix . '.' . $relation;
            $columns = $config['columns'] ?? ['*'];
            $columns = $this->normalizeRelationColumns($columns);
            if (['*'] === $columns) {
                $map[$relationKey] = null;
            } else {
                $map[$relationKey] = function ($query) use ($columns): void {
                    $query->select($this->qualifyRelationColumns($query, $columns));
                };
            }

            if (!empty($config['children'])) {
                $map = array_merge($map, $this->buildWithMapFromIncludes($config['children'], $relationKey));
            }
        }

        return $map;
    }

    private function normalizeRelationColumns(array $columns): array
    {
        $columns = array_values(array_filter(array_map(trim(...), $columns), fn(string $column): bool => '' !== $column));
        if ([] === $columns || in_array('*', $columns, true)) {
            return ['*'];
        }

        if (!in_array('id', $columns, true)) {
            array_unshift($columns, 'id');
        }

        return $columns;
    }

    private function mergeWithRelationMaps(array $base, array $override): array
    {
        foreach ($override as $relation => $constraint) {
            $base[$relation] = $constraint;
        }

        return $base;
    }

    private function buildWithArray(array $withMap): array
    {
        $with = [];
        foreach ($withMap as $relation => $constraint) {
            if (null === $constraint) {
                $with[] = $relation;
            } else {
                $with[$relation] = $constraint;
            }
        }

        return $with;
    }

    private function ensurePrimaryKeyInSelect(Builder $builder, array $columns, bool $hasRelationshipSelects): array
    {
        if (!$hasRelationshipSelects) {
            return $columns;
        }

        $tableName = $builder->getModel()->getTable();
        if (in_array($tableName . '.*', $columns, true) || in_array('*', $columns, true)) {
            return $columns;
        }

        $qualifiedKey = $builder->getModel()->getQualifiedKeyName();
        $keyName = $builder->getModel()->getKeyName();
        if (in_array($qualifiedKey, $columns, true) || in_array($keyName, $columns, true)) {
            return $columns;
        }

        array_unshift($columns, $qualifiedKey);

        return $columns;
    }

    private function qualifyRelationColumns(Builder|Relation $query, array $columns): array
    {
        if (['*'] === $columns) {
            return $columns;
        }

        $model = $query instanceof Relation ? $query->getRelated() : $query->getModel();
        $tableName = $model->getTable();

        return array_map(
            static fn(string $column): string => str_contains($column, '.') ? $column : $tableName . '.' . $column,
            $columns
        );
    }

    /**
     * Convert a string to an array, handling parentheses syntax properly.
     *
     * @param string $value The string to convert
     *
     * @return array The resulting array
     */
    private function castStringToArray(string $value): array
    {
        // Handle JSON array format
        if (str_contains($value, '[')) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        // Handle parentheses syntax properly - don't split on commas inside parentheses
        $result = [];
        $current = '';
        $depth = 0;
        $length = strlen($value);

        for ($i = 0; $i < $length; ++$i) {
            $char = $value[$i];

            if ('(' === $char) {
                ++$depth;
                $current .= $char;
            } elseif (')' === $char) {
                --$depth;
                $current .= $char;
            } elseif (',' === $char && 0 === $depth) {
                // Only split on commas when not inside parentheses
                if ('' !== trim($current)) {
                    $result[] = trim($current);
                }

                $current = '';
            } else {
                $current .= $char;
            }
        }

        // Add the last part
        if ('' !== trim($current)) {
            $result[] = trim($current);
        }

        return $result;
    }

    /**
     * Handle optimized query execution with lazy loading.
     *
     * @param Builder $builder The query builder instance
     * @param Request $request The HTTP request object
     *
     * @return Collection|LazyCollection
     */
    private function handleOptimizedQuery(Builder $builder, Request $request)
    {
        // Consistent lazy parameter validation - only when explicitly true
        $useLazy = 'true' === $request->query('lazy') || true === $request->query('lazy');
        $maxLimit = 20000; // Hard limit for safety

        // Validate and sanitize limit if provided
        $requestedLimit = $request->query('limit');
        if (null !== $requestedLimit) {
            $requestedLimit = (int) $requestedLimit;
            $maxLimit = min($requestedLimit, $maxLimit);
        }

        if ($useLazy) {
            // Use lazy loading for memory efficiency with chunked processing
            return $builder->lazy($maxLimit > 1000 ? 1000 : 500)->take($maxLimit);
        }

        // Default: Return collection with optimized limit
        return $builder->limit($maxLimit)->get();
    }
}
