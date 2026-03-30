<?php

namespace Sopheak\Core\Services;

use Sopheak\Core\Events\RecordMutated;
use Exception;
use Sopheak\Core\Interfaces\RecordFunctionInterface;
use Throwable;
use BackedEnum;
use UnitEnum;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Sopheak\Core\Utilities\TimeUtils;
use Sopheak\Core\Enums\AuditLogEventEnum;
use Sopheak\Core\Enums\RecordApiJsonResponseEnum;
use Sopheak\Core\Utilities\QueryBuilderFiltersUtils;
use Sopheak\Core\Utilities\RelationshipResolverUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Sopheak\Core\Types\RecordFunctionType;
use Sopheak\Core\Types\RecordTableTriggerType;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\RecordPayloadExtractor;
use Sopheak\Core\Types\RecordBelongsToType;
use Sopheak\Core\Utilities\RecordUtils;

class RecordService
{
    private const REQUEST_CONTEXT_KEY = 'record_context';

    private const TENANT_ATTRIBUTE_KEY = 'resolved_tenant_id';

    private const TENANT_SOURCE_PRIORITY = ['attribute', 'header'];

    /**
     * Create a new record with all related processing.
     *
     * @return array Returns ['id' => mixed, 'payload' => array, 'tenant_id' => mixed]
     */
    public function createRecord(string $table, array $payload, mixed $tenantId): array
    {
        $tableSchema = SchemaRegistryUtils::getTable($table);

        $payloadMain = $this->buildCrudPayload($payload, $tableSchema, $tenantId, false);

        // Resolve actual table name
        $actualTableName = $tableSchema->table ?? $table;
        $pk = $tableSchema->primaryKey ?? 'id';

        // Insert record
        if (array_key_exists($pk, $payloadMain) && null !== $payloadMain[$pk]) {
            DB::table($actualTableName)->insert($payloadMain);
            $insertedId = $payloadMain[$pk];
        } else {
            $insertedId = DB::table($actualTableName)->insertGetId($payloadMain, $pk);
        }

        // Process nested relationships
        RelationshipResolverUtils::processRelatedData($table, $payload, $insertedId, $tenantId, 'create');

        $tenantColumn = RecordConfigService::tenantColumn();
        $tenantEnabled = $this->shouldApplyTenantId($tableSchema);
        $cacheTenantId = $tenantEnabled ? $this->normalizeTenantId($tenantId) : null;
        $this->invalidateTableCache($table, $cacheTenantId, $tenantEnabled);
        $this->invalidateRecordCache($table, $insertedId, $cacheTenantId, $tenantEnabled);

        return [
            'id' => $insertedId,
            'payload' => $payloadMain,
            $tenantColumn => $tenantId,
        ];
    }

    /**
     * Update an existing record with all related processing.
     *
     * @return array Returns ['id' => mixed, 'payload' => array, 'tenant_id' => mixed, 'updated' => int]
     */
    public function updateRecord(string $table, mixed $id, array $payload, mixed $tenantId): array
    {
        $tableSchema = SchemaRegistryUtils::getTable($table);

        $payloadMain = $this->buildCrudPayload($payload, $tableSchema, $tenantId, true);

        // Resolve actual table name
        $actualTableName = $tableSchema->table ?? $table;
        $pk = $tableSchema->primaryKey ?? 'id';

        $query = DB::table($actualTableName)->where($pk, $id);
        $this->applyTenantFilter($query, $table, $tenantId);

        // Check existence to differentiate between "no changes" and "not found"
        $exists = (clone $query)->exists();

        $updated = 0;
        if ($exists && [] !== $payloadMain) {
            $updated = $query->update($payloadMain);
        }

        // Process nested relationships
        if ($exists) {
            RelationshipResolverUtils::processRelatedData($table, $payload, $id, $tenantId, 'update');
        }

        $tenantColumn = RecordConfigService::tenantColumn();
        if ($exists) {
            $tenantEnabled = $this->shouldApplyTenantId($tableSchema);
            $cacheTenantId = $tenantEnabled ? $this->normalizeTenantId($tenantId) : null;
            $this->invalidateTableCache($table, $cacheTenantId, $tenantEnabled);
            $this->invalidateRecordCache($table, $id, $cacheTenantId, $tenantEnabled);
        }

        return [
            'id' => $id,
            'payload' => $payloadMain,
            $tenantColumn => $tenantId,
            'updated' => $updated,
            'exists' => $exists,
        ];
    }

    /**
     * Fetch a single raw record by ID without hooks, eager loading, or deleted_at filtering.
     * Works for soft-deleted rows — needed by delete/restore triggers to pass the full row.
     *
     * @return object|null The raw DB row, or null if not found
     */
    public function fetchRawRecord(string $table, mixed $id, mixed $tenantId): ?object
    {
        $tableSchema     = SchemaRegistryUtils::getTable($table);
        $actualTableName = $tableSchema->table ?? $table;
        $pk              = $tableSchema->primaryKey ?? 'id';

        $query = DB::table($actualTableName)->where($pk, $id);
        $this->applyTenantFilter($query, $table, $tenantId);

        return $query->first() ?: null;
    }

    /**
     * Delete a record with all related processing.
     *
     * @return array Returns ['id' => mixed, 'affected' => int]
     */
    public function deleteRecord(string $table, mixed $id, mixed $tenantId): array
    {
        $tableSchema = SchemaRegistryUtils::getTable($table);

        $actualTableName = $tableSchema->table ?? $table;
        $pk = $tableSchema->primaryKey ?? 'id';

        $query = DB::table($actualTableName)->where($pk, $id);
        $this->applyTenantFilter($query, $table, $tenantId);
        $affected = $tableSchema->softDeletes ?? false ? $query->update(['deleted_at' => TimeUtils::now()]) : $query->delete();
        if ($affected > 0) {
            $tenantEnabled = $this->shouldApplyTenantId($tableSchema);
            $cacheTenantId = $tenantEnabled ? $this->normalizeTenantId($tenantId) : null;
            $this->invalidateTableCache($table, $cacheTenantId, $tenantEnabled);
            $this->invalidateRecordCache($table, $id, $cacheTenantId, $tenantEnabled);
        }

        return [
            'id' => $id,
            'affected' => $affected,
        ];
    }

    /**
     * Restore a soft-deleted record.
     *
     * @return array Returns ['id' => mixed, 'restored' => int]
     */
    public function restoreRecord(string $table, mixed $id, mixed $tenantId): array
    {
        $tableSchema = SchemaRegistryUtils::getTable($table);

        $actualTableName = $tableSchema->table ?? $table;
        $pk = $tableSchema->primaryKey ?? 'id';

        $query = DB::table($actualTableName)->where($pk, $id);
        $this->applyTenantFilter($query, $table, $tenantId);
        $query->whereNotNull($actualTableName . '.deleted_at');

        $restored = $query->update(['deleted_at' => null]);
        if ($restored > 0) {
            $tenantEnabled = $this->shouldApplyTenantId($tableSchema);
            $cacheTenantId = $tenantEnabled ? $this->normalizeTenantId($tenantId) : null;
            $this->invalidateTableCache($table, $cacheTenantId, $tenantEnabled);
            $this->invalidateRecordCache($table, $id, $cacheTenantId, $tenantEnabled);
        }

        return [
            'id' => $id,
            'restored' => $restored,
        ];
    }

    /**
     * Force delete a record.
     *
     * @return array Returns ['id' => mixed, 'deleted' => int]
     */
    public function forceDeleteRecord(Request $request, string $table, mixed $id, mixed $tenantId): array
    {
        $tableSchema = SchemaRegistryUtils::getTable($table);

        $actualTableName = $tableSchema->table ?? $table;
        $pk = $tableSchema->primaryKey ?? 'id';

        $query = DB::table($actualTableName)->where($pk, $id);
        $this->applyTenantFilter($query, $table, $tenantId);

        $deleted = $query->delete();
        if ($deleted > 0) {
            $tenantEnabled = $this->shouldApplyTenantId($tableSchema);
            $cacheTenantId = $tenantEnabled ? $this->normalizeTenantId($tenantId) : null;
            $this->invalidateTableCache($table, $cacheTenantId, $tenantEnabled);
            $this->invalidateRecordCache($table, $id, $cacheTenantId, $tenantEnabled);
        }

        return [
            'id' => $id,
            'deleted' => $deleted,
        ];
    }

    /**
     * Upsert a record.
     *
     * @return array Returns ['id' => mixed, 'payload' => array]
     */
    public function upsertRecord(Request $request, string $table, array $payload, mixed $tenantId, array $matchOn = []): array
    {
        $tableSchema = SchemaRegistryUtils::getTable($table);

        $actualTableName = $tableSchema->table ?? $table;
        $pk = $tableSchema->primaryKey ?? 'id';

        // Resolve match_on if not provided
        if (empty($matchOn)) {
            $matchOnStr = $request->query('match_on');
            if (!empty($matchOnStr)) {
                $matchOn = explode(',', $matchOnStr);
            }
        }

        if (empty($matchOn)) {
            throw new Exception('match_on query parameter is required for upsert operation', RecordApiJsonResponseEnum::VALIDATION_ERROR->value);
        }

        // Sanitize payload
        $item = $this->sanitizePayload($payload, $tableSchema);
        if ($this->shouldApplyTenantId($tableSchema)) {
            $item[RecordConfigService::tenantColumn()] = $this->normalizeTenantId($tenantId);
        }

        // Apply timestamps and audit fields
        $item = $this->applyTimestampsAndAuditFields($item, $tableSchema, true);

        // Upsert requires update columns; exclude match columns, primary key and system timestamps
        $excludeColumns = array_merge($matchOn, [$pk, 'id', 'created_at', 'deleted_at']);

        // If specific update columns are configured, use them. Otherwise, update all non-excluded columns.
        $updateColumns = array_values(array_diff(array_keys($item), $excludeColumns));

        // Ensure we have something to update, otherwise upsert might fail or do nothing if all columns match
        // If no columns to update, we might just return the existing record or do nothing.
        // But DB::upsert expects at least one column to update if we want to update.
        // If the intention is "insert if not exists, do nothing if exists", we can pass an empty array for update columns in some drivers, but Laravel's upsert expects columns.
        // However, let's assume if there are no other columns, we touch updated_at if it exists.
        if (empty($updateColumns) && array_key_exists('updated_at', $item)) {
            $updateColumns = ['updated_at'];
        }

        DB::table($actualTableName)->upsert([$item], $matchOn, $updateColumns);

        $tenantEnabled = $this->shouldApplyTenantId($tableSchema);
        $cacheTenantId = $tenantEnabled ? $this->normalizeTenantId($tenantId) : null;
        $this->invalidateTableCache($table, $cacheTenantId, $tenantEnabled);

        // Retrieve the ID - this is tricky with upsert as we don't always get the ID back easily across all drivers.
        // We might need to query it back using the matchOn columns.
        $query = DB::table($actualTableName);
        foreach ($matchOn as $col) {
            $query->where($col, $item[$col]);
        }

        if ($tenantEnabled) {
            $this->applyTenantFilter($query, $table, $tenantId);
        }

        $record = $query->first([$pk]);
        $id = $record ? $record->$pk : null;

        if ($id) {
            $this->invalidateRecordCache($table, $id, $cacheTenantId, $tenantEnabled);

            // Trigger post-write logic (audit logs, triggers)
            // Determining if it was insert or update is hard with standard upsert.
            // We'll treat it as 'update' for now as it's the safer assumption for audit logs in upsert context,
            // or we could check if created_at == updated_at (if we had precision).
            // For now, let's log it as 'upsert' (which might map to update or a custom event).
            // But processPostWriteLogic expects 'create', 'update', 'delete'.
            // Let's check if the record existed before? No, that defeats the performance purpose of upsert.
            // We will trigger 'update' logic as a fallback.
            $this->processPostWriteLogic($request, $table, 'update', [
                'id' => $id,
                'payload' => $item,
                RecordConfigService::tenantColumn() => $tenantId,
            ]);
        }

        return [
            'data' => [
                'id' => $id,
                'payload' => $item,
            ],
            'meta' => [],
        ];
    }

    /**
     * Bulk upsert records.
     *
     * @return array Returns ['count' => int]
     */
    public function bulkUpsertRecord(Request $request, string $table, array $payloads, mixed $tenantId, array $matchOn): array
    {
        $tableSchema = SchemaRegistryUtils::getTable($table);
        $actualTableName = $tableSchema->table ?? $table;
        $pk = $tableSchema->primaryKey ?? 'id';
        $tenantEnabled = $this->shouldApplyTenantId($tableSchema);
        $cacheTenantId = $tenantEnabled ? $this->normalizeTenantId($tenantId) : null;

        $preparedItems = [];
        $updateColumns = [];

        foreach ($payloads as $payload) {
            $item = $this->sanitizePayload($payload, $tableSchema);
            if ($tenantEnabled) {
                $item[RecordConfigService::tenantColumn()] = $cacheTenantId;
            }

            $item = $this->applyTimestampsAndAuditFields($item, $tableSchema, true);
            $preparedItems[] = $item;
        }

        if (empty($preparedItems)) {
            return ['count' => 0];
        }

        // Calculate update columns from the first item (assuming uniform payload structure)
        $firstItem = $preparedItems[0];
        $excludeColumns = array_merge($matchOn, [$pk, 'id', 'created_at', 'deleted_at']);
        $updateColumns = array_values(array_diff(array_keys($firstItem), $excludeColumns));
        if (empty($updateColumns) && array_key_exists('updated_at', $firstItem)) {
            $updateColumns = ['updated_at'];
        }

        $affected = DB::table($actualTableName)->upsert($preparedItems, $matchOn, $updateColumns);

        $this->invalidateTableCache($table, $cacheTenantId, $tenantEnabled);

        // We can't easily invalidate individual record caches or fire individual triggers for bulk upsert
        // without querying them all back. This is a trade-off for bulk performance.

        return [
            'data' => [
                'count' => $affected,
            ],
            'meta' => [],
        ];
    }

    /**
     * Execute post-write logic (Triggers and Audit Logs).
     * This should be called after the DB operation (and ideally after commit for single records, or inside transaction for bulk).
     */
    public function processPostWriteLogic(Request $request, string $table, string $operation, array $recordContext): void
    {
        $tableSchema = SchemaRegistryUtils::getTable($table);

        // 1. Execute Table Trigger
        $triggerConfig = match ($operation) {
            'create'  => $tableSchema->afterCreate ?? null,
            'update'  => $tableSchema->afterUpdate ?? null,
            'delete'  => $tableSchema->afterDelete ?? null,
            'restore' => $tableSchema->afterRestore ?? null,
            default   => null
        };

        if ($triggerConfig) {
            $this->executeTableTrigger($triggerConfig, [
                $request,
                $table,
                $recordContext,
            ]);
        }

        $globalTrigger = match ($operation) {
            'create'  => $this->globalTrigger('afterCreate'),
            'update'  => $this->globalTrigger('afterUpdate'),
            'delete'  => $this->globalTrigger('afterDelete'),
            'restore' => $this->globalTrigger('afterRestore'),
            default   => null
        };

        if ($globalTrigger) {
            $this->executeTableTrigger($globalTrigger, [
                $request,
                $table,
                $recordContext,
            ]);
        }

        // 2. Insert Audit Log
        if (!($tableSchema->disableAuditLog ?? false)) {
            $entityClass = 'App\Models\\' . Str::studly(Str::singular($table));
            $event = match ($operation) {
                'create'  => AuditLogEventEnum::CREATED,
                'update'  => AuditLogEventEnum::UPDATED,
                'delete'  => AuditLogEventEnum::DELETED,
                'restore' => AuditLogEventEnum::UPDATED,
                default   => AuditLogEventEnum::UPDATED
            };

            $auditData = $recordContext['response_data'] ?? $recordContext['payload'] ?? [];

            // If response is a JsonResponse, extract data
            if (isset($recordContext['response']) && $recordContext['response'] instanceof JsonResponse) {
                $data = $recordContext['response']->getData();
                $responseData = json_decode(json_encode($data->data ?? $data), true);
                if (is_array($responseData)) {
                    $auditData = array_merge($auditData, $responseData);
                }
            }

            $auditData = $this->stripRelationshipAuditData(
                $auditData,
                $tableSchema,
                RecordConfigService::auditLogRelationships()
            );

            // Ensure ID is present for delete operations if available in context
            if (!isset($auditData['id']) && isset($recordContext['id'])) {
                $auditData['id'] = $recordContext['id'];
            }

            $tenantId = $recordContext[RecordConfigService::tenantColumn()] ?? null;

            $context = [
                'request' => $request,
                'table' => $table,
                'operation' => $operation,
                'record_context' => $recordContext,
                'request_context' => $this->getRequestContext($request),
            ];

            if (
                !empty($tableSchema->customAuditLog) &&
                $this->callCustomAuditLogger(
                    callback: $tableSchema->customAuditLog,
                    event: $event,
                    entityClass: $entityClass,
                    auditData: $auditData,
                    tenantId: $tenantId,
                    context: $context,
                )
            ) {
                return;
            }

            AuditLogService::insertAuditLog(
                auditLogEventEnum: $event,
                entityClass: $entityClass,
                queryData: $auditData,
                subject: '',
                recap: '',
                tenantId: $tenantId
            );
        }

        // 3. Fire broadcast event (opt-in via record.broadcast_events)
        $this->fireBroadcastEvent($table, $operation, $recordContext, $tableSchema);
    }

    /**
     * Fire a RecordMutated broadcast event when broadcasting is enabled.
     */
    private function fireBroadcastEvent(string $table, string $operation, array $recordContext, ?RecordTableType $tableSchema): void
    {
        if (!RecordConfigService::broadcastEventsEnabled()) {
            return;
        }

        if ($tableSchema instanceof RecordTableType && $tableSchema->disableBroadcast) {
            return;
        }

        $allowedTables = RecordConfigService::broadcastTables();
        if (!empty($allowedTables) && !in_array($table, $allowedTables, true)) {
            return;
        }

        $action = match ($operation) {
            'create'  => 'created',
            'update'  => 'updated',
            'delete'  => 'deleted',
            'restore' => 'restored',
            default   => $operation,
        };

        $record = [];
        if (isset($recordContext['response']) && $recordContext['response'] instanceof JsonResponse) {
            $data = $recordContext['response']->getData();
            $decoded = json_decode(json_encode($data->data ?? $data), true);
            if (is_array($decoded)) {
                $record = $decoded;
            }
        } elseif (isset($recordContext['payload']) && is_array($recordContext['payload'])) {
            $record = $recordContext['payload'];
        }

        if (isset($recordContext['id']) && !isset($record['id'])) {
            $record['id'] = $recordContext['id'];
        }

        $tenantId = $recordContext[RecordConfigService::tenantColumn()] ?? null;

        try {
            RecordMutated::dispatch(
                $table,
                $action,
                $record,
                $tenantId,
                TimeUtils::now()->toISOString(),
            );
        } catch (Throwable) {
            // Never let a broadcast failure break the HTTP response
            
        }
    }

    private function stripRelationshipAuditData(array $auditData, RecordTableType $tableSchema, bool $includeRelationships): array
    {
        $relationships = is_array($tableSchema->relationships ?? null) ? $tableSchema->relationships : [];
        if (!$includeRelationships) {
            foreach (array_keys($relationships) as $relationKey) {
                unset($auditData[$relationKey]);
            }

            unset($auditData['relationship'], $auditData['relationships']);

            return $auditData;
        }

        $allowedRelations = [];

        foreach ($relationships as $relationKey => $relationConfig) {
            $foreignKey = null;

            if ($relationConfig instanceof RecordBelongsToType) {
                $foreignKey = $relationConfig->foreignKey ?? (Str::singular($relationConfig->table) . '_id');
            } elseif (is_array($relationConfig)) {
                $type = $relationConfig['type'] ?? null;
                if ('belongsTo' === $type) {
                    $foreignKey = $relationConfig['foreignKey'] ?? $relationConfig['foreign_key'] ?? (is_string($relationConfig['table'] ?? null) ? (Str::singular($relationConfig['table']) . '_id') : null);
                }
            }

            if ($foreignKey && array_key_exists($foreignKey, $auditData) && null !== $auditData[$foreignKey]) {
                $allowedRelations[] = $relationKey;
            }
        }

        foreach (array_keys($relationships) as $relationKey) {
            if (!in_array($relationKey, $allowedRelations, true)) {
                unset($auditData[$relationKey]);
            }
        }

        unset($auditData['relationship'], $auditData['relationships']);

        return $auditData;
    }

    /**
     * Execute a table-specific custom function.
     */
    public function executeTableFunction(Request $request, string $table, string $functionName): JsonResponse
    {
        // Get schema and validate table exists
        $tableSchema = SchemaRegistryUtils::getTable($table);
        if (!$tableSchema instanceof RecordTableType) {
            throw new Exception(sprintf("Table '%s' does not exist", $table), RecordApiJsonResponseEnum::NOT_FOUND->value);
        }

        // Check if function exists in table schema
        $tableFunctions = $tableSchema->functions ?? [];
        $functionConfig = null;
        $extractedId = null;

        // First try exact match
        if (isset($tableFunctions[$functionName])) {
            $functionConfig = $tableFunctions[$functionName];
        } else {
            // Try pattern matching for parameterized function names
            foreach ($tableFunctions as $configuredFunctionName => $config) {
                // Convert function name pattern to regex (e.g., 'role_permission/{id}' -> 'role_permission/(\d+)')
                $pattern = preg_replace('/\{[^}]+\}/', '(\d+)', (string) $configuredFunctionName);
                $pattern = '/^' . str_replace('/', '\/', $pattern) . '$/';

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

        // Resolve Class-Based Config
        if (is_string($functionConfig) && class_exists($functionConfig)) {
            $instance = new $functionConfig();
            if ($instance instanceof RecordFunctionInterface) {
                $functionConfig = $instance->toFunctionType();
            } elseif ($instance instanceof RecordFunctionType) {
                $functionConfig = $instance;
            }
        }

        if (!$functionConfig) {
            throw new Exception(sprintf("Function '%s' not found for table '%s'", $functionName, $table), RecordApiJsonResponseEnum::NOT_FOUND->value);
        }

        $disableCache = false;
        $functionCacheTtl = null;
        if ($functionConfig instanceof RecordFunctionType) {
            $disableCache = $functionConfig->disableCache;
            $functionCacheTtl = $functionConfig->cacheTTL;
        } elseif (is_array($functionConfig)) {
            $disableCache = (bool) ($functionConfig['disableCache'] ?? false);
            $functionCacheTtl = isset($functionConfig['cacheTTL']) ? (int) $functionConfig['cacheTTL'] : null;
        }

        if (null !== $functionCacheTtl && $functionCacheTtl <= 0) {
            $functionCacheTtl = null;
        }

        $tenantEnabled = $this->shouldApplyTenantId($tableSchema);
        $tenantId = $tenantEnabled ? $this->normalizeTenantId($request->header(RecordConfigService::tenantHeader())) : null;
        $cacheKey = null;

        if (!$disableCache && $this->isCacheableRequest($request, $table)) {
            $cacheKey = $this->generateTableFunctionCacheKey(
                table: $table,
                functionName: $functionName,
                queryParams: $request->query(),
                tenantId: $tenantId,
                tenantEnabled: $tenantEnabled
            );
            $cached = QueryCacheService::get($cacheKey);
            if (is_array($cached) && isset($cached['data'], $cached['status'])) {
                return new JsonResponse($cached['data'], $cached['status'], $cached['headers'] ?? []);
            }
        }

        $response = $this->executeCustomFunction($request, $functionConfig, $extractedId);

        $method = strtoupper($request->method());
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && $response->getStatusCode() < 400) {
            $this->cacheService()->invalidateTableFunctionCache(
                table: $table,
                functionName: $functionName,
                tenantId: $tenantId,
                tenantEnabled: $tenantEnabled
            );

            $clearCacheTables = null;
            if ($functionConfig instanceof RecordFunctionType) {
                $clearCacheTables = $functionConfig->clearCacheTables;
            } elseif (is_array($functionConfig)) {
                $clearCacheTables = $functionConfig['clearCacheTables'] ?? null;
            }

            if (null === $clearCacheTables || [] === $clearCacheTables || '' === $clearCacheTables) {
                $clearCacheTables = $table;
            }

            $this->cacheService()->clearCacheForTables($clearCacheTables, $tenantId);
        }

        if ($cacheKey) {
            $ttl = $this->calculateOptimalCacheTTL($table, 1, false);
            if (null !== $functionCacheTtl && $functionCacheTtl !== $ttl) {
                $ttl = $functionCacheTtl;
            }

            QueryCacheService::put($cacheKey, [
                'data' => $response->getData(true),
                'status' => $response->getStatusCode(),
                'headers' => $response->headers->all(),
            ], $ttl);
        }

        return $response;
    }

    /**
     * Execute a global custom function.
     * Supports patterns like: function_name or function_name/{id}.
     */
    public function executeGlobalFunction(Request $request, string $functionName): JsonResponse
    {
        // Check if function exists in table schema
        $globalFunctions = RecordConfigService::globalFunctions();
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
                $pattern = '/^' . str_replace('/', '\/', $pattern) . '$/';

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

        // Resolve Class-Based Config
        if (is_string($functionConfig) && class_exists($functionConfig)) {
            $instance = new $functionConfig();
            if ($instance instanceof RecordFunctionInterface) {
                $functionConfig = $instance->toFunctionType();
            } elseif ($instance instanceof RecordFunctionType) {
                $functionConfig = $instance;
            }
        }

        if (!$functionConfig) {
            throw new Exception(sprintf("Function '%s' not found", $functionName), RecordApiJsonResponseEnum::NOT_FOUND->value);
        }

        $disableCache = false;
        $functionCacheTtl = null;
        if ($functionConfig instanceof RecordFunctionType) {
            $disableCache = $functionConfig->disableCache;
            $functionCacheTtl = $functionConfig->cacheTTL;
        } elseif (is_array($functionConfig)) {
            $disableCache = (bool) ($functionConfig['disableCache'] ?? false);
            $functionCacheTtl = isset($functionConfig['cacheTTL']) ? (int) $functionConfig['cacheTTL'] : null;
        }

        if (null !== $functionCacheTtl && $functionCacheTtl <= 0) {
            $functionCacheTtl = null;
        }

        $tenantEnabled = RecordConfigService::enableTenantId();
        $tenantId = $tenantEnabled ? $this->normalizeTenantId($request->header(RecordConfigService::tenantHeader())) : null;
        $cacheKey = null;

        if (!$disableCache && RecordConfigService::cacheEnabled() && 'GET' === $request->method() && !$request->has(['search', 'filter', 'where'])) {
            $cacheKey = $this->generateGlobalFunctionCacheKey(
                functionName: $functionName,
                queryParams: $request->query(),
                tenantId: $tenantId,
                tenantEnabled: $tenantEnabled
            );
            $cached = QueryCacheService::get($cacheKey);
            if (is_array($cached) && isset($cached['data'], $cached['status'])) {
                return new JsonResponse($cached['data'], $cached['status'], $cached['headers'] ?? []);
            }
        }

        $response = $this->executeCustomFunction($request, $functionConfig, $extractedId);

        $method = strtoupper($request->method());
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && $response->getStatusCode() < 400) {
            $this->cacheService()->invalidateGlobalFunctionCache(
                functionName: $functionName,
                tenantId: $tenantId,
                tenantEnabled: $tenantEnabled
            );

            $clearCacheTables = null;
            if ($functionConfig instanceof RecordFunctionType) {
                $clearCacheTables = $functionConfig->clearCacheTables;
            } elseif (is_array($functionConfig)) {
                $clearCacheTables = $functionConfig['clearCacheTables'] ?? null;
            }

            $this->cacheService()->clearCacheForTables($clearCacheTables, $tenantId);
        }

        if ($cacheKey) {
            $ttl = $functionCacheTtl ?? RecordConfigService::cacheTtl();
            QueryCacheService::put($cacheKey, [
                'data' => $response->getData(true),
                'status' => $response->getStatusCode(),
                'headers' => $response->headers->all(),
            ], $ttl);
        }

        return $response;
    }

    /**
     * Bulk create, update, or delete records.
     */
    public function bulkRecord(Request $request, string $table, mixed $tenantId, ?string $legacyAction = null): array
    {
        $tableSchema = SchemaRegistryUtils::getTable($table);
        if (!$tableSchema instanceof RecordTableType) {
            throw new Exception('Resource not available', RecordApiJsonResponseEnum::NOT_FOUND->value);
        }

        // Parse items
        $requestData = $request->all();
        if (isset($requestData['items']) && is_array($requestData['items'])) {
            $items = $requestData['items'];
        } else {
            $jsonInput = $request->getContent();
            $decodedJson = json_decode($jsonInput, true);

            if (JSON_ERROR_NONE === json_last_error() && is_array($decodedJson) && [] !== $decodedJson) {
                $items = array_keys($decodedJson) === range(0, count($decodedJson) - 1) ? $decodedJson : [$decodedJson];
            } else {
                $items = $request->input('items', []);
            }
        }

        if (!is_array($items) || [] === $items) {
            throw new Exception('Data array required', RecordApiJsonResponseEnum::VALIDATION_ERROR->value);
        }

        $maxBatch = RecordConfigService::bulkMax();
        if (count($items) > $maxBatch) {
            throw new Exception('Batch too large, max ' . $maxBatch, 413);
        }

        $pk = $tableSchema->primaryKey ?? 'id';
        $affected = 0;
        $createdData = [];
        $updatedData = [];
        $deletedData = [];
        $upsertedData = [];

        // Begin transaction
        DB::beginTransaction();

        try {
            foreach ($items as $item) {
                $operation = $this->determineOperation($item, $pk, $legacyAction);

                if ('create' === $operation) {
                    $this->executeTableTrigger($tableSchema->beforeCreate ?? null, [$request, $table, $item]);

                    $result = $this->createRecord(table: $table, payload: $item,  tenantId: $tenantId);
                    $insertId = $result['id'];
                    $recordResult = $this->getRecord($request, $table, $insertId, $tenantId);
                    $createdData[] = $recordResult['data'];
                    ++$affected;

                    $tenantColumn = RecordConfigService::tenantColumn();
                    $this->processPostWriteLogic($request, $table, 'create', [
                        'id' => $insertId,
                        'payload' => $result['payload'],
                        $tenantColumn => $result[$tenantColumn] ?? $tenantId,
                        'response' => $recordResult['data'],
                    ]);
                } elseif ('update' === $operation) {
                    if (!isset($item[$pk])) {
                        throw new Exception('Primary key required for update');
                    }

                    $id = $item[$pk];
                    unset($item[$pk]);

                    $this->executeTableTrigger($tableSchema->beforeUpdate ?? null, [$request, $table, $id, $item]);

                    $result = $this->updateRecord(table: $table, id: $id, payload: $item, tenantId: $tenantId);
                    if ($result['updated'] > 0) {
                        $recordResult = $this->getRecord($request, $table, $id, $tenantId);
                        $updatedData[] = $recordResult['data'];
                        $affected += $result['updated'];

                        $tenantColumn = RecordConfigService::tenantColumn();
                        $this->processPostWriteLogic($request, $table, 'update', [
                            'id' => $id,
                            'payload' => $result['payload'],
                            $tenantColumn => $result[$tenantColumn] ?? $tenantId,
                            'updated' => $result['updated'],
                            'response' => $recordResult['data'],
                        ]);
                    }
                } elseif ('delete' === $operation) {
                    if (!isset($item[$pk])) {
                        throw new Exception('Primary key required for delete');
                    }

                    $this->executeTableTrigger($tableSchema->beforeDelete ?? null, [$request, $table, $item[$pk]]);

                    $result = $this->deleteRecord(table: $table, id: $item[$pk], tenantId: $tenantId);
                    if ($result['affected'] > 0) {
                        $deletedData[] = ['id' => $item[$pk]];
                        $affected += $result['affected'];

                        $response = ['deleted' => $result['affected']];

                        $this->processPostWriteLogic($request, $table, 'delete', [
                            'id' => $item[$pk],
                            RecordConfigService::tenantColumn() => $tenantId,
                            'affected' => $result['affected'],
                            'soft_deleted' => $tableSchema->softDeletes ?? false,
                            'response' => $response,
                        ]);
                    }
                } elseif ('upsert' === $operation) {
                    $result = $this->upsertRecord($request, $table, $item, $tenantId);
                    $upsertedId = $result['data']['id'] ?? ($item[$pk] ?? null);

                    if ($upsertedId) {
                        $recordResult = $this->getRecord($request, $table, $upsertedId, $tenantId);
                        $upsertedData[] = $recordResult['data'];
                        ++$affected;

                        if (!($tableSchema->disableAuditLog ?? false)) {
                            $entityClass = 'App\\Models\\' . Str::studly(Str::singular($table));

                            $context = [
                                'request' => $request,
                                'table' => $table,
                                'operation' => 'upsert',
                                'record_context' => [
                                    'id' => $upsertedId,
                                    'response' => $recordResult['data'],
                                ],
                            ];

                            if (!(!empty($tableSchema->customAuditLog) && $this->callCustomAuditLogger(
                                callback: $tableSchema->customAuditLog,
                                event: AuditLogEventEnum::UPDATED,
                                entityClass: $entityClass,
                                auditData: $recordResult['data'],
                                tenantId: $tenantId,
                                context: $context,
                            ))) {
                                AuditLogService::insertAuditLog(
                                    auditLogEventEnum: AuditLogEventEnum::UPDATED,
                                    entityClass: $entityClass,
                                    queryData: $recordResult['data'],
                                    subject: '',
                                    recap: '',
                                    tenantId: $tenantId
                                );
                            }
                        }
                    }
                }
            }

            DB::commit();
            $this->invalidateTableCache($table, $tenantId, $this->shouldApplyTenantId($tableSchema));

            $consolidatedData = array_merge($createdData, $updatedData, $upsertedData);

            return [
                'data' => $consolidatedData,
                'meta' => ['affected' => count($consolidatedData)], // Matches Controller logic
            ];
        } catch (Exception $exception) {
            DB::rollBack();

            throw $exception;
        }
    }

    protected function callCustomAuditLogger(
        string|array $callback,
        AuditLogEventEnum $event,
        string $entityClass,
        array $auditData,
        mixed $tenantId,
        array $context
    ): bool {
        $callable = null;

        if (is_array($callback)) {
            if (count($callback) === 2) {
                [$target, $method] = $callback;

                if (is_string($target) && class_exists($target)) {
                    $instance = app($target);

                    if (method_exists($instance, $method)) {
                        $callable = [$instance, $method];
                    } elseif (method_exists($target, $method)) {
                        $callable = [$target, $method];
                    }
                } elseif (is_object($target) && method_exists($target, $method)) {
                    $callable = [$target, $method];
                }
            }
        } elseif (is_string($callback)) {
            if (function_exists($callback)) {
                $callable = $callback;
            } elseif (str_contains($callback, '@')) {
                [$class, $method] = explode('@', $callback, 2);
                if (class_exists($class)) {
                    $instance = app($class);
                    if (method_exists($instance, $method)) {
                        $callable = [$instance, $method];
                    }
                }
            } elseif (str_contains($callback, '::') && is_callable($callback)) {
                $callable = $callback;
            } elseif (class_exists($callback)) {
                $instance = app($callback);
                if (is_callable($instance)) {
                    $callable = $instance;
                } elseif (method_exists($instance, 'handle')) {
                    $callable = [$instance, 'handle'];
                }
            }
        } elseif (is_callable($callback)) {
            $callable = $callback;
        }

        if (null === $callable) {
            return false;
        }

        $callable($event, $entityClass, $auditData, $tenantId, $context);

        return true;
    }

    public function executeTableTrigger(mixed $trigger, array $params): array
    {
        if (isset($params[0]) && $params[0] instanceof Request) {
            $request = $params[0];
            $table = isset($params[1]) && is_string($params[1]) ? $params[1] : '';
            $triggerType = null;
            if (isset($params[2]) && is_array($params[2])) {
                $triggerType = $params[2]['type'] ?? null;
            }

            $request = $this->attachRequestContext(
                request: $request,
                table: $table,
                action: is_string($triggerType) ? $triggerType : null
            );
            $params[0] = $request;

            if (isset($params[2]) && is_array($params[2])) {
                $params[2]['request_context'] = $this->getRequestContext($request);
            }
        }

        $triggers = $this->resolveTableTriggers($trigger);

        foreach ($triggers as $index => $item) {
            $triggerItem = $this->resolveTriggerItem($item, $index);
            $this->executeSingleTrigger($triggerItem, $params);
        }

        return $params;
    }

    public function executeGlobalTrigger(string $hook, array $params): array
    {
        return $this->executeTableTrigger($this->globalTrigger($hook), $params);
    }

    public function globalTrigger(string $hook): mixed
    {
        $triggers = RecordConfigService::globalTriggers();

        return $triggers[$hook] ?? null;
    }

    private function resolveTableTriggers(mixed $trigger): array
    {
        if (null === $trigger) {
            return [];
        }

        if ($trigger instanceof RecordTableTriggerType) {
            return [$trigger];
        }

        if (!is_array($trigger)) {
            throw new Exception(sprintf(
                'Invalid table trigger configuration. Expected %s or array, got %s',
                RecordTableTriggerType::class,
                get_debug_type($trigger)
            ));
        }

        if (isset($trigger['class']) || isset($trigger['functionName'])) {
            return [$trigger];
        }

        return $trigger;
    }

    private function resolveTriggerItem(mixed $item, int|string $index): RecordTableTriggerType
    {
        if ($item instanceof RecordTableTriggerType) {
            return $item;
        }

        if (is_array($item)) {
            try {
                return RecordTableTriggerType::fromArray($item);
            } catch (Throwable $exception) {
                throw new Exception(sprintf(
                    'Invalid table trigger config at index %s: %s',
                    (string) $index,
                    $exception->getMessage()
                ), 0, $exception);
            }
        }

        throw new Exception(sprintf(
            'Invalid table trigger item at index %s. Expected %s or array, got %s',
            (string) $index,
            RecordTableTriggerType::class,
            get_debug_type($item)
        ));
    }

    private function executeSingleTrigger(RecordTableTriggerType $trigger, array &$params): void
    {
        $className = $trigger->class;
        $method = $trigger->functionName;

        if (!class_exists($className)) {
            throw new Exception(sprintf("Table trigger class '%s' does not exist", $className));
        }

        if (!method_exists($className, $method)) {
            throw new Exception(sprintf("Table trigger method '%s::%s' does not exist", $className, $method));
        }

        try {
            $result = call_user_func_array([$className, $method], $params);
            if ($result instanceof JsonResponse) {
                throw new HttpResponseException($this->normalizeTriggerResponse($result));
            }

            if ($result instanceof Request && isset($params[0]) && $params[0] instanceof Request) {
                $params[0] = $result;
            } elseif (is_array($result) && isset($params[0]) && $params[0] instanceof Request) {
                $params[0]->merge($result);
            }
        } catch (Throwable $throwable) {
            if ($throwable instanceof HttpResponseException) {
                throw $throwable;
            }

            throw new Exception(sprintf(
                "Table trigger execution failed for '%s::%s': %s",
                $className,
                $method,
                $throwable->getMessage()
            ), 0, $throwable);
        }
    }

    private function normalizeTriggerResponse(JsonResponse $response): JsonResponse
    {
        $data = $response->getData(true);

        if (is_array($data) && array_key_exists('success', $data)) {
            return $response;
        }

        $status = $response->getStatusCode();

        if (is_array($data) && array_key_exists('validation_errors', $data)) {
            $validationErrors = $this->resolveValidationErrors($data['validation_errors']);
            $message = $this->resolveValidationErrorMessage($validationErrors);
            return RecordApiResponseService::errorWrapped(
                $message,
                RecordApiJsonResponseEnum::VALIDATION_ERROR->value,
                $validationErrors
            );
        }

        $message = 'Request failed';
        $errors = [];

        if (is_array($data)) {
            if (array_key_exists('message', $data)) {
                $message = (string) $data['message'];
            } elseif (array_key_exists('errors', $data) && is_string($data['errors'])) {
                $message = $data['errors'];
            }

            if (array_key_exists('errors', $data) && is_array($data['errors'])) {
                $errors = $data['errors'];
            }
        }

        return RecordApiResponseService::errorWrapped($message, $status, $errors);
    }

    private function resolveValidationErrors(mixed $errors): array
    {
        return is_array($errors) ? $errors : [];
    }

    private function resolveValidationErrorMessage(array $errors): string
    {
        if (array_key_exists('message', $errors) && is_string($errors['message'])) {
            return $errors['message'];
        }

        return 'Validation failed';
    }

    // --- Helper Methods ---

    private function cacheService(): RecordCacheService
    {
        return app(RecordCacheService::class);
    }

    public function isCacheableRequest(Request $request, string $table): bool
    {
        return $this->cacheService()->isCacheableRequest($request, $table);
    }

    public function generateOptimizedCacheKey(string $table, array $filters, array $includes, int $page, int $limit, bool $tenantEnabled): string
    {
        return $this->cacheService()->generateOptimizedCacheKey($table, $filters, $includes, $page, $limit, $tenantEnabled);
    }

    public function generateRecordCacheKey(string $table, mixed $id, mixed $tenantId, mixed $select, bool $tenantEnabled): string
    {
        return $this->cacheService()->generateRecordCacheKey($table, $id, $tenantId, $select, $tenantEnabled);
    }

    public function generateTableFunctionCacheKey(string $table, string $functionName, array $queryParams, mixed $tenantId, bool $tenantEnabled): string
    {
        return $this->cacheService()->generateTableFunctionCacheKey($table, $functionName, $queryParams, $tenantId, $tenantEnabled);
    }

    public function generateGlobalFunctionCacheKey(string $functionName, array $queryParams, mixed $tenantId, bool $tenantEnabled): string
    {
        return $this->cacheService()->generateGlobalFunctionCacheKey($functionName, $queryParams, $tenantId, $tenantEnabled);
    }

    public function invalidateTableCache(string $table, mixed $tenantId, bool $tenantEnabled): void
    {
        $this->cacheService()->invalidateTableCache($table, $tenantId, $tenantEnabled);
    }

    public function clearTableCache(string $table, mixed $tenantId = null): void
    {
        $this->cacheService()->clearTableCache($table, $tenantId);
    }

    public function invalidateRecordCache(string $table, mixed $id, mixed $tenantId, bool $tenantEnabled): void
    {
        $this->cacheService()->invalidateRecordCache($table, $id, $tenantId, $tenantEnabled);
    }

    public function calculateOptimalCacheTTL(string $table, int $recordCount, bool $hasRelationships): int
    {
        return $this->cacheService()->calculateOptimalCacheTTL($table, $recordCount, $hasRelationships);
    }

    public function sanitizePayload(array $input, object $meta): array
    {
        $columns = array_keys($meta->columns ?? []);
        $payload = array_intersect_key($input, array_flip($columns));

        $writeDisabled = is_array($meta->columnWriteDisabled ?? null) ? $meta->columnWriteDisabled : [];
        foreach ($writeDisabled as $column) {
            unset($payload[$column]);
        }

        unset($payload['id']);
        $overrideTimestamps = filter_var($meta->overrideTimestamps ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!$overrideTimestamps) {
            unset($payload['deleted_at'], $payload['created_at'], $payload['updated_at']);
        }

        return RecordUtils::applyCompositeTypes(payload: $payload, columns: $meta->columns ?? []);
    }

    private function buildCrudPayload(array $payload, object $tableSchema, mixed $tenantId, bool $isUpdate): array
    {
        $tenantColumn = RecordConfigService::tenantColumn();
        $baseData = [];
        if (!$isUpdate && $this->shouldApplyTenantId($tableSchema)) {
            $baseData[$tenantColumn] = $this->normalizeTenantId($tenantId);
        }

        $extracted = RecordPayloadExtractor::fromArray(
            data: $payload,
            fields: array_keys($tableSchema->columns ?? []),
            baseData: $baseData,
            isUpdate: $isUpdate,
            recordTableSchema: $tableSchema instanceof RecordTableType ? $tableSchema : null
        );

        $payloadMain = $this->sanitizePayload($extracted, $tableSchema);

        if ($this->shouldApplyTenantId($tableSchema)) {
            if ($isUpdate) {
                unset($payloadMain[$tenantColumn]);
            } else {
                $payloadMain[$tenantColumn] = $this->normalizeTenantId($tenantId);
            }
        }

        return $this->applyTimestampsAndAuditFields($payloadMain, $tableSchema, $isUpdate);
    }

    public function applyTimestampsAndAuditFields(array $payload, object $tableSchema, bool $isUpdate = false): array
    {
        $user = auth('api')->user();
        $now = TimeUtils::now();
        $overrideTimestamps = $tableSchema->overrideTimestamps ?? false;
        $overrideUserstamps = $tableSchema->overrideUserstamps ?? false;

        if ($isUpdate) {
            if (!array_key_exists('updated_at', $payload) || !$overrideTimestamps) {
                $payload['updated_at'] =  $now;
            }

            if ($user) {
                foreach (['created_by', 'created_by_id', 'updated_by', 'last_updated_by', 'last_updated_by_id'] as $auditField) {
                    if (isset($tableSchema->columns[$auditField]) && (!array_key_exists($auditField, $payload) || !$overrideUserstamps)) {
                        $payload[$auditField] = $user->id;
                    }
                }
            }
        } else {
            if (!array_key_exists('created_at', $payload) || !$overrideTimestamps) {
                $payload['created_at'] = $now;
            }

            if (!array_key_exists('updated_at', $payload) || !$overrideTimestamps) {
                $payload['updated_at'] = $now;
            }

            if ($user) {
                foreach (['created_by', 'created_by_id', 'updated_by', 'last_updated_by', 'last_updated_by_id'] as $auditField) {
                    if (isset($tableSchema->columns[$auditField]) && (!array_key_exists($auditField, $payload) || !$overrideUserstamps)) {
                        $payload[$auditField] = $user->id;
                    }
                }
            }
        }

        return $payload;
    }

    public function shouldApplyTenantId(object $tableSchema): bool
    {
        return RecordUtils::shouldApplyTenantId($tableSchema);
    }

    public function normalizeTenantId(mixed $tenantId): mixed
    {
        return RecordUtils::normalizeTenantId($tenantId);
    }

    public function isTenantIdEnabled(): bool
    {
        return RecordUtils::isTenantIdEnabled();
    }

    public function resolveTenantFromRequest(Request $request, object $tableSchema): mixed
    {
        [$tenantId] = $this->resolveTenantFromRequestWithSource($request, $tableSchema);

        return $tenantId;
    }

    public function attachRequestContext(Request $request, string $table = '', ?string $action = null, ?object $tableSchema = null, mixed $tenantId = null, array $extra = []): Request
    {
        $contextKey = self::REQUEST_CONTEXT_KEY;
        $existing = $request->attributes->get($contextKey);
        $context = is_array($existing) ? $existing : [];

        $resolvedTenant = $tenantId;
        $resolvedSource = null;

        if (RecordUtils::isTenantIdMissing($resolvedTenant)) {
            $resolvedTenant = $request->attributes->get(self::TENANT_ATTRIBUTE_KEY);
            if (!RecordUtils::isTenantIdMissing($resolvedTenant)) {
                $resolvedSource = 'attribute';
            }
        }

        if (($tableSchema instanceof RecordTableType) && RecordUtils::isTenantIdMissing($resolvedTenant)) {
            [$resolvedTenant, $resolvedSource] = $this->resolveTenantFromRequestWithSource($request, $tableSchema);
        }

        $resolvedTenant = $this->normalizeTenantId($resolvedTenant);
        if (!RecordUtils::isTenantIdMissing($resolvedTenant)) {
            $context['tenant_id'] = $resolvedTenant;
            $context['tenant_column'] = RecordConfigService::tenantColumn();
            if (is_string($resolvedSource) && '' !== $resolvedSource) {
                $context['tenant_source'] = $resolvedSource;
            }
     
            $request->attributes->set(self::TENANT_ATTRIBUTE_KEY, $resolvedTenant);
        }
    
        $guard = RecordConfigService::authGuard();
        $user = auth($guard)->user();
        $context['user'] = $user ? [
            'id' => $user->id ?? null,
            'guard' => $guard,
        ] : null;

        $context['request_id'] = $request->attributes->get('request_id');
        $context['table'] = $table !== '' ? $table : (string) ($request->route('table') ?? '');
        $context['action'] = $action ?? (($context['action'] ?? null));

        if ([] !== $extra) {
            $context = array_merge($context, $extra);
        }

        $request->attributes->set($contextKey, $context);

        return $request;
    }

    public function getRequestContext(Request $request): array
    {
        $context = $request->attributes->get(self::REQUEST_CONTEXT_KEY);

        return is_array($context) ? $context : [];
    }

    private function resolveTenantFromRequestWithSource(Request $request, object $tableSchema): array
    {
        if (!$this->shouldApplyTenantId($tableSchema)) {
            return [null, null];
        }

        foreach (self::TENANT_SOURCE_PRIORITY as $source) {
            $source = strtolower((string) $source);
            $candidate = match ($source) {
                'attribute' => $this->resolveTenantFromRequestAttributes($request),
                'header' => $request->header(RecordConfigService::tenantHeader()),
                default => null,
            };

            $candidate = $this->normalizeTenantId($candidate);
            if (!RecordUtils::isTenantIdMissing($candidate)) {
                return [$candidate, $source];
            }
        }

        return [null, null];
    }

    private function resolveTenantFromRequestAttributes(Request $request): mixed
    {
        return RecordUtils::resolveTenantIdFromRequest($request);
    }

    public function applyTenantFilter(mixed $query, string $table, mixed $tenantId): void
    {
        $tenantId = $this->normalizeTenantId($tenantId);
        $schema = SchemaRegistryUtils::get();
        $tableSchema = $schema[$table] ?? null;
        if (!$tableSchema) {
            foreach ($schema as $candidate) {
                if (!is_object($candidate)) {
                    continue;
                }

                if (($candidate->table ?? null) === $table) {
                    $tableSchema = $candidate;
                    break;
                }
            }
        }

        if ($this->isTenantIdEnabled() && null !== $tenantId && '' !== $tenantId && (($tableSchema->hasTenantId ?? false))) {
            $query->where($table . '.' . RecordConfigService::tenantColumn(), $tenantId);
        }
    }

    public function shouldIncludeDebug(Request $request): bool
    {
        $headerValue = $request->headers->get('X-Debug') ?? $request->headers->get('x-debug');
        if (null === $headerValue) {
            return false;
        }

        $normalized = strtolower(trim($headerValue));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * List records for a table.
     */
    public function listRecords(Request $request, string $table, mixed $tenantId): array
    {
        $schema = SchemaRegistryUtils::get();
        $tableSchema = $schema[$table];

        $triggerParams = [
            $request,
            $table,
            [
                'type' => 'index',
                RecordConfigService::tenantColumn() => $tenantId,
            ],
        ];
        $triggerParams = $this->executeTableTrigger( $tableSchema->beforeRead ?? null, $triggerParams);
        if (isset($triggerParams[0]) && $triggerParams[0] instanceof Request) {
            $request = $triggerParams[0];
        }

        $actualTableName = $tableSchema->table ?? $table;

        $filters = $request->except(['page', 'per_page', 'limit']);
        $includes = $request->query('select', []);
        if (is_string($includes)) {
            $includes = explode(',', $includes);
        }

        $page = max((int) $request->get('page', 1), 1);
        $perPage = $request->has('per_page') ? max(1, min((int) $request->get('per_page', 25), RecordConfigService::perPageMax())) : null;
        $limit = $request->has('limit') ? max(1, min((int) $request->get('limit'), RecordConfigService::limitMax())) : RecordConfigService::limitMax();

        $isCacheable = $this->isCacheableRequest(request: $request, table: $table);
        $cacheKey = null;

        if ($isCacheable) {
            $tenantEnabled = $this->shouldApplyTenantId($tableSchema);
            $cacheFilters = $filters;
            $cacheFilters['tenant_enabled'] = $tenantEnabled;
            if ($tenantEnabled) {
                $cacheFilters[RecordConfigService::tenantColumn()] = $tenantId;
            }

            $cacheKey = $this->generateOptimizedCacheKey(
                table: $table,
                filters: $cacheFilters,
                includes: $includes,
                page: $page,
                limit: $perPage ?? $limit,
                tenantEnabled: $tenantEnabled
            );

            $cached = QueryCacheService::get($cacheKey);
            if (null !== $cached) {
                return array_merge($cached, ['from_cache' => true, 'filters' => $filters, 'request' => $request]);
            }
        }

        $builder = DB::table($actualTableName);

        $this->applyTenantFilter($builder, $actualTableName, $tenantId);

        if ($tableSchema->softDeletes) {
            if ($request->boolean('only_trashed')) {
                $builder->whereNotNull($actualTableName . '.deleted_at');
            } elseif (!$request->boolean('with_trashed')) {
                $builder->whereNull($actualTableName . '.deleted_at');
            }
        }

        if ($request->boolean('distinct')) {
            $builder->distinct();
        }

        QueryBuilderFiltersUtils::apply($builder, $request, $actualTableName, $tableSchema->primaryKey ?? 'id');

        $headers = [];
        $meta = [];
        $data = [];
        $total = 0;

        $aggregateResult = QueryBuilderFiltersUtils::applyAggregateAndGroupBy($builder, $request, $actualTableName);

        if (null !== $aggregateResult) {
            $data = $aggregateResult['data'];
            $meta = $aggregateResult['meta'];
            $headers = $aggregateResult['headers'];
        } elseif ($request->has('limit') && !$request->has('per_page')) {
            $limit = max(1, min((int) $request->get('limit'), RecordConfigService::limitMax()));
            $data = $builder->limit($limit)->get()->all();
            $total = count($data);
            $headers['X-Total-Count'] = (string) $total;
            $meta = ['total' => $total];
        } else {
            $paginationRequested = $request->has('page') || $request->has('per_page');
            $maxPerPage = RecordConfigService::perPageMax();
            $perPage = max(1, min((int) $request->get('per_page', RecordConfigService::limitMax()), $maxPerPage));

            $page = max((int) $request->get('page', 1), 1);
            $countQuery = clone $builder;
            $total = $countQuery->count();

            $data = $builder->forPage($page, $perPage)->get()->all();

            $headers['X-Total-Count'] = (string) $total;
            $lastPage = (int) ceil($total / $perPage);
            if ($paginationRequested) {
                $headers['X-Page'] = (string) $page;
                $headers['X-Per-Page'] = (string) $perPage;
                $headers['X-Total-Pages'] = (string) $lastPage;
                $meta = [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                ];
            } else {
                $meta = ['total' => $total];
            }
        }

        if ($request->has('select')) {
            $selectParam = $request->query('select');
            $includes = RelationshipResolverUtils::parseSelectForIncludes($selectParam);
            $useSubqueryOptimization = RecordConfigService::useSubqueryOptimization() && count($data) <= 100;

            // Disable subquery optimization if nested filters, child relationships,
            // or database-specific limitations (e.g. PostgreSQL json_build_object argument limit) are detected
            if ($useSubqueryOptimization) {
                foreach ($includes as $alias => $include) {
                    // Check for child relationships (recursion)
                    if (!empty($include['children'])) {
                        $useSubqueryOptimization = false;

                        break;
                    }

                    // Check relationship type to avoid Postgres limit on json_build_object arguments
                    // and to follow "N+1" pattern for complex relationships as requested
                    $relConfig = RelationshipResolverUtils::resolveRelationship($table, $alias);
                    if ($relConfig && in_array($relConfig['type'], ['belongsToMany', 'morphToMany', 'hasManyThrough'])) {
                        $useSubqueryOptimization = false;

                        break;
                    }

                    // For PostgreSQL, avoid json_build_object with more than 50 columns
                    // (100 arguments limit: key + value per column). Fallback to includeRelationships
                    if ($relConfig && 'pgsql' === DB::getDriverName()) {
                        $schema = SchemaRegistryUtils::get();
                        $relatedTable = $relConfig['table'] ?? null;
                        $relatedSchema = $relatedTable && isset($schema[$relatedTable]) ? $schema[$relatedTable] : null;

                        if ($relatedSchema && is_array($relatedSchema->columns ?? null)) {
                            $requestedCols = $include['columns'] ?? ['*'];
                            if ($requestedCols === ['*'] || [] === $requestedCols) {
                                $columnCount = count($relatedSchema->columns ?? []);
                            } else {
                                $columnCount = 0;
                                foreach ($requestedCols as $col) {
                                    if (!is_string($col)) {
                                        continue;
                                    }

                                    if (str_contains($col, '=')) {
                                        continue;
                                    }

                                    if (isset($relatedSchema->columns[$col])) {
                                        ++$columnCount;
                                    }
                                }
                            }

                            if ($columnCount > 50) {
                                $useSubqueryOptimization = false;

                                break;
                            }
                        }
                    }

                    // Check for filters in columns
                    if (isset($include['columns']) && is_array($include['columns'])) {
                        foreach ($include['columns'] as $col) {
                            if (str_contains((string) $col, '=')) {
                                $useSubqueryOptimization = false;

                                break 2;
                            }
                        }
                    }
                }
            }

            if ($useSubqueryOptimization && [] !== $includes) {
                $primaryKey = $tableSchema->primaryKey ?? 'id';
                $recordIds = self::extractRecordIds($data, $primaryKey);

                if ([] !== $recordIds) {
                    $optimizedBuilder = DB::table($actualTableName);
                    $mainCols = RelationshipResolverUtils::getMainTableColumns($selectParam);
                    // Strip computed attribute keys — they are not real DB columns
                    $attributeKeys = array_keys($tableSchema->attributes ?? []);
                    $dbMainCols = $attributeKeys !== []
                        ? array_values(array_diff($mainCols, $attributeKeys))
                        : $mainCols;
                    if ([] !== $dbMainCols) {
                        $prefixedCols = array_map(fn($col) => '*' === $col ? $actualTableName . '.*' : (str_contains((string) $col, '.') ? $col : $actualTableName . '.' . $col), $dbMainCols);
                        $optimizedBuilder->select($prefixedCols);
                    } else {
                        $optimizedBuilder->select($actualTableName . '.*');
                    }

                    $this->applyTenantFilter($optimizedBuilder, $actualTableName, $tenantId);

                    if ($tableSchema->softDeletes) {
                        $optimizedBuilder->whereNull($actualTableName . '.deleted_at');
                    }

                    RelationshipResolverUtils::applySubqueryRelationships(
                        $optimizedBuilder,
                        $table,
                        $includes,
                        $this->shouldApplyTenantId($tableSchema) ? $tenantId : null
                    );

                    // Re-apply sorting to optimized query to ensure consistent order
                    QueryBuilderFiltersUtils::applySort($optimizedBuilder, $request, $actualTableName, $tableSchema->primaryKey ?? 'id');

                    $optimizedData = $optimizedBuilder->whereIn($actualTableName . '.' . $primaryKey, $recordIds)->get()->all();
                    $data = RelationshipResolverUtils::processJsonRelationships($optimizedData, $includes, $table);
                }
            } else {
                $data = RelationshipResolverUtils::includeRelationships(
                    $data,
                    $table,
                    $selectParam,
                    $this->shouldApplyTenantId($tableSchema) ? $tenantId : null
                );
            }
        }

        $data = RecordApiResponseService::removeDeletedAtFields($data);
        $data = RecordApiResponseService::removeHiddenFields($data, $table);
        $data = RecordApiResponseService::convertCompositeFields($data, $table);
        $data = RecordApiResponseService::applyCasts($data, $tableSchema->columns ?? [], $tableSchema->casting ?? []);
        if (!empty($tableSchema->attributes)) {
            $requestedCols = $request->has('select')
                ? RelationshipResolverUtils::getMainTableColumns($request->query('select'))
                : [];
            $data = RecordApiResponseService::applyAttributes($data, $table, $tableSchema->attributes, $requestedCols);
        }

        if ($isCacheable && $cacheKey) {
            $cacheData = [
                'data' => $data,
                'meta' => $meta,
                'headers' => $headers,
                'cached_at' => TimeUtils::now()->toISOString(),
                'tenant_enabled' => $this->shouldApplyTenantId($tableSchema),
            ];
            $ttl = $this->calculateOptimalCacheTTL($table, count($data), $request->has('select'));
            QueryCacheService::put($cacheKey, $cacheData, $ttl);
        }

        if ($this->shouldIncludeDebug($request)) {
            $meta['debug']['lazy_stats'] = QueryBuilderFiltersUtils::getLazyStats();
        }

        return [
            'data' => $data,
            'meta' => $meta,
            'headers' => $headers,
            'filters' => $filters,
            'request' => $request,
        ];
    }

    /**
     * Execute a dynamic query against a table using an array or query string of parameters.
     * This allows developers to fetch data in business logic using REST API syntax.
     *
     * @param string $table The table name
     * @param array|string $queryParams The query parameters (e.g., ['select' => '*,category(*)'] or 'select=*,category(*)&status=active')
     * @param mixed $tenantId Optional tenant ID
     * @param bool $isArray Whether to return the result as a flat array of rows
     * @param string $orderBy Default column to use for ordering
     * @return array The query results (data, meta, etc.)
     */
    public static function executeGetByFilter(string $table, array|string $queryParams = [], mixed $tenantId = null, bool $isArray = true, string $orderBy = 'id'): array
    {
        if (is_string($queryParams)) {
            parse_str($queryParams, $parsedParams);
            $queryParams = $parsedParams;
        }

        $request = new Request($queryParams);
        
        if ($tenantId !== null) {
            $request->attributes->set('resolved_tenant_id', $tenantId);
        }

        return self::applyRequestFilters($request, $table, $tenantId, $isArray, $orderBy);
    }

    /**
     * Get a single record by ID with dynamic query parameters (like select for relationships).
     *
     * @param string $table The table name
     * @param mixed $id The primary key value
     * @param array|string $queryParams The query parameters (e.g., ['select' => '*,category(*)'])
     * @param mixed $tenantId Optional tenant ID
     * @return array The query results (data, meta, etc.)
     */
    public static function executeGetById(string $table, mixed $id, array|string $queryParams = [], mixed $tenantId = null): array
    {
        if (is_string($queryParams)) {
            parse_str($queryParams, $parsedParams);
            $queryParams = $parsedParams;
        }

        $schema = SchemaRegistryUtils::get();
        $tableSchema = $schema[$table] ?? null;
        $pk = $tableSchema->primaryKey ?? 'id';

        // Add the ID filter to the query parameters
        $queryParams[$pk] = $id;

        $request = new Request($queryParams);
        
        if ($tenantId !== null) {
            $request->attributes->set('resolved_tenant_id', $tenantId);
        }

        return self::applyRequestFilters($request, $table, $tenantId, false);
    }

    /**
     * Create a record and return it fully loaded with relationships (including those from the payload).
     *
     * @param string $table The table name
     * @param array $payload The data to insert (can include nested relationships)
     * @param array|string $queryParams The query parameters (e.g., ['select' => '*,category(*)'])
     * @param mixed $tenantId Optional tenant ID
     * @return array The query results (data, meta, etc.)
     */
    public static function executeCreate(string $table, array $payload, array|string $queryParams = [], mixed $tenantId = null): array
    {
        $service = app(self::class);
        
        // Extract relationship keys from payload to automatically include them
        $includes = [];
        foreach ($payload as $key => $value) {
            if (is_array($value) && RelationshipResolverUtils::resolveRelationship($table, $key)) {
                $includes[] = $key . '(*)';
            }
        }
        
        if (is_string($queryParams)) {
            parse_str($queryParams, $parsedParams);
            $queryParams = $parsedParams;
        }
        
        // Merge payload relationships into select query param
        if (!empty($includes)) {
            $existingSelect = $queryParams['select'] ?? '';
            $selectArray = $existingSelect ? explode(',', (string) $existingSelect) : ['*'];
            $queryParams['select'] = implode(',', array_unique(array_merge($selectArray, $includes)));
        }

        $result = $service->createRecord($table, $payload, $tenantId);
        
        return self::executeGetById($table, $result['id'], $queryParams, $tenantId);
    }

    /**
     * Update a record and return it fully loaded with relationships (including those from the payload).
     *
     * @param string $table The table name
     * @param mixed $id The primary key value
     * @param array $payload The data to update (can include nested relationships)
     * @param array|string $queryParams The query parameters (e.g., ['select' => '*,category(*)'])
     * @param mixed $tenantId Optional tenant ID
     * @return array The query results (data, meta, etc.)
     */
    public static function executeUpdate(string $table, mixed $id, array $payload, array|string $queryParams = [], mixed $tenantId = null): array
    {
        $service = app(self::class);
        
        // Extract relationship keys from payload to automatically include them
        $includes = [];
        foreach ($payload as $key => $value) {
            if (is_array($value) && RelationshipResolverUtils::resolveRelationship($table, $key)) {
                $includes[] = $key . '(*)';
            }
        }
        
        if (is_string($queryParams)) {
            parse_str($queryParams, $parsedParams);
            $queryParams = $parsedParams;
        }
        
        // Merge payload relationships into select query param
        if (!empty($includes)) {
            $existingSelect = $queryParams['select'] ?? '';
            $selectArray = $existingSelect ? explode(',', (string) $existingSelect) : ['*'];
            $queryParams['select'] = implode(',', array_unique(array_merge($selectArray, $includes)));
        }

        $service->updateRecord($table, $id, $payload, $tenantId);
        
        return self::executeGetById($table, $id, $queryParams, $tenantId);
    }

    /**
     * Delete a record and return it fully loaded with relationships.
     *
     * @param string $table The table name
     * @param mixed $id The primary key value
     * @param array|string $queryParams The query parameters (e.g., ['select' => '*,category(*)'])
     * @param mixed $tenantId Optional tenant ID
     * @return array The query results (data, meta, etc.) containing the record before deletion
     */
    public static function executeDelete(string $table, mixed $id, array|string $queryParams = [], mixed $tenantId = null): array
    {
        $service = app(self::class);
        
        if (is_string($queryParams)) {
            parse_str($queryParams, $parsedParams);
            $queryParams = $parsedParams;
        }
        
        // Fetch the record before deleting it
        $record = self::executeGetById($table, $id, $queryParams, $tenantId);
        
        $service->deleteRecord($table, $id, $tenantId);
        
        return $record;
    }

    /**
     * Build a query builder instance based on dynamic query parameters.
     * Note: This applies filters and sorting, but does NOT apply 'select' relationships
     * since relationships are processed after the main query execution.
     *
     * @param string $table The table name
     * @param array|string $queryParams The query parameters
     * @param mixed $tenantId Optional tenant ID
     */
    public function buildQuery(string $table, array|string $queryParams = [], mixed $tenantId = null): Builder
    {
        if (is_string($queryParams)) {
            parse_str($queryParams, $parsedParams);
            $queryParams = $parsedParams;
        }

        $request = new Request($queryParams);
        
        $schema = SchemaRegistryUtils::get();
        $tableSchema = $schema[$table] ?? null;
        $actualTableName = $tableSchema->table ?? $table;

        $builder = DB::table($actualTableName);

        $this->applyTenantFilter($builder, $actualTableName, $tenantId);

        if ($tableSchema && $tableSchema->softDeletes) {
            if ($request->boolean('only_trashed')) {
                $builder->whereNotNull($actualTableName . '.deleted_at');
            } elseif (!$request->boolean('with_trashed')) {
                $builder->whereNull($actualTableName . '.deleted_at');
            }
        }

        if ($request->boolean('distinct')) {
            $builder->distinct();
        }

        QueryBuilderFiltersUtils::apply($builder, $request, $actualTableName, $tableSchema->primaryKey ?? 'id');

        return $builder;
    }

    /**
     * Helper method to handle common record query logic.
     *
     * @param Request                        $request        The HTTP request object.
     * @param Builder|RecordTableType|string $tableOrBuilder The table name, query builder, or table config.
     * @param null|string                    $tanentColumn   The tenant column name (optional).
     * @param bool                           $isArray        Whether to return the result as a flat array of rows.
     * @param string                         $orderBy        Default column to use for ordering when no sortby is provided.
     *
     * @return array{
     *     data: mixed,
     *     meta: array,
     *     headers: array,
     *     filters: array,
     *     request: Request,
     *     cursor_meta: mixed
     * }
     */
    public static function applyRequestFilters(Request $request, Builder|RecordTableType|string $tableOrBuilder, ?string $tanentColumn = '', bool $isArray = true, string $orderBy = 'id'): array
    {
        $service = app(self::class);
        $builder = null;
        $table = '';
        $customSchema = null;

        if ($tableOrBuilder instanceof Builder) {
            $builder = $tableOrBuilder;
            $table = $builder->from;
        } elseif ($tableOrBuilder instanceof RecordTableType) {
            $customSchema = $tableOrBuilder;
            if (is_string($customSchema->table) && '' !== trim($customSchema->table)) {
                $table = $customSchema->table;
            } elseif (is_string($customSchema->pmsName) && '' !== trim($customSchema->pmsName)) {
                $table = trim($customSchema->pmsName);
            } elseif (is_array($customSchema->pmsName) && [] !== $customSchema->pmsName) {
                $firstAlias = $customSchema->pmsName[0] ?? null;
                $table = is_string($firstAlias) ? trim($firstAlias) : '';
            } else {
                $table = '';
            }

            // Register custom schema to make it available for QueryBuilderFiltersUtils
            // Ensure we register under the actual table name as that's what QueryBuilderFiltersUtils looks up
            $registerKey = $customSchema->table ?? $table;
            SchemaRegistryUtils::register($registerKey, $customSchema);

            $aliases = [];
            if (is_string($customSchema->pmsName) && '' !== trim($customSchema->pmsName)) {
                $aliases[] = trim($customSchema->pmsName);
            } elseif (is_array($customSchema->pmsName)) {
                foreach ($customSchema->pmsName as $candidate) {
                    if (!is_string($candidate)) {
                        continue;
                    }

                    $candidate = trim($candidate);
                    if ('' === $candidate) {
                        continue;
                    }

                    $aliases[] = $candidate;
                }
            }

            foreach (array_values(array_unique($aliases)) as $alias) {
                if ($alias !== $registerKey) {
                    SchemaRegistryUtils::register($alias, $customSchema);
                }
            }
        } else {
            $table = $tableOrBuilder;
        }

        $tableSchema = $customSchema ?? SchemaRegistryUtils::getTable($table);
        $actualTableName = $tableSchema->table ?? $table;
        $tenantId = $tanentColumn;
        if ($tableSchema instanceof RecordTableType && $service->shouldApplyTenantId($tableSchema) && RecordUtils::isTenantIdMissing($tenantId)) {
            $tenantId = RecordUtils::resolveTenantIdFromRequest($request);
        }

        $filters = $request->except(['page', 'per_page', 'limit']);
        $includes = $request->query('select', []);
        if (is_string($includes)) {
            $includes = explode(',', $includes);
        }

        $page = max((int) $request->get('page', 1), 1);
        $perPage = $request->has('per_page') ? max(1, min((int) $request->get('per_page', 25), RecordConfigService::perPageMax())) : null;
        $limit = $request->has('limit') ? max(1, min((int) $request->get('limit'), RecordConfigService::limitMax())) : RecordConfigService::limitMax();

        // Disable cache if using builder as we can't easily key the builder state
        $isCacheable = !$builder && $service->isCacheableRequest($request, $table);
        $cacheKey = null;

        if ($isCacheable) {
            $tenantEnabled = $tableSchema instanceof RecordTableType && $service->shouldApplyTenantId($tableSchema);
            $cacheFilters = $filters;
            $cacheFilters['tenant_enabled'] = $tenantEnabled;
            if ($tenantEnabled) {
                $cacheFilters[RecordConfigService::tenantColumn()] = $tanentColumn;
            }

            $cacheKey = $service->generateOptimizedCacheKey(
                table: $table,
                filters: $cacheFilters,
                includes: $includes,
                page: $page,
                limit: $perPage ?? $limit,
                tenantEnabled: $tenantEnabled
            );

            $cached = QueryCacheService::get($cacheKey);
            if (null !== $cached) {
                return array_merge($cached, ['from_cache' => true, 'filters' => $filters, 'request' => $request]);
            }
        }

        if (!$builder instanceof Builder) {
            $builder = DB::table($actualTableName);
            $service->applyTenantFilter($builder, $actualTableName, $tenantId);

            if ($tableSchema instanceof RecordTableType && $tableSchema->softDeletes) {
                if ($request->boolean('only_trashed')) {
                    $builder->whereNotNull($actualTableName . '.deleted_at');
                } elseif (!$request->boolean('with_trashed')) {
                    $builder->whereNull($actualTableName . '.deleted_at');
                }
            }
        } else {
            $service->applyTenantFilter($builder, $actualTableName, $tenantId);

            if ($tableSchema instanceof RecordTableType && $tableSchema->softDeletes) {
                if ($request->boolean('only_trashed')) {
                    $builder->whereNotNull($actualTableName . '.deleted_at');
                } elseif (!$request->boolean('with_trashed')) {
                    $builder->whereNull($actualTableName . '.deleted_at');
                }
            }
        }

        if ($request->boolean('distinct')) {
            $builder->distinct();
        }

        $defaultOrderBy = $tableSchema->primaryKey ?? 'id';
        if ('' !== $orderBy && 'id' !== $orderBy) {
            $defaultOrderBy = $orderBy;
        }

        QueryBuilderFiltersUtils::apply($builder, $request, $actualTableName, $defaultOrderBy);

        $headers = [];
        $meta = [];
        $data = [];
        $total = 0;

        $aggregateResult = QueryBuilderFiltersUtils::applyAggregateAndGroupBy($builder, $request, $actualTableName);

        if (null !== $aggregateResult) {
            $data = $aggregateResult['data'];
            $meta = $aggregateResult['meta'];
            $headers = $aggregateResult['headers'];
        } elseif ($request->has('limit') && !$request->has('per_page')) {
            $limit = max(1, min((int) $request->get('limit'), RecordConfigService::limitMax()));
            $data = $builder->limit($limit)->get()->all();
            $total = count($data);
            $headers['X-Total-Count'] = (string) $total;
            $meta = ['total' => $total];
        } else {
            $paginationRequested = $request->has('page') || $request->has('per_page');
            $maxPerPage = RecordConfigService::perPageMax();
            $perPage = max(1, min((int) $request->get('per_page', RecordConfigService::limitMax()), $maxPerPage));

            $page = max((int) $request->get('page', 1), 1);
            $countQuery = clone $builder;
            $total = $countQuery->count();
            $data = $builder->forPage($page, $perPage)->get()->all();

            $headers['X-Total-Count'] = (string) $total;
            $lastPage = (int) ceil($total / $perPage);
            if ($paginationRequested) {
                $headers['X-Page'] = (string) $page;
                $headers['X-Per-Page'] = (string) $perPage;
                $headers['X-Total-Pages'] = (string) $lastPage;
                $meta = [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                ];
            } else {
                $meta = ['total' => $total];
            }
        }

        if ($request->has('select')) {
            $selectParam = $request->query('select');
            $includes = RelationshipResolverUtils::parseSelectForIncludes($selectParam);
            $useSubqueryOptimization = RecordConfigService::useSubqueryOptimization() && count($data) <= 100;

            // Disable subquery optimization if nested filters, child relationships,
            // or database-specific limitations (e.g. PostgreSQL json_build_object argument limit) are detected
            if ($useSubqueryOptimization) {
                foreach ($includes as $alias => $include) {
                    if (!empty($include['children'])) {
                        $useSubqueryOptimization = false;

                        break;
                    }

                    // Check relationship type to avoid Postgres limit on json_build_object arguments
                    // and to follow "N+1" pattern for complex relationships as requested
                    $relConfig = RelationshipResolverUtils::resolveRelationship($table, $alias);
                    if ($relConfig && in_array($relConfig['type'], ['belongsToMany', 'morphToMany', 'hasManyThrough'])) {
                        $useSubqueryOptimization = false;

                        break;
                    }

                    // For PostgreSQL, avoid json_build_object with more than 50 columns
                    // (100 arguments limit: key + value per column). Fallback to includeRelationships
                    if ($relConfig && 'pgsql' === DB::getDriverName()) {
                        $schema = SchemaRegistryUtils::get();
                        $relatedTable = $relConfig['table'] ?? null;
                        $relatedSchema = $relatedTable && isset($schema[$relatedTable]) ? $schema[$relatedTable] : null;

                        if ($relatedSchema && is_array($relatedSchema->columns ?? null)) {
                            $requestedCols = $include['columns'] ?? ['*'];
                            if ($requestedCols === ['*'] || [] === $requestedCols) {
                                $columnCount = count($relatedSchema->columns ?? []);
                            } else {
                                $columnCount = 0;
                                foreach ($requestedCols as $col) {
                                    if (!is_string($col)) {
                                        continue;
                                    }

                                    if (str_contains($col, '=')) {
                                        continue;
                                    }

                                    if (isset($relatedSchema->columns[$col])) {
                                        ++$columnCount;
                                    }
                                }
                            }

                            if ($columnCount > 50) {
                                $useSubqueryOptimization = false;

                                break;
                            }
                        }
                    }

                    if (isset($include['columns']) && is_array($include['columns'])) {
                        foreach ($include['columns'] as $col) {
                            if (str_contains((string) $col, '=')) {
                                $useSubqueryOptimization = false;

                                break 2;
                            }
                        }
                    }
                }
            }

            if ($useSubqueryOptimization && [] !== $includes) {
                $primaryKey = $tableSchema->primaryKey ?? 'id';
                $recordIds = self::extractRecordIds($data, $primaryKey);

                if ([] !== $recordIds) {
                    $optimizedBuilder = DB::table($actualTableName);
                    $mainCols = RelationshipResolverUtils::getMainTableColumns($selectParam);
                    // Strip computed attribute keys — they are not real DB columns
                    $attributeKeys = $tableSchema instanceof RecordTableType ? array_keys($tableSchema->attributes ?? []) : [];
                    $dbMainCols = $attributeKeys !== []
                        ? array_values(array_diff($mainCols, $attributeKeys))
                        : $mainCols;
                    if ([] !== $dbMainCols) {
                        $prefixedCols = array_map(fn($col) => '*' === $col ? $actualTableName . '.*' : (str_contains((string) $col, '.') ? $col : $actualTableName . '.' . $col), $dbMainCols);
                        $optimizedBuilder->select($prefixedCols);
                    } else {
                        $optimizedBuilder->select($actualTableName . '.*');
                    }

                    $service->applyTenantFilter($optimizedBuilder, $actualTableName, $tenantId);

                    if ($tableSchema instanceof RecordTableType && $tableSchema->softDeletes) {
                        $optimizedBuilder->whereNull($actualTableName . '.deleted_at');
                    }

                    RelationshipResolverUtils::applySubqueryRelationships(
                        $optimizedBuilder,
                        $table,
                        $includes,
                        $tableSchema instanceof RecordTableType && $service->shouldApplyTenantId($tableSchema) ? $tenantId : null
                    );

                    // Re-apply sorting to optimized query to ensure consistent order
                    QueryBuilderFiltersUtils::applySort($optimizedBuilder, $request, $actualTableName, $tableSchema->primaryKey ?? 'id');

                    $optimizedData = $optimizedBuilder->whereIn($actualTableName . '.' . $primaryKey, $recordIds)->get()->all();
                    $data = RelationshipResolverUtils::processJsonRelationships($optimizedData, $includes, $table);
                }
            } else {
                $data = RelationshipResolverUtils::includeRelationships(
                    $data,
                    $table,
                    $selectParam,
                    $tableSchema instanceof RecordTableType && $service->shouldApplyTenantId($tableSchema) ? $tenantId : null
                );
            }
        }

        $data = RecordApiResponseService::removeDeletedAtFields($data);
        $data = RecordApiResponseService::removeHiddenFields($data, $table);
        $data = RecordApiResponseService::convertCompositeFields($data, $table);
        $data = RecordApiResponseService::applyCasts($data, $tableSchema instanceof RecordTableType ? ($tableSchema->columns ?? []) : [], $tableSchema instanceof RecordTableType ? ($tableSchema->casting ?? []) : []);
        if ($tableSchema instanceof RecordTableType && !empty($tableSchema->attributes)) {
            $requestedCols = $request->has('select')
                ? RelationshipResolverUtils::getMainTableColumns($request->query('select'))
                : [];
            $data = RecordApiResponseService::applyAttributes($data, $table, $tableSchema->attributes, $requestedCols);
        }

        if ($isCacheable && $cacheKey) {
            $cacheData = [
                'data' => $data,
                'meta' => $meta,
                'headers' => $headers,
                'cached_at' => TimeUtils::now()->toISOString(),
                'tenant_enabled' => $tableSchema instanceof RecordTableType && $service->shouldApplyTenantId($tableSchema),
            ];
            $ttl = $service->calculateOptimalCacheTTL($table, count($data), $request->has('select'));
            QueryCacheService::put($cacheKey, $cacheData, $ttl);
        }

        if ($service->shouldIncludeDebug($request)) {
            $meta['debug']['lazy_stats'] = QueryBuilderFiltersUtils::getLazyStats();
        }

        if ($isArray === false) {
            $data = $data[0] ?? [];
        }

        return [
            'data' => $data,
            'meta' => $meta,
            'headers' => $headers,
            'filters' => $filters,
            'request' => $request,
        ];
    }


    /**
     * Get a single record by ID.
     */
    public function getRecord(Request $request, string $table, mixed $id, mixed $tenantId): array
    {
        $schema = SchemaRegistryUtils::get();
        $tableSchema = $schema[$table];

        $triggerParams = [
            $request,
            $table,
            [
                'type' => 'show',
                'id' => $id,
                RecordConfigService::tenantColumn() => $tenantId,
            ],
        ];
        $triggerParams = $this->executeTableTrigger($tableSchema->beforeRead ?? null, $triggerParams);
        if (isset($triggerParams[0]) && $triggerParams[0] instanceof Request) {
            $request = $triggerParams[0];
        }

        $actualTableName = $tableSchema->table ?? $table;
        $pk = $tableSchema->primaryKey ?? 'id';

        $tenantEnabled = $this->shouldApplyTenantId($tableSchema);
        $recordCacheKey = $this->generateRecordCacheKey(
            table: $table,
            id: $id,
            tenantId: $tenantId,
            select: $request->query('select'),
            tenantEnabled: $tenantEnabled
        );
        if ($this->isCacheableRequest(request: $request, table: $table)) {
            $cachedRecord = QueryCacheService::get($recordCacheKey);
            if ($cachedRecord) {
                return ['data' => $cachedRecord, 'request' => $request, 'from_cache' => true];
            }
        }

        $builder = DB::table($actualTableName);
        $this->applyTenantFilter($builder, $actualTableName, $tenantId);

        if ($tableSchema->softDeletes && !$request->boolean('with_trashed')) {
            $builder->whereNull($actualTableName . '.deleted_at');
        }

        $mainCols = [];
        $dbMainCols = [];
        if ($request->has('select')) {
            $mainCols = RelationshipResolverUtils::getMainTableColumns($request->query('select'));
            // Build DB-safe column list: strip computed attribute keys (not real DB columns)
            $attributeKeys = array_keys($tableSchema->attributes ?? []);
            $dbMainCols = $attributeKeys !== []
                ? array_values(array_diff($mainCols, $attributeKeys))
                : $mainCols;
            if ([] !== $dbMainCols && !in_array('*', $dbMainCols, true)) {
                $builder->addSelect($dbMainCols);
            }
        }

        $record = $builder->where($pk, $id)->first();
        if (!$record) {
            return ['data' => null, 'request' => $request];
        }

        if ($request->has('select')) {
            $selectParam = $request->query('select');
            $includes = RelationshipResolverUtils::parseSelectForIncludes($selectParam);
            $useSubqueryOptimization = RecordConfigService::useSubqueryOptimization();

            // Disable subquery optimization if nested filters or child relationships are detected
            if ($useSubqueryOptimization) {
                foreach ($includes as $alias => $include) {
                    if (!empty($include['children'])) {
                        $useSubqueryOptimization = false;

                        break;
                    }

                    // Check relationship type to avoid Postgres limit on json_build_object arguments
                    // and to follow "N+1" pattern for complex relationships as requested
                    $relConfig = RelationshipResolverUtils::resolveRelationship($table, $alias);
                    if ($relConfig && in_array($relConfig['type'], ['belongsToMany', 'morphToMany', 'hasManyThrough'])) {
                        $useSubqueryOptimization = false;

                        break;
                    }

                    if (isset($include['columns']) && is_array($include['columns'])) {
                        foreach ($include['columns'] as $col) {
                            if (str_contains((string) $col, '=')) {
                                $useSubqueryOptimization = false;

                                break 2;
                            }
                        }
                    }
                }
            }

            if ($useSubqueryOptimization && [] !== $includes) {
                $optimizedBuilder = DB::table($actualTableName);
                if ([] !== $dbMainCols) {
                    $prefixedCols = array_map(fn($col) => '*' === $col ? $actualTableName . '.*' : (str_contains((string) $col, '.') ? $col : $actualTableName . '.' . $col), $dbMainCols);
                    $optimizedBuilder->select($prefixedCols);
                } else {
                    $optimizedBuilder->select($actualTableName . '.*');
                }

                $this->applyTenantFilter($optimizedBuilder, $actualTableName, $tenantId);

                if ($tableSchema->softDeletes) {
                    $optimizedBuilder->whereNull($actualTableName . '.deleted_at');
                }

                RelationshipResolverUtils::applySubqueryRelationships(
                    $optimizedBuilder,
                    $table,
                    $includes,
                    $this->shouldApplyTenantId($tableSchema) ? $tenantId : null
                );

                $optimizedRecord = $optimizedBuilder->where($pk, $id)->first();

                if ($optimizedRecord) {
                    $processedData = RelationshipResolverUtils::processJsonRelationships([$optimizedRecord], $includes, $table);
                    $record = $processedData[0] ?? $record;
                }
            } else {
                $data = RelationshipResolverUtils::includeRelationships(
                    [$record],
                    $table,
                    $selectParam,
                    $this->shouldApplyTenantId($tableSchema) ? $tenantId : null
                );
                $record = $data[0] ?? $record;
            }
        }

        $record = RecordApiResponseService::removeDeletedAtFields($record);
        $record = RecordApiResponseService::convertCompositeFields($record, $table);
        $record = RecordApiResponseService::applyCasts($record, $tableSchema->columns ?? [], $tableSchema->casting ?? []);
        if (!empty($tableSchema->attributes)) {
            $requestedCols = $request->has('select')
                ? RelationshipResolverUtils::getMainTableColumns($request->query('select'))
                : [];
            $record = RecordApiResponseService::applyAttributes($record, $table, $tableSchema->attributes, $requestedCols);
        }

        if ($this->isCacheableRequest($request, $table)) {
            $ttl = $this->calculateOptimalCacheTTL($table, 1, $request->has('select'));
            QueryCacheService::put($recordCacheKey, $record, $ttl);
        }

        return ['data' => $record, 'request' => $request];
    }

    /**
     * Execute a custom function based on its configuration.
     */
    private function executeCustomFunction(Request $request, array|RecordFunctionType $functionConfig, mixed $id = null): JsonResponse
    {
        if ($functionConfig instanceof RecordFunctionType) {
            $config = $functionConfig->toArray();
        } else {
            $config = $functionConfig;
        }

        if (!array_key_exists('isPublic', $config) && (!array_key_exists('pmsName', $config) || $config['pmsName'] === null)) {
            $config['isPublic'] = true;
        }

        $isPublic = (bool)($config['isPublic'] ?? false);
        $pmsName = $config['pmsName'] ?? null;

        $requiresAuth = !$isPublic || (null !== $pmsName && '' !== $pmsName && [] !== $pmsName);

        if ($requiresAuth) {
            $guard = RecordConfigService::authGuard();
            $user = auth($guard)->user();
            if (!$user) {
                return RecordApiResponseService::errorWrapped(message: 'Authentication required', status: RecordApiJsonResponseEnum::UNAUTHORIZED->value);
            }

            if (null !== $pmsName && '' !== $pmsName && [] !== $pmsName) {
                $permissions = is_array($pmsName) ? $pmsName : [$pmsName];
                $hasPermission = false;
                $gate = Gate::forUser($user);

                foreach ($permissions as $permission) {
                    if ($gate->allows($permission)) {
                        $hasPermission = true;

                        break;
                    }
                }

                if (!$hasPermission) {
                    return RecordApiResponseService::errorWrapped(message: 'Insufficient permissions', status: RecordApiJsonResponseEnum::FORBIDDEN->value);
                }
            }
        }

        // Validate HTTP method if specified
        if (isset($config['httpMethod'])) {
            $allowedMethods = is_array($config['httpMethod']) ? $config['httpMethod'] : [$config['httpMethod']];
            $allowedMethods = array_map(function (mixed $method): string {
                if ($method instanceof BackedEnum) {
                    $method = $method->value;
                } elseif ($method instanceof UnitEnum) {
                    $method = $method->name;
                }

                return strtoupper((string) $method);
            }, $allowedMethods);

            if (!in_array(strtoupper($request->method()), $allowedMethods, true)) {
                return RecordApiResponseService::errorWrapped(message: sprintf("Method '%s' not allowed for this function", $request->method()), status: RecordApiJsonResponseEnum::METHOD_NOT_ALLOWED->value);
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
                return RecordApiResponseService::errorWrapped(message: 'Missing required parameters', status: RecordApiJsonResponseEnum::ERROR->value, errors: ['missing' => $missingParams]);
            }
        }

        return $this->executeClassFunction(request: $request, functionConfig: $config, id: $id);
    }

    /**
     * Execute a class-based custom function.
     */
    private function executeClassFunction(Request $request, array $functionConfig, mixed $id = null): JsonResponse
    {
        try {
            $className = $functionConfig['class'] ?? null;
            $method = $functionConfig['functionName'] ?? 'handle';

            if (!$className || !class_exists($className)) {
                return RecordApiResponseService::errorWrapped(message: sprintf("Class '%s' does not exist", $className), status: RecordApiJsonResponseEnum::SERVER_ERROR->value);
            }

            $instance = new $className();
            if (!method_exists($instance, $method)) {
                return RecordApiResponseService::errorWrapped(message: sprintf("Method '%s' does not exist in class '%s'", $method, $className), status: RecordApiJsonResponseEnum::SERVER_ERROR->value);
            }

            $result = $id ? $instance->{$method}($request, $id) : $instance->{$method}($request);

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

                $errorCode = null;

                if (is_object($responseData) && property_exists($responseData, 'error_code')) {
                    $errorCode = (int) $responseData->error_code;
                } elseif (is_array($responseData) && array_key_exists('error_code', $responseData)) {
                    $errorCode = (int) $responseData['error_code'];
                }

                if ($statusCode > 204) {
                    $wrapped = RecordApiResponseService::errorWrapped(message: 'string' === gettype($records) ? $records : '', status: $statusCode, errors: 'object' === gettype($responseData) ? (array) $responseData : [], error_code: $errorCode);
                } else {
                    $wrapped = RecordApiResponseService::successWrapped($records, $meta, $result->getStatusCode());
                }

                $originalHeaders = $result->headers;

                if (method_exists($originalHeaders, 'allPreserveCaseWithoutCookies')) {
                    $wrapped->headers->add($originalHeaders->allPreserveCaseWithoutCookies());
                } else {
                    $wrapped->headers->add($originalHeaders->all());
                }

                if (method_exists($originalHeaders, 'getCookies')) {
                    foreach ($originalHeaders->getCookies() as $cookie) {
                        $wrapped->headers->setCookie($cookie);
                    }
                }

                return $wrapped;
            }

            return RecordApiResponseService::successWrapped($result);
        } catch (Exception $exception) {
            return RecordApiResponseService::errorFromException(
                exception: $exception,
                message: 'Function execution failed: ' . $exception->getMessage(),
                status: RecordApiJsonResponseEnum::SERVER_ERROR->value
            );
        }
    }

    private function determineOperation(array $row, string $pk, ?string $legacyAction): string
    {
        if (null !== $legacyAction && '' !== $legacyAction && '0' !== $legacyAction) {
            return $legacyAction;
        }

        if (isset($row['operation'])) {
            return $row['operation'];
        }

        $hasId = isset($row[$pk]) && !empty($row[$pk]);
        $hasOtherFields = [] !== array_diff_key($row, [$pk => true]);

        if (!$hasId) {
            return 'create';
        }

        if ($hasOtherFields) {
            return 'update';
        }

        return 'delete';
    }

    private static function extractRecordIds(array $data, string $primaryKey): array
    {
        $recordIds = [];

        foreach ($data as $item) {
            if (is_array($item) && array_key_exists($primaryKey, $item)) {
                $recordIds[] = $item[$primaryKey];
                continue;
            }

            if (is_object($item) && isset($item->{$primaryKey})) {
                $recordIds[] = $item->{$primaryKey};
            }
        }

        return $recordIds;
    }
}
