<?php

namespace Sopheak\Core\Http\Controllers\Concerns;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Sopheak\Core\Enums\RecordApiJsonResponseEnum;
use Sopheak\Core\Exceptions\RecordNotFoundException;
use Sopheak\Core\Services\RecordApiResponseService;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * @property RecordService $recordService
 */
trait HasCrudOperations
{
    /**
     * List records for a table.
     */
    public function listRecords(Request $request, string $table): JsonResponse
    {
        try {
            $tableSchema = $this->resolveSchemaOrFail($table);

            if (!$this->isReadEndpointEnabled($tableSchema)) {
                return $this->resourceNotAvailableResponse();
            }

            $this->authorizeAction($table, 'read');

            [$tenantId, $tenantError] = $this->resolveTenantContext($request, $tableSchema);
            if ($tenantError instanceof JsonResponse) {
                return $tenantError;
            }

            $result = $this->recordService->listRecords(request: $request, table: $table, tenantId: $tenantId);

            $data    = $result['data'];
            $meta    = $result['meta'];
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
                        'type'                                  => 'index',
                        'filters'                               => $result['filters'] ?? [],
                        'data'                                  => $data,
                        'meta'                                  => $meta,
                        RecordConfigService::tenantColumn()     => $tenantId,
                        'response'                              => $response,
                    ],
                ]
            );

            return $response;
        } catch (RecordNotFoundException $e) {
            return RecordApiResponseService::errorWrapped($e->getMessage(), RecordApiJsonResponseEnum::NOT_FOUND->value);
        } catch (Exception $e) {
            Log::error('Failed to list records', ['table' => $table, 'exception' => $e]);
            return RecordApiResponseService::errorWrapped('An error occurred', RecordApiJsonResponseEnum::SERVER_ERROR->value);
        }
    }

    /**
     * Get a single record by ID.
     */
    public function getRecordById(Request $request, string $table, mixed $id): JsonResponse
    {
        try {
            $tableSchema = $this->resolveSchemaOrFail($table);

            if (!$this->isReadEndpointEnabled($tableSchema)) {
                return $this->resourceNotAvailableResponse();
            }

            $this->authorizeAction($table, 'read');

            [$tenantId, $tenantError] = $this->resolveTenantContext($request, $tableSchema);
            if ($tenantError instanceof JsonResponse) {
                return $tenantError;
            }

            $result  = $this->recordService->getRecord(request: $request, table: $table, id: $id, tenantId: $tenantId);
            $record  = $result['data'];
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
                        'type'                              => 'show',
                        'id'                                => $id,
                        RecordConfigService::tenantColumn() => $tenantId,
                        'record'                            => $record,
                        'response'                          => $response,
                    ],
                ]
            );

            return $response;
        } catch (RecordNotFoundException $e) {
            return RecordApiResponseService::errorWrapped($e->getMessage(), RecordApiJsonResponseEnum::NOT_FOUND->value);
        } catch (Exception $e) {
            Log::error('Failed to retrieve record', ['table' => $table, 'id' => $id, 'exception' => $e]);
            return RecordApiResponseService::errorWrapped('An error occurred', RecordApiJsonResponseEnum::SERVER_ERROR->value);
        }
    }

    /**
     * Create a new record.
     */
    public function createRecord(Request $request, string $table): JsonResponse
    {
        try {
            $tableSchema = $this->resolveSchemaOrFail($table);

            if (!$this->isCreateEndpointEnabled($tableSchema)) {
                return $this->resourceNotAvailableResponse();
            }

            $this->authorizeAction($table, 'create');
            $this->resolveActualTableName($table);

            [$tenantId, $tenantError] = $this->resolveTenantContext($request, $tableSchema);
            if ($tenantError instanceof JsonResponse) {
                return $tenantError;
            }

            $triggerParams = $this->recordService->executeTableTrigger(
                trigger: $tableSchema->beforeCreate ?? null,
                params: [$request, $table, [RecordConfigService::tenantColumn() => $tenantId]]
            );
            if (isset($triggerParams[0]) && $triggerParams[0] instanceof Request) {
                $request = $triggerParams[0];
            }

            if ($tableSchema->createValidator) {
                $response = $this->runTableValidators($tableSchema->createValidator, $request, null);
                if ($response instanceof JsonResponse) {
                    return $response;
                }
            }

            $payload = $request->all();

            return $this->withinTransaction(function () use ($request, $table, $payload, $tenantId, $tableSchema) {
                $result     = $this->recordService->createRecord(table: $table, payload: $payload, tenantId: $tenantId);
                $insertedId = $result['id'];

                $this->recordService->invalidateTableCache($table, $tenantId, $this->recordService->shouldApplyTenantId($tableSchema));

                $recordData     = $this->fetchRecordData(request: $request, table: $table, id: $insertedId, tenantId: $tenantId);
                $recordResponse = RecordApiResponseService::successWrapped($recordData);

                $tenantColumn = RecordConfigService::tenantColumn();
                $this->recordService->processPostWriteLogic(
                    request: $request,
                    table: $table,
                    operation: 'create',
                    recordContext: [
                        'id'          => $insertedId,
                        'payload'     => $result['payload'],
                        $tenantColumn => $result[$tenantColumn] ?? $tenantId,
                        'response'    => $recordResponse,
                    ]
                );

                return $recordResponse;
            });
        } catch (RecordNotFoundException $e) {
            return RecordApiResponseService::errorWrapped($e->getMessage(), RecordApiJsonResponseEnum::NOT_FOUND->value);
        } catch (ValidationException $e) {
            return RecordApiResponseService::errorWrapped('Validation failed', RecordApiJsonResponseEnum::VALIDATION_ERROR->value, $e->errors());
        } catch (Exception $e) {
            Log::error('Failed to create record', ['table' => $table, 'exception' => $e]);
            return RecordApiResponseService::errorWrapped('An error occurred', RecordApiJsonResponseEnum::SERVER_ERROR->value);
        }
    }

    /**
     * Update a record by ID.
     */
    public function updateRecord(Request $request, string $table, string $id): JsonResponse
    {
        try {
            $tableSchema = $this->resolveSchemaOrFail($table);

            if (!$this->isUpdateEndpointEnabled($tableSchema)) {
                return $this->resourceNotAvailableResponse();
            }

            $this->authorizeAction($table, 'update');
            $this->resolveActualTableName($table);

            [$tenantId, $tenantError] = $this->resolveTenantContext($request, $tableSchema);
            if ($tenantError instanceof JsonResponse) {
                return $tenantError;
            }

            $triggerParams = $this->recordService->executeTableTrigger(
                trigger: $tableSchema->beforeUpdate ?? null,
                params: [$request, $table, ['id' => $id, RecordConfigService::tenantColumn() => $tenantId]]
            );
            if (isset($triggerParams[0]) && $triggerParams[0] instanceof Request) {
                $request = $triggerParams[0];
            }

            if ($tableSchema->updateValidator) {
                $response = $this->runTableValidators($tableSchema->updateValidator, $request, $id);
                if ($response instanceof JsonResponse) {
                    return $response;
                }
            }

            $payload = $request->all();

            return $this->withinTransaction(function () use ($request, $table, $id, $payload, $tenantId, $tableSchema) {
                $result  = $this->recordService->updateRecord(table: $table, id: $id, payload: $payload, tenantId: $tenantId);
                $updated = $result['updated'];

                if (!$result['exists']) {
                    throw new RecordNotFoundException('Not found');
                }

                $this->recordService->invalidateTableCache($table, $tenantId, $this->recordService->shouldApplyTenantId($tableSchema));

                $recordData     = $this->fetchRecordData(request: $request, table: $table, id: $id, tenantId: $tenantId);
                $recordResponse = RecordApiResponseService::successWrapped($recordData);

                $tenantColumn = RecordConfigService::tenantColumn();
                $this->recordService->processPostWriteLogic(
                    request: $request,
                    table: $table,
                    operation: 'update',
                    recordContext: [
                        'id'          => $id,
                        'payload'     => $result['payload'],
                        $tenantColumn => $result[$tenantColumn] ?? $tenantId,
                        'updated'     => $updated,
                        'response'    => $recordResponse,
                    ]
                );

                return $recordResponse;
            });
        } catch (RecordNotFoundException $e) {
            return RecordApiResponseService::errorWrapped($e->getMessage(), RecordApiJsonResponseEnum::NOT_FOUND->value);
        } catch (ValidationException $e) {
            return RecordApiResponseService::errorWrapped('Validation failed', RecordApiJsonResponseEnum::VALIDATION_ERROR->value, $e->errors());
        } catch (Exception $e) {
            Log::error('Failed to update record', ['table' => $table, 'id' => $id, 'exception' => $e]);
            return RecordApiResponseService::errorWrapped('An error occurred', RecordApiJsonResponseEnum::SERVER_ERROR->value);
        }
    }

    /**
     * Delete a record by ID.
     */
    public function destroyRecord(Request $request, string $table, string $id): JsonResponse
    {
        try {
            $tableSchema = $this->resolveSchemaOrFail($table);

            if (!$this->isDeleteEndpointEnabled($tableSchema)) {
                return $this->resourceNotAvailableResponse();
            }

            $this->authorizeAction($table, 'delete');
            $this->resolveActualTableName($table);

            [$tenantId, $tenantError] = $this->resolveTenantContext($request, $tableSchema);
            if ($tenantError instanceof JsonResponse) {
                return $tenantError;
            }

            $triggerParams = $this->recordService->executeTableTrigger(
                trigger: $tableSchema->beforeDelete ?? null,
                params: [$request, $table, ['id' => $id, RecordConfigService::tenantColumn() => $tenantId]]
            );
            if (isset($triggerParams[0]) && $triggerParams[0] instanceof Request) {
                $request = $triggerParams[0];
            }

            if ($tableSchema->deleteValidator) {
                $response = $this->runTableValidators($tableSchema->deleteValidator, $request, $id);
                if ($response instanceof JsonResponse) {
                    return $response;
                }
            }

            return $this->withinTransaction(function () use ($request, $table, $id, $tenantId, $tableSchema) {
                $result   = $this->recordService->deleteRecord(table: $table, id: $id, tenantId: $tenantId);
                $affected = $result['affected'];

                if (0 === $affected) {
                    throw new RecordNotFoundException('Not found');
                }

                $this->recordService->invalidateTableCache($table, $tenantId, $this->recordService->shouldApplyTenantId($tableSchema));

                $response = RecordApiResponseService::successWrapped(['deleted' => $affected]);

                $this->recordService->processPostWriteLogic(
                    request: $request,
                    table: $table,
                    operation: 'delete',
                    recordContext: [
                        'id'                                => $id,
                        RecordConfigService::tenantColumn() => $tenantId,
                        'affected'                          => $affected,
                        'soft_deleted'                      => $tableSchema->softDeletes,
                        'response'                          => $response,
                    ]
                );

                return $response;
            });
        } catch (RecordNotFoundException $e) {
            return RecordApiResponseService::errorWrapped($e->getMessage(), RecordApiJsonResponseEnum::NOT_FOUND->value);
        } catch (ValidationException $e) {
            return RecordApiResponseService::errorWrapped('Validation failed', RecordApiJsonResponseEnum::VALIDATION_ERROR->value, $e->errors());
        } catch (Exception $e) {
            Log::error('Failed to delete record', ['table' => $table, 'id' => $id, 'exception' => $e]);
            return RecordApiResponseService::errorWrapped('An error occurred', RecordApiJsonResponseEnum::SERVER_ERROR->value);
        }
    }

    /**
     * Restore a soft-deleted record.
     */
    public function restoreRecord(Request $request, string $table, string $id): JsonResponse
    {
        try {
            $tableSchema = SchemaRegistryUtils::getTable($table);
            if (!$tableSchema instanceof RecordTableType || !$tableSchema->softDeletes) {
                return RecordApiResponseService::errorWrapped('Resource not restorable', RecordApiJsonResponseEnum::ERROR->value);
            }

            if (!$this->isUpdateEndpointEnabled($tableSchema)) {
                return $this->resourceNotAvailableResponse();
            }

            $this->authorizeAction($table, 'restore');
            $this->resolveActualTableName($table);

            [$tenantId, $tenantError] = $this->resolveTenantContext($request, $tableSchema);
            if ($tenantError instanceof JsonResponse) {
                return $tenantError;
            }

            return $this->withinTransaction(function () use ($request, $table, $id, $tenantId, $tableSchema) {
                $result   = $this->recordService->restoreRecord(table: $table, id: $id, tenantId: $tenantId);
                $affected = $result['restored'];

                if (0 === $affected) {
                    throw new RecordNotFoundException('Not found');
                }

                $this->recordService->invalidateTableCache($table, $tenantId, $this->recordService->shouldApplyTenantId($tableSchema));

                $response = RecordApiResponseService::successWrapped(['restored' => $affected]);

                $this->recordService->processPostWriteLogic(
                    request: $request,
                    table: $table,
                    operation: 'update',
                    recordContext: [
                        'id'                                => $id,
                        'payload'                           => [],
                        RecordConfigService::tenantColumn() => $tenantId,
                        'restored'                          => $affected,
                        'response'                          => $response,
                    ]
                );

                return $response;
            });
        } catch (RecordNotFoundException $e) {
            return RecordApiResponseService::errorWrapped($e->getMessage(), RecordApiJsonResponseEnum::NOT_FOUND->value);
        } catch (Exception $e) {
            Log::error('Failed to restore record', ['table' => $table, 'id' => $id, 'exception' => $e]);
            return RecordApiResponseService::errorWrapped('An error occurred', RecordApiJsonResponseEnum::SERVER_ERROR->value);
        }
    }

    /**
     * Force delete a record (bypass soft delete).
     */
    public function forceDeleteRecord(Request $request, string $table, string $id): JsonResponse
    {
        try {
            $tableSchema = $this->resolveSchemaOrFail($table);

            if (!$this->isDeleteEndpointEnabled($tableSchema)) {
                return $this->resourceNotAvailableResponse();
            }

            $this->authorizeAction($table, 'delete');
            $this->resolveActualTableName($table);

            [$tenantId, $tenantError] = $this->resolveTenantContext($request, $tableSchema);
            if ($tenantError instanceof JsonResponse) {
                return $tenantError;
            }

            return $this->withinTransaction(function () use ($request, $table, $id, $tenantId, $tableSchema) {
                $result  = $this->recordService->forceDeleteRecord($request, $table, $id, $tenantId);
                $deleted = $result['deleted'];

                if (0 === $deleted) {
                    throw new RecordNotFoundException('Not found');
                }

                $this->recordService->invalidateTableCache($table, $tenantId, $this->recordService->shouldApplyTenantId($tableSchema));

                $response = RecordApiResponseService::successWrapped(['deleted' => $deleted]);

                $this->recordService->processPostWriteLogic(
                    request: $request,
                    table: $table,
                    operation: 'delete',
                    recordContext: [
                        'id'                                => $id,
                        RecordConfigService::tenantColumn() => $tenantId,
                        'deleted'                           => $deleted,
                        'force_deleted'                     => true,
                        'response_data'                     => ['id' => $id],
                        'response'                          => $response,
                    ]
                );

                return $response;
            });
        } catch (RecordNotFoundException $e) {
            return RecordApiResponseService::errorWrapped($e->getMessage(), RecordApiJsonResponseEnum::NOT_FOUND->value);
        } catch (Exception $e) {
            Log::error('Failed to force delete record', ['table' => $table, 'id' => $id, 'exception' => $e]);
            return RecordApiResponseService::errorWrapped('An error occurred', RecordApiJsonResponseEnum::SERVER_ERROR->value);
        }
    }

    /**
     * Create or update a record based on matching criteria.
     */
    public function upsertRecord(Request $request, string $table): JsonResponse
    {
        try {
            $tableSchema = $this->resolveSchemaOrFail($table);

            if (!$this->isUpsertEndpointEnabled($tableSchema)) {
                return $this->resourceNotAvailableResponse();
            }

            $this->authorizeAction($table, 'create');
            $this->authorizeAction($table, 'update');
            $this->resolveActualTableName($table);

            [$tenantId, $tenantError] = $this->resolveTenantContext($request, $tableSchema);
            if ($tenantError instanceof JsonResponse) {
                return $tenantError;
            }

            $matchOn = $request->query('match_on');
            if (empty($matchOn)) {
                return RecordApiResponseService::errorWrapped('match_on query parameter is required', RecordApiJsonResponseEnum::VALIDATION_ERROR->value);
            }
            $matchOn = explode(',', $matchOn);

            $payload = $request->all();

            foreach ($matchOn as $col) {
                if (!array_key_exists($col, $payload)) {
                    return RecordApiResponseService::errorWrapped("Missing required matching column: $col", RecordApiJsonResponseEnum::VALIDATION_ERROR->value);
                }
            }

            $result = $this->recordService->upsertRecord($request, $table, $payload, $tenantId, $matchOn);

            return RecordApiResponseService::successWrapped($result['data'], $result['meta']);
        } catch (RecordNotFoundException $e) {
            return RecordApiResponseService::errorWrapped($e->getMessage(), RecordApiJsonResponseEnum::NOT_FOUND->value);
        } catch (ValidationException $e) {
            return RecordApiResponseService::errorWrapped('Validation failed', RecordApiJsonResponseEnum::VALIDATION_ERROR->value, $e->errors());
        } catch (Exception $e) {
            Log::error('Failed to upsert record', ['table' => $table, 'exception' => $e]);
            return RecordApiResponseService::errorWrapped('An error occurred', RecordApiJsonResponseEnum::SERVER_ERROR->value);
        }
    }
}
