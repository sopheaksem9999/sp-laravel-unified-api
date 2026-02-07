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
use Sopheak\Core\Utilities\TimeUtils;
use Sopheak\Core\Enums\AuditLogEventEnum;
use Sopheak\Core\Enums\RecordApiJsonResponseEnum;
use Sopheak\Core\Utilities\QueryBuilderFiltersUtils;
use Sopheak\Core\Utilities\RelationshipResolverUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Sopheak\Core\Types\RecordFunctionType;
use Sopheak\Core\Types\RecordTableTriggerType;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\RecordUtils;

class RecordService
{
    /**
     * Create a new record with all related processing.
     *
     * @return array Returns ['id' => mixed, 'payload' => array, 'tenant_id' => mixed]
     */
    public function createRecord(string $table, array $payload, mixed $tenantId): array
    {
        $tableSchema = SchemaRegistryUtils::getTable($table);

        // Sanitize payload
        $payloadMain = $this->sanitizePayload($payload, $tableSchema);

        // Handle tenant ID
        if ($this->shouldApplyTenantId($tableSchema)) {
            $payloadMain[RecordConfigService::tenantColumn()] = $this->normalizeTenantId($tenantId);
        }

        // Apply timestamps and audit fields
        $payloadMain = $this->applyTimestampsAndAuditFields($payloadMain, $tableSchema, false);

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
    public function updateRecord(string $table, mixed $id, array $payload, mixed $tenantId): array
    {
        $tableSchema = SchemaRegistryUtils::getTable($table);

        // Sanitize payload
        $payloadMain = $this->sanitizePayload($payload, $tableSchema);

        // Remove tenant ID from payload for update (security)
        if ($this->shouldApplyTenantId($tableSchema)) {
            unset($payloadMain[RecordConfigService::tenantColumn()]);
        }

        // Apply timestamps and audit fields
        $payloadMain = $this->applyTimestampsAndAuditFields($payloadMain, $tableSchema, true);

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
    public function deleteRecord(string $table, mixed $id, mixed $tenantId): array
    {
        $tableSchema = SchemaRegistryUtils::getTable($table);

        $actualTableName = $tableSchema->table ?? $table;
        $pk = $tableSchema->primaryKey ?? 'id';

        $query = DB::table($actualTableName)->where($pk, $id);
        $this->applyTenantFilter($query, $table, $tenantId);
        $affected = $tableSchema->softDeletes ?? false ? $query->update(['deleted_at' => TimeUtils::now()]) : $query->delete();

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
        $tableSchema = SchemaRegistryUtils::getTable($table);

        $actualTableName = $tableSchema->table ?? $table;
        $pk = $tableSchema->primaryKey ?? 'id';

        // Sanitize payload
        $item = $this->sanitizePayload($payload, $tableSchema);
        if ($this->shouldApplyTenantId($tableSchema)) {
            $item[RecordConfigService::tenantColumn()] = $this->normalizeTenantId($tenantId);
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
        $tableSchema = SchemaRegistryUtils::getTable($table);

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
        if (!($tableSchema->disableAuditLog ?? false)) {
            $entityClass = 'App\Models\\' . Str::studly(Str::singular($table));
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

            $tenantId = $recordContext[RecordConfigService::tenantColumn()] ?? null;

            $context = [
                'request' => $request,
                'table' => $table,
                'operation' => $operation,
                'record_context' => $recordContext,
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

        // Execute the custom function with extracted ID parameter
        return $this->executeCustomFunction($request, $functionConfig, $extractedId);
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

                    $this->processPostWriteLogic($request, $table, 'create', [
                        'id' => $insertId,
                        'payload' => $result['payload'],
                        RecordConfigService::tenantColumn() => $result['tenant_id'],
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

                        $this->processPostWriteLogic($request, $table, 'update', [
                            'id' => $id,
                            'payload' => $result['payload'],
                            RecordConfigService::tenantColumn() => $result['tenant_id'],
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
                    $upsertedId = $result['id'] ?? ($item[$pk] ?? null);

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
        if (null === $trigger) {
            return $params;
        }

        if ($trigger instanceof RecordTableTriggerType) {
            $triggers = [$trigger];
        } elseif (is_array($trigger)) {
            $isSingleTriggerConfig = isset($trigger['class']) || isset($trigger['functionName']);
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
            $method = $item->functionName;
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
        if (!RecordConfigService::cacheEnabled()) {
            return false;
        }

        $perTableCache = RecordConfigService::cachePerTable();
        $schema = SchemaRegistryUtils::get();
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
            $disableCache = (bool) ($tableSchema->disableCache ?? false);
        } elseif (is_array($tableSchema)) {
            $disableCache = (bool) ($tableSchema['disableCache'] ?? false);
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

        return 'record_index_' . md5(serialize($keyData));
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

        return 'record_show_' . md5(serialize($keyData));
    }

    public function calculateOptimalCacheTTL(string $table, int $recordCount, bool $hasRelationships): int
    {
        $baseTTL = RecordConfigService::cacheDefaultTtl();
        if ($recordCount > 100) {
            $baseTTL = (int) ($baseTTL * 0.5);
        }

        if ($hasRelationships) {
            $baseTTL = (int) ($baseTTL * 0.7);
        }

        $perTableTTL = RecordConfigService::cachePerTableTtl();
        if (isset($perTableTTL[$table])) {
            $baseTTL = $perTableTTL[$table];
        }

        return max($baseTTL, 300);
    }

    public function sanitizePayload(array $input, object $meta): array
    {
        $columns = array_keys($meta->columns ?? []);
        $payload = array_intersect_key($input, array_flip($columns));

        $writeDisabled = is_array($meta->columnWriteDisabled ?? null) ? $meta->columnWriteDisabled : [];
        foreach ($writeDisabled as $column) {
            unset($payload[$column]);
        }

        unset($payload['id'], $payload['deleted_at'], $payload['created_at'], $payload['updated_at']);

        return RecordUtils::applyCompositeTypes($payload, $meta->columns ?? []);
    }

    public function applyTimestampsAndAuditFields(array $payload, object $tableSchema, bool $isUpdate = false): array
    {
        $user = auth('api')->user();
        $now = TimeUtils::now();

        if ($isUpdate) {
            $payload['updated_at'] = $now;
            if ($user) {
                if (isset($tableSchema->columns['updated_by'])) {
                    $payload['updated_by'] = $user->id;
                } elseif (isset($tableSchema->columns['last_updated_by'])) {
                    $payload['last_updated_by'] = $user->id;
                }
            }
        } else {
            $payload['created_at'] = $now;
            $payload['updated_at'] = $now;
            if ($user && isset($tableSchema->columns['created_by'])) {
                $payload['created_by'] = $user->id;
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

    public function applyTenantFilter(mixed $query, string $table, mixed $tenantId): void
    {
        $tenantId = $this->normalizeTenantId($tenantId);
        $schema = SchemaRegistryUtils::get();
        if ($this->isTenantIdEnabled() && null !== $tenantId && '' !== $tenantId && ($schema[$table]->hasTenantId ?? false)) {
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
        $perPage = $request->has('per_page') ? max(1, min((int) $request->get('per_page', 25), RecordConfigService::perPageMax())) : null;
        $limit = $request->has('limit') ? max(1, min((int) $request->get('limit'), RecordConfigService::limitMax())) : RecordConfigService::limitMax();

        $isCacheable = $this->isCacheableRequest($request, $table);
        $cacheKey = null;

        if ($isCacheable) {
            $cacheKey = $this->generateOptimizedCacheKey(
                $table,
                array_merge($filters, [
                    RecordConfigService::tenantColumn() => $this->shouldApplyTenantId($tableSchema) ? $tenantId : null,
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

        if ($tableSchema->softDeletes) {
            if ($request->boolean('only_trashed')) {
                $builder->whereNotNull($actualTableName . '.deleted_at');
            } else {
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
            $maxPerPage = RecordConfigService::perPageMax();
            $perPage = max(1, min((int) $request->get('per_page', RecordConfigService::limitMax()), $maxPerPage));

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
                    if ([] !== $mainCols) {
                        $prefixedCols = array_map(fn($col) => '*' === $col ? $actualTableName . '.*' : (str_contains((string) $col, '.') ? $col : $actualTableName . '.' . $col), $mainCols);
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
            $cacheKey = $service->generateOptimizedCacheKey(
                $table,
                array_merge($filters, [
                    RecordConfigService::tenantColumn() => $tableSchema instanceof RecordTableType && $service->shouldApplyTenantId($tableSchema) ? $tanentColumn : null,
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

            if ($tableSchema instanceof RecordTableType && $tableSchema->softDeletes) {
                if ($request->boolean('only_trashed')) {
                    $builder->whereNotNull($actualTableName . '.deleted_at');
                } else {
                    $builder->whereNull($actualTableName . '.deleted_at');
                }
            }
        } else {
            $service->applyTenantFilter($builder, $actualTableName, $tenantId);

            if ($tableSchema instanceof RecordTableType && $tableSchema->softDeletes) {
                if ($request->boolean('only_trashed')) {
                    $builder->whereNotNull($actualTableName . '.deleted_at');
                } else {
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
            $maxPerPage = RecordConfigService::perPageMax();
            $perPage = max(1, min((int) $request->get('per_page', RecordConfigService::limitMax()), $maxPerPage));

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
                    if ([] !== $mainCols) {
                        $prefixedCols = array_map(fn($col) => '*' === $col ? $actualTableName . '.*' : (str_contains((string) $col, '.') ? $col : $actualTableName . '.' . $col), $mainCols);
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

        $recordCacheKey = $this->generateRecordCacheKey($table, $id, $tenantId, $request->query('select'));
        if ($this->isCacheableRequest($request, $table)) {
            $cachedRecord = QueryCacheService::get($recordCacheKey);
            if ($cachedRecord) {
                return ['data' => $cachedRecord, 'request' => $request, 'from_cache' => true];
            }
        }

        $builder = DB::table($actualTableName);
        $this->applyTenantFilter($builder, $actualTableName, $tenantId);

        if ($tableSchema->softDeletes) {
            $builder->whereNull($actualTableName . '.deleted_at');
        }

        if ($request->has('select')) {
            $mainCols = RelationshipResolverUtils::getMainTableColumns($request->query('select'));
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
                if ([] !== $mainCols) {
                    $prefixedCols = array_map(fn($col) => '*' === $col ? $actualTableName . '.*' : (str_contains((string) $col, '.') ? $col : $actualTableName . '.' . $col), $mainCols);
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

                foreach ($permissions as $permission) {
                    if (Gate::forUser($user)->allows($permission)) {
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
            return RecordApiResponseService::errorWrapped(message: 'Function execution failed: ' . $exception->getMessage(), status: RecordApiJsonResponseEnum::SERVER_ERROR->value);
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
