<?php

namespace Sopheak\Core\Http\Controllers\Concerns;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Sopheak\Core\Enums\RecordApiJsonResponseEnum;
use Sopheak\Core\Exceptions\RecordNotFoundException;
use Sopheak\Core\Services\RecordApiResponseService;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Utilities\DefaultValidationUtils;

/**
 * @property RecordService $recordService
 */
trait HasBulkOperations
{
    /**
     * Batch create, update, or delete records in a single API call (legacy mixed action).
     */
    public function bulkRecord(Request $request, string $table, ?string $legacyAction = null): JsonResponse
    {
        try {
            $tableSchema = $this->resolveSchemaOrFail($table);

            if (!$this->isCreateEndpointEnabled($tableSchema) || !$this->isUpdateEndpointEnabled($tableSchema) || !$this->isDeleteEndpointEnabled($tableSchema)) {
                return $this->resourceNotAvailableResponse();
            }

            $this->authorizeAction($table, 'create');
            $this->authorizeAction($table, 'update');
            $this->authorizeAction($table, 'delete');

            [$tenantId, $tenantError] = $this->resolveTenantContext($request, $tableSchema);
            if ($tenantError instanceof JsonResponse) {
                return $tenantError;
            }

            $result = $this->recordService->bulkRecord($request, $table, $tenantId, $legacyAction);

            return RecordApiResponseService::successWrapped($result['data'], $result['meta']);
        } catch (RecordNotFoundException $e) {
            return RecordApiResponseService::errorWrapped($e->getMessage(), RecordApiJsonResponseEnum::NOT_FOUND->value);
        } catch (Exception $e) {
            $code = $e->getCode();
            if (!is_int($code) || $code < 100 || $code > 599) {
                $code = RecordApiJsonResponseEnum::SERVER_ERROR->value;
            }

            return RecordApiResponseService::errorWrapped($e->getMessage(), $code);
        }
    }

    /**
     * Bulk upsert records.
     */
    public function bulkRecordUpsert(Request $request, string $table): JsonResponse
    {
        try {
            $tableSchema = $this->resolveSchemaOrFail($table);

            if (!$this->isUpsertEndpointEnabled($tableSchema)) {
                return $this->resourceNotAvailableResponse();
            }

            $this->authorizeAction($table, 'create');
            $this->authorizeAction($table, 'update');

            [$tenantId, $tenantError] = $this->resolveTenantContext($request, $tableSchema);
            if ($tenantError instanceof JsonResponse) {
                return $tenantError;
            }

            $matchOn = $request->query('match_on');
            if (empty($matchOn)) {
                return RecordApiResponseService::errorWrapped('match_on query parameter is required', RecordApiJsonResponseEnum::VALIDATION_ERROR->value);
            }

            $matchOn = explode(',', $matchOn);

            $payload = $request->except(['match_on', 'select', 'per_page', 'page']);

            if (!is_array($payload) || (is_array($payload) && !array_key_exists(0, $payload) && !empty($payload))) {
                $items = [$payload];
            } else {
                $items = $payload;
            }

            if (count($items) > RecordConfigService::bulkMax()) {
                return RecordApiResponseService::errorWrapped('Bulk limit exceeded', RecordApiJsonResponseEnum::VALIDATION_ERROR->value);
            }

            foreach ($items as $index => $item) {
                foreach ($matchOn as $col) {
                    if (!array_key_exists($col, $item)) {
                        return RecordApiResponseService::errorWrapped(sprintf('Item at index %s missing required matching column: %s', $index, $col), RecordApiJsonResponseEnum::VALIDATION_ERROR->value);
                    }
                }
            }

            $result = $this->recordService->bulkUpsertRecord($request, $table, $items, $tenantId, $matchOn);

            return RecordApiResponseService::successWrapped($result['data'], $result['meta']);
        } catch (RecordNotFoundException $e) {
            return RecordApiResponseService::errorWrapped($e->getMessage(), RecordApiJsonResponseEnum::NOT_FOUND->value);
        } catch (ValidationException $e) {
            return RecordApiResponseService::errorWrapped('Validation failed', RecordApiJsonResponseEnum::VALIDATION_ERROR->value, $e->errors());
        } catch (Exception) {
            //Log::error('Failed to bulk upsert records', ['table' => $table, 'exception' => $e]);
            return RecordApiResponseService::errorWrapped('An error occurred', RecordApiJsonResponseEnum::SERVER_ERROR->value);
        }
    }

    /**
     * Bulk create records.
     */
    public function bulkRecordCreate(Request $request, string $table): JsonResponse
    {
        try {
            $tableSchema = $this->resolveSchemaOrFail($table);

            if (!$this->isCreateEndpointEnabled($tableSchema)) {
                return $this->resourceNotAvailableResponse();
            }

            $this->authorizeAction($table, 'create');
            $this->resolveActualTableName($table);

            $payload = $request->all();
            $items   = (!is_array($payload) || (is_array($payload) && !array_key_exists(0, $payload) && !empty($payload)))
                ? [$payload]
                : $payload;

            $validator = Validator::make(['items' => $items], [
                'items'   => 'required|array|min:1|max:' . RecordConfigService::bulkMax(),
                'items.*' => 'required|array',
            ], [
                'items.required'   => 'Payload must be an array',
                'items.array'      => 'Payload must be an array',
                'items.min'        => 'At least one item is required',
                'items.max'        => 'Maximum ' . RecordConfigService::bulkMax() . ' items allowed',
                'items.*.required' => 'Each item is required',
                'items.*.array'    => 'Each item must be an object',
            ]);

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            [$tenantId, $tenantError] = $this->resolveTenantContext($request, $tableSchema);
            if ($tenantError instanceof JsonResponse) {
                return $tenantError;
            }

            if ($request->boolean('async') || $request->header('X-Async-Process')) {
                return $this->dispatchAsyncBulk($request, $table, 'create', $items, $tenantId);
            }

            $pk          = $tableSchema->primaryKey ?? 'id';
            $createdData = [];
            $affected    = 0;

            return $this->withinTransaction(function () use ($request, $table, $items, $tenantId, $tableSchema, $pk, &$createdData, &$affected): JsonResponse {
                foreach ($items as $index => $item) {
                    if (isset($item[$pk])) {
                        throw ValidationException::withMessages([
                            sprintf('items.%s.%s', $index, $pk) => 'Primary key should not be provided for create operation',
                        ]);
                    }

                    if (
                        RecordConfigService::defaultValidationEnabled()
                        && (!RecordConfigService::defaultValidationOnlyWhenMissing() || null === $tableSchema->createValidator)
                    ) {
                        $rules = DefaultValidationUtils::buildCreateRules($tableSchema);
                        if ($rules !== []) {
                            $validator = Validator::make($item, $rules);
                            if ($validator->fails()) {
                                throw ValidationException::withMessages($validator->errors()->toArray());
                            }
                        }
                    }

                    $this->recordService->executeGlobalTrigger(
                        hook: 'beforeCreate',
                        params: [$request, $table, $item]
                    );

                    $this->recordService->executeTableTrigger(
                        trigger: $tableSchema->beforeCreate ?? null,
                        params: [$request, $table, $item]
                    );

                    $result   = $this->recordService->createRecord(table: $table, payload: $item, tenantId: $tenantId);
                    $insertId = $result['id'];

                    $createdRecordData     = $this->fetchRecordData(request: $request, table: $table, id: $insertId, tenantId: $tenantId);
                    $createdRecordResponse = RecordApiResponseService::successWrapped($createdRecordData);
                    $createdData[]         = json_decode(json_encode($createdRecordData), true);
                    ++$affected;

                    $tenantColumn = RecordConfigService::tenantColumn();
                    $this->recordService->processPostWriteLogic(
                        request: $request,
                        table: $table,
                        operation: 'create',
                        recordContext: [
                            'id'          => $insertId,
                            'payload'     => $result['payload'],
                            $tenantColumn => $result[$tenantColumn] ?? $tenantId,
                            'response'    => $createdRecordResponse,
                        ]
                    );
                }

                return RecordApiResponseService::successWrapped($createdData, ['affected' => $affected]);
            });
        } catch (RecordNotFoundException $e) {
            return RecordApiResponseService::errorWrapped($e->getMessage(), RecordApiJsonResponseEnum::NOT_FOUND->value);
        } catch (ValidationException $e) {
            return RecordApiResponseService::errorWrapped('Validation failed', RecordApiJsonResponseEnum::VALIDATION_ERROR->value, $e->errors());
        } catch (Exception) {
            //Log::error('Failed to bulk create records', ['table' => $table, 'exception' => $e]);
            return RecordApiResponseService::errorWrapped('An error occurred', RecordApiJsonResponseEnum::SERVER_ERROR->value);
        }
    }

    /**
     * Bulk update records.
     */
    public function bulkRecordUpdate(Request $request, string $table): JsonResponse
    {
        try {
            $tableSchema = $this->resolveSchemaOrFail($table);

            if (!$this->isUpdateEndpointEnabled($tableSchema)) {
                return $this->resourceNotAvailableResponse();
            }

            $this->authorizeAction($table, 'update');
            $this->resolveActualTableName($table);

            $pk      = $tableSchema->primaryKey ?? 'id';
            $payload = $request->all();
            $items   = (!is_array($payload) || (is_array($payload) && !array_key_exists(0, $payload) && !empty($payload)))
                ? [$payload]
                : $payload;

            $validator = Validator::make(['items' => $items], [
                'items'           => 'required|array|min:1|max:' . RecordConfigService::bulkMax(),
                'items.*'         => 'required|array',
                'items.*.' . $pk  => 'required',
            ], [
                'items.required'                          => 'Payload must be an array',
                'items.array'                             => 'Payload must be an array',
                'items.min'                               => 'At least one item is required',
                'items.max'                               => 'Maximum ' . RecordConfigService::bulkMax() . ' items allowed',
                'items.*.required'                        => 'Each item is required',
                'items.*.array'                           => 'Each item must be an object',
                sprintf('items.*.%s.required', $pk)       => sprintf('Primary key (%s) is required for update operation', $pk),
            ]);

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            [$tenantId, $tenantError] = $this->resolveTenantContext($request, $tableSchema);
            if ($tenantError instanceof JsonResponse) {
                return $tenantError;
            }

            if ($request->boolean('async') || $request->header('X-Async-Process')) {
                return $this->dispatchAsyncBulk($request, $table, 'update', $items, $tenantId);
            }

            $updatedData = [];
            $affected    = 0;

            return $this->withinTransaction(function () use ($request, $table, $items, $tenantId, $tableSchema, $pk, &$updatedData, &$affected): JsonResponse {
                foreach ($items as $index => $item) {
                    if (!isset($item[$pk])) {
                        throw ValidationException::withMessages([
                            sprintf('items.%s.%s', $index, $pk) => sprintf('Primary key (%s) is required for update operation', $pk),
                        ]);
                    }

                    $updateFields = array_diff_key($item, [$pk => true]);
                    if (empty($updateFields)) {
                        throw ValidationException::withMessages([
                            'items.' . $index => 'At least one field besides the primary key must be provided for update',
                        ]);
                    }

                    $id = $item[$pk];
                    unset($item[$pk]);

                    if (
                        RecordConfigService::defaultValidationEnabled()
                        && (!RecordConfigService::defaultValidationOnlyWhenMissing() || null === $tableSchema->updateValidator)
                    ) {
                        $rules = DefaultValidationUtils::buildUpdateRules($tableSchema, $id);
                        if ($rules !== []) {
                            $validator = Validator::make($item, $rules);
                            if ($validator->fails()) {
                                throw ValidationException::withMessages($validator->errors()->toArray());
                            }
                        }
                    }

                    $this->recordService->executeGlobalTrigger(
                        hook: 'beforeUpdate',
                        params: [$request, $table, $id, $item]
                    );

                    $this->recordService->executeTableTrigger(
                        trigger: $tableSchema->beforeUpdate ?? null,
                        params: [$request, $table, $id, $item]
                    );

                    $result      = $this->recordService->updateRecord(table: $table, id: $id, payload: $item, tenantId: $tenantId);
                    $updateCount = $result['updated'];

                    if ($updateCount > 0) {
                        $updatedRecordData     = $this->fetchRecordData(request: $request, table: $table, id: $id, tenantId: $tenantId);
                        $updatedRecordResponse = RecordApiResponseService::successWrapped($updatedRecordData);
                        $updatedData[]         = json_decode(json_encode($updatedRecordData), true);
                        $affected              += $updateCount;

                        $tenantColumn = RecordConfigService::tenantColumn();
                        $this->recordService->processPostWriteLogic(
                            request: $request,
                            table: $table,
                            operation: 'update',
                            recordContext: [
                                'id'          => $id,
                                'payload'     => $result['payload'],
                                $tenantColumn => $result[$tenantColumn] ?? $tenantId,
                                'updated'     => $updateCount,
                                'response'    => $updatedRecordResponse,
                            ]
                        );
                    } else {
                        throw ValidationException::withMessages([
                            sprintf('items.%s.%s', $index, $pk) => sprintf("Record with %s '%s' not found or no changes detected", $pk, $id),
                        ]);
                    }
                }

                return RecordApiResponseService::successWrapped($updatedData, ['affected' => $affected]);
            });
        } catch (RecordNotFoundException $e) {
            return RecordApiResponseService::errorWrapped($e->getMessage(), RecordApiJsonResponseEnum::NOT_FOUND->value);
        } catch (ValidationException $e) {
            return RecordApiResponseService::errorWrapped('Validation failed', RecordApiJsonResponseEnum::VALIDATION_ERROR->value, $e->errors());
        } catch (Exception) {
            //Log::error('Failed to bulk update records', ['table' => $table, 'exception' => $e]);
            return RecordApiResponseService::errorWrapped('An error occurred', RecordApiJsonResponseEnum::SERVER_ERROR->value);
        }
    }

    /**
     * Bulk delete records.
     */
    public function bulkRecordDelete(Request $request, string $table): JsonResponse
    {
        try {
            $tableSchema = $this->resolveSchemaOrFail($table);

            if (!$this->isDeleteEndpointEnabled($tableSchema)) {
                return $this->resourceNotAvailableResponse();
            }

            $this->authorizeAction($table, 'delete');
            $this->resolveActualTableName($table);

            $pk      = $tableSchema->primaryKey ?? 'id';
            $payload = $request->all();
            $items   = (!is_array($payload) || (is_array($payload) && !array_key_exists(0, $payload) && !empty($payload)))
                ? [$payload]
                : $payload;

            $validator = Validator::make(['items' => $items], [
                'items' => 'required|array|min:1|max:' . RecordConfigService::bulkMax(),
            ], [
                'items.required' => 'Payload must be an array',
                'items.array'    => 'Payload must be an array',
                'items.min'      => 'At least one item is required',
                'items.max'      => 'Maximum ' . RecordConfigService::bulkMax() . ' items allowed',
            ]);

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            [$tenantId, $tenantError] = $this->resolveTenantContext($request, $tableSchema);
            if ($tenantError instanceof JsonResponse) {
                return $tenantError;
            }

            $idsToDelete = [];
            foreach ($items as $index => $item) {
                if (is_array($item)) {
                    if (!isset($item[$pk])) {
                        throw ValidationException::withMessages([
                            sprintf('items.%s.%s', $index, $pk) => sprintf('Primary key (%s) is required for delete operation', $pk),
                        ]);
                    }

                    $idsToDelete[] = $item[$pk];
                } else {
                    if (empty($item)) {
                        throw ValidationException::withMessages([
                            'items.' . $index => 'ID value cannot be empty',
                        ]);
                    }

                    $idsToDelete[] = $item;
                }
            }

            $idsToDelete = array_unique($idsToDelete);

            if ($request->boolean('async') || $request->header('X-Async-Process')) {
                $formattedItems = array_map(fn ($id): array => [$pk => $id], $idsToDelete);
                return $this->dispatchAsyncBulk($request, $table, 'delete', $formattedItems, $tenantId);
            }

            $deletedData = [];
            $affected    = 0;

            return $this->withinTransaction(function () use ($request, $table, $idsToDelete, $tenantId, $tableSchema, $pk, &$deletedData, &$affected): JsonResponse {
                foreach ($idsToDelete as $idToDelete) {
                    $this->recordService->executeGlobalTrigger(
                        hook: 'beforeDelete',
                        params: [$request, $table, $idToDelete]
                    );

                    $this->recordService->executeTableTrigger(
                        trigger: $tableSchema->beforeDelete ?? null,
                        params: [$request, $table, $idToDelete]
                    );

                    $result      = $this->recordService->deleteRecord(table: $table, id: $idToDelete, tenantId: $tenantId);
                    $deleteCount = $result['affected'];

                    if ($deleteCount > 0) {
                        $deletedData[] = [$pk => $idToDelete];
                        $affected      += $deleteCount;

                        $response = RecordApiResponseService::successWrapped(['deleted' => $deleteCount]);

                        $this->recordService->processPostWriteLogic(
                            request: $request,
                            table: $table,
                            operation: 'delete',
                            recordContext: [
                                'id'                                => $idToDelete,
                                'payload'                           => ['id' => $idToDelete],
                                RecordConfigService::tenantColumn() => $tenantId,
                                'affected'                          => $deleteCount,
                                'soft_deleted'                      => $tableSchema->softDeletes ?? false,
                                'response'                          => $response,
                            ]
                        );
                    }
                }

                $this->recordService->invalidateTableCache($table, $tenantId, $this->recordService->shouldApplyTenantId($tableSchema));

                return RecordApiResponseService::successWrapped($deletedData, ['affected' => $affected]);
            });
        } catch (RecordNotFoundException $e) {
            return RecordApiResponseService::errorWrapped($e->getMessage(), RecordApiJsonResponseEnum::NOT_FOUND->value);
        } catch (ValidationException $e) {
            return RecordApiResponseService::errorWrapped('Validation failed', RecordApiJsonResponseEnum::VALIDATION_ERROR->value, $e->errors());
        } catch (Exception) {
            //Log::error('Failed to bulk delete records', ['table' => $table, 'exception' => $e]);
            return RecordApiResponseService::errorWrapped('An error occurred', RecordApiJsonResponseEnum::SERVER_ERROR->value);
        }
    }
}
