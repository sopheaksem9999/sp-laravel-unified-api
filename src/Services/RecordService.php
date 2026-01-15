<?php

namespace Sopheak\Core\Services;

use Exception;
use Sopheak\Core\Interfaces\RecordFunctionInterface;
use Throwable;
use BackedEnum;
use UnitEnum;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Sopheak\Core\Enums\AuditLogEventEnum;
use Sopheak\Core\Enums\RecordApiJsonResponseEnum;
use Sopheak\Core\Support\QueryBuilderFilters;
use Sopheak\Core\Support\RelationshipResolver;
use Sopheak\Core\Support\SchemaRegistry;
use Sopheak\Core\Types\RecordFunctionType;
use Sopheak\Core\Types\RecordTableTriggerType;
use Sopheak\Core\Types\RecordTableType;

class RecordService
{
    /**
     * Create a new record with all related processing.
     *
     * @return array Returns ['id' => mixed, 'payload' => array, 'tenant_id' => mixed]
     */
    public function createRecord(Request $request, string $table, array $payload, mixed $tenantId): array
    {
        $tableSchema = SchemaRegistry::getTable($table);

        // Sanitize payload
        $payloadMain = $this->sanitizePayload($payload, $tableSchema);

        // Handle tenant ID
        if ($this->shouldApplyTenantId($tableSchema)) {
            $payloadMain[config('record.tenant_column', 'tenant_id')] = $this->normalizeTenantId($tenantId);
        }

        // Apply timestamps and audit fields
        $payloadMain = $this->applyTimestampsAndAuditFields($payloadMain, $tableSchema, false);

        // Resolve actual table name
        $actualTableName = $tableSchema->table ?? $table;
        $pk = $tableSchema->primary_key ?? 'id';

        // Insert record
        if (array_key_exists($pk, $payloadMain) && null !== $payloadMain[$pk]) {
            DB::table($actualTableName)->insert($payloadMain);
            $insertedId = $payloadMain[$pk];
        } else {
            $insertedId = DB::table($actualTableName)->insertGetId($payloadMain, $pk);
        }

        // Process nested relationships
        RelationshipResolver::processRelatedData($table, $payload, $insertedId, $tenantId, 'create');

        return [
            'id' => $insertedId,
            'payload' => $payloadMain,
            'tenant_id' => $tenantId,
        ];
    }

    /**
     * Update an existing record with all related processing.
     *
     * @return array Returns ['id' => mixed, 'payload' => array, 'tenant_id' => mixed, 'updated' => int]
     */
    public function updateRecord(Request $request, string $table, mixed $id, array $payload, mixed $tenantId): array
    {
        $tableSchema = SchemaRegistry::getTable($table);

        // Sanitize payload
        $payloadMain = $this->sanitizePayload($payload, $tableSchema);

        // Remove tenant ID from payload for update (security)
        if ($this->shouldApplyTenantId($tableSchema)) {
            unset($payloadMain[config('record.tenant_column', 'tenant_id')]);
        }

        // Apply timestamps and audit fields
        $payloadMain = $this->applyTimestampsAndAuditFields($payloadMain, $tableSchema, true);

        // Resolve actual table name
        $actualTableName = $tableSchema->table ?? $table;
        $pk = $tableSchema->primary_key ?? 'id';

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
            RelationshipResolver::processRelatedData($table, $payload, $id, $tenantId, 'update');
        }

        return [
            'id' => $id,
            'payload' => $payloadMain,
            'tenant_id' => $tenantId,
            'updated' => $updated,
            'exists' => $exists,
        ];
    }

    /**
     * Delete a record with all related processing.
     *
     * @return array Returns ['id' => mixed, 'affected' => int]
     */
    public function deleteRecord(Request $request, string $table, mixed $id, mixed $tenantId): array
    {
        $tableSchema = SchemaRegistry::getTable($table);

        $actualTableName = $tableSchema->table ?? $table;
        $pk = $tableSchema->primary_key ?? 'id';

        $query = DB::table($actualTableName)->where($pk, $id);
        $this->applyTenantFilter($query, $table, $tenantId);
        $affected = $tableSchema->soft_deletes ?? false ? $query->update(['deleted_at' => now()]) : $query->delete();

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
    public function restoreRecord(Request $request, string $table, mixed $id, mixed $tenantId): array
    {
        $tableSchema = SchemaRegistry::getTable($table);

        $actualTableName = $tableSchema->table ?? $table;
        $pk = $tableSchema->primary_key ?? 'id';

        $query = DB::table($actualTableName)->where($pk, $id);
        $this->applyTenantFilter($query, $table, $tenantId);
        $query->whereNotNull($actualTableName.'.deleted_at');

        $restored = $query->update(['deleted_at' => null]);

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
        $tableSchema = SchemaRegistry::getTable($table);

        $actualTableName = $tableSchema->table ?? $table;
        $pk = $tableSchema->primary_key ?? 'id';

        $query = DB::table($actualTableName)->where($pk, $id);
        $this->applyTenantFilter($query, $table, $tenantId);

        $deleted = $query->delete();

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
    public function upsertRecord(Request $request, string $table, array $payload, mixed $tenantId): array
    {
        $tableSchema = SchemaRegistry::getTable($table);

        $actualTableName = $tableSchema->table ?? $table;
        $pk = $tableSchema->primary_key ?? 'id';

        // Sanitize payload
        $item = $this->sanitizePayload($payload, $tableSchema);
        if ($this->shouldApplyTenantId($tableSchema)) {
            $item[config('record.tenant_column', 'tenant_id')] = $this->normalizeTenantId($tenantId);
        }

        // Apply timestamps and audit fields
        $item = $this->applyTimestampsAndAuditFields($item, $tableSchema, true);

        // Upsert requires update columns; exclude primary key and system timestamps
        $updateColumns = array_values(array_diff(array_keys($item), [$pk, 'id', 'created_at', 'deleted_at']));

        DB::table($actualTableName)->upsert([$item], [$pk], $updateColumns);

        return [
            'id' => $item[$pk] ?? null,
            'payload' => $item,
        ];
    }

    /**
     * Execute post-write logic (Triggers and Audit Logs).
     * This should be called after the DB operation (and ideally after commit for single records, or inside transaction for bulk).
     */
    public function processPostWriteLogic(Request $request, string $table, string $operation, array $recordContext): void
    {
        $tableSchema = SchemaRegistry::getTable($table);

        // 1. Execute Table Trigger
        $triggerConfig = match ($operation) {
            'create' => $tableSchema->afterCreate ?? null,
            'update' => $tableSchema->afterUpdate ?? null,
            'delete' => $tableSchema->afterDelete ?? null,
            default => null
        };

        if ($triggerConfig) {
            $this->executeTableTrigger($triggerConfig, [
                $request,
                $table,
                $recordContext,
            ]);
        }

        // 2. Insert Audit Log
        if (!($tableSchema->disable_auditLog ?? false)) {
            $entityClass = 'App\Models\\'.Str::studly(Str::singular($table));
            $event = match ($operation) {
                'create' => AuditLogEventEnum::CREATED,
                'update' => AuditLogEventEnum::UPDATED,
                'delete' => AuditLogEventEnum::DELETED,
                default => AuditLogEventEnum::UPDATED
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

            // Ensure ID is present for delete operations if available in context
            if (!isset($auditData['id']) && isset($recordContext['id'])) {
                $auditData['id'] = $recordContext['id'];
            }

            $tenantId = $recordContext[config('record.tenant_column', 'tenant_id')] ?? null;

            AuditLogService::insertAuditLog($event, $entityClass, $auditData, '', '', $tenantId);
        }
    }

    /**
     * Execute a table-specific custom function.
     */
    public function executeTableFunction(Request $request, string $table, string $functionName): JsonResponse
    {
        // Get schema and validate table exists
        $tableSchema = SchemaRegistry::getTable($table);
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

        // Execute the custom function with extracted ID parameter
        return $this->executeCustomFunction($request, $functionConfig, $extractedId);
    }

    /**
     * Execute a global custom function.
     * Supports patterns like: function_name or function_name/{id}.
     */
    public function executeGlobalFunction(Request $request, string $functionName): JsonResponse
    {
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

        // Execute the custom function with extracted ID parameter
        return $this->executeCustomFunction($request, $functionConfig, $extractedId);
    }

    /**
     * Bulk create, update, or delete records.
     */
    public function bulkRecord(Request $request, string $table, mixed $tenantId, ?string $legacyAction = null): array
    {
        $tableSchema = SchemaRegistry::getTable($table);
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

        $maxBatch = (int) config('record.bulk_max', 100);
        if (count($items) > $maxBatch) {
            throw new Exception('Batch too large, max '.$maxBatch, 413);
        }

        $pk = $tableSchema->primary_key ?? 'id';
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

                    $result = $this->createRecord($request, $table, $item, $tenantId);
                    $insertId = $result['id'];
                    $recordResult = $this->getRecord($request, $table, $insertId, $tenantId);
                    $createdData[] = $recordResult['data'];
                    ++$affected;

                    $this->processPostWriteLogic($request, $table, 'create', [
                        'id' => $insertId,
                        'payload' => $result['payload'],
                        config('record.tenant_column', 'tenant_id') => $result['tenant_id'],
                        'response' => $recordResult['data'],
                    ]);
                } elseif ('update' === $operation) {
                    if (!isset($item[$pk])) {
                        throw new Exception('Primary key required for update');
                    }

                    $id = $item[$pk];
                    unset($item[$pk]);

                    $this->executeTableTrigger($tableSchema->beforeUpdate ?? null, [$request, $table, $id, $item]);

                    $result = $this->updateRecord($request, $table, $id, $item, $tenantId);
                    if ($result['updated'] > 0) {
                        $recordResult = $this->getRecord($request, $table, $id, $tenantId);
                        $updatedData[] = $recordResult['data'];
                        $affected += $result['updated'];

                        $this->processPostWriteLogic($request, $table, 'update', [
                            'id' => $id,
                            'payload' => $result['payload'],
                            config('record.tenant_column', 'tenant_id') => $result['tenant_id'],
                            'updated' => $result['updated'],
                            'response' => $recordResult['data'],
                        ]);
                    }
                } elseif ('delete' === $operation) {
                    if (!isset($item[$pk])) {
                        throw new Exception('Primary key required for delete');
                    }

                    $this->executeTableTrigger($tableSchema->beforeDelete ?? null, [$request, $table, $item[$pk]]);

                    $result = $this->deleteRecord($request, $table, $item[$pk], $tenantId);
                    if ($result['affected'] > 0) {
                        $deletedData[] = ['id' => $item[$pk]];
                        $affected += $result['affected'];

                        $response = ['deleted' => $result['affected']];

                        $this->processPostWriteLogic($request, $table, 'delete', [
                            'id' => $item[$pk],
                            config('record.tenant_column', 'tenant_id') => $tenantId,
                            'affected' => $result['affected'],
                            'soft_deleted' => $tableSchema->soft_deletes ?? false,
                            'response' => $response,
                        ]);
                    }
                } elseif ('upsert' === $operation) {
                    $result = $this->upsertRecord($request, $table, $item, $tenantId);
                    $upsertedId = $result['id'] ?? ($item[$pk] ?? null);

                    if ($upsertedId) {
                        $recordResult = $this->getRecord($request, $table, $upsertedId, $tenantId);
                        $upsertedData[] = $recordResult['data'];
                        ++$affected;

                        if (!($tableSchema->disable_auditLog ?? false)) {
                            $entityClass = 'App\Models\\'.Str::studly(Str::singular($table));
                            AuditLogService::insertAuditLog(AuditLogEventEnum::UPDATED, $entityClass, $recordResult['data'], '', '', $tenantId);
                        }
                    }
                }
            }

            DB::commit();
            QueryCacheService::invalidateTable($table);

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

    public function executeTableTrigger(mixed $trigger, array $params): array
    {
        if (null === $trigger) {
            return $params;
        }

        if ($trigger instanceof RecordTableTriggerType) {
            $triggers = [$trigger];
        } elseif (is_array($trigger)) {
            $isSingleTriggerConfig = isset($trigger['class']) || isset($trigger['function_method']);
            $triggers = $isSingleTriggerConfig ? [$trigger] : $trigger;
        } else {
            throw new Exception(sprintf(
                'Invalid table trigger configuration. Expected %s or array, got %s',
                RecordTableTriggerType::class,
                get_debug_type($trigger)
            ));
        }

        if (!is_array($triggers)) {
            throw new Exception(sprintf(
                'Invalid table trigger configuration. Expected %s or array, got %s',
                RecordTableTriggerType::class,
                get_debug_type($triggers)
            ));
        }

        foreach ($triggers as $index => $item) {
            if ($item instanceof RecordTableTriggerType) {
            } elseif (is_array($item)) {
                try {
                    $item = RecordTableTriggerType::fromArray($item);
                } catch (Throwable $exception) {
                    throw new Exception(sprintf(
                        'Invalid table trigger config at index %s: %s',
                        (string) $index,
                        $exception->getMessage()
                    ), 0, $exception);
                }
            } else {
                throw new Exception(sprintf(
                    'Invalid table trigger item at index %s. Expected %s or array, got %s',
                    (string) $index,
                    RecordTableTriggerType::class,
                    get_debug_type($item)
                ));
            }

            $className = $item->class;
            $method = $item->function_method;
            if (!class_exists($className)) {
                throw new Exception(sprintf("Table trigger class '%s' does not exist", $className));
            }

            if (!method_exists($className, $method)) {
                throw new Exception(sprintf("Table trigger method '%s::%s' does not exist", $className, $method));
            }

            try {
                $result = call_user_func_array([$className, $method], $params);
                if ($result instanceof Request && isset($params[0]) && $params[0] instanceof Request) {
                    $params[0] = $result;
                } elseif (is_array($result) && isset($params[0]) && $params[0] instanceof Request) {
                    $params[0]->merge($result);
                }
            } catch (Throwable $exception) {
                throw new Exception(sprintf(
                    "Table trigger execution failed for '%s::%s': %s",
                    $className,
                    $method,
                    $exception->getMessage()
                ), 0, $exception);
            }
        }

        return $params;
    }

    // --- Helper Methods ---

    public function isCacheableRequest(Request $request, string $table): bool
    {
        if (!config('record.cache.enabled', true)) {
            return false;
        }

        $perTableCache = config('record.cache.per_table', []);
        $schema = SchemaRegistry::get();
        $tableSchema = $schema[$table] ?? null;

        $schemaTableName = null;
        if (is_object($tableSchema)) {
            $schemaTableName = $tableSchema->table ?? null;
        } elseif (is_array($tableSchema)) {
            $schemaTableName = $tableSchema['table'] ?? null;
        }

        if ((isset($perTableCache[$table]) && false === $perTableCache[$table]) || (null !== $schemaTableName && isset($perTableCache[$schemaTableName]) && false === $perTableCache[$schemaTableName])) {
            return false;
        }

        $disableCache = false;
        if (is_object($tableSchema)) {
            $disableCache = (bool) ($tableSchema->disable_cache ?? false);
        } elseif (is_array($tableSchema)) {
            $disableCache = (bool) ($tableSchema['disable_cache'] ?? false);
        }

        if ($disableCache) {
            return false;
        }

        if ('GET' !== $request->method()) {
            return false;
        }

        return !$request->has(['search', 'filter', 'where']);
    }

    public function generateOptimizedCacheKey(string $table, array $filters, array $includes, int $page, int $limit): string
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

    public function generateRecordCacheKey(string $table, mixed $id, mixed $tenantId, mixed $select): string
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

    public function calculateOptimalCacheTTL(string $table, int $recordCount, bool $hasRelationships): int
    {
        $baseTTL = config('record.cache.default_ttl', 3600);
        if ($recordCount > 100) {
            $baseTTL = (int) ($baseTTL * 0.5);
        }

        if ($hasRelationships) {
            $baseTTL = (int) ($baseTTL * 0.7);
        }

        $perTableTTL = config('record.cache.per_table_ttl', []);
        if (isset($perTableTTL[$table])) {
            $baseTTL = $perTableTTL[$table];
        }

        return max($baseTTL, 300);
    }

    public function sanitizePayload(array $input, object $meta): array
    {
        $columns = array_keys($meta->columns ?? []);
        $payload = array_intersect_key($input, array_flip($columns));
        unset($payload['id'], $payload['deleted_at'], $payload['created_at'], $payload['updated_at']);

        return $payload;
    }

    public function applyTimestampsAndAuditFields(array $payload, object $tableSchema, bool $isUpdate = false): array
    {
        $user = auth('api')->user();

        if ($isUpdate) {
            $payload['updated_at'] = now();
            if ($user) {
                if (isset($tableSchema->columns['updated_by'])) {
                    $payload['updated_by'] = $user->id;
                } elseif (isset($tableSchema->columns['last_updated_by'])) {
                    $payload['last_updated_by'] = $user->id;
                }
            }
        } else {
            $payload['created_at'] = now();
            $payload['updated_at'] = now();
            if ($user && isset($tableSchema->columns['created_by'])) {
                $payload['created_by'] = $user->id;
            }
        }

        return $payload;
    }

    public function shouldApplyTenantId(object $tableSchema): bool
    {
        return $this->isTenantIdEnabled() && ($tableSchema->has_tenant_id ?? false);
    }

    public function normalizeTenantId(mixed $tenantId): mixed
    {
        if (is_string($tenantId)) {
            return trim($tenantId);
        }

        return $tenantId;
    }

    public function isTenantIdEnabled(): bool
    {
        return config('record.enable_tenant_id', false);
    }

    public function applyTenantFilter(mixed $query, string $table, mixed $tenantId): void
    {
        $tenantId = $this->normalizeTenantId($tenantId);
        $schema = SchemaRegistry::get();
        if ($this->isTenantIdEnabled() && null !== $tenantId && '' !== $tenantId && ($schema[$table]->has_tenant_id ?? false)) {
            $query->where($table.'.'.config('record.tenant_column', 'tenant_id'), $tenantId);
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
        $schema = SchemaRegistry::get();
        $tableSchema = $schema[$table];

        $triggerParams = [
            $request,
            $table,
            [
                'type' => 'index',
                config('record.tenant_column', 'tenant_id') => $tenantId,
            ],
        ];
        $triggerParams = $this->executeTableTrigger($tableSchema->beforeRead ?? null, $triggerParams);
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
        $perPage = $request->has('per_page') ? max(1, min((int) $request->get('per_page', 25), (int) config('record.per_page_max', 1000))) : null;
        $limit = $request->has('limit') ? max(1, min((int) $request->get('limit'), (int) config('record.limit_max', 1000))) : config('record.limit_max', 1000);

        $isCacheable = $this->isCacheableRequest($request, $table);
        $cacheKey = null;

        if ($isCacheable) {
            $cacheKey = $this->generateOptimizedCacheKey(
                $table,
                array_merge($filters, [
                    config('record.tenant_column', 'tenant_id') => $this->shouldApplyTenantId($tableSchema) ? $tenantId : null,
                    'tenant_enabled' => $this->shouldApplyTenantId($tableSchema),
                ]),
                $includes,
                $page,
                $perPage ?? $limit
            );

            $cached = QueryCacheService::get($cacheKey);
            if (null !== $cached) {
                return array_merge($cached, ['from_cache' => true, 'filters' => $filters, 'request' => $request]);
            }
        }

        $builder = DB::table($actualTableName);

        $this->applyTenantFilter($builder, $actualTableName, $tenantId);

        if ($tableSchema->soft_deletes) {
            if ($request->boolean('only_trashed')) {
                $builder->whereNotNull($actualTableName.'.deleted_at');
            } else {
                $builder->whereNull($actualTableName.'.deleted_at');
            }
        }

        if ($request->boolean('distinct')) {
            $builder->distinct();
        }

        QueryBuilderFilters::apply($builder, $request, $actualTableName, $tableSchema->primary_key ?? 'id');

        $headers = [];
        $meta = [];
        $cursorMeta = null;
        $data = [];
        $total = 0;

        $aggregateResult = QueryBuilderFilters::applyAggregateAndGroupBy($builder, $request, $actualTableName);

        if (null !== $aggregateResult) {
            $data = $aggregateResult['data'];
            $meta = $aggregateResult['meta'];
            $headers = $aggregateResult['headers'];
        } elseif ($request->has('limit') && !$request->has('per_page')) {
            $limit = max(1, min((int) $request->get('limit'), (int) config('record.limit_max', 1000)));
            $data = $builder->limit($limit)->get()->all();
            $total = count($data);
            $headers['X-Total-Count'] = (string) $total;
            $meta = ['total' => $total];
        } else {
            $maxPerPage = (int) config('record.per_page_max', 100);
            $perPage = max(1, min((int) $request->get('per_page', config('record.limit_max', 1000)), $maxPerPage));

            $page = max((int) $request->get('page', 1), 1);
            $countQuery = clone $builder;
            $total = $countQuery->count();

            $data = $builder->forPage($page, $perPage)->get()->all();

            $headers['X-Total-Count'] = (string) $total;
            $lastPage = (int) ceil($total / $perPage);
            $headers['X-Page'] = (string) $page;
            $headers['X-Per-Page'] = (string) $perPage;
            $headers['X-Total-Pages'] = (string) $lastPage;

            $meta = [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
            ];
        }

        if ($request->has('select')) {
            $selectParam = $request->query('select');
            $includes = RelationshipResolver::parseSelectForIncludes($selectParam);
            $useSubqueryOptimization = config('record.use_subquery_optimization', true) && count($data) <= 100;

            // Disable subquery optimization if nested filters or child relationships are detected
            if ($useSubqueryOptimization) {
                foreach ($includes as $alias => $include) {
                    // Check for child relationships (recursion)
                    if (!empty($include['children'])) {
                        $useSubqueryOptimization = false;

                        break;
                    }

                    // Check relationship type to avoid Postgres limit on json_build_object arguments
                    // and to follow "N+1" pattern for complex relationships as requested
                    $relConfig = RelationshipResolver::resolveRelationship($table, $alias);
                    if ($relConfig && in_array($relConfig['type'], ['belongsToMany', 'morphToMany', 'hasManyThrough'])) {
                        $useSubqueryOptimization = false;

                        break;
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
                $primaryKey = $tableSchema->primary_key ?? 'id';
                $recordIds = array_column($data, $primaryKey);

                if ([] !== $recordIds) {
                    $optimizedBuilder = DB::table($actualTableName);
                    $mainCols = RelationshipResolver::getMainTableColumns($selectParam);
                    if ([] !== $mainCols) {
                        $prefixedCols = array_map(fn ($col) => '*' === $col ? $actualTableName.'.*' : (str_contains((string) $col, '.') ? $col : $actualTableName.'.'.$col), $mainCols);
                        $optimizedBuilder->select($prefixedCols);
                    } else {
                        $optimizedBuilder->select($actualTableName.'.*');
                    }

                    $this->applyTenantFilter($optimizedBuilder, $actualTableName, $tenantId);

                    if ($tableSchema->soft_deletes) {
                        $optimizedBuilder->whereNull($actualTableName.'.deleted_at');
                    }

                    RelationshipResolver::applySubqueryRelationships(
                        $optimizedBuilder,
                        $table,
                        $includes,
                        $this->shouldApplyTenantId($tableSchema) ? $tenantId : null
                    );

                    // Re-apply sorting to optimized query to ensure consistent order
                    QueryBuilderFilters::applySort($optimizedBuilder, $request, $actualTableName, $tableSchema->primary_key ?? 'id');

                    $optimizedData = $optimizedBuilder->whereIn($actualTableName.'.'.$primaryKey, $recordIds)->get()->all();
                    $data = RelationshipResolver::processJsonRelationships($optimizedData, $includes, $table);
                }
            } else {
                $data = RelationshipResolver::includeRelationships(
                    $data,
                    $table,
                    $selectParam,
                    $this->shouldApplyTenantId($tableSchema) ? $tenantId : null
                );
            }
        }

        $data = RecordApiResponseService::removeDeletedAtFields($data);
        $data = RecordApiResponseService::removeHiddenFields($data, $table);

        if ($isCacheable && $cacheKey) {
            $cacheData = [
                'data' => $data,
                'meta' => $meta,
                'headers' => $headers,
                'cached_at' => now()->toISOString(),
                'tenant_enabled' => $this->shouldApplyTenantId($tableSchema),
            ];
            $ttl = $this->calculateOptimalCacheTTL($table, count($data), $request->has('select'));
            QueryCacheService::put($cacheKey, $cacheData, $ttl);
        }

        if ($this->shouldIncludeDebug($request)) {
            $meta['debug']['lazy_stats'] = QueryBuilderFilters::getLazyStats();
        }

        return [
            'data' => $data,
            'meta' => $meta,
            'headers' => $headers,
            'filters' => $filters,
            'request' => $request,
            'cursor_meta' => $cursorMeta,
        ];
    }

    /**
     * Helper method to handle common record query logic.
     *
     * @param Request                        $request        The HTTP request object
     * @param Builder|RecordTableType|string $tableOrBuilder The table name, query builder, or table config
     * @param null|string                    $tanentColumn   The tenant column name (optional)
     *
     * @return array The query result array
     */
    public static function applyRequestFilters(Request $request, Builder|RecordTableType|string $tableOrBuilder, ?string $tanentColumn = ''): array
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
            } elseif (is_string($customSchema->pms_name) && '' !== trim($customSchema->pms_name)) {
                $table = trim($customSchema->pms_name);
            } elseif (is_array($customSchema->pms_name) && [] !== $customSchema->pms_name) {
                $first = $customSchema->pms_name[0] ?? null;
                $table = is_string($first) ? trim($first) : '';
            } else {
                $table = '';
            }

            // Register custom schema to make it available for QueryBuilderFilters
            // Ensure we register under the actual table name as that's what QueryBuilderFilters looks up
            $registerKey = $customSchema->table ?? $table;
            SchemaRegistry::register($registerKey, $customSchema);

            $aliases = [];
            if (is_string($customSchema->pms_name) && '' !== trim($customSchema->pms_name)) {
                $aliases[] = trim($customSchema->pms_name);
            } elseif (is_array($customSchema->pms_name)) {
                foreach ($customSchema->pms_name as $candidate) {
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
                    SchemaRegistry::register($alias, $customSchema);
                }
            }
        } else {
            $table = $tableOrBuilder;
        }

        $tableSchema = $customSchema ?? SchemaRegistry::getTable($table);
        $actualTableName = $tableSchema->table ?? $table;
        $tenantId = $tanentColumn;

        $filters = $request->except(['page', 'per_page', 'limit']);
        $includes = $request->query('select', []);
        if (is_string($includes)) {
            $includes = explode(',', $includes);
        }

        $page = max((int) $request->get('page', 1), 1);
        $perPage = $request->has('per_page') ? max(1, min((int) $request->get('per_page', 25), (int) config('record.per_page_max', 1000))) : null;
        $limit = $request->has('limit') ? max(1, min((int) $request->get('limit'), (int) config('record.limit_max', 1000))) : config('record.limit_max', 1000);

        // Disable cache if using builder as we can't easily key the builder state
        $isCacheable = !$builder && $service->isCacheableRequest($request, $table);
        $cacheKey = null;

        if ($isCacheable) {
            $cacheKey = $service->generateOptimizedCacheKey(
                $table,
                array_merge($filters, [
                    config('record.tenant_column', 'tenant_id') => $tableSchema instanceof RecordTableType && $service->shouldApplyTenantId($tableSchema) ? $tanentColumn : null,
                    'tenant_enabled' => $tableSchema instanceof RecordTableType && $service->shouldApplyTenantId($tableSchema),
                ]),
                $includes,
                $page,
                $perPage ?? $limit
            );

            $cached = QueryCacheService::get($cacheKey);
            if (null !== $cached) {
                return array_merge($cached, ['from_cache' => true, 'filters' => $filters, 'request' => $request]);
            }
        }

        if (!$builder instanceof Builder) {
            $builder = DB::table($actualTableName);
            $service->applyTenantFilter($builder, $actualTableName, $tenantId);

            if ($tableSchema instanceof RecordTableType && $tableSchema->soft_deletes) {
                if ($request->boolean('only_trashed')) {
                    $builder->whereNotNull($actualTableName.'.deleted_at');
                } else {
                    $builder->whereNull($actualTableName.'.deleted_at');
                }
            }
        } else {
            $service->applyTenantFilter($builder, $actualTableName, $tenantId);

            if ($tableSchema instanceof RecordTableType && $tableSchema->soft_deletes) {
                if ($request->boolean('only_trashed')) {
                    $builder->whereNotNull($actualTableName.'.deleted_at');
                } else {
                    $builder->whereNull($actualTableName.'.deleted_at');
                }
            }
        }

        if ($request->boolean('distinct')) {
            $builder->distinct();
        }

        QueryBuilderFilters::apply($builder, $request, $actualTableName, $tableSchema->primary_key ?? 'id');

        $headers = [];
        $meta = [];
        $cursorMeta = null;
        $data = [];
        $total = 0;

        $aggregateResult = QueryBuilderFilters::applyAggregateAndGroupBy($builder, $request, $actualTableName);

        if (null !== $aggregateResult) {
            $data = $aggregateResult['data'];
            $meta = $aggregateResult['meta'];
            $headers = $aggregateResult['headers'];
        } elseif ($request->has('limit') && !$request->has('per_page')) {
            $limit = max(1, min((int) $request->get('limit'), (int) config('record.limit_max', 1000)));
            $data = $builder->limit($limit)->get()->all();
            $total = count($data);
            $headers['X-Total-Count'] = (string) $total;
            $meta = ['total' => $total];
        } else {
            $maxPerPage = (int) config('record.per_page_max', 100);
            $perPage = max(1, min((int) $request->get('per_page', config('record.limit_max', 1000)), $maxPerPage));

            $page = max((int) $request->get('page', 1), 1);
            $countQuery = clone $builder;
            $total = $countQuery->count();
            $data = $builder->forPage($page, $perPage)->get()->all();

            $headers['X-Total-Count'] = (string) $total;
            $lastPage = (int) ceil($total / $perPage);
            $headers['X-Page'] = (string) $page;
            $headers['X-Per-Page'] = (string) $perPage;
            $headers['X-Total-Pages'] = (string) $lastPage;

            $meta = [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
            ];
        }

        if ($request->has('select')) {
            $selectParam = $request->query('select');
            $includes = RelationshipResolver::parseSelectForIncludes($selectParam);
            $useSubqueryOptimization = config('record.use_subquery_optimization', true) && count($data) <= 100;

            // Disable subquery optimization if nested filters or child relationships are detected
            if ($useSubqueryOptimization) {
                foreach ($includes as $alias => $include) {
                    if (!empty($include['children'])) {
                        $useSubqueryOptimization = false;

                        break;
                    }

                    // Check relationship type to avoid Postgres limit on json_build_object arguments
                    // and to follow "N+1" pattern for complex relationships as requested
                    $relConfig = RelationshipResolver::resolveRelationship($table, $alias);
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
                $primaryKey = $tableSchema->primary_key ?? 'id';
                $recordIds = array_column($data, $primaryKey);

                if ([] !== $recordIds) {
                    $optimizedBuilder = DB::table($actualTableName);
                    $mainCols = RelationshipResolver::getMainTableColumns($selectParam);
                    if ([] !== $mainCols) {
                        $prefixedCols = array_map(fn ($col) => '*' === $col ? $actualTableName.'.*' : (str_contains((string) $col, '.') ? $col : $actualTableName.'.'.$col), $mainCols);
                        $optimizedBuilder->select($prefixedCols);
                    } else {
                        $optimizedBuilder->select($actualTableName.'.*');
                    }

                    $service->applyTenantFilter($optimizedBuilder, $actualTableName, $tenantId);

                    if ($tableSchema instanceof RecordTableType && $tableSchema->soft_deletes) {
                        $optimizedBuilder->whereNull($actualTableName.'.deleted_at');
                    }

                    RelationshipResolver::applySubqueryRelationships(
                        $optimizedBuilder,
                        $table,
                        $includes,
                        $tableSchema instanceof RecordTableType && $service->shouldApplyTenantId($tableSchema) ? $tenantId : null
                    );

                    // Re-apply sorting to optimized query to ensure consistent order
                    QueryBuilderFilters::applySort($optimizedBuilder, $request, $actualTableName, $tableSchema->primary_key ?? 'id');

                    $optimizedData = $optimizedBuilder->whereIn($actualTableName.'.'.$primaryKey, $recordIds)->get()->all();
                    $data = RelationshipResolver::processJsonRelationships($optimizedData, $includes, $table);
                }
            } else {
                $data = RelationshipResolver::includeRelationships(
                    $data,
                    $table,
                    $selectParam,
                    $tableSchema instanceof RecordTableType && $service->shouldApplyTenantId($tableSchema) ? $tenantId : null
                );
            }
        }

        $data = RecordApiResponseService::removeDeletedAtFields($data);
        $data = RecordApiResponseService::removeHiddenFields($data, $table);

        if ($isCacheable && $cacheKey) {
            $cacheData = [
                'data' => $data,
                'meta' => $meta,
                'headers' => $headers,
                'cached_at' => now()->toISOString(),
                'tenant_enabled' => $tableSchema instanceof RecordTableType && $service->shouldApplyTenantId($tableSchema),
            ];
            $ttl = $service->calculateOptimalCacheTTL($table, count($data), $request->has('select'));
            QueryCacheService::put($cacheKey, $cacheData, $ttl);
        }

        if ($service->shouldIncludeDebug($request)) {
            $meta['debug']['lazy_stats'] = QueryBuilderFilters::getLazyStats();
        }

        return [
            'data' => $data,
            'meta' => $meta,
            'headers' => $headers,
            'filters' => $filters,
            'request' => $request,
            'cursor_meta' => $cursorMeta,
        ];
    }


    /**
     * Get a single record by ID.
     */
    public function getRecord(Request $request, string $table, mixed $id, mixed $tenantId): array
    {
        $schema = SchemaRegistry::get();
        $tableSchema = $schema[$table];

        $triggerParams = [
            $request,
            $table,
            [
                'type' => 'show',
                'id' => $id,
                config('record.tenant_column', 'tenant_id') => $tenantId,
            ],
        ];
        $triggerParams = $this->executeTableTrigger($tableSchema->beforeRead ?? null, $triggerParams);
        if (isset($triggerParams[0]) && $triggerParams[0] instanceof Request) {
            $request = $triggerParams[0];
        }

        $actualTableName = $tableSchema->table ?? $table;
        $pk = $tableSchema->primary_key ?? 'id';

        $recordCacheKey = $this->generateRecordCacheKey($table, $id, $tenantId, $request->query('select'));
        if ($this->isCacheableRequest($request, $table)) {
            $cachedRecord = QueryCacheService::get($recordCacheKey);
            if ($cachedRecord) {
                return ['data' => $cachedRecord, 'request' => $request, 'from_cache' => true];
            }
        }

        $builder = DB::table($actualTableName);
        $this->applyTenantFilter($builder, $actualTableName, $tenantId);

        if ($tableSchema->soft_deletes) {
            $builder->whereNull($actualTableName.'.deleted_at');
        }

        if ($request->has('select')) {
            $mainCols = RelationshipResolver::getMainTableColumns($request->query('select'));
            if ([] !== $mainCols) {
                $builder->addSelect($mainCols);
            }
        }

        $record = $builder->where($pk, $id)->first();
        if (!$record) {
            return ['data' => null, 'request' => $request];
        }

        if ($request->has('select')) {
            $selectParam = $request->query('select');
            $includes = RelationshipResolver::parseSelectForIncludes($selectParam);
            $useSubqueryOptimization = config('record.use_subquery_optimization', true);

            // Disable subquery optimization if nested filters or child relationships are detected
            if ($useSubqueryOptimization) {
                foreach ($includes as $alias => $include) {
                    if (!empty($include['children'])) {
                        $useSubqueryOptimization = false;

                        break;
                    }

                    // Check relationship type to avoid Postgres limit on json_build_object arguments
                    // and to follow "N+1" pattern for complex relationships as requested
                    $relConfig = RelationshipResolver::resolveRelationship($table, $alias);
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
                if ([] !== $mainCols) {
                    $prefixedCols = array_map(fn ($col) => '*' === $col ? $actualTableName.'.*' : (str_contains((string) $col, '.') ? $col : $actualTableName.'.'.$col), $mainCols);
                    $optimizedBuilder->select($prefixedCols);
                } else {
                    $optimizedBuilder->select($actualTableName.'.*');
                }

                $this->applyTenantFilter($optimizedBuilder, $actualTableName, $tenantId);

                if ($tableSchema->soft_deletes) {
                    $optimizedBuilder->whereNull($actualTableName.'.deleted_at');
                }

                RelationshipResolver::applySubqueryRelationships(
                    $optimizedBuilder,
                    $table,
                    $includes,
                    $this->shouldApplyTenantId($tableSchema) ? $tenantId : null
                );

                $optimizedRecord = $optimizedBuilder->where($pk, $id)->first();

                if ($optimizedRecord) {
                    $processedData = RelationshipResolver::processJsonRelationships([$optimizedRecord], $includes, $table);
                    $record = $processedData[0] ?? $record;
                }
            } else {
                $data = RelationshipResolver::includeRelationships(
                    [$record],
                    $table,
                    $selectParam,
                    $this->shouldApplyTenantId($tableSchema) ? $tenantId : null
                );
                $record = $data[0] ?? $record;
            }
        }

        $record = RecordApiResponseService::removeDeletedAtFields($record);

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
        // Convert RecordFunctionType to array if needed
        if ($functionConfig instanceof RecordFunctionType) {
            $config = $functionConfig->toArray();
        } else {
            $config = $functionConfig;
        }

        // Check permissions if pms_name is specified
        if (isset($config['pms_name']) && !empty($config['pms_name'])) {
            $guard = config('sp-laravel-api.auth.guard', 'api');
            $user = auth($guard)->user();
            if (!$user) {
                return RecordApiResponseService::errorWrapped('Authentication required', RecordApiJsonResponseEnum::UNAUTHORIZED->value);
            }

            // Handle both single permission (string) and multiple permissions (array)
            $permissions = is_array($config['pms_name']) ? $config['pms_name'] : [$config['pms_name']];
            $hasPermission = false;

            // Check if user has at least one of the required permissions
            foreach ($permissions as $permission) {
                if (Gate::forUser($user)->allows($permission)) {
                    $hasPermission = true;

                    break;
                }
            }

            if (!$hasPermission) {
                return RecordApiResponseService::errorWrapped('Insufficient permissions', RecordApiJsonResponseEnum::FORBIDDEN->value);
            }
        }

        // Validate HTTP method if specified
        if (isset($config['method'])) {
            $allowedMethods = is_array($config['method']) ? $config['method'] : [$config['method']];
            $allowedMethods = array_map(function (mixed $method): string {
                if ($method instanceof BackedEnum) {
                    $method = $method->value;
                } elseif ($method instanceof UnitEnum) {
                    $method = $method->name;
                }

                return strtoupper((string) $method);
            }, $allowedMethods);

            if (!in_array(strtoupper($request->method()), $allowedMethods, true)) {
                return RecordApiResponseService::errorWrapped(sprintf("Method '%s' not allowed for this function", $request->method()), 405);
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
                return RecordApiResponseService::errorWrapped('Missing required parameters', RecordApiJsonResponseEnum::ERROR->value, ['missing' => $missingParams]);
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
                return RecordApiResponseService::errorWrapped(sprintf("Class '%s' does not exist", $className), RecordApiJsonResponseEnum::SERVER_ERROR->value);
            }

            $instance = new $className();
            if (!method_exists($instance, $method)) {
                return RecordApiResponseService::errorWrapped(sprintf("Method '%s' does not exist in class '%s'", $method, $className), RecordApiJsonResponseEnum::SERVER_ERROR->value);
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
                    return RecordApiResponseService::errorWrapped('string' === gettype($records) ? $records : '', $statusCode, 'object' === gettype($responseData) ? (array) $responseData : []);
                }

                return RecordApiResponseService::successWrapped($records, $meta, $result->getStatusCode());
            }

            return RecordApiResponseService::successWrapped($result);
        } catch (Exception $exception) {
            return RecordApiResponseService::errorWrapped('Function execution failed: '.$exception->getMessage(), RecordApiJsonResponseEnum::SERVER_ERROR->value);
        }
    }

    private function determineOperation(array $row, string $pk, ?string $legacyAction): string
    {
        if (null !== $legacyAction && '' !== $legacyAction && '0' !== $legacyAction) {
            return $legacyAction;
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
}
