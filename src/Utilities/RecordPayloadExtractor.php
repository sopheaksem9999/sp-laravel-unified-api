<?php

namespace Sopheak\Core\Utilities;

use Sopheak\Core\Types\RecordTableType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Sopheak\Core\Utilities\RecordUtils;

final class RecordPayloadExtractor
{
    /**
     * Extract structured data from a Request or array source.
     *
     * This helper builds a payload array and can optionally hydrate a model
     * instance. It supports:
     * - Request or array input sources
     * - Explicit field lists or automatic fields from record table config
     * - Base data defaults that are preserved when request values are empty
     * - Create/update timestamp handling
     * - Optional per-field transformation callbacks
     *
     * @param Request|array    $request     The request object or array containing the input data
     * @param array            $fields      List of field names to extract (ignored when $recordTable is set)
     * @param array            $baseData    Base data to merge; not overridden by null/empty values
     * @param bool             $isUpdate    When true, only updated_at is managed; otherwise created_at and
     *                                      updated_at are set when changes exist
     * @param string           $recordTable Optional record table key; when provided, column names are
     *                                      loaded from config('record.tables.{table}.columns')
     * @param object|null      $classModel  Optional model instance to hydrate with extracted values
     * @param callable|null    $transform   Optional callback: function (string $field, mixed $value,
     *                                      Request|array $source): mixed
     *
     * @return array The array of extracted and transformed field data
     */
    private static function extract(
        Request|array $request,
        array $fields = [],
        array $baseData = [],
        bool $isUpdate = false,
        string $recordTable = '',
        mixed $classModel = null,
        ?callable $transform = null,
        ?RecordTableType $recordTableSchema = null,
    ): array {
        $tableSchema = $recordTableSchema;
        if (!$tableSchema && !empty($recordTable)) {
            $tableSchema = SchemaRegistryUtils::getTable($recordTable);
        }

        // get columns from record table
        if ($tableSchema instanceof RecordTableType) {
            if (isset($tableSchema->columns) && is_array($tableSchema->columns)) {
                $fields = array_keys($tableSchema->columns);
            }

            if (isset($tableSchema->columnWriteDisabled) && is_array($tableSchema->columnWriteDisabled)) {
                $fields = array_values(array_filter(
                    $fields,
                    static fn($field): bool => !in_array($field, $tableSchema->columnWriteDisabled, true)
                ));
            }
        } elseif (!empty($recordTable)) {
            $table = config('record.tables.' . $recordTable);

            if (isset($table->columns) && is_array($table->columns)) {
                $fields = array_keys($table->columns);
            }

            if (isset($table->columnWriteDisabled) && is_array($table->columnWriteDisabled)) {
                $fields = array_values(array_filter(
                    $fields,
                    static fn($field): bool => !in_array($field, $table->columnWriteDisabled, true)
                ));
            }
        }

        if (!empty($fields)) {
            $fields = array_values(array_filter($fields, static fn($field): bool => 'id' !== $field));
        }

        $data = $baseData;
        $hasChanges = false;

        foreach ($fields as $field) {
            $hasField = false;
            $value = null;

            if (array_key_exists($field, $baseData)) {
                $hasField = true;
                $value = $baseData[$field];
            } elseif (is_array($request)) {
                if (array_key_exists($field, $request)) {
                    $hasField = true;
                    $value = $request[$field];
                }
            } elseif ($request->has($field)) {
                $hasField = true;
                $value = $request->input($field);
            }

            // If the field is not present in the request/array, keep baseData as-is
            if (!$hasField) {
                continue;
            }

            if (null !== $transform) {
                $value = $transform($field, $value, $request);
            }

            // Do not override provided defaults with empty values
            if ((null === $value || '' === $value) && array_key_exists($field, $baseData)) {
                continue;
            }

            $data[$field] = $value;
            $hasChanges = true;

            if (null !== $classModel && \is_object($classModel)) {
                $classModel->{$field} = $value;
            }
        }

        if ($hasChanges) {
            $now = TimeUtils::now();
            // allow overriding timestamps if explicitly provided, otherwise set them based on operation type
            $overrideTimestamps = $tableSchema->overrideTimestamps ?? false;
            $overrideUserstamps = $tableSchema->overrideUserstamps ?? false;

            $hasUpdatedAt = false;
            $updatedAtVal = null;
            $hasCreatedBy = false;
            $createdByVal = null;
            $hasCreatedById = false;
            $createdByIdVal = null;
            $hasUpdatedBy = false;
            $updatedByVal = null;
            $hasLastUpdatedBy = false;
            $lastUpdatedByVal = null;
            $hasLastUpdatedById = false;
            $lastUpdatedByIdVal = null;

            if (is_array($request)) {
                $hasUpdatedAt = array_key_exists('updated_at', $request);
                $updatedAtVal = $hasUpdatedAt ? $request['updated_at'] : null;
                $hasCreatedBy = array_key_exists('created_by', $request);
                $createdByVal = $hasCreatedBy ? $request['created_by'] : null;
                $hasCreatedById = array_key_exists('created_by_id', $request);
                $createdByIdVal = $hasCreatedById ? $request['created_by_id'] : null;
                $hasUpdatedBy = array_key_exists('updated_by', $request);
                $updatedByVal = $hasUpdatedBy ? $request['updated_by'] : null;
                $hasLastUpdatedBy = array_key_exists('last_updated_by', $request);
                $lastUpdatedByVal = $hasLastUpdatedBy ? $request['last_updated_by'] : null;
                $hasLastUpdatedById = array_key_exists('last_updated_by_id', $request);
                $lastUpdatedByIdVal = $hasLastUpdatedById ? $request['last_updated_by_id'] : null;
            } elseif ($request instanceof Request) {
                $hasUpdatedAt = $request->has('updated_at');
                $updatedAtVal = $hasUpdatedAt ? $request->input('updated_at') : null;
                $hasCreatedBy = $request->has('created_by');
                $createdByVal = $hasCreatedBy ? $request->input('created_by') : null;
                $hasCreatedById = $request->has('created_by_id');
                $createdByIdVal = $hasCreatedById ? $request->input('created_by_id') : null;
                $hasUpdatedBy = $request->has('updated_by');
                $updatedByVal = $hasUpdatedBy ? $request->input('updated_by') : null;
                $hasLastUpdatedBy = $request->has('last_updated_by');
                $lastUpdatedByVal = $hasLastUpdatedBy ? $request->input('last_updated_by') : null;
                $hasLastUpdatedById = $request->has('last_updated_by_id');
                $lastUpdatedByIdVal = $hasLastUpdatedById ? $request->input('last_updated_by_id') : null;
            }

            $data['updated_at'] = (!$hasUpdatedAt || !$overrideTimestamps) ? $now : $updatedAtVal;

            // only set created_at if not update and not explicitly provided
            if (!$isUpdate && !array_key_exists('created_at', $data)) {
                $data['created_at'] = $now;
            }

            if ($tableSchema instanceof RecordTableType) {
                $user = auth('api')->user();
                if ($isUpdate) {
                    if ($user) {
                        if (isset($tableSchema->columns['updated_by'])) {
                            $data['updated_by'] = (!$hasUpdatedBy || !$overrideUserstamps) ? $user->id : $updatedByVal;
                        }
                        if (isset($tableSchema->columns['last_updated_by'])) {
                            $data['last_updated_by'] = (!$hasLastUpdatedBy || !$overrideUserstamps) ? $user->id : $lastUpdatedByVal;
                        }
                        if (isset($tableSchema->columns['last_updated_by_id'])) {
                            $data['last_updated_by_id'] = (!$hasLastUpdatedById || !$overrideUserstamps) ? $user->id : $lastUpdatedByIdVal;
                        }
                    }
                } else {
                    if ($user && isset($tableSchema->columns['created_by'])) {
                        $data['created_by'] = (!$hasCreatedBy || !$overrideUserstamps) ? $user->id : $createdByVal;
                    }
                    if ($user && isset($tableSchema->columns['created_by_id'])) {
                        $data['created_by_id'] = (!$hasCreatedById || !$overrideUserstamps) ? $user->id : $createdByIdVal;
                    }
                    if ($user && isset($tableSchema->columns['updated_by'])) {
                        $data['updated_by'] = (!$hasUpdatedBy || !$overrideUserstamps) ? $user->id : $updatedByVal;
                    }
                    if ($user && isset($tableSchema->columns['last_updated_by'])) {
                        $data['last_updated_by'] = (!$hasLastUpdatedBy || !$overrideUserstamps) ? $user->id : $lastUpdatedByVal;
                    }
                    if ($user && isset($tableSchema->columns['last_updated_by_id'])) {
                        $data['last_updated_by_id'] = (!$hasLastUpdatedById || !$overrideUserstamps) ? $user->id : $lastUpdatedByIdVal;
                    }

                    $tenantColumn = RecordConfigService::tenantColumn();
                    if (
                        RecordUtils::shouldApplyTenantId($tableSchema)
                        && isset($tableSchema->columns[$tenantColumn])
                        && !array_key_exists($tenantColumn, $data)
                    ) {
                        $tenantId = null;
                        if ($request instanceof Request) {
                            $tenantId = $request->attributes->get('resolved_tenant_id');
                            if (RecordUtils::isTenantIdMissing($tenantId)) {
                                $requestContext = $request->attributes->get('record_context');
                                if (is_array($requestContext)) {
                                    $tenantId = $requestContext['tenant_id'] ?? null;
                                }
                            }

                            if (RecordUtils::isTenantIdMissing($tenantId)) {
                                $tenantId = $request->header(RecordConfigService::tenantHeader());
                            }
                        }

                        $tenantId = RecordUtils::normalizeTenantId($tenantId);
                        if (!RecordUtils::isTenantIdMissing($tenantId)) {
                            $data[$tenantColumn] = $tenantId;
                        }
                    }
                }
            }
        }

        return $data;
    }

    public static function fromRequest(
        Request $request,
        array $fields = [],
        array $baseData = [],
        bool $isUpdate = false,
        string $recordTable = '',
        mixed $classModel = null,
        ?callable $transform = null,
        ?RecordTableType $recordTableSchema = null,
    ): array {
        return self::extract(
            request: $request,
            fields: $fields,
            baseData: $baseData,
            isUpdate: $isUpdate,
            recordTable: $recordTable,
            classModel: $classModel,
            transform: $transform,
            recordTableSchema: $recordTableSchema,
        );
    }

    public static function fromArray(
        array $data,
        array $fields = [],
        array $baseData = [],
        bool $isUpdate = false,
        string $recordTable = '',
        mixed $classModel = null,
        ?callable $transform = null,
        ?RecordTableType $recordTableSchema = null,
    ): array {
        return self::extract(
            request: $data,
            fields: $fields,
            baseData: $baseData,
            isUpdate: $isUpdate,
            recordTable: $recordTable,
            classModel: $classModel,
            transform: $transform,
            recordTableSchema: $recordTableSchema,
        );
    }

    public static function fromModel(
        object $model,
        array $fields = [],
        array $baseData = [],
        bool $isUpdate = true,
        string $recordTable = '',
        ?callable $transform = null,
        ?RecordTableType $recordTableSchema = null,
    ): array {
        $source = method_exists($model, 'toArray') ? $model->toArray() : get_object_vars($model);

        return self::extract(
            request: $source,
            fields: $fields,
            baseData: $baseData,
            isUpdate: $isUpdate,
            recordTable: $recordTable,
            classModel: $model,
            transform: $transform,
            recordTableSchema: $recordTableSchema,
        );
    }

    public static function fromDatabaseRow(
        array $row,
        array $fields = [],
        array $baseData = [],
        bool $isUpdate = true,
        string $recordTable = '',
        mixed $classModel = null,
        ?callable $transform = null,
        ?RecordTableType $recordTableSchema = null,
    ): array {
        return self::extract(
            request: $row,
            fields: $fields,
            baseData: $baseData,
            isUpdate: $isUpdate,
            recordTable: $recordTable,
            classModel: $classModel,
            transform: $transform,
            recordTableSchema: $recordTableSchema,
        );
    }

    public static function fromResponse(
        JsonResponse $response,
        array $fields = [],
        array $baseData = [],
        bool $isUpdate = false,
        string $recordTable = '',
        mixed $classModel = null,
        ?callable $transform = null,
        ?RecordTableType $recordTableSchema = null,
    ): array {
        $body = $response->getData(true);
        $source = is_array($body) && array_key_exists('data', $body) ? $body['data'] : $body;

        return self::extract(
            request: is_array($source) ? $source : [],
            fields: $fields,
            baseData: $baseData,
            isUpdate: $isUpdate,
            recordTable: $recordTable,
            classModel: $classModel,
            transform: $transform,
            recordTableSchema: $recordTableSchema,
        );
    }
}
