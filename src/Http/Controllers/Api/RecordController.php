<?php

namespace Sopheak\Core\Http\Controllers\Api;

use Illuminate\Routing\Controller;
use Sopheak\Core\Enums\AuditLogEventEnum;
use Sopheak\Core\Services\AuditLogService;
use Sopheak\Core\Services\CursorPagination;
use Sopheak\Core\Services\QueryCacheService;
use Sopheak\Core\Support\PermissionHelper;
use Sopheak\Core\Support\QueryBuilderFilters;
use Sopheak\Core\Support\RelationshipResolver;
use Sopheak\Core\Support\SchemaRegistry;
use Sopheak\Core\Types\RecordFunctionType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecordController extends Controller
{
    /**
     * Cache for schema lookups to reduce repeated calls.
     */
    private static array $schemaCache = [];

    /**
     * Cache for tenant configuration to avoid repeated config calls.
     */
    private static ?bool $tenantIdEnabled = null;

    /**
     * Optimized index method with reduced N+1 queries and better caching.
     *
     * This method implements comprehensive performance optimizations for listing records:
     * 1. Advanced caching with request fingerprinting and dynamic TTLs
     * 2. Optimized relationship loading to prevent N+1 query problems
     * 3. Tenant-aware filtering based on configuration settings
     * 4. Efficient pagination with both traditional and cursor-based options
     * 5. Schema-based column validation and security filtering
     *
     * Performance Optimizations:
     * - Uses cached schema to reduce database round trips
     * - Implements intelligent relationship analysis and eager loading
     * - Applies optimized query filters with operation categorization
     * - Generates cache keys with request fingerprinting for better hit rates
     * - Uses dynamic cache TTLs based on data volatility
     *
     * Security Features:
     * - Authorization checks before data access
     * - Tenant isolation when enabled in configuration
     * - Column-level access control through schema validation
     * - Input sanitization and validation
     *
     * Caching Strategy:
     * - Cacheable requests are identified based on query complexity
     * - Cache keys include tenant context and relationship data
     * - TTL varies based on table configuration and data patterns
     *
     * @param Request $request HTTP request with query parameters
     * @param string  $table   Target table name for record retrieval
     *
     * @return JsonResponse Paginated list of records with metadata
     */
    public function index(Request $request, string $table): JsonResponse
    {
        $this->authorizeAction($table, 'read');
        $schema = $this->getCachedSchema();
        if (!isset($schema[$table])) {
            return $this->error('Resource not available', 404);
        }

        // Resolve actual table name from RecordTableType configuration
        $actualTableName = $this->resolveActualTableName($table);

        $tenantId = $request->attributes->get('tenant_id');

        // Generate cache key for this query
        $filters = $request->except(['page', 'per_page', 'limit']);
        $includes = $request->query('select', []);
        // Ensure includes is always an array for cache key generation
        if (is_string($includes)) {
            $includes = explode(',', $includes);
        }

        $page = max((int) $request->get('page', 1), 1);
        $perPage = $request->has('per_page') ? max(1, min((int) $request->get('per_page', 25), (int) config('record.per_page_max', 100))) : null;
        $limit = $request->has('limit') ? max(1, min((int) $request->get('limit'), (int) config('record.limit_max', 1000))) : config('record.limit_max', 1000);

        // Enhanced caching with request fingerprinting
        $isCacheable = $this->isCacheableRequest($request, $table);
        $cacheKey = null;

        if ($isCacheable) {
            // Create more specific cache key including tenant status
            $cacheKey = $this->generateOptimizedCacheKey(
                $table,
                array_merge($filters, [
                    'tenant_id' => $this->isTenantIdEnabled() ? $tenantId : null,
                    'tenant_enabled' => $this->isTenantIdEnabled(),
                ]),
                $includes,
                $page,
                $perPage ?? $limit
            );

            $cached = QueryCacheService::get($cacheKey);
            if (null !== $cached) {
                return $this->success($cached['data'], $cached['meta'], status: 200, headers: $cached['headers']);
            }
        }

        // Optimized query building with better indexing hints
        $builder = DB::table($actualTableName);

        // Apply tenant filtering using optimized method
        $this->applyTenantFilter($builder, $actualTableName, $tenantId);

        // Apply soft delete filtering
        if ($schema[$table]->soft_deletes) {
            $builder->whereNull($actualTableName.'.deleted_at');
        }

        // Apply filters, including base select of main table columns
        QueryBuilderFilters::apply($builder, $request, $actualTableName, $schema[$table]->primary_key ?? 'id');

        // Handle limit parameter for non-paginated requests
        if ($request->has('limit') && !$request->has('per_page')) {
            $limit = max(1, min((int) $request->get('limit'), (int) config('record.limit_max', 1000)));
            $data = $builder->limit($limit)->get()->all();
            $total = count($data);
        } else {
            // Handle pagination - check if cursor-based pagination should be used
            $maxPerPage = (int) config('record.per_page_max', 100);
            $perPage = max(1, min((int) $request->get('per_page', config('record.limit_max', 1000)), $maxPerPage));

            // Use cursor-based pagination for large datasets or when explicitly requested
            if ($request->has('cursor') || CursorPagination::shouldUseCursorPagination($builder)) {
                $primaryKey = $schema[$table]->primary_key ?? 'id';
                $cursorColumn = $request->get('cursor_column', $primaryKey);

                // Use composite cursor for complex sorting
                if ($request->has('composite_cursor') || $request->has('sortby')) {
                    $cursorColumns = [$cursorColumn];
                    if ($cursorColumn !== $primaryKey) {
                        $cursorColumns[] = $primaryKey; // Add primary key for uniqueness
                    }

                    $result = CursorPagination::paginateComposite($builder, $request, $cursorColumns, $perPage);
                } else {
                    $result = CursorPagination::paginate($builder, $request, $cursorColumn, $perPage);
                }

                $data = $result['data'];
                $total = null; // Cursor pagination doesn't provide total count
                $cursorMeta = $result['meta'];
            } else {
                // Traditional pagination for smaller datasets
                $page = max((int) $request->get('page', 1), 1);

                // Clone for count to avoid select/limit interference
                $countQuery = clone $builder;
                $total = $countQuery->count();

                $data = $builder->forPage($page, $perPage)->get()->all();
            }
        }

        // Optimized relationship includes with subquery loading
        if ($request->has('select')) {
            $selectParam = $request->query('select');
            $includes = RelationshipResolver::parseSelectForIncludes($selectParam);

            // Check if we should use subquery optimization (for performance)
            $useSubqueryOptimization = config('record.use_subquery_optimization', true) && count($data) <= 100;

            if ($useSubqueryOptimization && [] !== $includes) {
                // Use new subquery optimization for better performance
                // Note: This approach loads relationships in a single query using JSON aggregation
                $primaryKey = $schema[$table]->primary_key ?? 'id';
                $recordIds = array_column($data, $primaryKey);

                if ([] !== $recordIds) {
                    // Build optimized query with subquery relationships
                    $optimizedBuilder = DB::table($actualTableName);

                    // Apply column selection based on select parameter
                    $mainCols = RelationshipResolver::getMainTableColumns($selectParam);
                    if ([] !== $mainCols) {
                        $prefixedCols = array_map(fn ($col) => '*' === $col ? $actualTableName.'.*' : (str_contains((string) $col, '.') ? $col : $actualTableName.'.'.$col), $mainCols);
                        $optimizedBuilder->select($prefixedCols);
                    } else {
                        $optimizedBuilder->select($actualTableName.'.*');
                    }

                    // Apply tenant filtering
                    $this->applyTenantFilter($optimizedBuilder, $actualTableName, $tenantId);

                    // Apply soft delete filtering
                    if ($schema[$table]->soft_deletes) {
                        $optimizedBuilder->whereNull($actualTableName.'.deleted_at');
                    }

                    // Add subquery relationships
                    RelationshipResolver::applySubqueryRelationships(
                        $optimizedBuilder,
                        $table,
                        $includes,
                        $this->isTenantIdEnabled() ? $tenantId : null
                    );

                    // Get records with relationships in single query
                    $optimizedData = $optimizedBuilder->whereIn($actualTableName.'.'.$primaryKey, $recordIds)->get()->all();

                    // Process JSON relationships
                    $data = RelationshipResolver::processJsonRelationships($optimizedData, $includes, $table);
                }
            } else {
                // Fallback to existing relationship loading method
                $data = RelationshipResolver::includeRelationships(
                    $data,
                    $table,
                    $selectParam,
                    $this->isTenantIdEnabled() ? $tenantId : null
                );
            }
        }

        // Remove deleted_at fields from response data
        $data = $this->removeDeletedAtFields($data);

        $headers = [];
        $meta = [];

        // Add pagination headers and metadata for paginated requests
        if ($request->has('per_page') && !$request->has('limit')) {
            // Check if cursor-based pagination was used
            if (isset($cursorMeta)) {
                // Cursor-based pagination response
                $headers['X-Per-Page'] = (string) $perPage;
                $headers['X-Cursor-Column'] = $cursorMeta['cursor_column'] ?? $cursorMeta['cursor_columns'][0] ?? 'id';

                if ($cursorMeta['has_more']) {
                    $headers['X-Has-More'] = 'true';
                }

                // Build cursor-based navigation links
                $baseUrl = $request->url();
                $queryParams = $request->query();
                $links = [];

                if ($cursorMeta['cursors']['next']) {
                    $queryParams['cursor'] = $cursorMeta['cursors']['next'];
                    $queryParams['direction'] = 'next';
                    unset($queryParams['page']); // Remove page parameter for cursor pagination
                    $links[] = '<'.$baseUrl.'?'.http_build_query($queryParams).'>; rel="next"';
                }

                if ($cursorMeta['cursors']['prev']) {
                    $queryParams['cursor'] = $cursorMeta['cursors']['prev'];
                    $queryParams['direction'] = 'prev';
                    unset($queryParams['page']);
                    $links[] = '<'.$baseUrl.'?'.http_build_query($queryParams).'>; rel="prev"';
                }

                if ([] !== $links) {
                    $headers['Link'] = implode(', ', $links);
                }

                // Add cursor metadata to response body
                $meta = $cursorMeta;
            } else {
                // Traditional pagination response
                $headers['X-Total-Count'] = (string) $total;
                $lastPage = (int) ceil($total / $perPage);
                $baseUrl = $request->url();
                $queryParams = $request->query();

                $links = [];
                $buildLink = function ($pageNum, string $rel) use ($baseUrl, $queryParams): string {
                    $queryParams['page'] = $pageNum;

                    return '<'.$baseUrl.'?'.http_build_query($queryParams).'>; rel="'.$rel.'"';
                };
                $links[] = $buildLink(max($page - 1, 1), 'prev');
                $links[] = $buildLink(min($page + 1, 0 !== $lastPage ? $lastPage : 1), 'next');
                $links[] = $buildLink(1, 'first');
                $links[] = $buildLink(0 !== $lastPage ? $lastPage : 1, 'last');
                $headers['Link'] = implode(', ', $links);

                // Add pagination headers
                $headers['X-Page'] = (string) $page;
                $headers['X-Per-Page'] = (string) $perPage;
                $headers['X-Total-Pages'] = (string) $lastPage;

                // Add pagination metadata to response body
                $meta = [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                ];
            }
        } else {
            // For limit-based requests, only include total in meta
            $headers['X-Total-Count'] = (string) $total;
            $meta = ['total' => $total];
        }

        // Enhanced caching with TTL optimization
        if ($isCacheable && $cacheKey) {
            $cacheData = [
                'data' => $data,
                'meta' => $meta,
                'headers' => $headers,
                'cached_at' => now()->toISOString(),
                'tenant_enabled' => $this->isTenantIdEnabled(),
            ];

            // Use dynamic TTL based on data size and complexity
            $ttl = $this->calculateOptimalCacheTTL($table, count($data), $request->has('select'));
            QueryCacheService::put($cacheKey, $cacheData, $table, $ttl);
        }

        return $this->success($data, $meta, status: 200, headers: $headers);
    }

    /**
     * Optimized show method with enhanced caching and relationship loading.
     *
     * @param mixed $id
     */
    public function show(Request $request, string $table, $id): JsonResponse
    {
        $this->authorizeAction($table, 'read');
        $schema = $this->getCachedSchema();
        if (!isset($schema[$table])) {
            return $this->error('Resource not available', 404);
        }

        // Resolve actual table name from RecordTableType configuration
        $actualTableName = $this->resolveActualTableName($table);

        $pk = $schema[$table]->primary_key ?? 'id';

        $tenantId = $request->attributes->get('tenant_id');

        // Check cache for single record
        $recordCacheKey = $this->generateRecordCacheKey($table, $id, $tenantId, $request->query('select'));
        if ($this->isCacheableRequest($request, $table)) {
            $cachedRecord = Cache::get($recordCacheKey);
            if ($cachedRecord) {
                return $this->success($cachedRecord);
            }
        }

        $builder = DB::table($actualTableName);

        // Apply tenant filtering using optimized method
        $this->applyTenantFilter($builder, $actualTableName, $tenantId);

        if ($schema[$table]->soft_deletes) {
            $builder->whereNull($actualTableName.'.deleted_at');
        }

        // Ensure base columns if select contains relationships
        if ($request->has('select')) {
            $mainCols = RelationshipResolver::getMainTableColumns($request->query('select'));
            if ([] !== $mainCols) {
                $builder->addSelect($mainCols);
            }
        }

        $record = $builder->where($pk, $id)->first();
        if (!$record) {
            return $this->error('Not found', 404);
        }

        // Optimized relationship includes for single record
        if ($request->has('select')) {
            $selectParam = $request->query('select');
            $includes = RelationshipResolver::parseSelectForIncludes($selectParam);

            // Use subquery optimization for single record (always enabled for single records)
            $useSubqueryOptimization = config('record.use_subquery_optimization', true);

            if ($useSubqueryOptimization && [] !== $includes) {
                // Use new subquery optimization for better performance
                $optimizedBuilder = DB::table($actualTableName);

                // Apply column selection based on select parameter
                if ([] !== $mainCols) {
                    $prefixedCols = array_map(fn ($col) => '*' === $col ? $actualTableName.'.*' : (str_contains((string) $col, '.') ? $col : $actualTableName.'.'.$col), $mainCols);
                    $optimizedBuilder->select($prefixedCols);
                } else {
                    $optimizedBuilder->select($actualTableName.'.*');
                }

                // Apply tenant filtering
                $this->applyTenantFilter($optimizedBuilder, $actualTableName, $tenantId);

                // Apply soft delete filtering
                if ($schema[$table]->soft_deletes) {
                    $optimizedBuilder->whereNull($actualTableName.'.deleted_at');
                }

                // Add subquery relationships
                RelationshipResolver::applySubqueryRelationships(
                    $optimizedBuilder,
                    $table,
                    $includes,
                    $this->isTenantIdEnabled() ? $tenantId : null
                );

                // Get record with relationships in single query
                $optimizedRecord = $optimizedBuilder->where($pk, $id)->first();

                if ($optimizedRecord) {
                    // Process JSON relationships
                    $processedData = RelationshipResolver::processJsonRelationships([$optimizedRecord], $includes, $table);
                    $record = $processedData[0] ?? $record;
                }
            } else {
                // Fallback to existing relationship loading method
                $data = RelationshipResolver::includeRelationships(
                    [$record],
                    $table,
                    $selectParam,
                    $this->isTenantIdEnabled() ? $tenantId : null
                );
                $record = $data[0] ?? $record;
            }
        }

        // Remove deleted_at field from response data
        $record = $this->removeDeletedAtFields($record);

        // Cache the single record result
        if ($this->isCacheableRequest($request, $table)) {
            $ttl = $this->calculateOptimalCacheTTL($table, 1, $request->has('select'));
            Cache::put($recordCacheKey, $record, $ttl);
        }

        return $this->success($record);
    }

    public function store(Request $request, string $table): JsonResponse
    {
        $this->authorizeAction($table, 'create');
        $schema = SchemaRegistry::get();
        if (!isset($schema[$table])) {
            return $this->error('Resource not available', 404);
        }

        // Resolve actual table name from RecordTableType configuration
        $actualTableName = $this->resolveActualTableName($table);

        $payload = $request->all();

        // Strip relationship data from main payload
        $payloadMain = RelationshipResolver::stripRelationshipData($table, $payload);
        $payloadMain = $this->sanitizePayload($payloadMain, $schema[$table], false, $table);

        $tenantId = $request->attributes->get('tenant_id');
        if ($tenantId && ($schema[$table]->has_tenant_id ?? false)) {
            $payloadMain['tenant_id'] = $tenantId;
        }

        // Apply timestamps and audit fields
        $payloadMain = $this->applyTimestampsAndAuditFields($payloadMain, $schema[$table], false);

        $insertedId = null;
        $record = null;

        // Begin transaction
        DB::beginTransaction();

        try {
            $pk = $schema[$table]->primary_key ?? 'id';
            $insertedId = DB::table($actualTableName)->insertGetId($payloadMain, $pk);

            $isCacheable = $this->isCacheableRequest($request, $table);
            if ($isCacheable) {
                // Invalidate cache for this table
                QueryCacheService::invalidateTable($table);
            }

            // Audit log for creation (if not disabled)
            if (!($schema[$table]->disable_auditLog ?? false)) {
                $entityClass = 'App\Models\\'.Str::studly(Str::singular($table));
                $record = $this->show($request, $table, $insertedId);
                AuditLogService::insertAuditLog(AuditLogEventEnum::CREATED, $entityClass, json_decode(json_encode($record->getData()->data), true));
            }

            // Commit transaction
            DB::commit();
        } catch (\Exception $exception) {
            // Rollback transaction on any error
            DB::rollBack();

            // Log the error for debugging
            Log::error('Store operation failed', [
                'table' => $table,
                'payload' => $payloadMain,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return $this->error('Failed to create record: '.$exception->getMessage(), 500);
        }

        return $record;
    }

    public function update(Request $request, string $table, $id): JsonResponse
    {
        $this->authorizeAction($table, 'update');
        $schema = SchemaRegistry::get();
        if (!isset($schema[$table])) {
            return $this->error('Resource not available', 404);
        }

        // Resolve actual table name from RecordTableType configuration
        $actualTableName = $this->resolveActualTableName($table);

        $payload = $request->all();

        // Strip relationship data from main payload
        $payloadMain = RelationshipResolver::stripRelationshipData($table, $payload);
        $payloadMain = $this->sanitizePayload($payloadMain, $schema[$table], true, $table);

        $tenantId = $request->attributes->get('tenant_id');
        $pk = $schema[$table]->primary_key ?? 'id';

        // Apply timestamps and audit fields
        $payloadMain = $this->applyTimestampsAndAuditFields($payloadMain, $schema[$table], true);

        $updated = 0;
        $record = null;

        // Begin transaction
        DB::beginTransaction();

        try {
            $query = DB::table($actualTableName)->where($pk, $id);

            // Apply tenant filtering using optimized method
            $this->applyTenantFilter($query, $actualTableName, $tenantId);

            if ($schema[$table]->soft_deletes) {
                $query->whereNull($actualTableName.'.deleted_at');
            }

            $updated = $query->update($payloadMain);

            if (0 === $updated) {
                DB::rollBack();

                return $this->error('Not found or no changes', 404);
            }

            // Invalidate cache for this table
            QueryCacheService::invalidateTable($table);

            // Audit log for update (if not disabled)
            if (!($schema[$table]->disable_auditLog ?? false)) {
                $entityClass = 'App\Models\\'.Str::studly(Str::singular($table));
                $record = $this->show($request, $table, $id);
                AuditLogService::insertAuditLog(AuditLogEventEnum::UPDATED, $entityClass, json_decode(json_encode($record->getData()->data), true));
            }

            // Commit transaction
            DB::commit();
        } catch (\Exception $exception) {
            // Rollback transaction on any error
            DB::rollBack();

            // Log the error for debugging
            Log::error('Update operation failed', [
                'table' => $table,
                'id' => $id,
                'payload' => $payloadMain,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return $this->error('Failed to update record: '.$exception->getMessage(), 500);
        }

        return $record;
    }

    public function destroy(Request $request, string $table, $id): JsonResponse
    {
        $this->authorizeAction($table, 'delete');
        $schema = SchemaRegistry::get();
        if (!isset($schema[$table])) {
            return $this->error('Resource not available', 404);
        }

        // Resolve actual table name from RecordTableType configuration
        $actualTableName = $this->resolveActualTableName($table);

        $tenantId = $request->attributes->get('tenant_id');
        $pk = $schema[$table]->primary_key ?? 'id';

        $affected = 0;

        // Begin transaction
        DB::beginTransaction();

        try {
            $query = DB::table($actualTableName)->where($pk, $id);

            // Apply tenant filtering using optimized method
            $this->applyTenantFilter($query, $actualTableName, $tenantId);

            $affected = $schema[$table]->soft_deletes ? $query->update(['deleted_at' => now()]) : $query->delete();

            if (0 === $affected) {
                DB::rollBack();

                return $this->error('Not found', 404);
            }

            // Invalidate cache for this table
            QueryCacheService::invalidateTable($table);

            // Audit log for deletion (soft or hard)
            $entityClass = 'App\Models\\'.Str::studly(Str::singular($table));
            AuditLogService::insertAuditLog(AuditLogEventEnum::DELETED, $entityClass, ['id' => $id]);

            // Commit transaction
            DB::commit();
        } catch (\Exception $exception) {
            // Rollback transaction on any error
            DB::rollBack();

            // Log the error for debugging
            Log::error('Delete operation failed', [
                'table' => $table,
                'id' => $id,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return $this->error('Failed to delete record: '.$exception->getMessage(), 500);
        }

        return $this->success(['deleted' => $affected]);
    }

    public function restore(Request $request, string $table, $id): JsonResponse
    {
        $this->authorizeAction($table, 'restore');
        $schema = SchemaRegistry::get();
        if (!isset($schema[$table]) || !$schema[$table]->soft_deletes) {
            return $this->error('Resource not restorable', 400);
        }

        // Resolve actual table name from RecordTableType configuration
        $actualTableName = $this->resolveActualTableName($table);

        $tenantId = $request->attributes->get('tenant_id');
        $pk = $schema[$table]->primary_key ?? 'id';

        $affected = 0;
        $post = null;

        // Begin transaction
        DB::beginTransaction();

        try {
            $query = DB::table($actualTableName)->where($pk, $id);
            if ($tenantId && ($schema[$table]->has_tenant_id ?? false)) {
                $query->where('tenant_id', $tenantId);
            }

            $affected = $query->update(['deleted_at' => null]);

            if (0 === $affected) {
                DB::rollBack();

                return $this->error('Not found', 404);
            }

            // Invalidate cache for this table
            QueryCacheService::invalidateTable($table);

            // Audit log for restore as an update event (if not disabled)
            if (!($schema[$table]->disable_auditLog ?? false)) {
                $entityClass = 'App\Models\\'.Str::studly(Str::singular($table));
                $query = DB::table($actualTableName)->where($pk, $id);
                if ($tenantId && ($schema[$table]->has_tenant_id ?? false)) {
                    $query->where('tenant_id', $tenantId);
                }

                $record = $this->show($request, $table, $id);
                AuditLogService::insertAuditLog(AuditLogEventEnum::UPDATED, $entityClass, json_decode(json_encode($record->getData()->data), true));
            }

            // Commit transaction
            DB::commit();
        } catch (\Exception $exception) {
            // Rollback transaction on any error
            DB::rollBack();

            // Log the error for debugging
            Log::error('Restore operation failed', [
                'table' => $table,
                'id' => $id,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return $this->error('Failed to restore record: '.$exception->getMessage(), 500);
        }

        return $this->success(['restored' => $affected]);
    }

    public function forceDelete(Request $request, string $table, $id): JsonResponse
    {
        $this->authorizeAction($table, 'force_delete');
        $schema = SchemaRegistry::get();
        if (!isset($schema[$table])) {
            return $this->error('Resource not available', 404);
        }

        // Resolve actual table name from RecordTableType configuration
        $actualTableName = $this->resolveActualTableName($table);

        $tenantId = $request->attributes->get('tenant_id');
        $pk = $schema[$table]->primary_key ?? 'id';

        $deleted = 0;

        // Begin transaction
        DB::beginTransaction();

        try {
            $query = DB::table($actualTableName)->where($pk, $id);
            if ($tenantId && ($schema[$table]->has_tenant_id ?? false)) {
                $query->where('tenant_id', $tenantId);
            }

            $deleted = $query->delete();

            if (0 === $deleted) {
                DB::rollBack();

                return $this->error('Not found', 404);
            }

            // Audit log for deletion (if not disabled)
            if (!($schema[$table]->disable_auditLog ?? false)) {
                $entityClass = 'App\Models\\'.Str::studly(Str::singular($table));
                AuditLogService::insertAuditLog(AuditLogEventEnum::DELETED, $entityClass, ['id' => $id]);
            }

            // Commit transaction
            DB::commit();
        } catch (\Exception $exception) {
            // Rollback transaction on any error
            DB::rollBack();

            // Log the error for debugging
            Log::error('Force delete operation failed', [
                'table' => $table,
                'id' => $id,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return $this->error('Failed to force delete record: '.$exception->getMessage(), 500);
        }

        return $this->success(['deleted' => $deleted]);
    }

    public function bulk(Request $request, string $table): JsonResponse
    {
        // Check all permissions for mixed operations
        $schema = SchemaRegistry::get();

        // Resolve actual table name from RecordTableType configuration
        $actualTableName = $this->resolveActualTableName($table);

        $this->authorizeAction($actualTableName, 'create');
        $this->authorizeAction($actualTableName, 'update');
        $this->authorizeAction($actualTableName, 'delete');
        if (!isset($schema[$table])) {
            return $this->error('Resource not available', 404);
        }

        // Support both direct array and items wrapper for backward compatibility
        $requestData = $request->all();
        if (isset($requestData['items']) && is_array($requestData['items'])) {
            $items = $requestData['items'];
        } else {
            // Check if request is a direct array (JSON array sent directly)
            $jsonInput = $request->getContent();
            $decodedJson = json_decode($jsonInput, true);

            if (JSON_ERROR_NONE === json_last_error() && is_array($decodedJson) && [] !== $decodedJson) {
                // Check if it's an indexed array (direct bulk data)
                if (array_keys($decodedJson) === range(0, count($decodedJson) - 1)) {
                    $items = $decodedJson;
                } else {
                    // Single object, wrap in array
                    $items = [$decodedJson];
                }
            } else {
                $items = $request->input('items', []);
            }
        }

        if (!is_array($items) || [] === $items) {
            return $this->error('Data array required', 422);
        }

        $maxBatch = (int) config('record.bulk_max', 100);
        if (count($items) > $maxBatch) {
            return $this->error("Batch too large, max {$maxBatch}", 413);
        }

        $tenantId = $request->attributes->get('tenant_id');
        $pk = $schema[$table]->primary_key ?? 'id';

        $affected = 0;
        $createdData = [];
        $updatedData = [];
        $deletedData = [];
        $upsertedData = [];

        // Begin transaction
        DB::beginTransaction();

        try {
            foreach ($items as $item) {
                // Determine operation type based on data structure
                $operation = $this->determineOperation($item, $pk, null);

                if ('create' === $operation) {
                    // CREATE: No ID present, create new record
                    $item = $this->sanitizePayload($item, $schema[$table], false, $table);
                    if ($tenantId && ($schema[$table]->has_tenant_id ?? false)) {
                        $item['tenant_id'] = $tenantId;
                    }

                    // Apply timestamps and audit fields
                    $item = $this->applyTimestampsAndAuditFields($item, $schema[$table], false);

                    $insertId = DB::table($actualTableName)->insertGetId($item);
                    // Get created record using show method
                    $createdRecord = $this->show($request, $table, $insertId);
                    $recordData = json_decode(json_encode($createdRecord->getData()->data), true);
                    $createdData[] = $recordData;
                    ++$affected;
                    // Audit log for insert with complete record data (if not disabled)
                    if (!($schema[$table]->disable_auditLog ?? false)) {
                        $entityClass = 'App\Models\\'.Str::studly(Str::singular($table));
                        AuditLogService::insertAuditLog(AuditLogEventEnum::CREATED, $entityClass, $recordData);
                    }
                } elseif ('update' === $operation) {
                    // UPDATE: ID present with additional data
                    if (!isset($item[$pk])) {
                        throw ValidationException::withMessages(["{$pk}" => 'Primary key required for update']);
                    }

                    $id = $item[$pk];
                    unset($item[$pk]);
                    $item = $this->sanitizePayload($item, $schema[$table], true, $table);
                    if ($tenantId && ($schema[$table]->has_tenant_id ?? false)) {
                        $item['tenant_id'] = $tenantId;
                    }

                    // Apply timestamps and audit fields
                    $item = $this->applyTimestampsAndAuditFields($item, $schema[$table], true);

                    $q = DB::table($actualTableName)->where($pk, $id);

                    // Apply tenant filtering using optimized method
                    $this->applyTenantFilter($q, $actualTableName, $tenantId);
                    $updateCount = $q->update($item);
                    if ($updateCount > 0) {
                        // Get updated record using show method
                        $updatedRecord = $this->show($request, $table, $id);
                        $recordData = json_decode(json_encode($updatedRecord->getData()->data), true);
                        $updatedData[] = $recordData;
                        $affected += $updateCount;
                        // Audit log per updated row with complete record data (if not disabled)
                        if (!($schema[$table]->disable_auditLog ?? false)) {
                            $entityClass = 'App\Models\\'.Str::studly(Str::singular($table));
                            AuditLogService::insertAuditLog(AuditLogEventEnum::UPDATED, $entityClass, $recordData);
                        }
                    }
                } elseif ('delete' === $operation) {
                    // DELETE: Only ID present
                    if (!isset($item[$pk])) {
                        throw ValidationException::withMessages(["{$pk}" => 'Primary key required for delete']);
                    }

                    $q = DB::table($actualTableName)->where($pk, $item[$pk]);

                    // Apply tenant filtering using optimized method
                    $this->applyTenantFilter($q, $actualTableName, $tenantId);
                    $deleteCount = $schema[$table]->soft_deletes ? $q->update(['deleted_at' => now()]) : $q->delete();

                    if ($deleteCount > 0) {
                        $deletedData[] = ['id' => $item[$pk]];
                        $affected += $deleteCount;
                        // Audit log per deleted row (if not disabled)
                        if (!($schema[$table]->disable_auditLog ?? false)) {
                            $entityClass = 'App\Models\\'.Str::studly(Str::singular($table));
                            AuditLogService::insertAuditLog(AuditLogEventEnum::DELETED, $entityClass, ['id' => $item[$pk]]);
                        }
                    }
                } elseif ('upsert' === $operation) {
                    // UPSERT: Legacy action support
                    $item = $this->sanitizePayload($item, $schema[$table], true, $table);
                    if ($tenantId && ($schema[$table]->has_tenant_id ?? false)) {
                        $item['tenant_id'] = $tenantId;
                    }

                    // Apply timestamps and audit fields
                    $item = $this->applyTimestampsAndAuditFields($item, $schema[$table], true);

                    // Upsert requires update columns; exclude primary key and system timestamps
                    $updateColumns = array_values(array_diff(array_keys($item), [$pk, 'id', 'created_at', 'deleted_at']));
                    DB::table($actualTableName)->upsert([$item], [$pk], $updateColumns);
                    // Get upserted record using show method
                    $upsertedRecord = $this->show($request, $table, $item[$pk]);
                    $recordData = json_decode(json_encode($upsertedRecord->getData()->data), true);
                    $upsertedData[] = $recordData;
                    ++$affected;
                    // Audit log for upsert with complete record data (if not disabled)
                    if (!($schema[$table]->disable_auditLog ?? false)) {
                        $entityClass = 'App\Models\\'.Str::studly(Str::singular($table));
                        AuditLogService::insertAuditLog(AuditLogEventEnum::UPDATED, $entityClass, $recordData);
                    }
                }
            }

            // Commit transaction
            DB::commit();
        } catch (\Exception $exception) {
            // Rollback transaction on any error
            DB::rollBack();

            // Log the error for debugging
            Log::error('Bulk operation failed', [
                'table' => $table,
                'action' => 'mixed',
                'items_count' => count($items),
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return $this->error('Failed to perform bulk operation: '.$exception->getMessage(), 500);
        }

        // Consolidate created and updated records into a single data array
        $consolidatedData = [];

        // Add created records
        if ([] !== $createdData) {
            $consolidatedData = array_merge($consolidatedData, $createdData);
        }

        // Add updated records
        if ([] !== $updatedData) {
            $consolidatedData = array_merge($consolidatedData, $updatedData);
        }

        // Add upserted records
        if ([] !== $upsertedData) {
            $consolidatedData = array_merge($consolidatedData, $upsertedData);
        }

        // Note: Deleted records are intentionally excluded from the consolidated data

        // Calculate total affected records (excluding deletes for the data array)
        $activeRecordsCount = count($createdData) + count($updatedData) + count($upsertedData);

        return $this->success(data: $consolidatedData, meta: ['affected' => $activeRecordsCount]);
    }

    /**
     * Execute a table-specific custom function.
     */
    public function executeTableFunction(Request $request, string $table, string $functionName): JsonResponse
    {
        try {
            // Get schema and validate table exists
            $schema = SchemaRegistry::get();
            if (!isset($schema[$table])) {
                return response()->json([
                    'error' => 'Table not found',
                    'message' => "Table '{$table}' does not exist",
                ], 404);
            }

            // Check if function exists in table schema
            $tableFunctions = $schema[$table]->functions ?? [];
            $functionConfig = null;
            $extractedId = null;

            // First try exact match
            if (isset($tableFunctions[$functionName])) {
                $functionConfig = $tableFunctions[$functionName];
            } else {
                // Try pattern matching for parameterized function names
                foreach ($tableFunctions as $configuredFunctionName => $config) {
                    // Convert function name pattern to regex (e.g., 'role_permission/{id}' -> 'role_permission/(\d+)')
                    $pattern = preg_replace('/\{[^}]+\}/', '(\d+)', $configuredFunctionName);
                    $pattern = '/^'.str_replace('/', '\/', $pattern).'$/';

                    if (preg_match($pattern, $functionName, $matches)) {
                        $functionConfig = $config;

                        // Extract ID parameter if present (first captured group)
                        if (isset($matches[1])) {
                            $extractedId = $matches[1];
                        }

                        break;
                    }
                }
            }

            if (!$functionConfig) {
                return response()->json([
                    'error' => 'Function not found',
                    'message' => "Function '{$functionName}' not found for table '{$table}'",
                ], 404);
            }

            // Check permission using table function's pms_name
            // Resolve actual table name from RecordTableType configuration
            $actualTableName = $this->resolveActualTableName($table);
            if (isset($actualTableName)) {
                $this->authorizeAction($actualTableName, 'read');
            }

            // Execute the custom function with extracted ID parameter
            return $this->executeCustomFunction($request, $functionConfig, $extractedId);
        } catch (\Exception $exception) {
            Log::error('Table function execution failed', [
                'table' => $table,
                'function' => $functionName,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Function execution failed',
                'message' => $exception->getMessage(),
            ], 500);
        }
    }

    /**
     * Execute a global custom function.
     * Supports patterns like: function_name or function_name/{id}.
     */
    public function executeGlobalFunction(Request $request, string $functionName): JsonResponse
    {
        try {
            // Check if function exists in table schema
            $globalFunctions = config('record.global_functions', []);
            $functionConfig = null;
            $extractedId = null;

            // First try exact match
            if (isset($globalFunctions[$functionName])) {
                $functionConfig = $globalFunctions[$functionName];
            } else {
                // Try pattern matching for parameterized function names
                foreach ($globalFunctions as $configuredFunctionName => $config) {
                    // Convert function name pattern to regex (e.g., 'role_permission/{id}' -> 'role_permission/(\d+)')
                    $pattern = preg_replace('/\{[^}]+\}/', '(\d+)', (string) $configuredFunctionName);
                    $pattern = '/^'.str_replace('/', '\/', $pattern).'$/';

                    if (preg_match($pattern, $functionName, $matches)) {
                        $functionConfig = $config;

                        // Extract ID parameter if present (first captured group)
                        if (isset($matches[1])) {
                            $extractedId = $matches[1];
                        }

                        break;
                    }
                }
            }

            if (!$functionConfig) {
                return response()->json([
                    'error' => 'Function not found',
                    'message' => "Function '{$functionName}' not found",
                ], 404);
            }

            // Execute the custom function with extracted ID parameter
            return $this->executeCustomFunction($request, $functionConfig, $extractedId);
        } catch (\Exception $exception) {
            Log::error('Table function execution failed', [
                'function' => $functionName,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Function execution failed',
                'message' => $exception->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk create records with proper validation.
     *
     * @param Request $request HTTP request with array of records to create
     * @param string  $table   Target table name
     *
     * @return JsonResponse Response with created records
     */
    public function bulkCreate(Request $request, string $table): JsonResponse
    {
        $this->authorizeAction($table, 'create');
        $schema = SchemaRegistry::get();
        if (!isset($schema[$table])) {
            return $this->error('Resource not available', 404);
        }

        // Resolve actual table name from RecordTableType configuration
        $actualTableName = $this->resolveActualTableName($table);

        // Validate request structure - expect direct array payload
        $payload = $request->all();

        // Handle both direct array and single object
        if (!is_array($payload) || (is_array($payload) && !array_key_exists(0, $payload) && !empty($payload))) {
            // Single object, wrap in array
            $items = [$payload];
        } else {
            // Direct array
            $items = $payload;
        }

        $validator = Validator::make(['items' => $items], [
            'items' => 'required|array|min:1|max:'.config('record.bulk_max', 100),
            'items.*' => 'required|array',
        ], [
            'items.required' => 'Payload must be an array',
            'items.array' => 'Payload must be an array',
            'items.min' => 'At least one item is required',
            'items.max' => 'Maximum '.config('record.bulk_max', 100).' items allowed',
            'items.*.required' => 'Each item is required',
            'items.*.array' => 'Each item must be an object',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $tenantId = $request->attributes->get('tenant_id');
        $pk = $schema[$table]->primary_key ?? 'id';

        $createdData = [];
        $affected = 0;

        DB::beginTransaction();

        try {
            foreach ($items as $index => $item) {
                // Validate that no ID is provided for create operation
                if (isset($item[$pk])) {
                    throw ValidationException::withMessages([
                        "items.{$index}.{$pk}" => 'Primary key should not be provided for create operation',
                    ]);
                }

                // Sanitize and prepare payload
                $item = $this->sanitizePayload($item, $schema[$table], false, $table);

                if ($tenantId && ($schema[$table]->has_tenant_id ?? false)) {
                    $item['tenant_id'] = $tenantId;
                }

                // Apply timestamps and audit fields
                $item = $this->applyTimestampsAndAuditFields($item, $schema[$table], false);

                $insertId = DB::table($actualTableName)->insertGetId($item);

                // Get created record using show method
                $createdRecord = $this->show($request, $table, $insertId);
                $recordData = json_decode(json_encode($createdRecord->getData()->data), true);
                $createdData[] = $recordData;
                ++$affected;

                // Audit log for insert with complete record data (if not disabled)
                if (!($schema[$table]->disable_auditLog ?? false)) {
                    $entityClass = 'App\Models\\'.Str::studly(Str::singular($table));
                    AuditLogService::insertAuditLog(AuditLogEventEnum::CREATED, $entityClass, $recordData);
                }
            }

            DB::commit();

            return $this->success($createdData, ['affected' => $affected]);
        } catch (\Exception $exception) {
            DB::rollBack();

            Log::error('Bulk create operation failed', [
                'table' => $table,
                'items_count' => count($items),
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            if ($exception instanceof ValidationException) {
                return $this->error('Validation failed', 422, $exception->errors());
            }

            return $this->error('Failed to perform bulk create operation: '.$exception->getMessage(), 500);
        }
    }

    /**
     * Bulk update records with proper validation.
     *
     * @param Request $request HTTP request with array of records to update
     * @param string  $table   Target table name
     *
     * @return JsonResponse Response with updated records
     */
    public function bulkUpdate(Request $request, string $table): JsonResponse
    {
        $this->authorizeAction($table, 'update');
        $schema = SchemaRegistry::get();
        if (!isset($schema[$table])) {
            return $this->error('Resource not available', 404);
        }

        // Resolve actual table name from RecordTableType configuration
        $actualTableName = $this->resolveActualTableName($table);
        $pk = $schema[$table]->primary_key ?? 'id';

        // Validate request structure - expect direct array payload
        $payload = $request->all();

        // Handle both direct array and single object
        if (!is_array($payload) || (is_array($payload) && !array_key_exists(0, $payload) && !empty($payload))) {
            // Single object, wrap in array
            $items = [$payload];
        } else {
            // Direct array
            $items = $payload;
        }

        $validator = Validator::make(['items' => $items], [
            'items' => 'required|array|min:1|max:'.config('record.bulk_max', 100),
            'items.*' => 'required|array',
            "items.*.{$pk}" => 'required',
        ], [
            'items.required' => 'Payload must be an array',
            'items.array' => 'Payload must be an array',
            'items.min' => 'At least one item is required',
            'items.max' => 'Maximum '.config('record.bulk_max', 100).' items allowed',
            'items.*.required' => 'Each item is required',
            'items.*.array' => 'Each item must be an object',
            "items.*.{$pk}.required" => "Primary key ({$pk}) is required for update operation",
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $tenantId = $request->attributes->get('tenant_id');

        $updatedData = [];
        $affected = 0;

        DB::beginTransaction();

        try {
            foreach ($items as $index => $item) {
                // Validate that ID is provided and other fields exist
                if (!isset($item[$pk])) {
                    throw ValidationException::withMessages([
                        "items.{$index}.{$pk}" => "Primary key ({$pk}) is required for update operation",
                    ]);
                }

                // Check if there are fields to update besides the primary key
                $updateFields = array_diff_key($item, [$pk => true]);
                if (empty($updateFields)) {
                    throw ValidationException::withMessages([
                        "items.{$index}" => 'At least one field besides the primary key must be provided for update',
                    ]);
                }

                $id = $item[$pk];
                unset($item[$pk]);

                // Sanitize and prepare payload
                $item = $this->sanitizePayload($item, $schema[$table], true, $table);

                if ($tenantId && ($schema[$table]->has_tenant_id ?? false)) {
                    $item['tenant_id'] = $tenantId;
                }

                // Apply timestamps and audit fields
                $item = $this->applyTimestampsAndAuditFields($item, $schema[$table], true);

                $q = DB::table($actualTableName)->where($pk, $id);

                // Apply tenant filtering using optimized method
                $this->applyTenantFilter($q, $actualTableName, $tenantId);

                $updateCount = $q->update($item);

                if ($updateCount > 0) {
                    // Get updated record using show method
                    $updatedRecord = $this->show($request, $table, $id);
                    $recordData = json_decode(json_encode($updatedRecord->getData()->data), true);
                    $updatedData[] = $recordData;
                    $affected += $updateCount;

                    // Audit log per updated row with complete record data (if not disabled)
                    if (!($schema[$table]->disable_auditLog ?? false)) {
                        $entityClass = 'App\Models\\'.Str::studly(Str::singular($table));
                        AuditLogService::insertAuditLog(AuditLogEventEnum::UPDATED, $entityClass, $recordData);
                    }
                } else {
                    // Record not found or no changes made
                    throw ValidationException::withMessages([
                        "items.{$index}.{$pk}" => "Record with {$pk} '{$id}' not found or no changes detected",
                    ]);
                }
            }

            DB::commit();

            return $this->success($updatedData, ['affected' => $affected]);
        } catch (\Exception $exception) {
            DB::rollBack();

            Log::error('Bulk update operation failed', [
                'table' => $table,
                'items_count' => count($items),
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            if ($exception instanceof ValidationException) {
                return $this->error('Validation failed', 422, $exception->errors());
            }

            return $this->error('Failed to perform bulk update operation: '.$exception->getMessage(), 500);
        }
    }

    /**
     * Bulk delete records with proper validation.
     *
     * @param Request $request HTTP request with array of IDs to delete
     * @param string  $table   Target table name
     *
     * @return JsonResponse Response with deletion results
     */
    public function bulkDelete(Request $request, string $table): JsonResponse
    {
        $this->authorizeAction($table, 'delete');
        $schema = SchemaRegistry::get();
        if (!isset($schema[$table])) {
            return $this->error('Resource not available', 404);
        }

        // Resolve actual table name from RecordTableType configuration
        $actualTableName = $this->resolveActualTableName($table);
        $pk = $schema[$table]->primary_key ?? 'id';

        // Validate request structure - expect direct array payload
        $payload = $request->all();

        // Handle both direct array and single object
        if (!is_array($payload) || (is_array($payload) && !array_key_exists(0, $payload) && !empty($payload))) {
            // Single object, wrap in array
            $items = [$payload];
        } else {
            // Direct array
            $items = $payload;
        }

        $validator = Validator::make(['items' => $items], [
            'items' => 'required|array|min:1|max:'.config('record.bulk_max', 100),
        ], [
            'items.required' => 'Payload must be an array',
            'items.array' => 'Payload must be an array',
            'items.min' => 'At least one item is required',
            'items.max' => 'Maximum '.config('record.bulk_max', 100).' items allowed',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $tenantId = $request->attributes->get('tenant_id');

        // Normalize items to extract IDs
        $idsToDelete = [];
        foreach ($items as $index => $item) {
            if (is_array($item)) {
                // Object format: {"id": 123}
                if (!isset($item[$pk])) {
                    throw ValidationException::withMessages([
                        "items.{$index}.{$pk}" => "Primary key ({$pk}) is required for delete operation",
                    ]);
                }

                $idsToDelete[] = $item[$pk];
            } else {
                // Direct ID format: [123, 456, 789]
                if (empty($item)) {
                    throw ValidationException::withMessages([
                        "items.{$index}" => 'ID value cannot be empty',
                    ]);
                }

                $idsToDelete[] = $item;
            }
        }

        // Remove duplicates
        $idsToDelete = array_unique($idsToDelete);

        $deletedData = [];
        $affected = 0;

        DB::beginTransaction();

        try {
            foreach ($idsToDelete as $id) {
                // Get record data before deletion for audit log
                $recordToDelete = null;
                if (!($schema[$table]->disable_auditLog ?? false)) {
                    $recordResponse = $this->show($request, $table, $id);
                    if (200 === $recordResponse->getStatusCode()) {
                        $recordToDelete = json_decode(json_encode($recordResponse->getData()->data), true);
                    }
                }

                $q = DB::table($actualTableName)->where($pk, $id);

                // Apply tenant filtering using optimized method
                $this->applyTenantFilter($q, $actualTableName, $tenantId);

                $deleteCount = $schema[$table]->soft_deletes
                    ? $q->update(['deleted_at' => now()])
                    : $q->delete();

                if ($deleteCount > 0) {
                    $deletedData[] = [$pk => $id];
                    $affected += $deleteCount;

                    // Audit log per deleted row (if not disabled)
                    if (!($schema[$table]->disable_auditLog ?? false) && $recordToDelete) {
                        $entityClass = 'App\Models\\'.Str::studly(Str::singular($table));
                        AuditLogService::insertAuditLog(AuditLogEventEnum::DELETED, $entityClass, $recordToDelete);
                    }
                }
            }

            DB::commit();

            return $this->success($deletedData, ['affected' => $affected]);
        } catch (\Exception $exception) {
            DB::rollBack();

            Log::error('Bulk delete operation failed', [
                'table' => $table,
                'items_count' => count($idsToDelete),
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            if ($exception instanceof ValidationException) {
                return $this->error('Validation failed', 422, $exception->errors());
            }

            return $this->error('Failed to perform bulk delete operation: '.$exception->getMessage(), 500);
        }
    }

    /**
     * Apply timestamps and audit fields to payload.
     *
     * @param array  $payload     The data payload
     * @param object $tableSchema The table schema
     * @param bool   $isUpdate    Whether this is an update operation
     *
     * @return array Modified payload with timestamps and audit fields
     */
    private function applyTimestampsAndAuditFields(array $payload, object $tableSchema, bool $isUpdate = false): array
    {
        $user = auth('api')->user();

        if ($isUpdate) {
            // Auto-add updated_at timestamp
            $payload['updated_at'] = now();

            // Auto-assign updated_by or last_updated_by if column exists and user is authenticated
            if ($user) {
                if (isset($tableSchema->columns['updated_by'])) {
                    $payload['updated_by'] = $user->id;
                } elseif (isset($tableSchema->columns['last_updated_by'])) {
                    $payload['last_updated_by'] = $user->id;
                }
            }
        } else {
            // Auto-add created_at and updated_at timestamps
            $payload['created_at'] = now();
            $payload['updated_at'] = now();

            // Auto-assign created_by if column exists and user is authenticated
            if ($user && isset($tableSchema->columns['created_by'])) {
                $payload['created_by'] = $user->id;
            }
        }

        return $payload;
    }

    /**
     * Resolve the actual table name from schema configuration.
     */
    private function resolveActualTableName(string $table): string
    {
        $schema = SchemaRegistry::get();

        return $schema[$table]->table ?? $table;
    }

    /**
     * Get cached schema or fetch if not cached.
     * Uses optimized validation configuration for better performance.
     */
    private function getCachedSchema(): array
    {
        if ([] === self::$schemaCache) {
            self::$schemaCache = SchemaRegistry::get();
        }

        return self::$schemaCache;
    }

    /**
     * Check if tenant_id functionality is enabled.
     */
    private function isTenantIdEnabled(): bool
    {
        if (null === self::$tenantIdEnabled) {
            self::$tenantIdEnabled = config('record.enable_tenant_id', false);
        }

        return self::$tenantIdEnabled;
    }

    /**
     * Apply tenant_id filtering if enabled and available.
     *
     * This method conditionally applies tenant-based filtering to queries
     * based on the 'enable_tenant_id' configuration setting. It provides
     * secure multi-tenant data isolation when enabled.
     *
     * Security Features:
     * - Only applies filtering when tenant_id is enabled in configuration
     * - Validates tenant_id column exists in table schema before filtering
     * - Prevents cross-tenant data access in multi-tenant environments
     *
     * Performance Considerations:
     * - Uses cached schema to avoid repeated database queries
     * - Applies filtering at query level for optimal performance
     * - Integrates with existing query optimizations
     *
     * @param mixed  $query    Laravel query builder instance
     * @param string $table    Target table name for schema validation
     * @param mixed  $tenantId Tenant identifier for filtering
     */
    private function applyTenantFilter($query, string $table, $tenantId): void
    {
        if ($this->isTenantIdEnabled() && $tenantId) {
            $schema = $this->getCachedSchema();
            if ($schema[$table]->has_tenant_id ?? false) {
                $query->where($table.'.tenant_id', $tenantId);
            }
        }
    }

    /**
     * Determine the operation type based on data structure.
     */
    private function determineOperation(array $row, string $pk, ?string $legacyAction): string
    {
        // If legacy action is provided, use it
        if (null !== $legacyAction && '' !== $legacyAction && '0' !== $legacyAction) {
            return $legacyAction;
        }

        // Auto-detect operation based on data structure
        $hasId = isset($row[$pk]) && !empty($row[$pk]);
        $hasOtherFields = [] !== array_diff_key($row, [$pk => true]);

        if (!$hasId) {
            // No ID present = CREATE
            return 'create';
        }

        if ($hasId && $hasOtherFields) {
            // ID + other fields = UPDATE
            return 'update';
        }

        if ($hasId && !$hasOtherFields) {
            // Only ID present = DELETE
            return 'delete';
        }

        // Default fallback
        return 'update';
    }

    private function authorizeAction(string $table, string $action): void
    {
        // Allow unauthenticated access for configured tables/actions (per-table config)
        if (PermissionHelper::isPublicAction($table, $action)) {
            return;
        }

        $user = auth('api')->user();
        if (!$user) {
            abort(401, 'Unauthenticated');
        }

        $perm = PermissionHelper::mapPermission($table, $action);

        if (!$user->can($perm)) {
            abort(403, 'Forbidden');
        }
    }

    private function sanitizePayload(array $input, $meta, bool $isUpdate = false, string $table = ''): array
    {
        $columns = array_keys($meta->columns ?? []);
        // Only allow known columns; prevent mass assignment to meta/system columns
        $payload = array_intersect_key($input, array_flip($columns));

        // Never allow setting these explicitly
        unset($payload['id'], $payload['deleted_at'], $payload['created_at'], $payload['updated_at']);

        return $payload;
    }

    private function success($data, array $meta = [], int $status = 200, array $headers = []): JsonResponse
    {
        $requestId = request()->attributes->get('request_id');
        $meta = array_merge(['request_id' => $requestId], $meta);

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => $meta,
        ], $status, $headers);
    }

    private function error(string $message, int $status = 400, array $errors = []): JsonResponse
    {
        $requestId = request()->attributes->get('request_id');

        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
            'meta' => [
                'request_id' => $requestId,
            ],
        ], $status);
    }

    private function isCacheableRequest(Request $request, string $table): bool
    {
        // Check if caching is globally enabled
        if (!config('record.cache.enabled', true)) {
            return false;
        }

        // Check per-table cache settings (overrides global setting)
        $perTableCache = config('record.cache.per_table', []);
        if (isset($perTableCache[$table]) && !$perTableCache[$table]) {
            return false;
        }

        // Only cache GET requests without complex filters
        if ('GET' !== $request->method()) {
            return false;
        }

        // Don't cache if user-specific data or complex queries
        return !$request->has(['search', 'filter', 'where']);
    }

    /**
     * Generate optimized cache key with better collision resistance.
     */
    private function generateOptimizedCacheKey(string $table, array $filters, array $includes, int $page, int $limit): string
    {
        $keyData = [
            'table' => $table,
            'filters' => $filters,
            'includes' => $includes,
            'page' => $page,
            'limit' => $limit,
            'tenant_enabled' => $this->isTenantIdEnabled(),
        ];

        return 'record_index_'.md5(serialize($keyData));
    }

    /**
     * Generate cache key for single record.
     *
     * @param mixed $id
     * @param mixed $tenantId
     * @param mixed $select
     */
    private function generateRecordCacheKey(string $table, $id, $tenantId, $select): string
    {
        $keyData = [
            'table' => $table,
            'id' => $id,
            'tenant_id' => $this->isTenantIdEnabled() ? $tenantId : null,
            'select' => $select,
            'tenant_enabled' => $this->isTenantIdEnabled(),
        ];

        return 'record_show_'.md5(serialize($keyData));
    }

    /**
     * Calculate optimal cache TTL based on data characteristics.
     */
    private function calculateOptimalCacheTTL(string $table, int $recordCount, bool $hasRelationships): int
    {
        $baseTTL = config('record.cache.default_ttl', 3600); // 1 hour default

        // Reduce TTL for large datasets
        if ($recordCount > 100) {
            $baseTTL = (int) ($baseTTL * 0.5);
        }

        // Reduce TTL for complex queries with relationships
        if ($hasRelationships) {
            $baseTTL = (int) ($baseTTL * 0.7);
        }

        // Per-table TTL overrides
        $perTableTTL = config('record.cache.per_table_ttl', []);
        if (isset($perTableTTL[$table])) {
            $baseTTL = $perTableTTL[$table];
        }

        return max($baseTTL, 300); // Minimum 5 minutes
    }

    /**
     * Recursively remove deleted_at fields from response data.
     *
     * @param mixed $data
     *
     * @return mixed
     */
    private function removeDeletedAtFields($data)
    {
        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                if ('deleted_at' !== $key) {
                    $result[$key] = $this->removeDeletedAtFields($value);
                }
            }

            return $result;
        }

        if (is_object($data)) {
            $result = new \stdClass();
            foreach ($data as $key => $value) {
                if ('deleted_at' !== $key) {
                    $result->{$key} = $this->removeDeletedAtFields($value);
                }
            }

            return $result;
        }

        return $data;
    }

    /**
     * Execute a custom function based on its configuration.
     */
    private function executeCustomFunction(Request $request, array|RecordFunctionType $functionConfig, mixed $id = null): JsonResponse
    {
        // Convert RecordFunctionType to array if needed
        if ($functionConfig instanceof RecordFunctionType) {
            $config = $functionConfig->toArray();
        } else {
            $config = $functionConfig;
        }

        // Check permissions if pms_name is specified
        if (isset($config['pms_name']) && !empty($config['pms_name'])) {
            $user = auth('api')->user();
            if (!$user) {
                return response()->json([
                    'error' => 'Unauthenticated',
                    'message' => 'Authentication required',
                ], 401);
            }

            // Handle both single permission (string) and multiple permissions (array)
            $permissions = is_array($config['pms_name']) ? $config['pms_name'] : [$config['pms_name']];
            $hasPermission = false;

            // Check if user has at least one of the required permissions
            foreach ($permissions as $permission) {
                if ($user->can($permission)) {
                    $hasPermission = true;

                    break;
                }
            }

            if (!$hasPermission) {
                return response()->json([
                    'error' => 'Forbidden',
                    'message' => 'Insufficient permissions',
                ], 403);
            }
        }

        // Validate HTTP method if specified
        if (isset($config['method'])) {
            $allowedMethods = is_array($config['method']) ? $config['method'] : [$config['method']];
            if (!in_array(strtoupper($request->method()), array_map('strtoupper', $allowedMethods))) {
                return response()->json([
                    'error' => 'Method not allowed',
                    'message' => "Method '{$request->method()}' not allowed for this function",
                ], 405);
            }
        }

        // Validate required parameters
        if (isset($config['required_params'])) {
            $missingParams = [];
            foreach ($config['required_params'] as $param) {
                if (!$request->has($param)) {
                    $missingParams[] = $param;
                }
            }

            if ([] !== $missingParams) {
                return response()->json([
                    'error' => 'Missing required parameters',
                    'message' => 'Missing parameters: '.implode(', ', $missingParams),
                ], 400);
            }
        }

        return $this->executeClassFunction($request, $config, $id);
    }

    /**
     * Execute a class-based custom function.
     */
    private function executeClassFunction(Request $request, array $functionConfig, mixed $id = null): JsonResponse
    {
        try {
            $className = $functionConfig['class'] ?? null;
            $method = $functionConfig['function_method'] ?? 'handle';

            if (!$className || !class_exists($className)) {
                return response()->json([
                    'error' => 'Function class not found',
                    'message' => "Class '{$className}' does not exist",
                ], 500);
            }

            $instance = new $className();
            if (!method_exists($instance, $method)) {
                return response()->json([
                    'error' => 'Function method not found',
                    'message' => "Method '{$method}' does not exist in class '{$className}'",
                ], 500);
            }

            $result = $id ? $instance->{$method}($request, $id) : $instance->{$method}($request);

            // If the result is already a Response instance, return it directly
            // @var JsonResponse
            if ($result instanceof JsonResponse) {
                $responseData = $result->getData();
                $statusCode = $result->getStatusCode();
                $meta = [];
                $records = null;

                if (empty($responseData->data)) {
                    $records = $responseData;
                } else {
                    $records = $responseData->data;
                    unset($meta->data);
                    $meta = json_decode(json_encode($meta), true);
                }

                if ($statusCode > 204) {
                    return $this->error('string' === gettype($records) ? $records : '', $statusCode, 'object' === gettype($responseData) ? (array) $responseData : []);
                }

                return $this->success($records, $meta, $result->getStatusCode());
            }

            return response()->json($result);
        } catch (\Exception $exception) {
            return response()->json([
                'error' => 'Function execution failed',
                'message' => $exception->getMessage(),
            ], 500);
        }
    }
}
