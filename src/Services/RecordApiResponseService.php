<?php

namespace Sopheak\Core\Services;

use stdClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Facades\DB;
use Sopheak\Core\Enums\RecordApiJsonResponseEnum;
use Sopheak\Core\Constants\HttpErrorCodeConstant;
use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Sopheak\Core\Utilities\RelationshipResolverUtils;

class RecordApiResponseService
{
    /**
     * Create a simple JSON response for API v1 compatibility
     * Returns only data and status code to maintain backward compatibility.
     *
     * @param mixed               $data       The data to return
     * @param RecordApiJsonResponseEnum $statusCode HTTP status code
     */
    public static function jsonResponse(
        mixed $data = null,
        RecordApiJsonResponseEnum $statusCode = RecordApiJsonResponseEnum::SUCCESS
    ): JsonResponse {
        // Remove deleted_at fields from data for security
        $cleanData = static::removeDeletedAtFields($data);

        return response()->json($cleanData, $statusCode->value);
    }

    /**
     * Remove hidden columns from data recursively based on table schema.
     *
     * @param mixed  $data  The data to clean
     * @param string $table The table name for the current level of data
     */
    public static function removeHiddenFields(mixed $data, string $table): mixed
    {
        if (null === $data) {
            return null;
        }

        // Handle Laravel Collections
        if ($data instanceof Collection) {
            return $data->map(fn($item): mixed => static::removeHiddenFields($item, $table));
        }

        // Handle Laravel Paginator
        if ($data instanceof LengthAwarePaginator) {
            $items = $data->getCollection()->map(fn($item): mixed => static::removeHiddenFields($item, $table));

            return new LengthAwarePaginator(
                $items,
                $data->total(),
                $data->perPage(),
                $data->currentPage(),
                [
                    'path' => request()->url(),
                    'pageName' => 'page',
                ]
            );
        }

        // Handle arrays of items (list of records)
        // Check if array keys are all integers (sequential or not)
        if (is_array($data) && !empty($data)) {
            $isList = true;
            foreach (array_keys($data) as $k) {
                if (!is_int($k)) {
                    $isList = false;
                    break;
                }
            }

            if ($isList) {
                foreach ($data as $key => $value) {
                    $data[$key] = static::removeHiddenFields($value, $table);
                }

                return $data;
            }
        }

        // From here, $data is likely a single item (array or object) or a primitive

        // If it's a primitive, return it (unless we want to handle single column selects?)
        // Assuming records are objects/arrays.
        if (!is_array($data) && !is_object($data)) {
            return $data;
        }

        $schema = SchemaRegistryUtils::resolveTableSchema($table);
        $hiddenColumns = $schema->columnHiddens ?? [];

        $isObject = is_object($data);

        // Remove hidden columns
        if (!empty($hiddenColumns)) {
            foreach ($hiddenColumns as $col) {
                if ($isObject) {
                    if (property_exists($data, $col)) {
                        unset($data->{$col});
                    }
                } elseif (array_key_exists($col, $data)) {
                    unset($data[$col]);
                }
            }
        }

        // Recursively clean relationships
        $keys = $isObject ? array_keys(get_object_vars($data)) : array_keys($data);

        foreach ($keys as $key) {
            // Check if this key corresponds to a relationship
            // We use RelationshipResolverUtils to find if 'key' is a valid alias for 'table'
            $relationConfig = RelationshipResolverUtils::resolveRelationship($table, $key);

            if ($relationConfig) {
                $relatedTable = $relationConfig['table'];

                if ($relatedTable) {
                    if ($isObject) {
                        $data->{$key} = static::removeHiddenFields($data->{$key}, $relatedTable);
                    } else {
                        $data[$key] = static::removeHiddenFields($data[$key], $relatedTable);
                    }
                }
            }
        }

        return $data;
    }

    public static function convertCompositeFields(mixed $data, string $table): mixed
    {
        if (null === $data) {
            return null;
        }

        if (DB::getDriverName() !== 'pgsql') {
            return $data;
        }

        if ($data instanceof Collection) {
            return $data->map(fn($item): mixed => static::convertCompositeFields($item, $table));
        }

        if ($data instanceof LengthAwarePaginator) {
            $items = $data->getCollection()->map(fn($item): mixed => static::convertCompositeFields($item, $table));

            return new LengthAwarePaginator(
                $items,
                $data->total(),
                $data->perPage(),
                $data->currentPage(),
                [
                    'path' => request()->url(),
                    'pageName' => 'page',
                ]
            );
        }

        if (is_array($data) && !empty($data)) {
            $isList = true;
            foreach (array_keys($data) as $k) {
                if (!is_int($k)) {
                    $isList = false;
                    break;
                }
            }

            if ($isList) {
                foreach ($data as $key => $value) {
                    $data[$key] = static::convertCompositeFields($value, $table);
                }

                return $data;
            }
        }

        if (!is_array($data) && !is_object($data)) {
            return $data;
        }

        $schema = SchemaRegistryUtils::resolveTableSchema($table);
        $columns = $schema->columns ?? [];
        $compositeColumns = [];

        if (is_array($columns)) {
            foreach ($columns as $column => $meta) {
                if (!is_array($meta)) {
                    continue;
                }

                $fields = $meta['compositeFields'] ?? ($meta['composite_fields'] ?? []);
                if (!is_array($fields)) {
                    continue;
                }
                if ($fields === []) {
                    continue;
                }

                $fields = array_values(array_filter($fields, is_string(...)));
                if ($fields === []) {
                    continue;
                }

                $compositeColumns[$column] = $fields;
            }
        }

        $isObject = is_object($data);

        foreach ($compositeColumns as $column => $fields) {
            if ($isObject) {
                if (!property_exists($data, $column)) {
                    continue;
                }

                $value = $data->{$column};
            } else {
                if (!array_key_exists($column, $data)) {
                    continue;
                }

                $value = $data[$column];
            }
            if (is_array($value)) {
                continue;
            }
            if (is_object($value)) {
                continue;
            }
            if (!is_string($value)) {
                continue;
            }

            $parsed = self::parseCompositeLiteral($value);
            if ($parsed === null) {
                continue;
            }

            $mapped = [];
            foreach ($fields as $index => $field) {
                $mapped[$field] = $parsed[$index] ?? null;
            }

            if ($isObject) {
                $data->{$column} = $mapped;
            } else {
                $data[$column] = $mapped;
            }
        }

        $keys = $isObject ? array_keys(get_object_vars($data)) : array_keys($data);

        foreach ($keys as $key) {
            $relationConfig = RelationshipResolverUtils::resolveRelationship($table, $key);
            if ($relationConfig) {
                $relatedTable = $relationConfig['table'] ?? null;
                if ($relatedTable) {
                    if ($isObject) {
                        $data->{$key} = static::convertCompositeFields($data->{$key}, $relatedTable);
                    } else {
                        $data[$key] = static::convertCompositeFields($data[$key], $relatedTable);
                    }
                }
            }
        }

        return $data;
    }

    private static function parseCompositeLiteral(string $literal): ?array
    {
        $value = trim($literal);
        if ($value === '') {
            return null;
        }

        if (!str_starts_with($value, '(') || !str_ends_with($value, ')')) {
            return null;
        }

        $inner = substr($value, 1, -1);
        $length = strlen($inner);
        $values = [];
        $token = '';
        $inQuotes = false;
        $quoted = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $inner[$i];

            if ($inQuotes) {
                if ($char === '"') {
                    if ($i + 1 < $length && $inner[$i + 1] === '"') {
                        $token .= '"';
                        $i++;
                    } else {
                        $inQuotes = false;
                        $quoted = true;
                    }

                    continue;
                }

                if ($char === '\\') {
                    if ($i + 1 < $length) {
                        $token .= $inner[$i + 1];
                        $i++;
                    } else {
                        $token .= $char;
                    }

                    continue;
                }

                $token .= $char;
                continue;
            }

            if ($char === '"') {
                $inQuotes = true;
                continue;
            }

            if ($char === ',') {
                $values[] = self::normalizeCompositeToken($token, $quoted);
                $token = '';
                $quoted = false;
                continue;
            }

            $token .= $char;
        }

        $values[] = self::normalizeCompositeToken($token, $quoted);

        return $values;
    }

    private static function normalizeCompositeToken(string $token, bool $quoted): mixed
    {
        if ($quoted) {
            return $token;
        }

        $trimmed = trim($token);
        if ($trimmed === '') {
            return null;
        }

        if (strtolower($trimmed) === 'null') {
            return null;
        }

        return $trimmed;
    }

    public static function successWrapped(mixed $data, array $meta = [], int $status = RecordApiJsonResponseEnum::SUCCESS->value, array $headers = [], ?int $error_code = null): JsonResponse
    {
        $requestId = request()->attributes->get('request_id');
        $meta = array_merge(['request_id' => $requestId], $meta);
        $data = static::removeDeletedAtFields($data);

        return response()->json([
            'success' => true,
            'error_code' => $error_code ?? HttpErrorCodeConstant::SUCCESS,
            'data' => $data,
            'meta' => $meta,
        ], $status, $headers);
    }

    public static function errorWrapped(string $message, int $status = RecordApiJsonResponseEnum::ERROR->value, array $errors = [], ?int $error_code = null): JsonResponse
    {
        $requestId = request()->attributes->get('request_id');

        $resolvedErrorCode = $error_code ?? match ($status) {
            RecordApiJsonResponseEnum::UNAUTHORIZED->value => HttpErrorCodeConstant::INVALID_ACCESS,
            RecordApiJsonResponseEnum::FORBIDDEN->value => HttpErrorCodeConstant::PERMISSION_DENIED,
            RecordApiJsonResponseEnum::NOT_FOUND->value => HttpErrorCodeConstant::RESOURCE_NOT_FOUND,
            RecordApiJsonResponseEnum::VALIDATION_ERROR->value => HttpErrorCodeConstant::INVALID_REQUEST,
            RecordApiJsonResponseEnum::SERVER_ERROR->value => HttpErrorCodeConstant::INTERNAL_SERVER_ERROR,
            default => HttpErrorCodeConstant::GENERAL_ERROR,
        };

        return response()->json([
            'success' => false,
            'error_code' => $resolvedErrorCode,
            'message' => $message,
            'errors' => $errors,
            'meta' => [
                'request_id' => $requestId,
            ],
        ], $status);
    }

    /**
     * Create a success response with data.
     *
     * @param mixed $data The data to return
     */
    public static function success(mixed $data = null): JsonResponse
    {
        return static::jsonResponse($data, RecordApiJsonResponseEnum::SUCCESS);
    }

    /**
     * Create a created response (201).
     *
     * @param mixed $data The created resource data
     */
    public static function created(mixed $data = null): JsonResponse
    {
        return static::jsonResponse($data, RecordApiJsonResponseEnum::CREATED);
    }

    /**
     * Create an updated response (200).
     *
     * @param mixed $data The updated resource data
     */
    public static function updated(mixed $data = null): JsonResponse
    {
        return static::jsonResponse($data, RecordApiJsonResponseEnum::SUCCESS);
    }

    /**
     * Create a deleted response (204).
     */
    public static function deleted(): JsonResponse
    {
        return static::jsonResponse(null, RecordApiJsonResponseEnum::DELETED);
    }

    /**
     * Create an error response.
     *
     * @param string              $message    Error message
     * @param RecordApiJsonResponseEnum $statusCode Error status code
     */
    public static function errorResponse(
        string $message,
        RecordApiJsonResponseEnum $statusCode = RecordApiJsonResponseEnum::ERROR
    ): JsonResponse {
        return static::jsonResponse(['errors' => $message], $statusCode);
    }

    /**
     * Create a validation error response (RecordApiJsonResponseEnum::ERROR->value).
     *
     * @param array|MessageBag|string $errors Validation errors
     */
    public static function validationError(array|MessageBag|string $errors): JsonResponse
    {
        // Handle Laravel's MessageBag, array, or string errors
        if ($errors instanceof MessageBag) {
            $data = ['validation_errors' => $errors->toArray()];
        } elseif (is_array($errors)) {
            $data = ['validation_errors' => $errors];
        } else {
            $data = ['validation_errors' => ['message' => $errors]];
        }

        return static::jsonResponse($data, RecordApiJsonResponseEnum::VALIDATION_ERROR);
    }

    /**
     * Create an unauthorized response (401).
     *
     * @param string $message Error message
     */
    public static function unauthorized(string $message = 'Unauthorized access'): JsonResponse
    {
        return static::jsonResponse(['errors' => $message], RecordApiJsonResponseEnum::UNAUTHORIZED);
    }

    /**
     * Create a forbidden response (403).
     *
     * @param string $message Error message
     */
    public static function forbidden(string $message = 'Access forbidden'): JsonResponse
    {
        return static::jsonResponse(['errors' => $message], RecordApiJsonResponseEnum::FORBIDDEN);
    }

    /**
     * Create a not found response (404).
     *
     * @param string $message Error message
     */
    public static function notFound(string $message = 'Resource not found'): JsonResponse
    {
        return static::jsonResponse(['errors' => $message], RecordApiJsonResponseEnum::NOT_FOUND);
    }

    /**
     * Create a server error response (500).
     *
     * @param string $message Error message
     * @param mixed  $data    Optional error data
     */
    public static function serverError(string $message = 'Internal server error', mixed $data = null): JsonResponse
    {
        $errorData = $data ?? ['errors' => $message];

        return static::jsonResponse($errorData, RecordApiJsonResponseEnum::SERVER_ERROR);
    }

    public static function removeDeletedAtFields(mixed $data): mixed
    {
        if (null === $data) {
            return null;
        }

        // Handle arrays
        if (is_array($data)) {
            $cleaned = [];
            foreach ($data as $key => $value) {
                if ('deleted_at' !== $key) {
                    $cleaned[$key] = static::removeDeletedAtFields($value);
                }
            }

            return $cleaned;
        }

        // Handle objects (including stdClass and Eloquent models)
        if (is_object($data)) {
            // Handle Laravel Collections
            if ($data instanceof Collection) {
                return $data->map(fn($item): mixed => static::removeDeletedAtFields($item));
            }

            // Handle Laravel Paginator
            if ($data instanceof LengthAwarePaginator) {
                $items = $data->getCollection()->map(fn($item): mixed => static::removeDeletedAtFields($item));

                // Create a new paginator with cleaned items
                return new LengthAwarePaginator(
                    $items,
                    $data->total(),
                    $data->perPage(),
                    $data->currentPage(),
                    [
                        'path' => request()->url(),
                        'pageName' => 'page',
                    ]
                );
            }

            // Handle Eloquent models
            if (method_exists($data, 'toArray')) {
                $array = $data->toArray();

                return static::removeDeletedAtFields($array);
            }

            // Handle stdClass and other objects
            $cleaned = new stdClass();
            foreach (get_object_vars($data) as $key => $value) {
                if ('deleted_at' !== $key) {
                    $cleaned->{$key} = static::removeDeletedAtFields($value);
                }
            }

            return $cleaned;
        }

        // Return primitive values as-is
        return $data;
    }
}
