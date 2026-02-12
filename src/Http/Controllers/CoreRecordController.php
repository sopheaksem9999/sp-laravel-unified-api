<?php

namespace Sopheak\Core\Http\Controllers;

use Exception;
use RuntimeException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Sopheak\Core\Enums\RecordApiJsonResponseEnum;
use Sopheak\Core\Utilities\RecordUtils;
use Sopheak\Core\Services\QueryCacheService;
use Sopheak\Core\Services\RecordApiResponseService;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Utilities\PermissionUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Jobs\ProcessBulkOperationJob;

class CoreRecordController extends Controller
{
    public function __construct(
        protected RecordService $recordService
    ) {}

    /**
     * List records for a table.
     */
    public function listRecords(Request $request, string $table): JsonResponse
    {
        $tableSchema = SchemaRegistryUtils::getTable($table);
        if (!$tableSchema instanceof RecordTableType) {
            return RecordApiResponseService::errorWrapped('Resource not available', RecordApiJsonResponseEnum::NOT_FOUND->value);
        }

        if (!$this->isReadEndpointEnabled($tableSchema)) {
            return $this->resourceNotAvailableResponse();
        }

        $this->authorizeAction($table, 'read');

        $tenantId = $this->recordService->normalizeTenantId($request->header(RecordConfigService::tenantHeader()));
        if (($response = $this->validateTenantIdRequired($tableSchema, $tenantId)) instanceof JsonResponse) {
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
                trigger: $tableSchema->afterRead ?? null,
                params: [
                    $request,
                    $table,
                    [
                        'type' => 'index',
                        'filters' => $result['filters'] ?? [],
                        'data' => $data,
                        'meta' => $meta,
                        RecordConfigService::tenantColumn() => $tenantId,
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
        $tableSchema = SchemaRegistryUtils::getTable($table);
        if (!$tableSchema instanceof RecordTableType) {
            return RecordApiResponseService::errorWrapped('Resource not available', RecordApiJsonResponseEnum::NOT_FOUND->value);
        }

        if (!$this->isReadEndpointEnabled($tableSchema)) {
            return $this->resourceNotAvailableResponse();
        }

        $this->authorizeAction($table, 'read');

        $tenantId = $this->recordService->normalizeTenantId($request->header(RecordConfigService::tenantHeader()));
        if (($response = $this->validateTenantIdRequired($tableSchema, $tenantId)) instanceof JsonResponse) {
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
                trigger: $tableSchema->afterRead ?? null,
                params: [
                    $request,
                    $table,
                    [
                        'type' => 'show',
                        'id' => $id,
                        RecordConfigService::tenantColumn() => $tenantId,
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
        $tableSchema = SchemaRegistryUtils::getTable($table);
        if (!$tableSchema instanceof RecordTableType) {
            return RecordApiResponseService::errorWrapped('Resource not available', RecordApiJsonResponseEnum::NOT_FOUND->value);
        }

        if (!$this->isCreateEndpointEnabled($tableSchema)) {
            return $this->resourceNotAvailableResponse();
        }

        $this->authorizeAction($table, 'create');

        // Resolve actual table name from RecordTableType configuration
        $this->resolveActualTableName($table);
        $tenantId = $this->recordService->normalizeTenantId($request->header(RecordConfigService::tenantHeader()));
        if (($response = $this->validateTenantIdRequired($tableSchema, $tenantId)) instanceof JsonResponse) {
            return $response;
        }

        $triggerParams = [
            $request,
            $table,
            [
                RecordConfigService::tenantColumn() => $tenantId,
            ],
        ];
        $triggerParams = $this->recordService->executeTableTrigger(
            trigger: $tableSchema->beforeCreate ?? null,
            params: $triggerParams
        );
        if (isset($triggerParams[0]) && $triggerParams[0] instanceof Request) {
            $request = $triggerParams[0];
        }

        $validatorCallback = $tableSchema->createValidator ?? null;
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
            $result = $this->recordService->createRecord(table: $table, payload: $payload, tenantId: $tenantId);
            $insertedId = $result['id'];

            // Commit transaction
            DB::commit();
            QueryCacheService::invalidateTable($table);

            $recordData = $this->fetchRecordData(request: $request, table: $table, id: $insertedId, tenantId: $tenantId);
            $recordResponse = RecordApiResponseService::successWrapped($recordData);

            // Execute Post-Write Logic (Triggers and Audit Logs)
            $this->recordService->processPostWriteLogic(
                request: $request,
                table: $table,
                operation: 'create',
                recordContext: [
                    'id' => $insertedId,
                    'payload' => $result['payload'],
                    RecordConfigService::tenantColumn() => $result['tenant_id'],
                    'response' => $recordResponse,
                ]
            );

            return $recordResponse;
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
        $tableSchema = SchemaRegistryUtils::getTable($table);
        if (!$tableSchema instanceof RecordTableType) {
            return RecordApiResponseService::errorWrapped('Resource not available', RecordApiJsonResponseEnum::NOT_FOUND->value);
        }

        if (!$this->isUpdateEndpointEnabled($tableSchema)) {
            return $this->resourceNotAvailableResponse();
        }

        $this->authorizeAction($table, 'update');

        // Resolve actual table name from RecordTableType configuration
        $this->resolveActualTableName($table);
        $tenantId = $this->recordService->normalizeTenantId($request->header(RecordConfigService::tenantHeader()));
        if (($response = $this->validateTenantIdRequired($tableSchema, $tenantId)) instanceof JsonResponse) {
            return $response;
        }

        $triggerParams = [
            $request,
            $table,
            [
                'id' => $id,
                RecordConfigService::tenantColumn() => $tenantId,
            ],
        ];

        // Execute beforeUpdate trigger
        $triggerParams = $this->recordService->executeTableTrigger(
            trigger: $tableSchema->beforeUpdate ?? null,
            params: $triggerParams
        );
        if (isset($triggerParams[0]) && $triggerParams[0] instanceof Request) {
            $request = $triggerParams[0];
        }

        $validatorCallback = $tableSchema->updateValidator ?? null;
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
            $result = $this->recordService->updateRecord(table: $table, id: $id, payload: $payload, tenantId: $tenantId);
            $updated = $result['updated'];

            // Handle not found
            if (!$result['exists']) {
                DB::rollBack();

                return RecordApiResponseService::errorWrapped('Not found', RecordApiJsonResponseEnum::NOT_FOUND->value);
            }

            // Commit transaction
            DB::commit();
            QueryCacheService::invalidateTable($table);

            $recordData = $this->fetchRecordData(request: $request, table: $table, id: $id, tenantId: $tenantId);
            $recordResponse = RecordApiResponseService::successWrapped($recordData);

            // Execute Post-Write Logic (Triggers and Audit Logs)
            $this->recordService->processPostWriteLogic(
                request: $request,
                table: $table,
                operation: 'update',
                recordContext: [
                    'id' => $id,
                    'payload' => $result['payload'],
                    RecordConfigService::tenantColumn() => $result['tenant_id'],
                    'updated' => $updated,
                    'response' => $recordResponse,
                ]
            );

            return $recordResponse;
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
        $tableSchema = SchemaRegistryUtils::getTable($table);
        if (!$tableSchema instanceof RecordTableType) {
            return RecordApiResponseService::errorWrapped('Resource not available', RecordApiJsonResponseEnum::NOT_FOUND->value);
        }

        if (!$this->isDeleteEndpointEnabled($tableSchema)) {
            return $this->resourceNotAvailableResponse();
        }

        $this->authorizeAction($table, 'delete');

        // Resolve actual table name from RecordTableType configuration
        $this->resolveActualTableName($table);
        $tenantId = $this->recordService->normalizeTenantId($request->header(RecordConfigService::tenantHeader()));
        if (($response = $this->validateTenantIdRequired($tableSchema, $tenantId)) instanceof JsonResponse) {
            return $response;
        }

        $triggerParams = [
            $request,
            $table,
            [
                'id' => $id,
                RecordConfigService::tenantColumn() => $tenantId,
            ],
        ];

        // Execute beforeDelete trigger if defined
        $triggerParams = $this->recordService->executeTableTrigger(
            trigger: $tableSchema->beforeDelete ?? null,
            params: $triggerParams
        );
        if (isset($triggerParams[0]) && $triggerParams[0] instanceof Request) {
            $request = $triggerParams[0];
        };

        $validatorCallback = $tableSchema->deleteValidator ?? null;
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
            $result = $this->recordService->deleteRecord(table: $table, id: $id, tenantId: $tenantId);
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
            $this->recordService->processPostWriteLogic(
                request: $request,
                table: $table,
                operation: 'delete',
                recordContext: [
                    'id' => $id,
                    RecordConfigService::tenantColumn() => $tenantId,
                    'affected' => $affected,
                    'soft_deleted' => $tableSchema->softDeletes,
                    'response' => $response,
                ]
            );

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
        $tableSchema = SchemaRegistryUtils::getTable($table);
        if (!$tableSchema instanceof RecordTableType || !$tableSchema->softDeletes) {
            return RecordApiResponseService::errorWrapped('Resource not restorable', RecordApiJsonResponseEnum::ERROR->value);
        }

        if (!$this->isUpdateEndpointEnabled($tableSchema)) {
            return $this->resourceNotAvailableResponse();
        }

        $this->authorizeAction($table, 'restore');

        // Resolve actual table name from RecordTableType configuration
        $this->resolveActualTableName($table);

        $tenantId = $this->recordService->normalizeTenantId($request->header(RecordConfigService::tenantHeader()));
        if (($response = $this->validateTenantIdRequired($tableSchema, $tenantId)) instanceof JsonResponse) {
            return $response;
        }

        $affected = 0;

        // Begin transaction
        DB::beginTransaction();

        try {
            // Use RecordService to restore the record
            $result = $this->recordService->restoreRecord(table: $table, id: $id, tenantId: $tenantId);
            $affected = $result['restored'];

            if (0 === $affected) {
                DB::rollBack();

                return RecordApiResponseService::errorWrapped('Not found', RecordApiJsonResponseEnum::NOT_FOUND->value);
            }

            // Commit transaction
            DB::commit();

            QueryCacheService::invalidateTable($table);
            $response = RecordApiResponseService::successWrapped(['restored' => $affected]);

            // Execute Post-Write Logic (Triggers and Audit Logs)
            $this->recordService->processPostWriteLogic(
                request: $request,
                table: $table,
                operation: 'update',
                recordContext: [
                    'id' => $id,
                    'payload' => [], // No payload for restore
                    RecordConfigService::tenantColumn() => $tenantId,
                    'restored' => $affected,
                    'response' => $response,
                ]
            );

            return $response;
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
        $schema = SchemaRegistryUtils::get();
        if (!isset($schema[$table])) {
            return RecordApiResponseService::errorWrapped('Resource not available', RecordApiJsonResponseEnum::NOT_FOUND->value);
        }

        if (!$this->isDeleteEndpointEnabled($schema[$table])) {
            return $this->resourceNotAvailableResponse();
        }

        $this->authorizeAction($table, 'delete');

        // Resolve actual table name from RecordTableType configuration
        $this->resolveActualTableName($table);

        $tenantId = $this->recordService->normalizeTenantId($request->header(RecordConfigService::tenantHeader()));
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
            $this->recordService->processPostWriteLogic(
                request: $request,
                table: $table,
                operation: 'delete',
                recordContext: [
                    'id' => $id,
                    RecordConfigService::tenantColumn() => $tenantId,
                    'deleted' => $deleted,
                    'force_deleted' => true,
                    'response_data' => ['id' => $id], // Preserve original audit log data
                    'response' => $response,
                ]
            );

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
        $schema = SchemaRegistryUtils::get();
        if (!isset($schema[$table])) {
            return RecordApiResponseService::errorWrapped('Resource not available', RecordApiJsonResponseEnum::NOT_FOUND->value);
        }

        if (!$this->isCreateEndpointEnabled($schema[$table]) || !$this->isUpdateEndpointEnabled($schema[$table]) || !$this->isDeleteEndpointEnabled($schema[$table])) {
            return $this->resourceNotAvailableResponse();
        }

        $this->authorizeAction($table, 'create');
        $this->authorizeAction($table, 'update');
        $this->authorizeAction($table, 'delete');

        $tenantId = $this->recordService->normalizeTenantId($request->header(RecordConfigService::tenantHeader()));
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
        $schema = SchemaRegistryUtils::get();
        if (!isset($schema[$table])) {
            return RecordApiResponseService::errorWrapped('Resource not available', RecordApiJsonResponseEnum::NOT_FOUND->value);
        }

        if (!$this->isCreateEndpointEnabled($schema[$table])) {
            return $this->resourceNotAvailableResponse();
        }

        $this->authorizeAction($table, 'create');

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
            'items' => 'required|array|min:1|max:' . RecordConfigService::bulkMax(),
            'items.*' => 'required|array',
        ], [
            'items.required' => 'Payload must be an array',
            'items.array' => 'Payload must be an array',
            'items.min' => 'At least one item is required',
            'items.max' => 'Maximum ' . RecordConfigService::bulkMax() . ' items allowed',
            'items.*.required' => 'Each item is required',
            'items.*.array' => 'Each item must be an object',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $tenantId = $this->recordService->normalizeTenantId($request->header(RecordConfigService::tenantHeader()));
        if (($response = $this->validateTenantIdRequired($schema[$table], $tenantId)) instanceof JsonResponse) {
            return $response;
        }

        // Async processing
        if ($request->boolean('async') || $request->header('X-Async-Process')) {
            return $this->dispatchAsyncBulk($request, $table, 'create', $items, $tenantId);
        }

        $pk = $schema[$table]->primaryKey ?? 'id';

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
                $this->recordService->executeTableTrigger(
                    trigger: $schema[$table]->beforeCreate ?? null,
                    params: [$request, $table, $item]
                );

                $result = $this->recordService->createRecord(table: $table, payload: $item, tenantId: $tenantId);
                $insertId = $result['id'];

                $createdRecordData = $this->fetchRecordData(request: $request, table: $table, id: $insertId, tenantId: $tenantId);
                $createdRecordResponse = RecordApiResponseService::successWrapped($createdRecordData);
                $recordData = json_decode(json_encode($createdRecordData), true);
                $createdData[] = $recordData;
                ++$affected;

                // Post-write logic
                $this->recordService->processPostWriteLogic(
                    request: $request,
                    table: $table,
                    operation: 'create',
                    recordContext: [
                        'id' => $insertId,
                        'payload' => $result['payload'],
                        RecordConfigService::tenantColumn() => $result['tenant_id'],
                        'response' => $createdRecordResponse,
                    ]
                );
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
        $schema = SchemaRegistryUtils::get();
        if (!isset($schema[$table])) {
            return RecordApiResponseService::errorWrapped('Resource not available', RecordApiJsonResponseEnum::NOT_FOUND->value);
        }

        if (!$this->isUpdateEndpointEnabled($schema[$table])) {
            return $this->resourceNotAvailableResponse();
        }

        $this->authorizeAction($table, 'update');

        // Resolve actual table name from RecordTableType configuration
        $this->resolveActualTableName($table);
        $pk = $schema[$table]->primaryKey ?? 'id';

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
            'items' => 'required|array|min:1|max:' . RecordConfigService::bulkMax(),
            'items.*' => 'required|array',
            'items.*.' . $pk => 'required',
        ], [
            'items.required' => 'Payload must be an array',
            'items.array' => 'Payload must be an array',
            'items.min' => 'At least one item is required',
            'items.max' => 'Maximum ' . RecordConfigService::bulkMax() . ' items allowed',
            'items.*.required' => 'Each item is required',
            'items.*.array' => 'Each item must be an object',
            sprintf('items.*.%s.required', $pk) => sprintf('Primary key (%s) is required for update operation', $pk),
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $tenantId = $this->recordService->normalizeTenantId($request->header(RecordConfigService::tenantHeader()));
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
                $this->recordService->executeTableTrigger(
                    trigger: $schema[$table]->beforeUpdate ?? null,
                    params: [$request, $table, $id, $item]
                );

                $result = $this->recordService->updateRecord(table: $table, id: $id, payload: $item, tenantId: $tenantId);
                $updateCount = $result['updated'];

                if ($updateCount > 0) {
                    $updatedRecordData = $this->fetchRecordData(request: $request, table: $table, id: $id, tenantId: $tenantId);
                    $updatedRecordResponse = RecordApiResponseService::successWrapped($updatedRecordData);
                    $recordData = json_decode(json_encode($updatedRecordData), true);
                    $updatedData[] = $recordData;
                    $affected += $updateCount;

                    // Post-write logic
                    $this->recordService->processPostWriteLogic(
                        request: $request,
                        table: $table,
                        operation: 'update',
                        recordContext: [
                            'id' => $id,
                            'payload' => $result['payload'],
                            RecordConfigService::tenantColumn() => $result['tenant_id'],
                            'updated' => $updateCount,
                            'response' => $updatedRecordResponse,
                        ]
                    );
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
        $schema = SchemaRegistryUtils::get();
        if (!isset($schema[$table])) {
            return RecordApiResponseService::errorWrapped('Resource not available', RecordApiJsonResponseEnum::NOT_FOUND->value);
        }

        if (!$this->isDeleteEndpointEnabled($schema[$table])) {
            return $this->resourceNotAvailableResponse();
        }

        $this->authorizeAction($table, 'delete');

        // Resolve actual table name from RecordTableType configuration
        $this->resolveActualTableName($table);
        $pk = $schema[$table]->primaryKey ?? 'id';

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
            'items' => 'required|array|min:1|max:' . RecordConfigService::bulkMax(),
        ], [
            'items.required' => 'Payload must be an array',
            'items.array' => 'Payload must be an array',
            'items.min' => 'At least one item is required',
            'items.max' => 'Maximum ' . RecordConfigService::bulkMax() . ' items allowed',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $tenantId = $this->recordService->normalizeTenantId($request->header(RecordConfigService::tenantHeader()));
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
            $formattedItems = array_map(fn($id): array => [$pk => $id], $idsToDelete);
            return $this->dispatchAsyncBulk($request, $table, 'delete', $formattedItems, $tenantId);
        }

        $deletedData = [];
        $affected = 0;

        DB::beginTransaction();

        try {
            foreach ($idsToDelete as $idToDelete) {
                // Execute beforeDelete trigger
                $this->recordService->executeTableTrigger(
                    trigger: $schema[$table]->beforeDelete ?? null,
                    params: [$request, $table, $idToDelete]
                );

                $result = $this->recordService->deleteRecord(table: $table, id: $idToDelete, tenantId: $tenantId);
                $deleteCount = $result['affected'];

                if ($deleteCount > 0) {
                    $deletedData[] = [$pk => $idToDelete];
                    $affected += $deleteCount;

                    $response = RecordApiResponseService::successWrapped(['deleted' => $deleteCount]);

                    // Post-write logic
                    $this->recordService->processPostWriteLogic(
                        request: $request,
                        table: $table,
                        operation: 'delete',
                        recordContext: [
                            'id' => $idToDelete,
                            'payload' => ['id' => $idToDelete],
                            RecordConfigService::tenantColumn() => $tenantId,
                            'affected' => $deleteCount,
                            'soft_deleted' => $schema[$table]->softDeletes ?? false,
                            'response' => $response,
                        ]
                    );
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
            $schema = SchemaRegistryUtils::get();
            if (!isset($schema[$table])) {
                return RecordApiResponseService::errorWrapped(sprintf("Table '%s' does not exist", $table), RecordApiJsonResponseEnum::NOT_FOUND->value);
            }

            if (!$this->isReadEndpointEnabled($schema[$table])) {
                return $this->resourceNotAvailableResponse();
            }

            $this->authorizeAction($table, 'read');

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
     * @return string Modified payload with timestamps and audit fields
     */
    /**
     * Resolve the actual table name from schema configuration.
     */
    private function resolveActualTableName(string $table): string
    {
        $schema = SchemaRegistryUtils::get();
        $config = $schema[$table] ?? null;

        if ($config instanceof RecordTableType) {
            return $config->table ?? $table;
        }

        return $table;
    }

    private function validateTenantIdRequired(object $tableSchema, mixed $tenantId): ?JsonResponse
    {
        if (!RecordUtils::shouldApplyTenantId($tableSchema)) {
            return null;
        }

        if (RecordUtils::isTenantIdMissing($tenantId)) {
            return RecordApiResponseService::errorWrapped('Validation failed', RecordApiJsonResponseEnum::VALIDATION_ERROR->value, [
                RecordConfigService::tenantHeader() => ['header ' . RecordConfigService::tenantHeader() . ' cannot be empty'],
            ]);
        }

        return null;
    }

    private function resourceNotAvailableResponse(): JsonResponse
    {
        return RecordApiResponseService::errorWrapped('Resource not available', RecordApiJsonResponseEnum::NOT_FOUND->value);
    }

    private function isReadEndpointEnabled(object $tableSchema): bool
    {
        return (bool) ($tableSchema->canRead ?? true);
    }

    private function isCreateEndpointEnabled(object $tableSchema): bool
    {
        return (bool) ($tableSchema->canCreate ?? true);
    }

    private function isUpdateEndpointEnabled(object $tableSchema): bool
    {
        return (bool) ($tableSchema->canUpdate ?? true);
    }

    private function isDeleteEndpointEnabled(object $tableSchema): bool
    {
        return (bool) ($tableSchema->canDelete ?? true);
    }

    private function fetchRecordData(Request $request, string $table, mixed $id, mixed $tenantId): mixed
    {
        try {
            $result = $this->recordService->getRecord($request, $table, $id, $tenantId);

            return $result['data'] ?? null;
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Authorize the action for the given table.
     */
    private function authorizeAction(string $table, string $action): void
    {
        // Allow unauthenticated access for configured tables/actions (per-table config)
        if (PermissionUtils::isPublicAction($table, $action)) {
            return;
        }

        $guard = RecordConfigService::authGuard();
        $user = auth($guard)->user();
        if (!$user) {
            throw new HttpResponseException(
                RecordApiResponseService::errorWrapped(
                    'Unauthenticated',
                    RecordApiJsonResponseEnum::UNAUTHORIZED->value
                )
            );
        }

        $tableSchema = SchemaRegistryUtils::getTable($table);
        if ($tableSchema instanceof RecordTableType) {
            if (is_null($tableSchema->pmsName)) {
                return;
            }

            if (is_array($tableSchema->pmsName) && [] === $tableSchema->pmsName) {
                return;
            }
        }

        $perms = PermissionUtils::mapPermissions($table, $action);

        $allowed = false;
        foreach ($perms as $perm) {
            if (Gate::forUser($user)->allows($perm)) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed) {
            throw new HttpResponseException(
                RecordApiResponseService::errorWrapped(
                    'Forbidden',
                    RecordApiJsonResponseEnum::FORBIDDEN->value
                )
            );
        }
    }

    /**
     * Helper to dispatch async bulk job.
     */
    private function dispatchAsyncBulk(Request $request, string $table, string $operation, array $items, mixed $tenantId): JsonResponse
    {
        $guard = RecordConfigService::authGuard();
        $user = auth($guard)->user();

        $context = [
            'headers' => $request->headers->all(),
            'server' => $request->server->all(),
            'user_id' => $user?->id,
            'guard' => $guard,
        ];

        ProcessBulkOperationJob::dispatch($operation, $table, $items, $tenantId, $context);

        return RecordApiResponseService::successWrapped(
            ['status' => 'queued', 'message' => 'Bulk operation queued for processing'],
            [],
            202
        );
    }
}
