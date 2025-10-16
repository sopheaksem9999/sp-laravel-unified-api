<?php

namespace Sopheak\Core\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Cursor-based pagination utility for Laravel applications.
 * 
 * This service provides efficient cursor-based pagination for large datasets,
 * automatically switching from offset-based to cursor-based pagination when
 * dataset size exceeds configurable thresholds.
 * 
 * Features:
 * - Automatic cursor pagination detection based on dataset size
 * - Support for simple and composite cursor columns
 * - Configurable thresholds and caching
 * - Security validations and input sanitization
 * - Performance optimizations for large datasets
 * - Comprehensive error handling and logging
 * - Database query optimization strategies
 * 
 * Usage Examples:
 * 
 * Basic Usage:
 * ```php
 * // Simple cursor pagination
 * $users = User::query();
 * $result = CursorPagination::paginate($users, $request, 'id', 20);
 * 
 * // Check if cursor pagination should be used
 * if (CursorPagination::shouldUseCursorPagination($users)) {
 *     $result = CursorPagination::paginate($users, $request, 'created_at');
 * } else {
 *     $result = $users->paginate(15);
 * }
 * ```
 * 
 * Composite Cursor Pagination:
 * ```php
 * // For complex sorting with multiple columns
 * $orders = Order::query();
 * $result = CursorPagination::paginateComposite(
 *     $orders, 
 *     $request, 
 *     ['created_at', 'id'], 
 *     25
 * );
 * ```
 * 
 * Configuration:
 * The service uses config/cursor_pagination.php for:
 * - Auto-detection thresholds
 * - Caching settings
 * - Security parameters
 * - Table-specific configurations
 * 
 * @package App\Utilities\Services
 * @version 2.0.0
 * @author ERP Development Team
 */
class CursorPagination
{
    /**
     * Apply cursor-based pagination to a query.
     * 
     * This method provides efficient pagination for large datasets using cursor-based
     * navigation instead of offset-based pagination. It automatically handles cursor
     * validation, query optimization, and metadata generation.
     *
     * @param Builder|QueryBuilder $query The Eloquent query builder instance
     * @param Request              $request The HTTP request containing cursor parameters:
     *                                     - 'cursor': Base64 encoded cursor value (optional)
     *                                     - 'direction': 'next' or 'prev' (default: 'next')
     * @param string               $cursorColumn The column to use for cursor pagination (must be indexed)
     * @param int                  $perPage      Number of items per page (default: 25, max: 100)
     * 
     * @return array Paginated results with metadata:
     *               - 'data': Collection of paginated items
     *               - 'meta': Pagination metadata including cursors and navigation info
     * 
     * @throws ValidationException When cursor validation fails or invalid parameters provided
     * 
     * @example
     * ```php
     * $users = User::where('active', true);
     * $result = CursorPagination::paginate($users, $request, 'created_at', 20);
     * 
     * // Access paginated data
     * $items = $result['data'];
     * $nextCursor = $result['meta']['cursors']['next'];
     * $hasMore = $result['meta']['has_more'];
     * ```
     */
    public static function paginate($query, Request $request, string $cursorColumn = 'id', int $perPage = 25): array
    {
        // Validate and sanitize inputs
        self::validatePaginationInputs($request, $cursorColumn, $perPage);
        $maxPerPage = (int) config('cursor_pagination.max_per_page', config('record.per_page_max', 100));
        $perPage = max(1, min($perPage, $maxPerPage));
        
        // Validate cursor column exists in query
        self::validateCursorColumn($query, $cursorColumn);

        // Get cursor parameters
        $cursor = $request->query('cursor');
        $direction = strtolower($request->query('direction', 'next')); // 'next' or 'prev'

        try {
            // Clone query to avoid modifying original
            $paginatedQuery = clone $query;

            // Apply cursor conditions
            if ($cursor) {
                if ('prev' === $direction) {
                    $paginatedQuery->where($cursorColumn, '>', $cursor);
                    $paginatedQuery->orderBy($cursorColumn, 'asc');
                } else {
                    $paginatedQuery->where($cursorColumn, '<', $cursor);
                    $paginatedQuery->orderBy($cursorColumn, 'desc');
                }
            } else {
                // Default ordering for first page
                $paginatedQuery->orderBy($cursorColumn, 'desc');
            }

            // Fetch one extra item to determine if there are more pages
            $items = $paginatedQuery->limit($perPage + 1)->get();
        } catch (\Exception $exception) {
            Log::error('Cursor pagination query failed', [
                'cursor' => $cursor,
                'direction' => $direction,
                'cursor_column' => $cursorColumn,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString()
            ]);
            
            throw ValidationException::withMessages([
                'pagination' => 'Failed to execute pagination query: ' . $exception->getMessage()
            ]);
        }

        // Check if there are more items
        $hasMore = $items->count() > $perPage;
        if ($hasMore) {
            $items = $items->take($perPage);
        }

        // If we're going backwards, reverse the order
        if ('prev' === $direction) {
            $items = $items->reverse()->values();
        }

        // Generate cursors for navigation
        $nextCursor = null;
        $prevCursor = null;

        if ($items->isNotEmpty()) {
            $firstItem = $items->first();
            $lastItem = $items->last();

            if ($hasMore && 'next' === $direction) {
                $nextCursor = $lastItem->{$cursorColumn};
            }

            if ($hasMore && 'prev' === $direction) {
                $prevCursor = $firstItem->{$cursorColumn};
            }

            // For first page, set next cursor if there are more items
            if (!$cursor && $hasMore) {
                $nextCursor = $lastItem->{$cursorColumn};
            }

            // Check if there are previous items (only if not on first page)
            if ($cursor) {
                $prevQuery = clone $query;
                $prevQuery->where($cursorColumn, '>', $firstItem->{$cursorColumn});
                $hasPrev = $prevQuery->exists();
                if ($hasPrev) {
                    $prevCursor = $firstItem->{$cursorColumn};
                }
            }
        }

        return [
            'data' => $items->toArray(),
            'meta' => [
                'per_page' => $perPage,
                'cursor_column' => $cursorColumn,
                'has_more' => $hasMore,
                'cursors' => [
                    'next' => $nextCursor,
                    'prev' => $prevCursor,
                ],
            ],
        ];
    }

    /**
     * Apply cursor-based pagination with composite cursors for complex sorting.
     * 
     * This method handles pagination scenarios where multiple columns are needed
     * for unique sorting, such as when the primary sort column contains duplicate
     * values or when complex multi-column sorting is required.
     *
     * @param Builder|QueryBuilder $query The Eloquent query builder instance
     * @param Request              $request The HTTP request containing cursor parameters:
     *                                     - 'cursor': Base64 encoded JSON array of cursor values
     *                                     - 'direction': 'next' or 'prev' (default: 'next')
     * @param array                $cursorColumns Array of column names for composite cursor (ordered by priority)
     * @param int                  $perPage Number of items per page (default: 25, max: 100)
     * 
     * @return array Paginated results with metadata:
     *               - 'data': Collection of paginated items
     *               - 'meta': Pagination metadata with composite cursors
     * 
     * @throws ValidationException When cursor validation fails or invalid parameters provided
     * 
     * @example
     * ```php
     * // Paginate orders by created_at and id for uniqueness
     * $orders = Order::with('customer');
     * $result = CursorPagination::paginateComposite(
     *     $orders, 
     *     $request, 
     *     ['created_at', 'id'], 
     *     25
     * );
     * 
     * // For non-unique columns like status and priority
     * $tickets = Ticket::where('status', 'open');
     * $result = CursorPagination::paginateComposite(
     *     $tickets,
     *     $request,
     *     ['priority', 'created_at', 'id'],
     *     20
     * );
     * ```
     */
    public static function paginateComposite($query, Request $request, array $cursorColumns = ['created_at', 'id'], int $perPage = 25): array
    {
        // Validate composite cursor inputs
        self::validateCompositeCursorInputs($request, $cursorColumns, $perPage);
        $maxPerPage = (int) config('cursor_pagination.max_per_page', config('record.per_page_max', 100));
        $perPage = max(1, min($perPage, $maxPerPage));

        // Get cursor parameters
        $cursor = $request->query('cursor');
        $direction = strtolower($request->query('direction', 'next'));

        try {
            // Clone query
            $paginatedQuery = clone $query;

            // Parse composite cursor with enhanced validation
            $cursorValues = [];
            if ($cursor) {
                try {
                    $decoded = base64_decode($cursor, true);
                    if ($decoded === false) {
                        throw new \InvalidArgumentException('Invalid base64 cursor format');
                    }
                    
                    $cursorValues = json_decode($decoded, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new \InvalidArgumentException('Invalid JSON in cursor: ' . json_last_error_msg());
                    }
                    
                    if (!is_array($cursorValues) || count($cursorValues) !== count($cursorColumns)) {
                        throw new \InvalidArgumentException('Cursor values count does not match cursor columns count');
                    }
                    
                    // Validate cursor values
                    foreach ($cursorValues as $value) {
                        if (!is_scalar($value) && $value !== null) {
                            throw new \InvalidArgumentException('Invalid cursor value type');
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning('Composite cursor parsing failed', [
                        'cursor' => $cursor,
                        'error' => $e->getMessage()
                    ]);
                    throw ValidationException::withMessages([
                        'cursor' => 'Invalid composite cursor format: ' . $e->getMessage()
                    ]);
                }
            }

            // Apply cursor conditions for composite sorting
            if ($cursorValues !== []) {
                $paginatedQuery->where(function ($q) use ($cursorColumns, $cursorValues, $direction): void {
                    self::applyCompositeCursorConditions($q, $cursorColumns, $cursorValues, $direction);
                });
            }

            // Apply ordering
            foreach ($cursorColumns as $column) {
                $order = ('prev' === $direction) ? 'asc' : 'desc';
                $paginatedQuery->orderBy($column, $order);
            }

            // Fetch items
            $items = $paginatedQuery->limit($perPage + 1)->get();
        } catch (ValidationException $e) {
            // Re-throw validation exceptions
            throw $e;
        } catch (\Exception $e) {
            Log::error('Composite cursor pagination query failed', [
                'cursor' => $cursor,
                'direction' => $direction,
                'cursor_columns' => $cursorColumns,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw ValidationException::withMessages([
                'pagination' => 'Failed to execute composite pagination query: ' . $e->getMessage()
            ]);
        }

        // Check if there are more items
        $hasMore = $items->count() > $perPage;
        if ($hasMore) {
            $items = $items->take($perPage);
        }

        // Reverse order if going backwards
        if ('prev' === $direction) {
            $items = $items->reverse()->values();
        }

        // Generate cursors
        $nextCursor = null;
        $prevCursor = null;

        if ($items->isNotEmpty()) {
            $firstItem = $items->first();
            $lastItem = $items->last();

            if ($hasMore) {
                $nextCursorValues = [];
                foreach ($cursorColumns as $cursorColumn) {
                    $nextCursorValues[] = $lastItem->{$cursorColumn};
                }

                $nextCursor = base64_encode(json_encode($nextCursorValues));
            }

            // Check for previous cursor
            if ($cursor || $hasMore) {
                $prevCursorValues = [];
                foreach ($cursorColumns as $cursorColumn) {
                    $prevCursorValues[] = $firstItem->{$cursorColumn};
                }

                $prevCursor = base64_encode(json_encode($prevCursorValues));
            }
        }

        return [
            'data' => $items->toArray(),
            'meta' => [
                'per_page' => $perPage,
                'cursor_columns' => $cursorColumns,
                'has_more' => $hasMore,
                'cursors' => [
                    'next' => $nextCursor,
                    'prev' => $prevCursor,
                ],
            ],
        ];
    }

    /**
     * Determine if cursor pagination should be used based on dataset size and configuration.
     * 
     * This method analyzes the query and dataset to automatically decide whether
     * cursor-based pagination would be more efficient than offset-based pagination.
     * The decision is based on estimated row counts, configuration settings, and
     * table-specific rules.
     *
     * @param Builder|QueryBuilder $query The Eloquent query builder to analyze
     * @param int|null $threshold Optional threshold override (bypasses config settings)
     * 
     * @return bool True if cursor pagination should be used, false for offset pagination
     * 
     * Decision Logic:
     * 1. Check if table is in forced_tables list (always true)
     * 2. Check if table is in excluded_tables list (always false)
     * 3. Compare estimated row count against threshold
     * 4. Use table-specific threshold if configured
     * 5. Fall back to default threshold from config
     * 
     * @example
     * ```php
     * $users = User::where('active', true);
     * 
     * if (CursorPagination::shouldUseCursorPagination($users)) {
     *     // Use cursor pagination for large dataset
     *     $result = CursorPagination::paginate($users, $request, 'created_at');
     * } else {
     *     // Use standard Laravel pagination for smaller dataset
     *     $result = $users->paginate(15);
     * }
     * 
     * // Override threshold for specific case
     * if (CursorPagination::shouldUseCursorPagination($users, 5000)) {
     *     // Force cursor pagination if more than 5000 rows
     * }
     * ```
     */
    public static function shouldUseCursorPagination($query, ?int $threshold = null): bool
    {
        try {
            // Get table name for configuration checks
            $tableName = self::getTableName($query);

            // Check if table is in forced list
            if (in_array($tableName, config('cursor_pagination.forced_tables', []))) {
                return true;
            }

            // Check if table is in excluded list
            if (in_array($tableName, config('cursor_pagination.excluded_tables', []))) {
                return false;
            }

            // Get threshold - check table-specific first, then parameter, then default
            $tableThresholds = config('cursor_pagination.table_thresholds', []);
            $threshold = $tableThresholds[$tableName] ?? $threshold ?? config('cursor_pagination.auto_threshold', 10000);

            // If threshold is 0, disable auto-detection
            if ($threshold <= 0) {
                return false;
            }

            // For very large tables, always use cursor pagination
            $estimatedCount = self::estimateRowCount($query);

            return $estimatedCount > $threshold;
        } catch (\Exception $exception) {
            Log::error('Error determining cursor pagination usage', [
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString()
            ]);
            
            // Default to false for safety when error occurs
            return false;
        }
    }

    /**
     * Apply composite cursor conditions recursively.
     *
     * @param mixed $query
     */
    private static function applyCompositeCursorConditions($query, array $columns, array $values, string $direction, int $index = 0): void
    {
        if ($index >= count($columns)) {
            return;
        }

        $column = $columns[$index];
        $value = $values[$index];
        $operator = ('prev' === $direction) ? '>' : '<';

        if ($index === count($columns) - 1) {
            // Last column, apply simple condition
            $query->where($column, $operator, $value);
        } else {
            // Not the last column, apply OR condition
            $query->where(function ($q) use ($columns, $values, $direction, $index, $column, $value, $operator): void {
                // Equal to current value AND recurse to next column
                $q->where(function ($subQ) use ($columns, $values, $direction, $index, $column, $value): void {
                    $subQ->where($column, '=', $value);
                    self::applyCompositeCursorConditions($subQ, $columns, $values, $direction, $index + 1);
                });
                // OR greater/less than current value
                $q->orWhere($column, $operator, $value);
            });
        }
    }

    /**
     * Get table name from query.
     *
     * @param mixed $query
     */
    private static function getTableName($query): ?string
    {
        try {
            if ($query instanceof Builder) {
                $tableName = $query->getModel()->getTable();
                
                if (empty($tableName)) {
                    throw new \InvalidArgumentException('Model does not have a table name');
                }
                
                // Validate table name format
                if (in_array(preg_match('/^[a-zA-Z_]\w*$/', $tableName), [0, false], true)) {
                    throw new \InvalidArgumentException('Invalid table name format: ' . $tableName);
                }
                
                return $tableName;
            }

            if ($query instanceof QueryBuilder) {
                $from = $query->from;
                
                if (empty($from)) {
                    throw new \InvalidArgumentException('Query does not have a FROM clause');
                }
                
                // Handle table aliases (e.g., "users as u")
                $tableName = str_contains($from, ' as ') ? trim(explode(' as ', $from)[0]) : $from;
                
                // Validate table name format
                if (in_array(preg_match('/^[a-zA-Z_]\w*$/', $tableName), [0, false], true)) {
                    throw new \InvalidArgumentException('Invalid table name format: ' . $tableName);
                }
                
                return $tableName;
            }

            return null;
        } catch (\Exception $exception) {
            Log::error('Failed to extract table name from query', [
                'error' => $exception->getMessage(),
                'query_type' => $query::class
            ]);
            
            return null;
        }
    }

    /**
     * Estimate row count without expensive COUNT() query.
     * Uses optimized strategies with caching and fallback mechanisms.
     *
     * @param mixed $query
     */
    private static function estimateRowCount($query): int
    {
        try {
            $tableName = self::getTableName($query);

            if ($tableName === null || $tableName === '' || $tableName === '0') {
                return 0;
            }

            // Check cache first (configurable TTL)
            $cacheKey = "table_row_count:{$tableName}";
            $cacheTtl = config('cursor_pagination.statistics_cache_ttl', 300);
            $cached = cache()->remember($cacheKey, $cacheTtl, function () use ($tableName) {
                try {
                    return self::fetchTableRowCount($tableName);
                } catch (\Exception $exception) {
                    Log::warning('Row count estimation failed, using default', [
                        'table' => $tableName,
                        'error' => $exception->getMessage()
                    ]);
                    
                    return config('cursor_pagination.default_estimate', 1000);
                }
            });

            return $cached;
        } catch (\Exception $exception) {
            Log::error('Critical error in row count estimation', [
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString()
            ]);
            
            return config('cursor_pagination.default_estimate', 1000);
        }
    }

    /**
     * Fetch table row count using multiple optimized strategies.
     * Uses the most efficient approaches to avoid expensive COUNT() queries.
     *
     * @param string $tableName
     */
    private static function fetchTableRowCount(string $tableName): int
    {
        $timeout = config('cursor_pagination.estimation_timeout', 2);
        
        try {
            // Set query timeout to prevent long-running estimation queries
            DB::statement("SET SESSION max_execution_time = {$timeout}");
            
            // Strategy 1: Use INFORMATION_SCHEMA for accurate InnoDB estimates
            $infoSchema = DB::select(
                "SELECT table_rows FROM INFORMATION_SCHEMA.TABLES 
                 WHERE table_schema = DATABASE() AND table_name = ?",
                [$tableName]
            );
            
            if (!empty($infoSchema) && isset($infoSchema[0]->table_rows)) {
                $rows = (int) $infoSchema[0]->table_rows;
                if ($rows > 0) {
                    return $rows;
                }
            }

            // Strategy 2: Use SHOW TABLE STATUS (fastest for MyISAM, good estimate for InnoDB)
            $status = DB::select("SHOW TABLE STATUS LIKE ?", [$tableName]);
            if (!empty($status) && isset($status[0]->Rows)) {
                $approximateRows = (int) $status[0]->Rows;
                if ($approximateRows > 0) {
                    return $approximateRows;
                }
            }

            // Strategy 3: Use EXPLAIN SELECT for query optimizer estimates
            $explain = DB::select("EXPLAIN SELECT COUNT(*) FROM `{$tableName}`");
            if (!empty($explain) && isset($explain[0]->rows)) {
                return (int) $explain[0]->rows;
            }

            // Strategy 4: Statistical sampling fallback
            return self::estimateRowCountBySampling($tableName);
        } catch (\Exception $exception) {
            Log::debug('Row count estimation failed', [
                'table' => $tableName,
                'error' => $exception->getMessage()
            ]);
            
            // Final fallback
            return self::estimateRowCountBySampling($tableName);
        } finally {
            // Reset query timeout
            try {
                DB::statement('SET SESSION max_execution_time = DEFAULT');
            } catch (\Exception) {
                // Ignore timeout reset errors
            }
        }
    }

    /**
     * Estimate row count using optimized statistical sampling.
     * This is the fastest and most reliable fallback method.
     *
     * @param string $tableName
     */
    private static function estimateRowCountBySampling(string $tableName): int
    {
        try {
            $sampleSize = config('cursor_pagination.sampling_size', 1000);
            $timeout = config('cursor_pagination.estimation_timeout', 2);
            
            // Use optimized sampling with query hints for better performance
            $sampleQuery = "
                SELECT COUNT(*) as count 
                FROM (
                    SELECT /*+ USE_INDEX() */ 1 
                    FROM `{$tableName}` 
                    USE INDEX () 
                    LIMIT {$sampleSize}
                ) as sample
            ";
            
            // Set a shorter timeout for sampling queries
            DB::statement("SET SESSION max_execution_time = {$timeout}");
            
            $sample = DB::select($sampleQuery);
            
            if (!empty($sample) && isset($sample[0]->count)) {
                $actualSample = (int) $sample[0]->count;
                
                if ($actualSample == $sampleSize) {
                    // Full sample retrieved - estimate total using statistical extrapolation
                    // Use a more sophisticated estimation based on table characteristics
                    $multiplier = config('cursor_pagination.estimation_multiplier', 10);
                    
                    // Try to get table size for better estimation
                    try {
                        $tableSize = DB::select(
                            "SELECT ROUND(((data_length + index_length) / 1024 / 1024), 2) as size_mb 
                             FROM information_schema.TABLES 
                             WHERE table_schema = DATABASE() AND table_name = ?",
                            [$tableName]
                        );
                        
                        if (!empty($tableSize) && $tableSize[0]->size_mb > 100) {
                            // Large table - use higher multiplier
                            $multiplier = 50;
                        } elseif (!empty($tableSize) && $tableSize[0]->size_mb > 10) {
                            // Medium table - moderate multiplier
                            $multiplier = 20;
                        }
                    } catch (\Exception) {
                        // Ignore table size check errors
                    }
                    
                    return $sampleSize * $multiplier;
                }
                
                return $actualSample;
            }
            
            return 0;
        } catch (\Exception $exception) {
            Log::debug('Statistical sampling failed', [
                'table' => $tableName,
                'error' => $exception->getMessage()
            ]);
            
            // Ultimate fallback - use configured default estimate
            return config('cursor_pagination.default_estimate', 5000);
        } finally {
            try {
                DB::statement('SET SESSION max_execution_time = DEFAULT');
            } catch (\Exception) {
                // Ignore timeout reset errors
            }
        }
    }

    /**
     * Validate pagination input parameters for security and correctness.
     *
     * @param Request $request
     * @param string $cursorColumn
     * @param int $perPage
     * 
     * @throws ValidationException
     */
    private static function validatePaginationInputs(Request $request, string $cursorColumn, int $perPage): void
    {
        // Validate cursor column name (prevent SQL injection)
        if (in_array(preg_match('/^[a-zA-Z_]\w*$/', $cursorColumn), [0, false], true)) {
            throw ValidationException::withMessages([
                'cursor_column' => 'Invalid cursor column name format.'
            ]);
        }

        // Validate direction parameter
        $direction = $request->query('direction', 'next');
        if (!in_array(strtolower($direction), ['next', 'prev'])) {
            throw ValidationException::withMessages([
                'direction' => 'Direction must be either "next" or "prev".'
            ]);
        }

        // Validate cursor format if provided
        $cursor = $request->query('cursor');
        if ($cursor && !self::isValidCursor($cursor)) {
            throw ValidationException::withMessages([
                'cursor' => 'Invalid cursor format.'
            ]);
        }

        // Validate per_page parameter
        if ($perPage < 1 || $perPage > config('cursor_pagination.max_per_page', 100)) {
            throw ValidationException::withMessages([
                'per_page' => 'Per page value must be between 1 and ' . config('cursor_pagination.max_per_page', 100) . '.'
            ]);
        }
    }

    /**
     * Validate that the cursor column exists in the query.
     *
     * @param Builder|QueryBuilder $query
     * @param string $cursorColumn
     * 
     * @throws ValidationException
     */
    private static function validateCursorColumn($query, string $cursorColumn): void
    {
        try {
            $tableName = self::getTableName($query);
            if ($tableName === null || $tableName === '' || $tableName === '0') {
                return; // Skip validation if table name cannot be determined
            }

            // Check if column exists in table schema
            $columns = Cache::remember(
                "table_columns:{$tableName}",
                config('cursor_pagination.schema_cache_ttl', 3600),
                fn() => DB::getSchemaBuilder()->getColumnListing($tableName)
            );

            if (!in_array($cursorColumn, $columns)) {
                throw ValidationException::withMessages([
                    'cursor_column' => "Column '{$cursorColumn}' does not exist in table '{$tableName}'."
                ]);
            }
        } catch (\Exception $exception) {
            // Log error but don't fail pagination
            Log::warning('Cursor column validation failed', [
                'cursor_column' => $cursorColumn,
                'error' => $exception->getMessage()
            ]);
        }
    }

    /**
     * Validate cursor format for security.
     *
     * @param string $cursor
     */
    private static function isValidCursor(string $cursor): bool
    {
        // Check if cursor is valid base64
        if (in_array(base64_decode($cursor, true), ['', '0'], true) || base64_decode($cursor, true) === false) {
            return false;
        }

        // Decode and validate JSON structure for composite cursors
        $decoded = base64_decode($cursor);
        if (json_decode($decoded) !== null) {
            // Valid JSON cursor (composite)
            return true;
        }

        // For simple cursors, check if it's a reasonable value
        return strlen($decoded) <= 255 && preg_match('/^[a-zA-Z0-9\-_\.\s]*$/', $decoded);
    }

    /**
     * Validate composite cursor input parameters.
     *
     * @param Request $request
     * @param array $cursorColumns
     * @param int $perPage
     * 
     * @throws ValidationException
     */
    private static function validateCompositeCursorInputs(Request $request, array $cursorColumns, int $perPage): void
    {
        // Validate cursor columns array
        if ($cursorColumns === []) {
            throw ValidationException::withMessages([
                'cursor_columns' => 'Cursor columns array cannot be empty.'
            ]);
        }

        if (count($cursorColumns) > config('cursor_pagination.max_cursor_columns', 5)) {
            throw ValidationException::withMessages([
                'cursor_columns' => 'Too many cursor columns. Maximum allowed: ' . config('cursor_pagination.max_cursor_columns', 5)
            ]);
        }

        // Validate each cursor column name
        foreach ($cursorColumns as $column) {
            if (!is_string($column) || in_array(preg_match('/^[a-zA-Z_]\w*$/', $column), [0, false], true)) {
                throw ValidationException::withMessages([
                    'cursor_columns' => "Invalid cursor column name format: {$column}"
                ]);
            }
        }

        // Validate direction parameter
        $direction = $request->query('direction', 'next');
        if (!in_array(strtolower($direction), ['next', 'prev'])) {
            throw ValidationException::withMessages([
                'direction' => 'Direction must be either "next" or "prev".'
            ]);
        }

        // Validate per_page parameter
        if ($perPage < 1 || $perPage > config('cursor_pagination.max_per_page', 100)) {
            throw ValidationException::withMessages([
                'per_page' => 'Per page value must be between 1 and ' . config('cursor_pagination.max_per_page', 100) . '.'
            ]);
        }
    }
}
