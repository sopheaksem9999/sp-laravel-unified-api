<?php

namespace Sopheak\Core\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\LazyCollection;

trait QueryHelpers
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
        $isTenantEnabled = config('record.enable_tenant_id', false);
        $tenantColumn = config('record.tenant_column', 'tenant_id');
        $tenantHeader = config('record.tenant_header', 'X-Tenant-ID');

        $tableName = $builder->getModel()->getTable();

        // check if model has tenant_column
        $hasCompanyId = in_array($tenantColumn, $builder->getModel()->getFillable());

        $commonQuery = $builder
            // Apply tenant filter if tenant is enabled and model has tenant_column
            ->when($isTenantEnabled && $hasCompanyId, function ($query) use ($request, $tenantColumn, $tenantHeader) {
                $tenantId = $request->header($tenantHeader);

                return $query->where($tenantColumn, $tenantId);
            })
            ->when($request->has('s') || $request->has('search'), function ($query) use ($request) {
                $keyword = $request->query('s') ?? $request->query('search');
                $columns = Schema::getColumnListing($query->getModel()->getTable());

                return $query->where(function ($q) use ($columns, $keyword): void {
                    foreach ($columns as $column) {
                        $q->orWhere($column, 'like', sprintf('%%%s%%', $keyword));
                    }
                });
            })
            ->when($request->has('with'), function ($query) use ($request) {
                $withRelations = $this->parseWithRelations($request->query('with'));

                return $query->with($withRelations);
            })
            ->when($request->has('select'), function ($query) use ($request, $tableName) {
                $selectColumns = $this->parseSelectColumns($request->query('select'), $tableName);

                return $query->select($selectColumns['main']);
            });

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

        // Apply filters with operators - Handle multiple values for same parameter
        $queryString = $request->getQueryString();
        if (null !== $queryString && '' !== $queryString && '0' !== $queryString) {
            parse_str($queryString, $allParams);

            // Group parameters by key to handle multiple values
            $groupedParams = [];
            foreach ($allParams as $key => $value) {
                $groupedParams[$key] = is_array($value) ? $value : [$value];
            }

            foreach ($groupedParams as $key => $values) {
                foreach ($values as $value) {
                    if (preg_match('/^(is|eq|neq|like|gt|lt|gte|lte|in|contains|between|not_between)\.(.+)$/', (string) $value, $matches)) {
                        $operator = $matches[1];
                        $queryValue = 'null' === $matches[2] ? null : $matches[2];
                        $this->applyFilterOperator($commonQuery, $key, $operator, $queryValue, $tableName);
                    }
                    // Compare two fields: ?field1=compare.neq.field2
                    elseif (preg_match('/^compare\.(eq|neq|gt|lt|gte|lte)\.(.+)$/', (string) $value, $matches)) {
                        $compareOperator = $matches[1];
                        $compareField = $matches[2];
                        $operatorMap = [
                            'eq' => '=',
                            'neq' => '!=',
                            'gt' => '>',
                            'lt' => '<',
                            'gte' => '>=',
                            'lte' => '<=',
                        ];
                        if (isset($operatorMap[$compareOperator])) {
                            $commonQuery = $commonQuery->whereColumn($key, $operatorMap[$compareOperator], $compareField);
                        }
                    }
                }
            }
        }

        // handle check permission query only own user created record
        $modelClass = class_basename($commonQuery->getModel());
        $modelName = lcfirst($modelClass); // e.g., 'ReceivePayment' => 'receivePayment'
        $permission = 'viewOnlyCreateBy_' . $modelName;

        if (Auth::check() && Gate::check($permission)) {
            $commonQuery = $commonQuery->where($tableName . '.created_by', Auth::id());
        }

        // Apply soft delete filter if model uses soft deletes
        $commonQuery = $commonQuery->when(
            method_exists($commonQuery->getModel(), 'getDeletedAtColumn'),
            fn($query) => $query->whereNull($tableName . '.' . $commonQuery->getModel()->getDeletedAtColumn())
        );

        // Apply sorting with sortby and order parameters
        $sortBy = $request->query('sortby', $orderBy);
        $sortOrder = $request->query('order', 'desc');

        // Validate sort order (only allow 'asc' or 'desc')
        $sortOrder = in_array(strtolower($sortOrder), ['asc', 'desc']) ? strtolower($sortOrder) : 'desc';

        // Prefix sortBy with table name if it doesn't already have a table prefix
        if (!str_contains($sortBy, '.')) {
            $sortBy = $tableName . '.' . $sortBy;
        }

        $commonQuery = $commonQuery->orderBy($sortBy, $sortOrder);

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
            $limit = $request->query('limit', 500);
            $data = $commonQuery->limit($limit)->get();

            return [
                'data' => $isArray ? $data->toArray() : $data,
                'total' => $total,
            ];
        }

        // Handle lazy loading for large datasets
        if ($request->has('lazy') && $request->boolean('lazy')) {
            // Preferred method: Memory-efficient lazy loading with generators
            return $commonQuery->lazy();
        }

        if ($isArray) {
            $limit = min((int) $request->query('limit', 10000), 20000); // Safety limit set to 20,000

            return $commonQuery->limit($limit)->get();
        }

        return $commonQuery;
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
        $columns = $this->castStringToArray($selectParam);
        $mainColumns = [];
        $relationships = [];

        foreach ($columns as $column) {
            $column = trim((string) $column);

            // Check for parentheses syntax: customer(*) or customer(id,name)
            if (preg_match('/^([a-zA-Z_]\w*)\(([^)]*)\)$/', $column, $matches)) {
                $relationName = trim($matches[1]);
                $relationColumns = trim($matches[2]);

                $relationships[$relationName] = '*' === $relationColumns ? ['*'] : array_map(trim(...), explode(',', $relationColumns));

                // IMPORTANT: Skip adding to main columns - this is a relationship!
                continue;
            }

            // Check for colon syntax: customer:id,name
            if (str_contains($column, ':')) {
                $parts = explode(':', $column, 2);
                $relationName = trim($parts[0]);
                $relationColumns = array_map(trim(...), explode(',', $parts[1]));
                $relationships[$relationName] = $relationColumns;

                // IMPORTANT: Skip adding to main columns - this is a relationship!
                continue;
            }

            // Regular column - prefix with table name to avoid ambiguity
            $mainColumns[] = str_contains($column, '.') ? $column : $tableName . '.' . $column;
        }

        return [
            'main' => $mainColumns,
            'relationships' => $relationships,
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
                    // Load all columns for this relationship
                    $withRelations[] = $relationName;
                } else {
                    // Apply specific column selection
                    $columnArray = array_map(trim(...), explode(',', $columns));
                    // Ensure primary key is included for relationship to work
                    if (!in_array('id', $columnArray)) {
                        array_unshift($columnArray, 'id');
                    }

                    // Use Laravel's ORM format with colon syntax
                    $withRelations[] = $relationName . ':' . implode(',', $columnArray);

                    $withRelations[$relationName] = (fn($query) => $query->select($columnArray));
                }
            } else {
                // Load relationship without column constraints
                $withRelations[] = $relation;
            }
        }

        return $withRelations;
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
