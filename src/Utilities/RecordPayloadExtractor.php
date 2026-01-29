<?php

namespace Sopheak\Core\Utilities;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
    ): array {
        // get columns from record table
        if (!empty($recordTable)) {
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

            if (is_array($request)) {
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
            $data['updated_at'] = TimeUtils::now();

            if (!$isUpdate && !array_key_exists('created_at', $data)) {
                $data['created_at'] = TimeUtils::now();
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
    ): array {
        return self::extract(
            request: $request,
            fields: $fields,
            baseData: $baseData,
            isUpdate: $isUpdate,
            recordTable: $recordTable,
            classModel: $classModel,
            transform: $transform,
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
    ): array {
        return self::extract(
            request: $data,
            fields: $fields,
            baseData: $baseData,
            isUpdate: $isUpdate,
            recordTable: $recordTable,
            classModel: $classModel,
            transform: $transform,
        );
    }

    public static function fromModel(
        object $model,
        array $fields = [],
        array $baseData = [],
        bool $isUpdate = true,
        string $recordTable = '',
        ?callable $transform = null,
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
    ): array {
        return self::extract(
            request: $row,
            fields: $fields,
            baseData: $baseData,
            isUpdate: $isUpdate,
            recordTable: $recordTable,
            classModel: $classModel,
            transform: $transform,
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
        );
    }
}
