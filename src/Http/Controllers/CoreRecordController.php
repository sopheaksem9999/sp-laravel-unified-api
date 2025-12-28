<?php

namespace Sopheak\Core\Http\Controllers;

use Exception;
use RuntimeException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Sopheak\Core\Enums\RecordApiJsonResponseEnum;
use Sopheak\Core\Services\QueryCacheService;
use Sopheak\Core\Services\RecordApiResponseService;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Support\PermissionHelper;
use Sopheak\Core\Support\SchemaRegistry;
use Sopheak\Core\Jobs\ProcessBulkOperationJob;

class CoreRecordController extends Controller
{
    /**
     * Cache for schema lookups to reduce repeated calls.
     */
    private static array $schemaCache = [];

    /**
     * Cache for tenant configuration to avoid repeated config calls.
     */
    private static ?bool $tenantIdEnabled = null;

    public function __construct(
        protected RecordService $recordService
    ) {}

    /**
     * List records for a table.
     */
    public function listRecords(Request $request, string $table): JsonResponse
    {
        $this->authorizeAction($table, 'read');
        $schema = $this->getCachedSchema();
        if (!isset($schema[$table])) {
            return RecordApiResponseService::errorWrapped('Resource not available', RecordApiJsonResponseEnum::NOT_FOUND->value);
        }

        $tenantId = $this->recordService->normalizeTenantId($request->header(config('record.tenant_header', 'X-Tenant-ID')));
        if (($response = $this->validateTenantIdRequired($schema[$table], $tenantId)) instanceof JsonResponse) {
            return $response;
        }

        try {
            $result = $this->recordService->listRecords($request, $table, $tenantId);

            $data = $result['data'];
            $meta = $result['meta'];
            $headers = $result['headers'];
            $request = $result['request'];

            if (isset($result['from_cache']) && $result['from_cache']) {
                return RecordApiResponseService::successWrapped($data, $meta, RecordApiJsonResponseEnum::SUCCESS->value, $headers);
            }

            $response = RecordApiResponseService::successWrapped($data, $meta, RecordApiJsonResponseEnum::SUCCESS->value, $headers);

            $this->recordService->executeTableTrigger(
                $schema[$table]->afterRead ?? null,
                [
                    $request,
                    $table,
                    [
                        'type' => 'index',
                        'filters' => $result['filters'] ?? [],
                        'data' => $data,
                        'meta' => $meta,
                        config('record.tenant_column', 'tenant_id') => $tenantId,
                        'response' => $response,
                    ],
                ]
            );

            return $response;
        } catch (Exception $exception) {
            return RecordApiResponseService::errorWrapped('Failed to list records: ' . $exception->getMessage(), RecordApiJsonResponseEnum::SERVER_ERROR->value);
        }
    }

    /**
     * Get a single record by ID.
     */
    public function getRecordById(Request $request, string $table, mixed $id): JsonResponse
    {
        $this->authorizeAction($table, 'read');
        $schema = $this->getCachedSchema();
        if (!isset($schema[$table])) {
            return RecordApiResponseService::errorWrapped('Resource not available', RecordApiJsonResponseEnum::NOT_FOUND->value);
        }

        $tenantId = $this->recordService->normalizeTenantId($request->header(config('record.tenant_header', 'X-Tenant-ID')));
        if (($response = $this->validateTenantIdRequired($schema[$table], $tenantId)) instanceof JsonResponse) {
            return $response;
        }

        try {
            $result = $this->recordService->getRecord($request, $table, $id, $tenantId);
            $record = $result['data'];
            $request = $result['request'];

            if (isset($result['from_cache']) && $result['from_cache']) {
                return RecordApiResponseService::successWrapped($record);
            }

            if (!$record) {
                return RecordApiResponseService::errorWrapped('Not found', RecordApiJsonResponseEnum::NOT_FOUND->value);
            }

            $response = RecordApiResponseService::successWrapped($record);

            $this->recordService->executeTableTrigger(
                $schema[$table]->afterRead ?? null,
                [
                    $request,
                    $table,
                    [
                        'type' => 'show',
                        'id' => $id,
                        config('record.tenant_column', 'tenant_id') => $tenantId,
                        'record' => $record,
                        'response' => $response,
                    ],
                ]
            );

            return $response;
        } catch (Exception $exception) {
            return RecordApiResponseService::errorWrapped('Failed to retrieve record: ' . $exception->getMessage(), RecordApiJsonResponseEnum::SERVER_ERROR->value);
        }
    }

    /**
     * Create a new record.
     */
    public function createRecord(Request $request, string $table): JsonResponse
    {
        $this->authorizeAction($table, 'create');
        $schema = SchemaRegistry::get();
        if (!isset($schema[$table])) {
            return RecordApiResponseService::errorWrapped('Resource not available', RecordApiJsonResponseEnum::NOT_FOUND->value);
        }

        // Resolve actual table name from RecordTableType configuration
        $this->resolveActualTableName($table);
        $tenantId = $this->recordService->normalizeTenantId($request->header(config('record.tenant_header', 'X-Tenant-ID')));
        if (($response = $this->validateTenantIdRequired($schema[$table], $tenantId)) instanceof JsonResponse) {
            return $response;
        }

        $triggerParams = [
            $request,
            $table,
            [
                config('record.tenant_column', 'tenant_id') => $tenantId,
            ],
        ];
        $triggerParams = $this->recordService->executeTableTrigger($schema[$table]->beforeCreate ?? null, $triggerParams);
        if (isset($triggerParams[0]) && $triggerParams[0] instanceof Request) {
            $request = $triggerParams[0];
        }

        $validatorCallback = $schema[$table]->createValidator ?? null;
        if ($validatorCallback) {
            $validator = $validatorCallback($request, null);
            if (!$validator instanceof \Illuminate\Contracts\Validation\Validator) {
                throw new RuntimeException('Validator callback must return a Validator instance');
            }

            if ($validator->fails()) {
                return RecordApiResponseService::errorWrapped('Validation failed', RecordApiJsonResponseEnum::VALIDATION_ERROR->value, $validator->errors()->toArray());
            }
        }

        $payload = $request->all();

        // Begin transaction
        DB::beginTransaction();

        try {
            // Use RecordService to create the record
            $result = $this->recordService->createRecord($request, $table, $payload, $tenantId);
            $insertedId = $result['id'];

            // Commit transaction
            DB::commit();
            QueryCacheService::invalidateTable($table);

            $record = $this->getRecordById($request, $table, $insertedId);

            // Execute Post-Write Logic (Triggers and Audit Logs)
            $this->recordService->processPostWriteLogic($request, $table, 'create', [
                'id' => $insertedId,
                'payload' => $result['payload'],
                config('record.tenant_column', 'tenant_id') => $result['tenant_id'],
                'response' => $record,
            ]);

            return $record;
        } catch (Exception $exception) {
            // Rollback transaction on any error
            DB::rollBack();

            return RecordApiResponseService::errorWrapped('Failed to create record: ' . $exception->getMessage(), RecordApiJsonResponseEnum::SERVER_ERROR->value);
        }
    }

    /**
     * Update a record by ID.
     *
     * @param [type] $id
     */
    public function updateRecord(Request $request, string $table, string $id): JsonResponse
    {
        $this->authorizeAction($table, 'update');
        $schema = SchemaRegistry::get();
        if (!isset($schema[$table])) {
            return RecordApiResponseService::errorWrapped('Resource not available', RecordApiJsonResponseEnum::NOT_FOUND->value);
        }

        // Resolve actual table name from RecordTableType configuration
        $this->resolveActualTableName($table);
        $tenantId = $this->recordService->normalizeTenantId($request->header(config('record.tenant_header', 'X-Tenant-ID')));
        if (($response = $this->validateTenantIdRequired($schema[$table], $tenantId)) instanceof JsonResponse) {
            return $response;
        }

        $triggerParams = [
            $request,
            $table,
            [
                'id' => $id,
                config('record.tenant_column', 'tenant_id') => $tenantId,
            ],
        ];

        // Execute beforeUpdate trigger
        $triggerParams = $this->recordService->executeTableTrigger($schema[$table]->beforeUpdate ?? null, $triggerParams);
        if (isset($triggerParams[0]) && $triggerParams[0] instanceof Request) {
            $request = $triggerParams[0];
        }

        $validatorCallback = $schema[$table]->updateValidator ?? null;
        if ($validatorCallback) {
            $validator = $validatorCallback($request, $id);
            if (!$validator instanceof \Illuminate\Contracts\Validation\Validator) {
                throw new RuntimeException('Validator callback must return a Validator instance');
            }

            if ($validator->fails()) {
                return RecordApiResponseService::errorWrapped('Validation failed', RecordApiJsonResponseEnum::VALIDATION_ERROR->value, $validator->errors()->toArray());
            }
        }

        $payload = $request->all();

        // Begin transaction
        DB::beginTransaction();

        try {
            // Use RecordService to update the record
            $result = $this->recordService->updateRecord($request, $table, $id, $payload, $tenantId);
            $updated = $result['updated'];

            // Handle not found
            if (!$result['exists']) {
                DB::rollBack();

                return RecordApiResponseService::errorWrapped('Not found', RecordApiJsonResponseEnum::NOT_FOUND->value);
            }

            // Commit transaction
            DB::commit();
            QueryCacheService::invalidateTable($table);

            $record = $this->getRecordById($request, $table, $id);

            // Execute Post-Write Logic (Triggers and Audit Logs)
            $this->recordService->processPostWriteLogic($request, $table, 'update', [
                'id' => $id,
                'payload' => $result['payload'],
                config('record.tenant_column', 'tenant_id') => $result['tenant_id'],
                'updated' => $updated,
                'response' => $record,
            ]);

            return $record;
        } catch (Exception $exception) {
            // Rollback transaction on any error
            DB::rollBack();

            return RecordApiResponseService::errorWrapped('Failed to update record: ' . $exception->getMessage(), RecordApiJsonResponseEnum::SERVER_ERROR->value);
        }
    }

    /**
     * Delete a record by ID.
     *
     * @param [type] $id
     */
    public function destroyRecord(Request $request, string $table, string $id): JsonResponse
    {
        $this->authorizeAction($table, 'delete');
        $schema = SchemaRegistry::get();
        if (!isset($schema[$table])) {
            return RecordApiResponseService::errorWrapped('Resource not available', RecordApiJsonResponseEnum::NOT_FOUND->value);
        }

        // Resolve actual table name from RecordTableType configuration
        $this->resolveActualTableName($table);
        $tenantId = $this->recordService->normalizeTenantId($request->header(config('record.tenant_header', 'X-Tenant-ID')));
        if (($response = $this->validateTenantIdRequired($schema[$table], $tenantId)) instanceof JsonResponse) {
            return $response;
        }

        $triggerParams = [
            $request,
            $table,
            [
                'id' => $id,
                config('record.tenant_column', 'tenant_id') => $tenantId,
            ],
        ];

        // Execute beforeDelete trigger if defined
        $triggerParams = $this->recordService->executeTableTrigger($schema[$table]->beforeDelete ?? null, $triggerParams);
        if (isset($triggerParams[0]) && $triggerParams[0] instanceof Request) {
            $request = $triggerParams[0];
        };

        $validatorCallback = $schema[$table]->deleteValidator ?? null;
        if ($validatorCallback) {
            $validator = $validatorCallback($request, $id);
            if (!$validator instanceof \Illuminate\Contracts\Validation\Validator) {
                throw new RuntimeException('Validator callback must return a Validator instance');
            }

            if ($validator->fails()) {
                return RecordApiResponseService::errorWrapped('Validation failed', RecordApiJsonResponseEnum::VALIDATION_ERROR->value, $validator->errors()->toArray());
            }
        }

        $affected = 0;
        // Begin transaction
        DB::beginTransaction();

        try {
            // Use RecordService to delete the record
            $result = $this->recordService->deleteRecord($request, $table, $id, $tenantId);
            $affected = $result['affected'];

            if (0 === $affected) {
                DB::rollBack();

                return RecordApiResponseService::errorWrapped('Not found', RecordApiJsonResponseEnum::NOT_FOUND->value);
            }

            // Commit transaction
            DB::commit();

            QueryCacheService::invalidateTable($table);

            $response = RecordApiResponseService::successWrapped(['deleted' => $affected]);

            // Execute Post-Write Logic (Triggers and Audit Logs)
            $this->recordService->processPostWriteLogic($request, $table, 'delete', [
                'id' => $id,
                config('record.tenant_column', 'tenant_id') => $tenantId,
                'affected' => $affected,
                'soft_deleted' => $schema[$table]->soft_deletes,
                'response' => $response,
            ]);

            return $response;
        } catch (Exception $exception) {
            // Rollback transaction on any error
            DB::rollBack();
            return RecordApiResponseService::errorWrapped('Failed to delete record: ' . $exception->getMessage(), RecordApiJsonResponseEnum::SERVER_ERROR->value);
        }
    }

    /**
     * Restore a soft-deleted record.
     *
     * @param [type] $id
     */
    public function restoreRecord(Request $request, string $table, string $id): JsonResponse
    {
        $this->authorizeAction($table, 'restore');
        $schema = SchemaRegistry::get();
        if (!isset($schema[$table]) || !$schema[$table]->soft_deletes) {
            return RecordApiResponseService::errorWrapped('Resource not restorable', RecordApiJsonResponseEnum::ERROR->value);
        }

        // Resolve actual table name from RecordTableType configuration
        $this->resolveActualTableName($table);

        $tenantId = $this->recordService->normalizeTenantId($request->header(config('record.tenant_header', 'X-Tenant-ID')));
        if (($response = $this->validateTenantIdRequired($schema[$table], $tenantId)) instanceof JsonResponse) {
            return $response;
        }

        $affected = 0;

        // Begin transaction
        DB::beginTransaction();

        try {
            // Use RecordService to restore the record
            $result = $this->recordService->restoreRecord($request, $table, $id, $tenantId);
            $affected = $result['restored'];

            if (0 === $affected) {
                DB::rollBack();

                return RecordApiResponseService::errorWrapped('Not found', RecordApiJsonResponseEnum::NOT_FOUND->value);
            }

            // Commit transaction
            DB::commit();

            QueryCacheService::invalidateTable($table);

            $record = $this->getRecordById($request, $table, $id);

            // Execute Post-Write Logic (Triggers and Audit Logs)
            $this->recordService->processPostWriteLogic($request, $table, 'update', [
                'id' => $id,
                'payload' => [], // No payload for restore
                config('record.tenant_column', 'tenant_id') => $tenantId,
                'restored' => $affected,
                'response' => $record,
            ]);

            return RecordApiResponseService::successWrapped(['restored' => $affected]);
        } catch (Exception $exception) {
            // Rollback transaction on any error
            DB::rollBack();

            return RecordApiResponseService::errorWrapped('Failed to restore record: ' . $exception->getMessage(), RecordApiJsonResponseEnum::SERVER_ERROR->value);
        }
    }

    /**
     * Force delete a record (bypass soft delete).
     */
    public function forceDeleteRecord(Request $request, string $table, string $id): JsonResponse
    {
        $this->authorizeAction($table, 'delete');
        $schema = SchemaRegistry::get();
        if (!isset($schema[$table])) {
            return RecordApiResponseService::errorWrapped('Resource not available', RecordApiJsonResponseEnum::NOT_FOUND->value);
        }

        // Resolve actual table name from RecordTableType configuration
        $this->resolveActualTableName($table);

        $tenantId = $this->recordService->normalizeTenantId($request->header(config('record.tenant_header', 'X-Tenant-ID')));
        if (($response = $this->validateTenantIdRequired($schema[$table], $tenantId)) instanceof JsonResponse) {
            return $response;
        }

        $deleted = 0;

        // Begin transaction
        DB::beginTransaction();

        try {
            // Use RecordService to force delete the record
            $result = $this->recordService->forceDeleteRecord($request, $table, $id, $tenantId);
            $deleted = $result['deleted'];

            if (0 === $deleted) {
                DB::rollBack();

                return RecordApiResponseService::errorWrapped('Not found', RecordApiJsonResponseEnum::NOT_FOUND->value);
            }

            // Commit transaction
            DB::commit();

            QueryCacheService::invalidateTable($table);

            $response = RecordApiResponseService::successWrapped(['deleted' => $deleted]);

            // Execute Post-Write Logic (Triggers and Audit Logs)
            $this->recordService->processPostWriteLogic($request, $table, 'delete', [
                'id' => $id,
                config('record.tenant_column', 'tenant_id') => $tenantId,
                'deleted' => $deleted,
                'force_deleted' => true,
                'response_data' => ['id' => $id], // Preserve original audit log data
                'response' => $response,
            ]);

            return $response;
        } catch (Exception $exception) {
            // Rollback transaction on any error
            DB::rollBack();

            return RecordApiResponseService::errorWrapped('Failed to force delete record: ' . $exception->getMessage(), RecordApiJsonResponseEnum::SERVER_ERROR->value);
        }
    }

    /**
     * Batch create, update, or delete records in a single API call.
     */
    public function bulkRecord(Request $request, string $table, ?string $legacyAction = null): JsonResponse
    {
        // Check all permissions for mixed operations
        $schema = SchemaRegistry::get();
        if (!isset($schema[$table])) {
            return RecordApiResponseService::errorWrapped('Resource not available', RecordApiJsonResponseEnum::NOT_FOUND->value);
        }

        // Resolve actual table name from RecordTableType configuration
        $actualTableName = (string) $this->resolveActualTableName($table);

        $this->authorizeAction($actualTableName, 'create');
        $this->authorizeAction($actualTableName, 'update');
        $this->authorizeAction($actualTableName, 'delete');

        $tenantId = $this->recordService->normalizeTenantId($request->header(config('record.tenant_header', 'X-Tenant-ID')));
        if (($response = $this->validateTenantIdRequired($schema[$table], $tenantId)) instanceof JsonResponse) {
            return $response;
        }

        try {
            $result = $this->recordService->bulkRecord($request, $table, $tenantId, $legacyAction);
            return RecordApiResponseService::successWrapped($result['data'], $result['meta']);
        } catch (Exception $exception) {
            $code = $exception->getCode();
            if (!is_int($code) || $code < 100 || $code > 599) {
                $code = RecordApiJsonResponseEnum::SERVER_ERROR->value;
            }

            return RecordApiResponseService::errorWrapped($exception->getMessage(), $code);
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
    public function bulkRecordCreate(Request $request, string $table): JsonResponse
    {
        $this->authorizeAction($table, 'create');
        $schema = SchemaRegistry::get();
        if (!isset($schema[$table])) {
            return RecordApiResponseService::errorWrapped('Resource not available', RecordApiJsonResponseEnum::NOT_FOUND->value);
        }

        // Resolve actual table name from RecordTableType configuration
        $this->resolveActualTableName($table);

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
            'items' => 'required|array|min:1|max:' . config('record.bulk_max', 100),
            'items.*' => 'required|array',
        ], [
            'items.required' => 'Payload must be an array',
            'items.array' => 'Payload must be an array',
            'items.min' => 'At least one item is required',
            'items.max' => 'Maximum ' . config('record.bulk_max', 100) . ' items allowed',
            'items.*.required' => 'Each item is required',
            'items.*.array' => 'Each item must be an object',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $tenantId = $this->recordService->normalizeTenantId($request->header(config('record.tenant_header', 'X-Tenant-ID')));
        if (($response = $this->validateTenantIdRequired($schema[$table], $tenantId)) instanceof JsonResponse) {
            return $response;
        }

        // Async processing
        if ($request->boolean('async') || $request->header('X-Async-Process')) {
            return $this->dispatchAsyncBulk($request, $table, 'create', $items, $tenantId);
        }

        $pk = $schema[$table]->primary_key ?? 'id';

        $createdData = [];
        $affected = 0;

        DB::beginTransaction();

        try {
            foreach ($items as $index => $item) {
                // Validate that no ID is provided for create operation
                if (isset($item[$pk])) {
                    throw ValidationException::withMessages([
                        sprintf('items.%s.%s', $index, $pk) => 'Primary key should not be provided for create operation',
                    ]);
                }

                // Execute beforeCreate trigger
                $this->recordService->executeTableTrigger($schema[$table]->beforeCreate ?? null, [$request, $table, $item]);

                $result = $this->recordService->createRecord($request, $table, $item, $tenantId);
                $insertId = $result['id'];

                // Get created record using show method
                $createdRecord = $this->getRecordById($request, $table, $insertId);
                $recordData = json_decode(json_encode($createdRecord->getData()->data), true);
                $createdData[] = $recordData;
                ++$affected;

                // Post-write logic
                $this->recordService->processPostWriteLogic($request, $table, 'create', [
                    'id' => $insertId,
                    'payload' => $result['payload'],
                    config('record.tenant_column', 'tenant_id') => $result['tenant_id'],
                    'response' => $createdRecord,
                ]);
            }

            DB::commit();

            return RecordApiResponseService::successWrapped($createdData, ['affected' => $affected]);
        } catch (Exception $exception) {
            DB::rollBack();

            if ($exception instanceof ValidationException) {
                return RecordApiResponseService::errorWrapped('Validation failed', RecordApiJsonResponseEnum::VALIDATION_ERROR->value, $exception->errors());
            }

            return RecordApiResponseService::errorWrapped('Failed to perform bulk create operation: ' . $exception->getMessage(), RecordApiJsonResponseEnum::SERVER_ERROR->value);
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
    public function bulkRecordUpdate(Request $request, string $table): JsonResponse
    {
        $this->authorizeAction($table, 'update');
        $schema = SchemaRegistry::get();
        if (!isset($schema[$table])) {
            return RecordApiResponseService::errorWrapped('Resource not available', RecordApiJsonResponseEnum::NOT_FOUND->value);
        }

        // Resolve actual table name from RecordTableType configuration
        $this->resolveActualTableName($table);
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
            'items' => 'required|array|min:1|max:' . config('record.bulk_max', 100),
            'items.*' => 'required|array',
            'items.*.' . $pk => 'required',
        ], [
            'items.required' => 'Payload must be an array',
            'items.array' => 'Payload must be an array',
            'items.min' => 'At least one item is required',
            'items.max' => 'Maximum ' . config('record.bulk_max', 100) . ' items allowed',
            'items.*.required' => 'Each item is required',
            'items.*.array' => 'Each item must be an object',
            sprintf('items.*.%s.required', $pk) => sprintf('Primary key (%s) is required for update operation', $pk),
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $tenantId = $this->recordService->normalizeTenantId($request->header(config('record.tenant_header', 'X-Tenant-ID')));
        if (($response = $this->validateTenantIdRequired($schema[$table], $tenantId)) instanceof JsonResponse) {
            return $response;
        }

        // Async processing
        if ($request->boolean('async') || $request->header('X-Async-Process')) {
            return $this->dispatchAsyncBulk($request, $table, 'update', $items, $tenantId);
        }

        $updatedData = [];
        $affected = 0;

        DB::beginTransaction();

        try {
            foreach ($items as $index => $item) {
                // Validate that ID is provided and other fields exist
                if (!isset($item[$pk])) {
                    throw ValidationException::withMessages([
                        sprintf('items.%s.%s', $index, $pk) => sprintf('Primary key (%s) is required for update operation', $pk),
                    ]);
                }

                // Check if there are fields to update besides the primary key
                $updateFields = array_diff_key($item, [$pk => true]);
                if (empty($updateFields)) {
                    throw ValidationException::withMessages([
                        'items.' . $index => 'At least one field besides the primary key must be provided for update',
                    ]);
                }

                $id = $item[$pk];
                unset($item[$pk]);

                // Execute beforeUpdate trigger
                $this->recordService->executeTableTrigger($schema[$table]->beforeUpdate ?? null, [$request, $table, $id, $item]);

                $result = $this->recordService->updateRecord($request, $table, $id, $item, $tenantId);
                $updateCount = $result['updated'];

                if ($updateCount > 0) {
                    // Get updated record using show method
                    $updatedRecord = $this->getRecordById($request, $table, $id);
                    $recordData = json_decode(json_encode($updatedRecord->getData()->data), true);
                    $updatedData[] = $recordData;
                    $affected += $updateCount;

                    // Post-write logic
                    $this->recordService->processPostWriteLogic($request, $table, 'update', [
                        'id' => $id,
                        'payload' => $result['payload'],
                        config('record.tenant_column', 'tenant_id') => $result['tenant_id'],
                        'updated' => $updateCount,
                        'response' => $updatedRecord,
                    ]);
                } else {
                    // Record not found or no changes made
                    throw ValidationException::withMessages([
                        sprintf('items.%s.%s', $index, $pk) => sprintf("Record with %s '%s' not found or no changes detected", $pk, $id),
                    ]);
                }
            }

            DB::commit();

            return RecordApiResponseService::successWrapped($updatedData, ['affected' => $affected]);
        } catch (Exception $exception) {
            DB::rollBack();

            if ($exception instanceof ValidationException) {
                return RecordApiResponseService::errorWrapped('Validation failed', RecordApiJsonResponseEnum::VALIDATION_ERROR->value, $exception->errors());
            }

            return RecordApiResponseService::errorWrapped('Failed to perform bulk update operation: ' . $exception->getMessage(), RecordApiJsonResponseEnum::SERVER_ERROR->value);
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
    public function bulkRecordDelete(Request $request, string $table): JsonResponse
    {
        $this->authorizeAction($table, 'delete');
        $schema = SchemaRegistry::get();
        if (!isset($schema[$table])) {
            return RecordApiResponseService::errorWrapped('Resource not available', RecordApiJsonResponseEnum::NOT_FOUND->value);
        }

        // Resolve actual table name from RecordTableType configuration
        $this->resolveActualTableName($table);
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
            'items' => 'required|array|min:1|max:' . config('record.bulk_max', 100),
        ], [
            'items.required' => 'Payload must be an array',
            'items.array' => 'Payload must be an array',
            'items.min' => 'At least one item is required',
            'items.max' => 'Maximum ' . config('record.bulk_max', 100) . ' items allowed',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $tenantId = $this->recordService->normalizeTenantId($request->header(config('record.tenant_header', 'X-Tenant-ID')));
        if (($response = $this->validateTenantIdRequired($schema[$table], $tenantId)) instanceof JsonResponse) {
            return $response;
        }

        // Normalize items to extract IDs
        $idsToDelete = [];
        foreach ($items as $index => $item) {
            if (is_array($item)) {
                // Object format: {"id": 123}
                if (!isset($item[$pk])) {
                    throw ValidationException::withMessages([
                        sprintf('items.%s.%s', $index, $pk) => sprintf('Primary key (%s) is required for delete operation', $pk),
                    ]);
                }

                $idsToDelete[] = $item[$pk];
            } else {
                // Direct ID format: [123, 456, 789]
                if (empty($item)) {
                    throw ValidationException::withMessages([
                        'items.' . $index => 'ID value cannot be empty',
                    ]);
                }

                $idsToDelete[] = $item;
            }
        }

        // Remove duplicates
        $idsToDelete = array_unique($idsToDelete);

        // Async processing
        if ($request->boolean('async') || $request->header('X-Async-Process')) {
            $formattedItems = array_map(fn($id) => [$pk => $id], $idsToDelete);
            return $this->dispatchAsyncBulk($request, $table, 'delete', $formattedItems, $tenantId);
        }

        $deletedData = [];
        $affected = 0;

        DB::beginTransaction();

        try {
            foreach ($idsToDelete as $idToDelete) {
                // Execute beforeDelete trigger
                $this->recordService->executeTableTrigger($schema[$table]->beforeDelete ?? null, [$request, $table, $idToDelete]);

                $result = $this->recordService->deleteRecord($request, $table, $idToDelete, $tenantId);
                $deleteCount = $result['affected'];

                if ($deleteCount > 0) {
                    $deletedData[] = [$pk => $idToDelete];
                    $affected += $deleteCount;

                    $response = RecordApiResponseService::successWrapped(['deleted' => $deleteCount]);

                    // Post-write logic
                    $this->recordService->processPostWriteLogic($request, $table, 'delete', [
                        'id' => $idToDelete,
                        'payload' => ['id' => $idToDelete],
                        config('record.tenant_column', 'tenant_id') => $tenantId,
                        'affected' => $deleteCount,
                        'soft_deleted' => $schema[$table]->soft_deletes ?? false,
                        'response' => $response,
                    ]);
                }
            }

            DB::commit();

            QueryCacheService::invalidateTable($table);

            return RecordApiResponseService::successWrapped($deletedData, ['affected' => $affected]);
        } catch (Exception $exception) {
            DB::rollBack();
            if ($exception instanceof ValidationException) {
                return RecordApiResponseService::errorWrapped('Validation failed', RecordApiJsonResponseEnum::VALIDATION_ERROR->value, $exception->errors());
            }

            return RecordApiResponseService::errorWrapped('Failed to perform bulk delete operation: ' . $exception->getMessage(), RecordApiJsonResponseEnum::SERVER_ERROR->value);
        }
    }

    /**
     * Execute a table-specific custom function.
     */
    public function executeTableFunction(Request $request, string $table, string $functionName): JsonResponse
    {
        try {
            $schema = $this->getCachedSchema();
            if (!isset($schema[$table])) {
                return RecordApiResponseService::errorWrapped(sprintf("Table '%s' does not exist", $table), RecordApiJsonResponseEnum::NOT_FOUND->value);
            }

            // Resolve actual table name from RecordTableType configuration
            $actualTableName = (string) $this->resolveActualTableName($table);
            $this->authorizeAction($actualTableName, 'read');

            return $this->recordService->executeTableFunction($request, $table, $functionName);
        } catch (Exception $exception) {
            return RecordApiResponseService::errorWrapped($exception->getMessage(), $exception->getCode() ?: RecordApiJsonResponseEnum::SERVER_ERROR->value);
        }
    }

    /**
     * Execute a global custom function.
     * Supports patterns like: function_name or function_name/{id}.
     */
    public function executeGlobalFunction(Request $request, string $functionName): JsonResponse
    {
        try {
            return $this->recordService->executeGlobalFunction($request, $functionName);
        } catch (Exception $exception) {
            return RecordApiResponseService::errorWrapped($exception->getMessage(), $exception->getCode() ?: RecordApiJsonResponseEnum::SERVER_ERROR->value);
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
     * Normalize tenant ID.
     */
    private function normalizeTenantId(mixed $tenantId): mixed
    {
        if (is_string($tenantId)) {
            return trim($tenantId);
        }

        return $tenantId;
    }

    private function isTenantIdMissing(mixed $tenantId): bool
    {
        $tenantId = $this->normalizeTenantId($tenantId);

        return null === $tenantId || '' === $tenantId;
    }

    private function shouldApplyTenantId(object $tableSchema): bool
    {
        return $this->isTenantIdEnabled() && ($tableSchema->has_tenant_id ?? false);
    }

    private function validateTenantIdRequired(object $tableSchema, mixed $tenantId): ?JsonResponse
    {
        if (!$this->shouldApplyTenantId($tableSchema)) {
            return null;
        }

        if ($this->isTenantIdMissing($tenantId)) {
            return RecordApiResponseService::errorWrapped('Validation failed', RecordApiJsonResponseEnum::VALIDATION_ERROR->value, [
                config('record.tenant_header', 'X-Tenant-ID') => ['header ' . config('record.tenant_header', 'X-Tenant-ID') . ' cannot be empty'],
            ]);
        }

        return null;
    }

    /**
     * Authorize the action for the given table.
     */
    private function authorizeAction(string $table, string $action): void
    {
        // Allow unauthenticated access for configured tables/actions (per-table config)
        if (PermissionHelper::isPublicAction($table, $action)) {
            return;
        }

        $guard = config('sp-laravel-api.auth.guard', 'api');
        $user = auth($guard)->user();
        if (!$user) {
            abort(RecordApiJsonResponseEnum::UNAUTHORIZED->value, 'Unauthenticated');
        }

        $perm = PermissionHelper::mapPermission($table, $action);

        if (!Gate::forUser($user)->allows($perm)) {
            abort(RecordApiJsonResponseEnum::FORBIDDEN->value, 'Forbidden');
        }
    }

    /**
     * Helper to dispatch async bulk job.
     */
    private function dispatchAsyncBulk(Request $request, string $table, string $operation, array $items, mixed $tenantId): JsonResponse
    {
        $user = auth(config('sp-laravel-api.auth.guard', 'api'))->user();

        $context = [
            'headers' => $request->headers->all(),
            'server' => $request->server->all(),
            'user_id' => $user?->id,
            'guard' => config('sp-laravel-api.auth.guard', 'api'),
        ];

        ProcessBulkOperationJob::dispatch($operation, $table, $items, $tenantId, $context);

        return RecordApiResponseService::successWrapped(
            ['status' => 'queued', 'message' => 'Bulk operation queued for processing'],
            [],
            202
        );
    }
}
