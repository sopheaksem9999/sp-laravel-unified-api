<?php

declare(strict_types=1);

namespace Sopheak\Core\Services;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;
use Sopheak\Core\Events\RecordMutated;
use Sopheak\Core\Events\RecordCreated;
use Sopheak\Core\Events\RecordUpdated;
use Sopheak\Core\Events\RecordDeleted;
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
use Symfony\Component\HttpFoundation\Response;
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
     * Audit columns that record *who created the row*. They are written on create
     * only: rewriting them on update silently reassigns record ownership (an admin
     * editing a customer's row would claim it), which also breaks `viewOwn:*`
     * scoping for tables whose owner column resolves to an audit stamp.
     */
    private const CREATE_AUDIT_COLUMNS = ['created_by', 'created_by_id'];

    /**
     * Audit columns that record *who wrote the row last*. Written on create and
     * on every update.
     */
    private const UPDATE_AUDIT_COLUMNS = ['updated_by', 'last_updated_by', 'last_updated_by_id'];

    /**
     * Create a new record with all related processing.
     *
     * @return array Returns ['id' => mixed, 'payload' => array, 'tenant_id' => mixed]
     */
    public function createRecord(string $table, array $payload, mixed $tenantId): array
    {
        RelationshipResolverUtils::validatePayloadFields($table, $payload);

        $tableSchema = SchemaRegistryUtils::getTable($table);

        $payloadMain = $this->buildCrudPayload($payload, $tableSchema, $tenantId, false);

        // Resolve actual table name
        $actualTableName = $tableSchema->table ?? $table;
        $pk = $tableSchema->primaryKey ?? 'id';

        // Insert record.
        //
        // A uuid primary key has no database default and is not auto-incrementing,
        // so when the client supplies no id the value has to be generated here or
        // the insert violates the column's not-null constraint. This mirrors the
        // nested-create path in RelationshipResolverUtils::processRelatedData and
        // shares its uuid detection so the two cannot drift.
        if ((!array_key_exists($pk, $payloadMain) || null === $payloadMain[$pk]) && SchemaRegistryUtils::isUuidColumnType($tableSchema->columns[$pk] ?? null)) {
            $payloadMain[$pk] = (string) Str::uuid();
        }

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
        RelationshipResolverUtils::validatePayloadFields($table, $payload);

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
     * @return array<string, mixed> Returns ['id' => mixed, 'affected' => int]
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
     * @return array<string, mixed> Returns ['id' => mixed, 'restored' => int]
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
     * @return array<string, mixed> Returns ['id' => mixed, 'deleted' => int]
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
     * @return array<string, mixed[]|array<string, mixed>> Returns ['id' => mixed, 'payload' => array]
     */
    public function upsertRecord(Request $request, string $table, array $payload, mixed $tenantId, array $matchOn = []): array
    {
        RelationshipResolverUtils::validatePayloadFields($table, $payload);

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
            throw new Exception(
                message: 'match_on query parameter is required for upsert operation',
                code: (int) RecordApiJsonResponseEnum::VALIDATION_ERROR->value
            );
        }

        // Sanitize payload
        $item = $this->sanitizePayload($payload, $tableSchema);
        if ($this->shouldApplyTenantId($tableSchema)) {
            $item[RecordConfigService::tenantColumn()] = $this->normalizeTenantId($tenantId);
        }

        // Apply timestamps and audit fields
        $item = $this->applyTimestampsAndAuditFields($item, $tableSchema, true);
        $item = $this->applyUpsertCreateStamps($item, $tableSchema);

        // Restore an explicit primary key (e.g. a client-generated UUID) or generate one for
        // a UUID-typed key with no database default. sanitizePayload() strips 'id'
        // unconditionally, and the INSERT branch of an upsert (no existing row matches
        // match_on) needs a value for the same reason createRecord() does: a uuid column
        // has no database default and does not auto-increment, so a NULL insert violates
        // its NOT NULL constraint. Harmless when the row actually matches and updates
        // instead — $pk is excluded from $updateColumns below, so this value is never
        // applied to an existing row.
        if (isset($tableSchema->columns[$pk]) && array_key_exists($pk, $payload) && null !== $payload[$pk]) {
            $item[$pk] = $payload[$pk];
        } elseif (!array_key_exists($pk, $item) && SchemaRegistryUtils::isUuidColumnType($tableSchema->columns[$pk] ?? null)) {
            $item[$pk] = (string) Str::uuid();
        }

        // Upsert requires update columns; exclude match columns, primary key and system timestamps
        $excludeColumns = array_merge($matchOn, [$pk, 'id', 'created_at', 'deleted_at'], self::CREATE_AUDIT_COLUMNS);

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
            RelationshipResolverUtils::validatePayloadFields($table, $payload);

            $item = $this->sanitizePayload($payload, $tableSchema);
            if ($tenantEnabled) {
                $item[RecordConfigService::tenantColumn()] = $cacheTenantId;
            }

            $item = $this->applyTimestampsAndAuditFields($item, $tableSchema, true);
            $item = $this->applyUpsertCreateStamps($item, $tableSchema);

            // See upsertRecord() for why an explicit/generated primary key is needed here.
            if (isset($tableSchema->columns[$pk]) && array_key_exists($pk, $payload) && null !== $payload[$pk]) {
                $item[$pk] = $payload[$pk];
            } elseif (!array_key_exists($pk, $item) && SchemaRegistryUtils::isUuidColumnType($tableSchema->columns[$pk] ?? null)) {
                $item[$pk] = (string) Str::uuid();
            }

            $preparedItems[] = $item;
        }

        if (empty($preparedItems)) {
            return ['count' => 0];
        }

        // Calculate update columns from the first item (assuming uniform payload structure)
        $firstItem = $preparedItems[0];
        $excludeColumns = array_merge($matchOn, [$pk, 'id', 'created_at', 'deleted_at'], self::CREATE_AUDIT_COLUMNS);
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
     * @param array<string, mixed> $recordContext
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
                !empty($tableSchema->customAuditLog)
                && $this->callCustomAuditLogger(
                    callback: $tableSchema->customAuditLog,
                    event: $event,
                    entityClass: $entityClass,
                    auditData: self::stripHiddenColumns($auditData, $tableSchema),
                    tenantId: $tenantId,
                    context: $context,
                )
            ) {
                return;
            }

            $auditMethod = config('audit.filter') === null ? 'insertAuditLog' : 'insertAuditLogWithContext';
            $auditContext = config('audit.filter') === null ? [] : ['context' => array_replace($context, ['source' => 'record'])];
            AuditLogService::$auditMethod(...[
                'auditLogEventEnum' => $event,
                'entityClass' => $entityClass,
                'queryData' => $auditData,
                'subject' => '',
                'recap' => '',
                'tenantId' => $tenantId,
                ...$auditContext
            ]);
        }

        // 3. Fire broadcast event (opt-in via record.broadcast_events)
        $this->fireBroadcastEvent($table, $operation, $recordContext, $tableSchema);
    }

    /**
     * Fire a RecordMutated broadcast event when broadcasting is enabled.
     * @param array<string, mixed> $recordContext
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

        $record = self::stripHiddenColumns($record, $tableSchema);

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

    /**
     * @param array<string, mixed>|object $data
     * @return array<string, mixed>
     */
    public static function stripHiddenColumns(array|object $data, ?RecordTableType $tableSchema): array
    {
        if (is_object($data)) {
            $data = (array) $data;
        }

        if (array_is_list($data) && !empty($data)) {
            /** @var array<string, mixed> */
            return array_map(static fn($item): mixed => (is_array($item) || is_object($item)) ? self::stripHiddenColumns($item, $tableSchema) : $item, $data);
        }

        $tableName = $tableSchema?->table;
        if (is_string($tableName) && '' !== $tableName) {
            $cleaned = RecordApiResponseService::removeHiddenFields($data, $tableName);
            if (is_array($cleaned)) {
                $data = $cleaned;
            }
        } else {
            $hidden = is_array($tableSchema?->columnHiddens ?? null) ? $tableSchema->columnHiddens : [];
            foreach ($hidden as $col) {
                unset($data[$col]);
            }
        }

        $excluded = RecordConfigService::auditExcludedAttributes();
        if (is_array($excluded)) {
            foreach ($excluded as $col) {
                if (is_string($col)) {
                    unset($data[$col]);
                }
            }
        }

        return $data;
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
    public function executeTableFunction(Request $request, string $table, string $functionName): Response
    {
        // Get schema and validate table exists
        $tableSchema = SchemaRegistryUtils::getTable($table);
        if (!$tableSchema instanceof RecordTableType) {
            throw new Exception(
                message: sprintf("Table '%s' does not exist", $table),
                code: (int) RecordApiJsonResponseEnum::NOT_FOUND->value
            );
        }

        // Check if function exists in table schema
        $tableFunctions = $tableSchema->functions ?? [];
        ['config' => $functionConfig, 'routeParams' => $extractedParams] = $this->resolveFunctionConfigAndRouteParams($tableFunctions, $functionName);

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
            throw new Exception(
                message: sprintf("Function '%s' not found for table '%s'", $functionName, $table),
                code: (int) RecordApiJsonResponseEnum::NOT_FOUND->value
            );
        }

        $disableCache = false;
        $functionCacheTtl = null;
        if ($functionConfig instanceof RecordFunctionType) {
            $disableCache = $functionConfig->disableCache;
            $functionCacheTtl = $functionConfig->cacheTTL;
        } elseif (is_array($functionConfig)) {
            $disableCache = (bool) ($functionConfig['disableCache'] ?? true);
            $functionCacheTtl = isset($functionConfig['cacheTTL']) ? (int) $functionConfig['cacheTTL'] : null;
        }

        if (null !== $functionCacheTtl && $functionCacheTtl <= 0) {
            $functionCacheTtl = null;
        }

        $tenantEnabled = $this->shouldApplyTenantId($tableSchema);
        $tenantId = $tenantEnabled ? $this->resolveTenantFromRequest($request, $tableSchema) : null;
        $cacheKey = null;

        $clearCacheTables = null;
        if ($functionConfig instanceof RecordFunctionType) {
            $clearCacheTables = $functionConfig->clearCacheTables;
        } elseif (is_array($functionConfig)) {
            $clearCacheTables = $functionConfig['clearCacheTables'] ?? null;
        }

        if (null === $clearCacheTables || [] === $clearCacheTables || '' === $clearCacheTables) {
            $clearCacheTables = $table;
        }

        $cacheDependencies = $this->cacheService()->functionCacheDependencies($clearCacheTables, $tenantId, $tenantEnabled);

        if (!$disableCache && $this->isCacheableRequest($request, $table)) {
            $cacheKey = $this->generateTableFunctionCacheKey(
                table: $table,
                functionName: $functionName,
                queryParams: $request->query(),
                tenantId: $tenantId,
                tenantEnabled: $tenantEnabled,
                queryFingerprint: $this->cacheService()->queryFingerprint($request)
            );
            $cached = QueryCacheService::get($cacheKey, $cacheDependencies);
            if (is_array($cached) && isset($cached['data'], $cached['status'])) {
                return new JsonResponse($cached['data'], $cached['status'], $cached['headers'] ?? []);
            }
        }

        $response = $this->executeCustomFunction($request, $functionConfig, $extractedParams);

        $method = strtoupper($request->method());
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && $response->getStatusCode() < 400) {
            $this->cacheService()->invalidateTableFunctionCache(
                table: $table,
                functionName: $functionName,
                tenantId: $tenantId,
                tenantEnabled: $tenantEnabled
            );

            $this->cacheService()->clearCacheForTables($clearCacheTables, $tenantId);
        }

        if ($cacheKey && $response instanceof JsonResponse) {
            $ttl = $this->calculateOptimalCacheTTL($table, 1, false);
            if (null !== $functionCacheTtl && $functionCacheTtl !== $ttl) {
                $ttl = $functionCacheTtl;
            }

            QueryCacheService::put($cacheKey, [
                'data' => $response->getData(true),
                'status' => $response->getStatusCode(),
                'headers' => $response->headers->all(),
            ], $ttl, $cacheDependencies);
        }

        return $response;
    }

    /**
     * Execute a global custom function.
     * Supports patterns like: function_name or function_name/{id}.
     */
    public function executeGlobalFunction(Request $request, string $functionName): Response
    {
        // Check if function exists in table schema
        $globalFunctions = RecordConfigService::globalFunctions();
        ['config' => $functionConfig, 'routeParams' => $extractedParams] = $this->resolveFunctionConfigAndRouteParams($globalFunctions, $functionName);

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
            throw new Exception(
                message: sprintf("Function '%s' not found", $functionName),
                code: (int) RecordApiJsonResponseEnum::NOT_FOUND->value
            );
        }

        $disableCache = false;
        $functionCacheTtl = null;
        if ($functionConfig instanceof RecordFunctionType) {
            $disableCache = $functionConfig->disableCache;
            $functionCacheTtl = $functionConfig->cacheTTL;
        } elseif (is_array($functionConfig)) {
            $disableCache = (bool) ($functionConfig['disableCache'] ?? true);
            $functionCacheTtl = isset($functionConfig['cacheTTL']) ? (int) $functionConfig['cacheTTL'] : null;
        }

        if (null !== $functionCacheTtl && $functionCacheTtl <= 0) {
            $functionCacheTtl = null;
        }

        $tenantEnabled = RecordConfigService::enableTenantId();
        $tenantId = $tenantEnabled ? RecordUtils::resolveTenantIdFromRequest($request) : null;
        $cacheKey = null;

        $clearCacheTables = null;
        if ($functionConfig instanceof RecordFunctionType) {
            $clearCacheTables = $functionConfig->clearCacheTables;
        } elseif (is_array($functionConfig)) {
            $clearCacheTables = $functionConfig['clearCacheTables'] ?? null;
        }

        $cacheDependencies = $this->cacheService()->functionCacheDependencies($clearCacheTables, $tenantId, $tenantEnabled);

        if (!$disableCache && $this->cacheService()->isCacheableGlobalRequest($request)) {
            $cacheKey = $this->generateGlobalFunctionCacheKey(
                functionName: $functionName,
                queryParams: $request->query(),
                tenantId: $tenantId,
                tenantEnabled: $tenantEnabled,
                queryFingerprint: $this->cacheService()->queryFingerprint($request)
            );
            $cached = QueryCacheService::get($cacheKey, $cacheDependencies);
            if (is_array($cached) && isset($cached['data'], $cached['status'])) {
                return new JsonResponse($cached['data'], $cached['status'], $cached['headers'] ?? []);
            }
        }

        $response = $this->executeCustomFunction($request, $functionConfig, $extractedParams);

        $method = strtoupper($request->method());
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && $response->getStatusCode() < 400) {
            $this->cacheService()->invalidateGlobalFunctionCache(
                functionName: $functionName,
                tenantId: $tenantId,
                tenantEnabled: $tenantEnabled
            );

            $this->cacheService()->clearCacheForTables($clearCacheTables, $tenantId);
        }

        if ($cacheKey && $response instanceof JsonResponse) {
            $ttl = $functionCacheTtl ?? RecordConfigService::cacheTtl();
            QueryCacheService::put($cacheKey, [
                'data' => $response->getData(true),
                'status' => $response->getStatusCode(),
                'headers' => $response->headers->all(),
            ], $ttl, $cacheDependencies);
        }

        return $response;
    }

    /**
     * Bulk create, update, or delete records.
     * @return array<string, array<int|string, mixed>>
     */
    public function bulkRecord(Request $request, string $table, mixed $tenantId, ?string $legacyAction = null): array
    {
        $tableSchema = SchemaRegistryUtils::getTable($table);
        if (!$tableSchema instanceof RecordTableType) {
            throw new Exception(
                message: 'Resource not available',
                code: (int) RecordApiJsonResponseEnum::NOT_FOUND->value
            );
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
            throw new Exception(
                message: 'Data array required',
                code: (int) RecordApiJsonResponseEnum::VALIDATION_ERROR->value
            );
        }

        $maxBatch = RecordConfigService::bulkMax();
        if (count($items) > $maxBatch) {
            throw new Exception(
                message: 'Batch too large, max ' . $maxBatch,
                code: 413
            );
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
                // 'operation' is a per-item control field consumed above, not a data column/relationship.
                unset($item['operation']);

                if ('create' === $operation) {
                    $this->executeTableTrigger($tableSchema->beforeCreate ?? null, [$request, $table, $item]);

                    $result = $this->createRecord(table: $table, payload: $item, tenantId: $tenantId);
                    if (!array_key_exists('id', $result)) {
                        throw new RuntimeException('Failed to create record in bulk operation for table: ' . $table);
                    }

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

                            $upsertAuditData = self::stripHiddenColumns($recordResult['data'], $tableSchema);

                            if (!(!empty($tableSchema->customAuditLog) && $this->callCustomAuditLogger(
                                callback: $tableSchema->customAuditLog,
                                event: AuditLogEventEnum::UPDATED,
                                entityClass: $entityClass,
                                auditData: $upsertAuditData,
                                tenantId: $tenantId,
                                context: $context,
                            ))) {
                                $auditMethod = config('audit.filter') === null ? 'insertAuditLog' : 'insertAuditLogWithContext';
                                $auditContext = config('audit.filter') === null ? [] : ['context' => array_replace($context, ['source' => 'record'])];
                                AuditLogService::$auditMethod(...[
                                    'auditLogEventEnum' => AuditLogEventEnum::UPDATED,
                                    'entityClass' => $entityClass,
                                    'queryData' => $upsertAuditData,
                                    'subject' => '',
                                    'recap' => '',
                                    'tenantId' => $tenantId,
                                    ...$auditContext
                                ]);
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

    /**
     * @param array<array<string, mixed>, mixed> $context
     */
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

    /**
     * @param array<mixed[], mixed> $params
     */
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

    /**
     * @param array<int, mixed> $params
     */
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

    /**
     * @param array<string, mixed> $errors
     */
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

    /**
     * Normalize a payload to JSON-safe arrays/scalars before it is stored in
     * the cache. Query results come back as stdClass rows (DB query builder);
     * serializing cache stores (database, file, redis-php) persist with PHP
     * serialize() and can fail to restore those objects on read, which turns
     * every cached request into a 500 "incomplete object" until TTL expiry.
     */
    private static function cacheSafePayload(mixed $value): mixed
    {
        $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $decoded = json_decode($encoded, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    public function generateOptimizedCacheKey(string $table, array $filters, array $includes, int $page, int $limit, bool $tenantEnabled, string $queryFingerprint = ''): string
    {
        return $this->cacheService()->generateOptimizedCacheKey($table, $filters, $includes, $page, $limit, $tenantEnabled, $queryFingerprint);
    }

    public function generateRecordCacheKey(string $table, mixed $id, mixed $tenantId, mixed $select, bool $tenantEnabled, string $queryFingerprint = ''): string
    {
        return $this->cacheService()->generateRecordCacheKey($table, $id, $tenantId, $select, $tenantEnabled, $queryFingerprint);
    }

    public function generateTableFunctionCacheKey(string $table, string $functionName, array $queryParams, mixed $tenantId, bool $tenantEnabled, string $queryFingerprint = ''): string
    {
        return $this->cacheService()->generateTableFunctionCacheKey($table, $functionName, $queryParams, $tenantId, $tenantEnabled, $queryFingerprint);
    }

    public function generateGlobalFunctionCacheKey(string $functionName, array $queryParams, mixed $tenantId, bool $tenantEnabled, string $queryFingerprint = ''): string
    {
        return $this->cacheService()->generateGlobalFunctionCacheKey($functionName, $queryParams, $tenantId, $tenantEnabled, $queryFingerprint);
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

    /**
     * @param array<string, mixed> $payload
     */
    private function buildCrudPayload(array $payload, object $tableSchema, mixed $tenantId, bool $isUpdate): array
    {
        $tenantColumn = RecordConfigService::tenantColumn();
        $baseData = [];
        $primaryKey = (string) ($tableSchema->primaryKey ?? 'id');
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

        // Preserve explicit primary key values on create (e.g., UUID-based tables).
        // Updates must never allow primary key mutation.
        if (
            !$isUpdate
            && isset($tableSchema->columns[$primaryKey])
            && array_key_exists($primaryKey, $payload)
            && null !== $payload[$primaryKey]
            && !array_key_exists($primaryKey, $payloadMain)
        ) {
            $payloadMain[$primaryKey] = $payload[$primaryKey];
        }

        if ($this->shouldApplyTenantId($tableSchema)) {
            if ($isUpdate) {
                unset($payloadMain[$tenantColumn]);
            } else {
                $payloadMain[$tenantColumn] = $this->normalizeTenantId($tenantId);
            }
        }

        if ($isUpdate) {
            unset($payloadMain[$primaryKey]);
        }

        return $this->applyTimestampsAndAuditFields($payloadMain, $tableSchema, $isUpdate);
    }

    /**
     * @param array<string, mixed> $payload
     */
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
                // created_by* deliberately excluded: see self::CREATE_AUDIT_COLUMNS.
                foreach (self::UPDATE_AUDIT_COLUMNS as $auditField) {
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
                foreach ([...self::CREATE_AUDIT_COLUMNS, ...self::UPDATE_AUDIT_COLUMNS] as $auditField) {
                    if (isset($tableSchema->columns[$auditField]) && (!array_key_exists($auditField, $payload) || !$overrideUserstamps)) {
                        $payload[$auditField] = $user->id;
                    }
                }
            }
        }

        return $payload;
    }

    /**
     * Fill the create-time stamps that an upsert's INSERT branch needs.
     *
     * Both upsert paths call applyTimestampsAndAuditFields() with $isUpdate = true,
     * which by design only writes updated_at and the UPDATE_AUDIT_COLUMNS. When no
     * existing row matches match_on the upsert inserts instead, and those columns
     * would go in as NULL. Filling them here is safe for the UPDATE branch too:
     * created_at and CREATE_AUDIT_COLUMNS are excluded from $updateColumns, so an
     * existing row keeps its original author and creation time.
     *
     * Only absent keys are filled, so an explicit client value that survived
     * sanitization (overrideUserstamps / overrideTimestamps) still wins.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function applyUpsertCreateStamps(array $item, object $tableSchema): array
    {
        if (isset($tableSchema->columns['created_at']) && !array_key_exists('created_at', $item)) {
            $item['created_at'] = TimeUtils::now();
        }

        $user = auth('api')->user();
        if (null === $user) {
            return $item;
        }

        foreach (self::CREATE_AUDIT_COLUMNS as $auditField) {
            if (isset($tableSchema->columns[$auditField]) && !array_key_exists($auditField, $item)) {
                $item[$auditField] = $user->id;
            }
        }

        return $item;
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

    /**
     * Get combined select and with parameters from request.
     */
    private static function getCombinedSelectParam(Request $request): string
    {
        $selectParam = $request->query('select', '');
        $withParam = $request->query('with', '');

        $combined = [];
        if (is_string($selectParam) && $selectParam !== '') {
            $combined[] = $selectParam;
        }

        if (is_string($withParam) && $withParam !== '') {
            $combined[] = $withParam;
        }

        return implode(',', $combined);
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

    /**
     * Resolve tenant ID for relationship subqueries independently of the main table's hasTenantId.
     * When the main table isn't tenant-scoped (e.g., users), pivot tables like sp_model_has_roles
     * may still need tenant filtering.
     */
    private function resolveRelationshipTenantId(Request $request, mixed $tenantId): mixed
    {
        if (!RecordUtils::isTenantIdMissing($tenantId)) {
            return $tenantId;
        }

        if (!$this->isTenantIdEnabled()) {
            return null;
        }

        $fromAttr = $request->attributes->get(self::TENANT_ATTRIBUTE_KEY);
        if (!RecordUtils::isTenantIdMissing($fromAttr)) {
            return $fromAttr;
        }

        $fromHeader = $request->header(RecordConfigService::tenantHeader());
        if (!RecordUtils::isTenantIdMissing($fromHeader)) {
            return $fromHeader;
        }

        $fromInput = $request->input(RecordConfigService::tenantColumn());
        if (!RecordUtils::isTenantIdMissing($fromInput)) {
            return $fromInput;
        }

        return null;
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

    public function generateCursorCacheKey(string $table, array $filters, array $includes, string $cursor, string $direction, string $cursorColumn, int $limit, bool $tenantEnabled, string $queryFingerprint = ''): string
    {
        return $this->cacheService()->generateCursorCacheKey($table, $filters, $includes, $cursor, $direction, $cursorColumn, $limit, $tenantEnabled, $queryFingerprint);
    }

    private function getReadConnection(): ?ConnectionInterface
    {
        $connection = RecordConfigService::readConnection();

        return $connection ? DB::connection($connection) : null;
    }

    private function createReadBuilder(string $table): Builder
    {
        $readConn = $this->getReadConnection();

        return $readConn instanceof ConnectionInterface ? $readConn->table($table) : DB::table($table);
    }

    private function applyIndexHint(Builder $builder, string $table, string $context = 'list'): void
    {
        if ('mysql' !== $this->getBuilderDriverName($builder)) {
            return;
        }

        $hints = RecordConfigService::tableIndexHints($table);
        $index = $hints[$context] ?? null;
        if ($index !== null) {
            $builder->from($builder->getConnection()->raw($builder->from . ' FORCE INDEX (' . $index . ')'));
        }
    }

    private function getBuilderDriverName(Builder $builder): ?string
    {
        $connection = $builder->getConnection();

        if (method_exists($connection, 'getDriverName')) {
            $driverName = $connection->getDriverName();

            return is_string($driverName) ? $driverName : null;
        }

        return null;
    }

    private function explainQuery(Builder $builder): array
    {
        try {
            $sql = $builder->toSql();
            $bindings = $builder->getBindings();

            $start = microtime(true);
            $result = match (DB::getDriverName()) {
                'mysql' => DB::select('EXPLAIN FORMAT=JSON ' . $sql, $bindings),
                'pgsql' => DB::select('EXPLAIN (ANALYZE false, FORMAT JSON) ' . $sql, $bindings),
                default => [['query' => $sql, 'bindings' => $bindings]],
            };
            $durationMs = (microtime(true) - $start) * 1000;

            return [
                'plan' => $result,
                'query_time_ms' => round($durationMs, 2),
                'sql' => $sql,
                'bindings' => $bindings,
            ];
        } catch (Throwable $throwable) {
            return [
                'error' => $throwable->getMessage(),
            ];
        }
    }

    /**
     * Execute offset pagination (page/per_page) on a built query.
     *
     * @return array{data: array, meta: array, headers: array, total: int}
     */
    private function executeOffsetPagination(Builder $builder, Request $request): array
    {
        $headers = [];
        $meta = [];

        $maxPerPage = RecordConfigService::perPageMax();
        $perPage = max(1, min((int) $request->input('per_page', RecordConfigService::limitMax()), $maxPerPage));
        $page = max((int) $request->input('page', 1), 1);

        $skipTotal = $this->shouldSkipTotal($request);

        if ($skipTotal) {
            $total = 0;
        } else {
            $countQuery = clone $builder;
            $total = $countQuery->count();
            $headers['X-Total-Count'] = (string) $total;
        }

        $data = $builder->forPage($page, $perPage)->get()->all();

        $paginationRequested = $request->has('page') || $request->has('per_page');

        if ($skipTotal) {
            $meta = $paginationRequested
                ? ['page' => $page, 'per_page' => $perPage]
                : [];
        } elseif ($total > 0) {
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
        } else {
            $meta = ['total' => 0];
        }

        return [$data, $meta, $headers, $total];
    }

    /**
     * Execute cursor (keyset) pagination on a built query.
     *
     * @return array{data: array, meta: array, headers: array, total: int}
     */
    private function executeCursorPagination(Builder $builder, Request $request, string $primaryKey): array
    {
        $headers = [];
        $meta = [];

        $maxPerPage = RecordConfigService::perPageMax();
        $perPage = max(1, min((int) $request->input('per_page', RecordConfigService::limitMax()), $maxPerPage));

        $direction = $request->input('direction', 'next');

        $sortColumn = $this->resolveSortColumnForCursor($builder, $request);
        $cursorColumn = $this->resolveCursorColumn($request, $sortColumn);
        $isUuidColumn = $this->detectUuidCursorColumn($builder, $cursorColumn);

        // A composite cursor only means anything when the paging column is not
        // already unique — on the primary key the simple comparison is exact.
        $useComposite = RecordConfigService::cursorCompositeEnabled() && $cursorColumn !== $primaryKey;

        // A cursor issued for a composite page carries both components; a plain
        // scalar (a legacy token, or one of the boundary cursors below) yields a
        // null key and falls back to the simple comparison.
        [$cursor, $cursorKey] = $this->decodeCursor($request->input('cursor'));

        // First page has no cursor filter — use null so frontend sends cursor=
        $firstCursorDefault = null;

        // Normalize cursor: cast numeric strings to int so PostgreSQL uses index-friendly comparisons (skip UUID columns)
        if (!$isUuidColumn && null !== $cursor && '' !== $cursor && ctype_digit((string) $cursor)) {
            $cursor = (int) $cursor;
        }

        // Count total matching records before cursor filtering
        $skipTotal = $this->shouldSkipTotal($request);
        $total = 0;
        $firstCursor = $firstCursorDefault;
        $lastCursor = null;

        if (!$skipTotal) {
            $total = (clone $builder)->count();

            if ($total > $perPage && RecordConfigService::cursorBoundaryEnabled()) {

                $lastPageSize = $total % $perPage;
                $lastPageSize = 0 === $lastPageSize ? $perPage : $lastPageSize;

                // Get the cursor at the start of the last page using O(per_page) query:
                // ORDER BY id DESC LIMIT last_page_size + 1, then pick the MIN
                $boundaryRows = (clone $builder)
                    ->select($cursorColumn)
                    ->reorder()
                    ->orderBy($cursorColumn, 'desc')
                    ->limit($lastPageSize + 1)
                    ->get();

                $boundaryMin = $boundaryRows->min($cursorColumn);
                $lastCursor = $boundaryMin ?? null;
            }
        }

        $hasCursor = null !== $cursor && '' !== $cursor && 0 !== $cursor;

        // For UUID columns, only apply cursor filter when the cursor is a valid UUID
        if ($isUuidColumn && $hasCursor && (!is_string($cursor) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $cursor))) {
            $hasCursor = false;
            $cursor = null;
            $cursorKey = null;
        }

        // Default sort: created_at DESC (matches applySort behavior)
        $sortOrder = $request->input('order', 'desc');
        $sortOrder = in_array(strtolower((string) $sortOrder), ['asc', 'desc'], true) ? strtolower((string) $sortOrder) : 'desc';

        // Whatever decides the paging column also has to decide the order, or
        // page 1 is sorted one way and continued another. applySort() has
        // already ordered by $sortColumn, so the ordering only needs rebuilding
        // when the cursor pages on something else, or when a cursor filter is
        // being applied on top of it.
        if ($hasCursor || $cursorColumn !== $sortColumn) {
            $builder->reorder()->orderBy($cursorColumn, $sortOrder);
        }

        if ($hasCursor) {
            // Cursor operator depends on sort direction:
            // ASC + next → >  |  ASC + prev → <
            // DESC + next → <  |  DESC + prev → >
            $cursorOperator = match (true) {
                'asc' === $sortOrder && 'next' === $direction => '>',
                'asc' === $sortOrder && 'prev' === $direction => '<',
                'desc' === $sortOrder && 'next' === $direction => '<',
                'desc' === $sortOrder && 'prev' === $direction => '>',
                default => '>',
            };

            if ($useComposite && null !== $cursorKey) {
                // The tie-break compares the key against the *key* component of
                // the cursor, and does so strictly: an inclusive >=/<= re-serves
                // the row that ended the previous page.
                $builder->where(function ($q) use ($cursorColumn, $primaryKey, $cursor, $cursorKey, $cursorOperator): void {
                    $q->where($cursorColumn, $cursorOperator, $cursor)
                        ->orWhere(function ($q2) use ($cursorColumn, $primaryKey, $cursor, $cursorKey, $cursorOperator): void {
                            $q2->where($cursorColumn, '=', $cursor)
                                ->where($primaryKey, $cursorOperator, $cursorKey);
                        });
                });
            } else {
                $builder->where($cursorColumn, $cursorOperator, $cursor);
            }
        }

        // Keyset paging needs a total order. Without the key as a tie-break,
        // rows sharing a cursor value are ordered arbitrarily by the database
        // and can be skipped or repeated across pages.
        if ($useComposite) {
            $builder->orderBy($primaryKey, $sortOrder);
        }

        $data = $builder->limit($perPage)->get()->all();

        $nextCursor = null;
        if (count($data) > 0) {
            $lastRow = $data[count($data) - 1];
            $cursorValue = $lastRow->{$cursorColumn} ?? null;
            $keyValue = $lastRow->{$primaryKey} ?? null;

            $nextCursor = $useComposite && null !== $cursorValue && null !== $keyValue
                ? $this->encodeCursor($cursorValue, $keyValue)
                : $cursorValue;
        }

        $headers['X-Cursor'] = (string) ($nextCursor ?? '');
        $meta = [
            'cursor' => $nextCursor,
            'direction' => $direction,
            'cursor_column' => $cursorColumn,
            'total' => $total,
            'first_cursor' => $firstCursor,
            'last_cursor' => $lastCursor,
        ];

        return [$data, $meta, $headers, $total];
    }

    /**
     * The column {@see QueryBuilderFiltersUtils::applySort()} has ordered this
     * query by, so the cursor can page on the same column instead of guessing.
     */
    private function resolveSortColumnForCursor(Builder $builder, Request $request): string
    {
        $table = is_string($builder->from) ? $builder->from : '';
        if ('' === $table) {
            return RecordConfigService::cursorDefaultColumn();
        }

        return QueryBuilderFiltersUtils::resolveSort(
            $request,
            $table,
            RecordConfigService::cursorDefaultColumn()
        )['column'];
    }

    /**
     * Decide which column the cursor pages on.
     *
     * In precedence order:
     *  1. an explicit `cursor_column` on the request;
     *  2. an explicitly configured `pagination.cursor.default_column`, when it
     *     is something other than the shipped `id` default;
     *  3. the column the results are actually sorted by.
     *
     * Rule 3 is the fix for the reported bug: the cursor used to default to the
     * primary key regardless of `sortby`, so a list sorted by `created_at` was
     * continued from an `id`. It also makes `created_at` the effective default
     * paging column for any table that has one — resolveSort() already prefers
     * it — while tables without timestamps still fall back to the primary key,
     * which a flat config default could not do.
     */
    private function resolveCursorColumn(Request $request, string $sortColumn): string
    {
        $requested = $request->input('cursor_column');
        if (is_string($requested) && '' !== trim($requested)) {
            return trim($requested);
        }

        $configured = config('record.pagination.cursor.default_column');
        if (is_string($configured) && '' !== trim($configured) && 'id' !== trim($configured)) {
            return trim($configured);
        }

        return $sortColumn;
    }

    /**
     * Marks a cursor that carries both a sort value and a tie-breaking key.
     *
     * Versioned so the encoding can change without silently misreading tokens
     * already held by clients.
     */
    private const CURSOR_COMPOUND_PREFIX = 'c1.';

    /**
     * Encode a cursor value together with its tie-breaking key.
     *
     * A composite cursor needs both components, and the single scalar the API
     * used to return could not carry them — which is why the tie-break ended up
     * comparing the primary key against a timestamp.
     */
    private function encodeCursor(mixed $cursorValue, mixed $keyValue): string
    {
        $json = json_encode(['v' => $cursorValue, 'k' => $keyValue]);
        if (!is_string($json)) {
            return (string) $cursorValue;
        }

        return self::CURSOR_COMPOUND_PREFIX . rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * Split a cursor into its value and key components.
     *
     * Anything that is not a compound token — a cursor issued by an older
     * version, a boundary cursor, or a hand-written one — comes back with a null
     * key, which routes the caller to the simple exclusive comparison rather
     * than the composite branch.
     *
     * @return array{0: mixed, 1: mixed}
     */
    private function decodeCursor(mixed $cursor): array
    {
        if (!is_string($cursor) || !str_starts_with($cursor, self::CURSOR_COMPOUND_PREFIX)) {
            return [$cursor, null];
        }

        $encoded = substr($cursor, strlen(self::CURSOR_COMPOUND_PREFIX));
        $binary = base64_decode(strtr($encoded, '-_', '+/'), true);
        if (false === $binary) {
            return [$cursor, null];
        }

        $decoded = json_decode($binary, true);
        if (!is_array($decoded) || !array_key_exists('v', $decoded) || !array_key_exists('k', $decoded)) {
            return [$cursor, null];
        }

        return [$decoded['v'], $decoded['k']];
    }

    /**
     * Execute pagination on a built query, handling all modes.
     *
     * @return array{data: array, meta: array, headers: array, total: int}
     */
    private function executePagination(Builder $builder, Request $request, string $primaryKey): array
    {
        if ($this->shouldUseCursorPagination($request)) {
            return $this->executeCursorPagination($builder, $request, $primaryKey);
        }

        return $this->executeOffsetPagination($builder, $request);
    }

    private function shouldUseCursorPagination(Request $request): bool
    {
        if ($request->has('cursor')) {
            return true;
        }

        return 'cursor' === RecordConfigService::paginationDefaultMode();
    }

    private function shouldSkipTotal(Request $request): bool
    {
        if ($this->hasBooleanQueryParameter($request, 'total')) {
            return !$request->boolean('total');
        }

        return $request->boolean('skip_total', RecordConfigService::skipTotalDefault());
    }

    private function shouldIncludeLimitedTotal(Request $request): bool
    {
        if ($this->hasBooleanQueryParameter($request, 'total')) {
            return $request->boolean('total');
        }

        return $request->boolean('add_total');
    }

    private function hasBooleanQueryParameter(Request $request, string $key): bool
    {
        if (!$request->query->has($key)) {
            return false;
        }

        $value = $request->query($key);
        if (is_bool($value)) {
            return true;
        }

        if (is_int($value)) {
            return 0 === $value || 1 === $value;
        }

        if (!is_string($value)) {
            return false;
        }

        return in_array(strtolower(trim($value)), ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'], true);
    }

    private function paginationControlParameters(Request $request): array
    {
        $parameters = ['page', 'per_page', 'limit', 'cursor', 'cursor_column', 'direction', 'skip_total', 'add_total', 'explain'];
        if ($this->hasBooleanQueryParameter($request, 'total')) {
            $parameters[] = 'total';
        }

        return $parameters;
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

        $filters = $request->except($this->paginationControlParameters($request));

        $selectParam = $request->query('select', '');
        $effectiveSelectParam = self::getCombinedSelectParam($request);

        $includes = $effectiveSelectParam !== '' ? explode(',', $effectiveSelectParam) : [];

        $page = max((int) $request->input('page', 1), 1);
        $perPage = $request->has('per_page') ? max(1, min((int) $request->input('per_page', 25), RecordConfigService::perPageMax())) : null;
        $limit = $request->has('limit') ? max(1, min((int) $request->input('limit'), RecordConfigService::limitMax())) : RecordConfigService::limitMax();

        $isCacheable = $this->isCacheableRequest(request: $request, table: $table);
        $cacheKey = null;
        $isCursor = $this->shouldUseCursorPagination($request);

        if ($isCacheable) {
            $tenantEnabled = $this->shouldApplyTenantId($tableSchema);
            $cacheFilters = $filters;
            $cacheFilters['tenant_enabled'] = $tenantEnabled;
            if ($tenantEnabled) {
                $cacheFilters[RecordConfigService::tenantColumn()] = $tenantId;
            }

            if ($isCursor) {
                $cacheKey = $this->generateCursorCacheKey(
                    table: $table,
                    filters: $cacheFilters,
                    includes: $includes,
                    cursor: $request->input('cursor', ''),
                    direction: $request->input('direction', 'next'),
                    cursorColumn: $request->input('cursor_column', RecordConfigService::cursorDefaultColumn()),
                    limit: $perPage ?? $limit,
                    tenantEnabled: $tenantEnabled,
                    queryFingerprint: $this->cacheService()->queryFingerprint($request)
                );
            } else {
                $cacheKey = $this->generateOptimizedCacheKey(
                    table: $table,
                    filters: $cacheFilters,
                    includes: $includes,
                    page: $page,
                    limit: $perPage ?? $limit,
                    tenantEnabled: $tenantEnabled,
                    queryFingerprint: $this->cacheService()->queryFingerprint($request)
                );
            }

            $cached = QueryCacheService::get($cacheKey);
            if (null !== $cached) {
                return array_merge($cached, ['from_cache' => true, 'filters' => $filters, 'request' => $request]);
            }
        }

        $builder = $this->createReadBuilder($actualTableName);

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

        $this->applyIndexHint($builder, $table, 'list');

        QueryBuilderFiltersUtils::apply($builder, $request, $actualTableName, $tableSchema->primaryKey ?? 'id');

        $headers = [];
        $meta = [];
        $data = [];
        $total = 0;

        // Explain query profiling (opt-in via ?explain=true)
        $explainResult = null;
        if (RecordConfigService::profilingEnabled() && $request->boolean('explain')) {
            $explainBuilder = clone $builder;
            $explainResult = $this->explainQuery($explainBuilder);
        }

        $aggregateResult = QueryBuilderFiltersUtils::applyAggregateAndGroupBy($builder, $request, $actualTableName);

        if (null !== $aggregateResult) {
            $data = $aggregateResult['data'];
            $meta = $aggregateResult['meta'];
            $headers = $aggregateResult['headers'];
        } elseif ($request->has('limit') && !$request->has('per_page')) {
            $limit = max(1, min((int) $request->input('limit'), RecordConfigService::limitMax()));
            $data = $builder->limit($limit)->get()->all();

            if ($this->shouldIncludeLimitedTotal($request)) {
                $total = count($data);
                $headers['X-Total-Count'] = (string) $total;
                $meta = ['total' => $total];
            }
        } else {
            [$data, $meta, $headers, $total] = $this->executePagination($builder, $request, $tableSchema->primaryKey ?? 'id');
        }

        $selectParam = $request->query('select', '');
        $effectiveSelectParam = self::getCombinedSelectParam($request);

        if ($effectiveSelectParam !== '') {
            $includes = RelationshipResolverUtils::parseSelectForIncludes($effectiveSelectParam);
            $useSubqueryOptimization = count($data) <= RecordConfigService::subqueryOptimizationMaxRecords();

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
                        $requestedCols = $include['columns'] ?? ['*'];
                        $isWildcardSelect = $requestedCols === ['*'] || [] === $requestedCols;

                        // If we cannot resolve related schema for wildcard selection on PostgreSQL,
                        // avoid subquery optimization to prevent json_build_object argument overflow.
                        if (! $relatedSchema && $isWildcardSelect) {
                            $useSubqueryOptimization = false;

                            break;
                        }

                        if ($relatedSchema && is_array($relatedSchema->columns ?? null)) {
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
                    $optimizedBuilder = $this->createReadBuilder($actualTableName);
                    $mainCols = RelationshipResolverUtils::getMainTableColumns(is_string($selectParam) ? $selectParam : '');
                    RelationshipResolverUtils::validateMainTableColumns($table, $mainCols);
                    // Strip computed attribute keys — they are not real DB columns
                    $attributeKeys = array_keys($tableSchema->attributes ?? []);
                    $dbMainCols = $attributeKeys !== []
                        ? array_values(array_diff($mainCols, $attributeKeys))
                        : $mainCols;
                    if ([] !== $dbMainCols) {
                        $prefixedCols = array_map(fn($col): string => '*' === $col ? $actualTableName . '.*' : (str_contains($col, '.') ? $col : $actualTableName . '.' . $col), $dbMainCols);
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
                        $this->resolveRelationshipTenantId($request, $tenantId)
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
                    $effectiveSelectParam,
                    $this->resolveRelationshipTenantId($request, $tenantId)
                );
            }
        }

        $data = RecordApiResponseService::removeDeletedAtFields($data);
        $data = RecordApiResponseService::removeHiddenFields($data, $table);
        $data = RecordApiResponseService::convertCompositeFields($data, $table);
        $data = RecordApiResponseService::applyCasts($data, $tableSchema->columns ?? [], $tableSchema->casting ?? []);
        if (!empty($tableSchema->attributes)) {
            $requestedCols = $effectiveSelectParam !== ''
                ? RelationshipResolverUtils::getMainTableColumns(is_string($selectParam) ? $selectParam : '')
                : [];
            RelationshipResolverUtils::validateMainTableColumns($table, $requestedCols);
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
            $ttl = $this->calculateOptimalCacheTTL($table, count($data), $effectiveSelectParam !== '');
            // Rows come off the query builder as stdClass objects; serializing
            // stores (database, file, redis-php) persist with PHP serialize()
            // and can fail to restore them on read ("incomplete object").
            // Cache JSON-safe arrays only.
            QueryCacheService::put($cacheKey, self::cacheSafePayload($cacheData), $ttl);
        }

        if ($this->shouldIncludeDebug($request)) {
            $meta['debug']['lazy_stats'] = QueryBuilderFiltersUtils::getLazyStats();
            $meta['debug']['cache_stats'] = QueryCacheService::requestStats();
            $tenantContextStats = $request->attributes->get('record_pgsql_tenant_context_stats');
            if (is_array($tenantContextStats)) {
                $meta['debug']['pgsql_tenant_context'] = $tenantContextStats;
            }
        }

        if ($explainResult !== null) {
            $meta['debug']['explain'] = $explainResult;
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

        $request = Request::create(uri: '/', method: 'GET', parameters: $queryParams);

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
        $queryParams[$pk] = 'eq.' . $id;

        $request = Request::create(uri: '/', method: 'GET', parameters: $queryParams);

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

        if (!is_array($result) || !array_key_exists('id', $result)) {
            throw new RuntimeException('Failed to create record or retrieve inserted ID for table: ' . $table);
        }

        $record = self::executeGetById(
            table: $table,
            id: $result['id'],
            queryParams: $queryParams,
            tenantId: $tenantId
        );

        $request = request();
        $auditContext = [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'user_id' => auth(RecordConfigService::authGuard())->id(),
            'request_id' => $request->attributes->get('request_id'),
        ];

        $recordData = $record['data'] ?? [];
        if (!is_array($recordData)) {
            $recordData = json_decode(json_encode($recordData), true) ?: [];
        }

        $tableSchema = SchemaRegistryUtils::getTable($table);
        $recordData = self::stripHiddenColumns($recordData, $tableSchema);

        RecordCreated::dispatch($table, $recordData, $result['id'], $auditContext);

        return $record;
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

        $oldRecord = self::executeGetById(
            table: $table,
            id: $id,
            queryParams: [],
            tenantId: $tenantId
        );
        $oldPayload = $oldRecord['data'] ?? [];
        if (!is_array($oldPayload)) {
            $oldPayload = json_decode(json_encode($oldPayload), true) ?: [];
        }

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

        $newRecord = self::executeGetById($table, $id, $queryParams, $tenantId);

        $request = request();
        $auditContext = [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'user_id' => auth(RecordConfigService::authGuard())->id(),
            'request_id' => $request->attributes->get('request_id'),
        ];

        $newRecordData = $newRecord['data'] ?? [];
        if (!is_array($newRecordData)) {
            $newRecordData = json_decode(json_encode($newRecordData), true) ?: [];
        }

        $tableSchema = SchemaRegistryUtils::getTable($table);
        $oldPayload = self::stripHiddenColumns($oldPayload, $tableSchema);
        $newRecordData = self::stripHiddenColumns($newRecordData, $tableSchema);

        RecordUpdated::dispatch($table, $oldPayload, $newRecordData, $id, $auditContext);

        return $newRecord;
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
        $record = self::executeGetById(
            table: $table,
            id: $id,
            queryParams: $queryParams,
            tenantId: $tenantId
        );
        $oldPayload = $record['data'] ?? [];
        if (!is_array($oldPayload)) {
            $oldPayload = json_decode(json_encode($oldPayload), true) ?: [];
        }

        $service->deleteRecord($table, $id, $tenantId);

        $request = request();
        $auditContext = [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'user_id' => auth(RecordConfigService::authGuard())->id(),
            'request_id' => $request->attributes->get('request_id'),
        ];

        $tableSchema = SchemaRegistryUtils::getTable($table);
        $oldPayload = self::stripHiddenColumns($oldPayload, $tableSchema);

        RecordDeleted::dispatch($table, $oldPayload, $id, $auditContext);

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
     * @param mixed                          $tenantId       Optional tenant ID value for multi-tenant scoping.
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
    public static function applyRequestFilters(Request $request, Builder|RecordTableType|string $tableOrBuilder, mixed $tenantId = null, bool $isArray = true, string $orderBy = 'id'): array
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

        if ($tableSchema instanceof RecordTableType && $service->shouldApplyTenantId($tableSchema) && RecordUtils::isTenantIdMissing($tenantId)) {
            $tenantId = RecordUtils::resolveTenantIdFromRequest($request);
        }

        $filters = $request->except($service->paginationControlParameters($request));
        $includes = $request->query('select', []);
        if (is_string($includes)) {
            $includes = explode(',', $includes);
        }

        $page = max((int) $request->input('page', 1), 1);
        $perPage = $request->has('per_page') ? max(1, min((int) $request->input('per_page', 25), RecordConfigService::perPageMax())) : null;
        $limit = $request->has('limit') ? max(1, min((int) $request->input('limit'), RecordConfigService::limitMax())) : RecordConfigService::limitMax();
        $isCursor = $service->shouldUseCursorPagination($request);

        // Disable cache if using builder as we can't easily key the builder state
        $isCacheable = !$builder && $service->isCacheableRequest($request, $table);
        $cacheKey = null;

        if ($isCacheable) {
            $tenantEnabled = $tableSchema instanceof RecordTableType && $service->shouldApplyTenantId($tableSchema);
            $cacheFilters = $filters;
            $cacheFilters['tenant_enabled'] = $tenantEnabled;
            if ($tenantEnabled) {
                $cacheFilters[RecordConfigService::tenantColumn()] = $tenantId;
            }

            if ($isCursor) {
                $cacheKey = $service->generateCursorCacheKey(
                    table: $table,
                    filters: $cacheFilters,
                    includes: $includes,
                    cursor: $request->input('cursor', ''),
                    direction: $request->input('direction', 'next'),
                    cursorColumn: $request->input('cursor_column', RecordConfigService::cursorDefaultColumn()),
                    limit: $perPage ?? $limit,
                    tenantEnabled: $tenantEnabled,
                    queryFingerprint: app(RecordCacheService::class)->queryFingerprint($request)
                );
            } else {
                $cacheKey = $service->generateOptimizedCacheKey(
                    table: $table,
                    filters: $cacheFilters,
                    includes: $includes,
                    page: $page,
                    limit: $perPage ?? $limit,
                    tenantEnabled: $tenantEnabled,
                    queryFingerprint: app(RecordCacheService::class)->queryFingerprint($request)
                );
            }

            // List and single-record shapes share filters (e.g. id=eq.X), so
            // the shape must be part of the key. Without it, whichever shape
            // is cached first is served to the other and the payload breaks.
            $cacheKey .= ':shape:' . ($isArray ? 'list' : 'single');

            $cached = QueryCacheService::get($cacheKey);
            if (null !== $cached) {
                return array_merge($cached, ['from_cache' => true, 'filters' => $filters, 'request' => $request]);
            }
        }

        if (!$builder instanceof Builder) {
            $builder = $service->createReadBuilder($actualTableName);
            $service->applyTenantFilter($builder, $actualTableName, $tenantId);

            if ($tableSchema instanceof RecordTableType && $tableSchema->softDeletes) {
                if ($request->boolean('only_trashed')) {
                    $builder->whereNotNull($actualTableName . '.deleted_at');
                } elseif (!$request->boolean('with_trashed')) {
                    $builder->whereNull($actualTableName . '.deleted_at');
                }
            }
        } else {
            // If a builder is passed in, we assume the caller has already applied tenant and soft delete filters
            // if they wanted to. Applying them again here causes duplicate WHERE clauses.
            // We only apply tenant filter if it's explicitly requested and not already applied.
            // For safety, we'll let the caller handle it or we can check if the builder already has the condition.
            // To fix the duplicate `deleted_at IS NULL` issue, we remove the redundant soft delete check here.
        }

        if ($request->boolean('distinct')) {
            $builder->distinct();
        }

        $defaultOrderBy = $tableSchema->primaryKey ?? 'id';
        if ('' !== $orderBy && 'id' !== $orderBy) {
            $defaultOrderBy = $orderBy;
        }

        $service->applyIndexHint($builder, $table, 'list');

        QueryBuilderFiltersUtils::apply($builder, $request, $actualTableName, $defaultOrderBy);

        $headers = [];
        $meta = [];
        $data = [];
        $total = 0;

        // Explain query profiling (opt-in via ?explain=true)
        $explainResult = null;
        if (RecordConfigService::profilingEnabled() && $request->boolean('explain')) {
            $explainBuilder = clone $builder;
            $explainResult = $service->explainQuery($explainBuilder);
        }

        $aggregateResult = QueryBuilderFiltersUtils::applyAggregateAndGroupBy($builder, $request, $actualTableName);

        if (null !== $aggregateResult) {
            $data = $aggregateResult['data'];
            $meta = $aggregateResult['meta'];
            $headers = $aggregateResult['headers'];
        } elseif ($request->has('limit') && !$request->has('per_page')) {
            $limit = max(1, min((int) $request->input('limit'), RecordConfigService::limitMax()));
            $data = $builder->limit($limit)->get()->all();
            if ($service->shouldIncludeLimitedTotal($request)) {
                $total = count($data);
                $headers['X-Total-Count'] = (string) $total;
                $meta = ['total' => $total];
            }
        } else {
            [$data, $meta, $headers, $total] = $service->executePagination($builder, $request, $defaultOrderBy);
        }

        $selectParam = $request->query('select', '');
        $effectiveSelectParam = self::getCombinedSelectParam($request);

        if ($effectiveSelectParam !== '') {
            $includes = RelationshipResolverUtils::parseSelectForIncludes($effectiveSelectParam);
            $useSubqueryOptimization = count($data) <= RecordConfigService::subqueryOptimizationMaxRecords();

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
                    $optimizedBuilder = $service->createReadBuilder($actualTableName);
                    $mainCols = RelationshipResolverUtils::getMainTableColumns(is_string($selectParam) ? $selectParam : '');
                    RelationshipResolverUtils::validateMainTableColumns($table, $mainCols);
                    // Strip computed attribute keys — they are not real DB columns
                    $attributeKeys = $tableSchema instanceof RecordTableType ? array_keys($tableSchema->attributes ?? []) : [];
                    $dbMainCols = $attributeKeys !== []
                        ? array_values(array_diff($mainCols, $attributeKeys))
                        : $mainCols;
                    if ([] !== $dbMainCols) {
                        $prefixedCols = array_map(fn($col): string => '*' === $col ? $actualTableName . '.*' : (str_contains($col, '.') ? $col : $actualTableName . '.' . $col), $dbMainCols);
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
                        $service->resolveRelationshipTenantId($request, $tenantId)
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
                    $effectiveSelectParam,
                    $service->resolveRelationshipTenantId($request, $tenantId)
                );
            }
        }

        $data = RecordApiResponseService::removeDeletedAtFields($data);
        $data = RecordApiResponseService::removeHiddenFields($data, $table);
        $data = RecordApiResponseService::convertCompositeFields($data, $table);
        $data = RecordApiResponseService::applyCasts($data, $tableSchema instanceof RecordTableType ? ($tableSchema->columns ?? []) : [], $tableSchema instanceof RecordTableType ? ($tableSchema->casting ?? []) : []);
        if ($tableSchema instanceof RecordTableType && !empty($tableSchema->attributes)) {
            $requestedCols = $effectiveSelectParam !== ''
                ? RelationshipResolverUtils::getMainTableColumns(is_string($selectParam) ? $selectParam : '')
                : [];
            RelationshipResolverUtils::validateMainTableColumns($table, $requestedCols);
            $data = RecordApiResponseService::applyAttributes($data, $table, $tableSchema->attributes, $requestedCols);
        }

        $recordCount = count($data);

        if ($isArray === false) {
            $data = $data[0] ?? [];
        }

        if ($isCacheable && $cacheKey) {
            $cacheData = [
                'data' => $data,
                'meta' => $meta,
                'headers' => $headers,
                'cached_at' => TimeUtils::now()->toISOString(),
                'tenant_enabled' => $tableSchema instanceof RecordTableType && $service->shouldApplyTenantId($tableSchema),
            ];
            $ttl = $service->calculateOptimalCacheTTL($table, $recordCount, $effectiveSelectParam !== '');
            QueryCacheService::put($cacheKey, self::cacheSafePayload($cacheData), $ttl);
        }

        if ($service->shouldIncludeDebug($request)) {
            $meta['debug']['lazy_stats'] = QueryBuilderFiltersUtils::getLazyStats();
            $meta['debug']['cache_stats'] = QueryCacheService::requestStats();
            $tenantContextStats = $request->attributes->get('record_pgsql_tenant_context_stats');
            if (is_array($tenantContextStats)) {
                $meta['debug']['pgsql_tenant_context'] = $tenantContextStats;
            }
        }

        if ($explainResult !== null) {
            $meta['debug']['explain'] = $explainResult;
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

        $selectParam = $request->query('select', '');
        $effectiveSelectParam = self::getCombinedSelectParam($request);

        $tenantEnabled = $this->shouldApplyTenantId($tableSchema);
        $recordCacheKey = $this->generateRecordCacheKey(
            table: $table,
            id: $id,
            tenantId: $tenantId,
            select: $effectiveSelectParam,
            tenantEnabled: $tenantEnabled,
            queryFingerprint: $this->cacheService()->queryFingerprint($request)
        );
        if ($this->isCacheableRequest(request: $request, table: $table)) {
            $cachedRecord = QueryCacheService::get($recordCacheKey);
            if (null !== $cachedRecord) {
                return ['data' => $cachedRecord, 'request' => $request, 'from_cache' => true];
            }
        }

        $builder = $this->createReadBuilder($actualTableName);
        $this->applyTenantFilter($builder, $actualTableName, $tenantId);

        if ($tableSchema->softDeletes && !$request->boolean('with_trashed')) {
            $builder->whereNull($actualTableName . '.deleted_at');
        }

        $mainCols = [];
        $dbMainCols = [];
        if ($effectiveSelectParam !== '') {
            $mainCols = RelationshipResolverUtils::getMainTableColumns(is_string($selectParam) ? $selectParam : '');
            RelationshipResolverUtils::validateMainTableColumns($table, $mainCols);
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

        if ($effectiveSelectParam !== '') {
            $includes = RelationshipResolverUtils::parseSelectForIncludes($effectiveSelectParam);
            $useSubqueryOptimization = true;

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

            if ($useSubqueryOptimization && [] !== $includes) {
                $optimizedBuilder = $this->createReadBuilder($actualTableName);
                if ([] !== $dbMainCols) {
                    $prefixedCols = array_map(fn($col): string => '*' === $col ? $actualTableName . '.*' : (str_contains($col, '.') ? $col : $actualTableName . '.' . $col), $dbMainCols);
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
                    $this->resolveRelationshipTenantId($request, $tenantId)
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
                    $effectiveSelectParam,
                    $this->resolveRelationshipTenantId($request, $tenantId)
                );
                $record = $data[0] ?? $record;
            }
        }

        $record = RecordApiResponseService::removeDeletedAtFields($record);
        $record = RecordApiResponseService::removeHiddenFields($record, $table);
        $record = RecordApiResponseService::convertCompositeFields($record, $table);
        $record = RecordApiResponseService::applyCasts($record, $tableSchema->columns ?? [], $tableSchema->casting ?? []);
        if (!empty($tableSchema->attributes)) {
            $requestedCols = $effectiveSelectParam !== ''
                ? RelationshipResolverUtils::getMainTableColumns(is_string($selectParam) ? $selectParam : '')
                : [];
            RelationshipResolverUtils::validateMainTableColumns($table, $requestedCols);
            $record = RecordApiResponseService::applyAttributes($record, $table, $tableSchema->attributes, $requestedCols);
        }

        if ($this->isCacheableRequest($request, $table)) {
            $ttl = $this->calculateOptimalCacheTTL($table, 1, $effectiveSelectParam !== '');
            // Single records are stdClass objects off the query builder; cache
            // JSON-safe arrays only (see listRecords for why).
            QueryCacheService::put($recordCacheKey, self::cacheSafePayload($record), $ttl);
        }

        return ['data' => $record, 'request' => $request];
    }

    /**
     * Execute a custom function based on its configuration.
     * @param array<string, string> $routeParams
     */
    private function executeCustomFunction(Request $request, array|RecordFunctionType $functionConfig, array $routeParams = []): Response
    {
        if ($functionConfig instanceof RecordFunctionType) {
            $config = $functionConfig->toArray();
        } else {
            $config = $functionConfig;
        }

        if (!array_key_exists('isPublic', $config) && (!array_key_exists('pmsName', $config) || $config['pmsName'] === null)) {
            $config['isPublic'] = true;
        }

        $isPublic = (bool) ($config['isPublic'] ?? false);
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

        return $this->executeClassFunction(request: $request, functionConfig: $config, routeParams: $routeParams);
    }

    /**
     * Execute a class-based custom function.
     * @param array<string, mixed> $functionConfig
     * @param array<string, string> $routeParams
     */
    private function executeClassFunction(Request $request, array $functionConfig, array $routeParams = []): Response
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

            $result = $instance->{$method}($request, ...array_values($routeParams));

            if ($result instanceof JsonResponse) {
                $responseData = $result->getData();

                // If already a standard API response (has success + error_code), return as-is
                if (is_object($responseData) && property_exists($responseData, 'success') && property_exists($responseData, 'error_code')) {
                    return $result;
                }

                $statusCode = $result->getStatusCode();
                $meta = [];
                $records = null;

                if (empty($responseData->data)) {
                    $records = $responseData;
                } else {
                    $records = $responseData->data;
                    $meta = [];
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

            if ($result instanceof Response) {
                return $result;
            }

            return RecordApiResponseService::successWrapped($result);
        } catch (Exception $exception) {
            return RecordApiResponseService::errorFromException(
                exception: $exception,
                message: $exception->getMessage(),
                status: RecordApiJsonResponseEnum::SERVER_ERROR->value
            );
        }
    }

    /**
     * @param array<string,mixed> $registry
     *
     * @return array{config:mixed,routeParams:array<string,string>}
     */
    private function resolveFunctionConfigAndRouteParams(array $registry, string $requestedFunctionName): array
    {
        if (array_key_exists($requestedFunctionName, $registry)) {
            return [
                'config' => $registry[$requestedFunctionName],
                'routeParams' => [],
            ];
        }

        foreach ($registry as $configuredFunctionName => $config) {
            if (!is_string($configuredFunctionName)) {
                continue;
            }

            if ('' === $configuredFunctionName) {
                continue;
            }

            $routeParams = $this->extractRouteParamsFromFunctionPattern($configuredFunctionName, $requestedFunctionName);
            if (null !== $routeParams) {
                return [
                    'config' => $config,
                    'routeParams' => $routeParams,
                ];
            }
        }

        return [
            'config' => null,
            'routeParams' => [],
        ];
    }

    /**
     * @return array<string,string>|null
     */
    private function extractRouteParamsFromFunctionPattern(string $configuredFunctionName, string $requestedFunctionName): ?array
    {
        $parameterNames = [];
        $escapedPattern = preg_quote($configuredFunctionName, '/');
        $escapedPattern = (string) preg_replace_callback(
            '/\\\\\{([^\\\\}]+)\\\\\}/',
            static function (array $matches) use (&$parameterNames): string {
                $parameterNames[] = $matches[1];

                return '([^\/]+)';
            },
            $escapedPattern
        );

        $pattern = '/^' . $escapedPattern . '$/';
        $matches = [];
        if (1 !== preg_match($pattern, $requestedFunctionName, $matches)) {
            return null;
        }

        if ([] === $parameterNames) {
            return [];
        }

        $routeParams = [];
        foreach ($parameterNames as $index => $name) {
            $matchIndex = $index + 1;
            if (!isset($matches[$matchIndex])) {
                continue;
            }

            $routeParams[$name] = urldecode($matches[$matchIndex]);
        }

        return $routeParams;
    }

    /**
     * @param array<string, mixed> $row
     */
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

    private function detectUuidCursorColumn(Builder $builder, string $column): bool
    {
        $table = $builder->from;

        // Try schema registry first
        $schema = SchemaRegistryUtils::get();
        if (is_string($table) && isset($schema[$table])) {
            $tableSchema = $schema[$table];
            if ($tableSchema instanceof RecordTableType) {
                $colDef = $tableSchema->columns[$column] ?? null;
                if ($colDef !== null) {
                    return ($colDef['type'] ?? '') === 'uuid' || ($colDef['udt_name'] ?? '') === 'uuid';
                }
            }
        }

        // Fallback: sample a row to detect non-numeric IDs
        if (is_string($table)) {
            try {
                $sample = (clone $builder)->select($column)->limit(1)->first();
                if ($sample && isset($sample->{$column}) && is_string($sample->{$column})) {
                    return !ctype_digit((string) $sample->{$column});
                }
            } catch (Throwable) {
            }
        }

        return false;
    }
}
