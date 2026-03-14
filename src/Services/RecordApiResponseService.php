<?php

namespace Sopheak\Core\Services;

use stdClass;
use Throwable;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    /**
     * Apply column casts to response data — only for columns that have a 'cast' key defined.
     * Columns without a 'cast' key are completely untouched. Null values are preserved as-is.
     *
     * Supported built-in cast strings (Laravel-compatible names):
     *   int, integer, float, double, real, decimal, decimal:N,
     *   string, bool, boolean, array, json, object, date, datetime, timestamp
     *
     * Custom cast forms:
     *   - Closure:          fn($value, $column, $row) => mixed
     *   - [Class, 'method'] static or instance call
     *   - 'Class@method'    string
     *   - 'ClassName'       calls ->get($value, $column, $row) on a new instance
     *
     * @param mixed $data    Single record, sequential list, Collection, or LengthAwarePaginator
     * @param array $columns Column definitions from RecordTableType::$columns
     */
    public static function applyCasts(mixed $data, array $columns): mixed
    {
        if (null === $data || [] === $columns) {
            return $data;
        }

        // Build cast map — skip columns with no 'cast' key and columns that have
        // compositeFields (those are already handled by convertCompositeFields)
        $castMap = [];
        foreach ($columns as $col => $meta) {
            if (!is_array($meta) || !array_key_exists('cast', $meta)) {
                continue;
            }
            if (!empty($meta['compositeFields']) || !empty($meta['composite_fields'])) {
                continue;
            }
            $castMap[(string) $col] = $meta['cast'];
        }

        if ([] === $castMap) {
            return $data;
        }

        // Resolve custom callables once; leave built-in strings as descriptor arrays
        $resolved = [];
        foreach ($castMap as $col => $cast) {
            if ($cast instanceof \Closure) {
                $resolved[$col] = ['callable' => $cast];
            } elseif (is_array($cast) && count($cast) === 2) {
                [$class, $method] = $cast;
                if (is_object($class)) {
                    $callable = [$class, $method];
                } elseif (is_string($class) && class_exists($class)) {
                    // Prefer static call for simple utility classes; fall back to instance
                    $callable = (is_callable([$class, $method]) && method_exists($class, $method))
                        ? [$class, $method]
                        : [app($class), $method];
                } else {
                    $callable = null;
                }
                if (null !== $callable && is_callable($callable)) {
                    $resolved[$col] = ['callable' => $callable];
                }
            } elseif (is_string($cast)) {
                if (str_contains($cast, '@')) {
                    [$class, $method] = explode('@', $cast, 2);
                    if (class_exists($class)) {
                        $resolved[$col] = ['callable' => [app($class), $method]];
                    }
                } elseif (class_exists($cast)) {
                    $resolved[$col] = ['callable' => [app($cast), 'get']];
                } else {
                    $resolved[$col] = ['builtin' => strtolower($cast)];
                }
            }
        }

        if ([] === $resolved) {
            return $data;
        }

        $applyToRow = static function (mixed $row) use ($resolved): mixed {
            $isObject = is_object($row);
            foreach ($resolved as $col => $descriptor) {
                if ($isObject) {
                    if (!property_exists($row, $col)) {
                        continue;
                    }
                    $value = $row->{$col};
                } else {
                    if (!array_key_exists($col, $row)) {
                        continue;
                    }
                    $value = $row[$col];
                }

                if (null === $value) {
                    continue;
                }

                if (isset($descriptor['callable'])) {
                    $value = ($descriptor['callable'])($value, $col, $row);
                } else {
                    $cast = $descriptor['builtin'];
                    $value = match (true) {
                        $cast === 'int' || $cast === 'integer'                       => (int) $value,
                        $cast === 'float' || $cast === 'double' || $cast === 'real' => (float) $value,
                        str_starts_with($cast, 'decimal:')                          => number_format((float) $value, (int) substr($cast, 8), '.', ''),
                        $cast === 'decimal'                                          => (float) $value,
                        $cast === 'string'                                           => (string) $value,
                        $cast === 'bool' || $cast === 'boolean'                     => (bool) $value,
                        $cast === 'array' || $cast === 'json'                       => is_string($value) ? (json_decode($value, true) ?? $value) : (array) $value,
                        $cast === 'object'                                           => is_string($value) ? (json_decode($value) ?? $value) : (object) $value,
                        $cast === 'date'                                             => \Illuminate\Support\Carbon::parse($value)->toDateString(),
                        $cast === 'datetime'                                         => \Illuminate\Support\Carbon::parse($value)->toISOString(),
                        $cast === 'timestamp'                                        => \Illuminate\Support\Carbon::parse($value)->getTimestamp(),
                        default                                                      => $value,
                    };
                }

                if ($isObject) {
                    $row->{$col} = $value;
                } else {
                    $row[$col] = $value;
                }
            }

            return $row;
        };

        if ($data instanceof LengthAwarePaginator) {
            $items = $data->getCollection()->map($applyToRow);

            return new LengthAwarePaginator(
                $items,
                $data->total(),
                $data->perPage(),
                $data->currentPage(),
                ['path' => request()->url(), 'pageName' => 'page']
            );
        }

        if ($data instanceof Collection) {
            return $data->map($applyToRow);
        }

        if (is_array($data) && !empty($data) && array_is_list($data)) {
            return array_map($applyToRow, $data);
        }

        if (is_array($data) || is_object($data)) {
            return $applyToRow($data);
        }

        return $data;
    }

    /**
     * Apply computed attributes to each row — only for fields present in $requestedCols.
     * When $requestedCols is empty (no ?select= param), no attributes are resolved.
     *
     * Each entry in $attributes maps a field name to a callable resolver:
     *   - Closure:        fn($row, $table) => value
     *   - [Class, method] array
     *   - 'Class@method'  string
     *   - 'Class'         string — calls handle($row, $table)
     *
     * @param mixed    $data          Single record or sequential list of records
     * @param string   $table         Table name
     * @param array    $attributes    Map of field => callable
     * @param string[] $requestedCols Columns from ?select= (only matching attributes are resolved)
     */
    public static function applyAttributes(mixed $data, string $table, array $attributes, array $requestedCols = []): mixed
    {
        if (null === $data || [] === $attributes || [] === $requestedCols) {
            return $data;
        }

        // Only resolve attributes explicitly requested via ?select=
        $appends = array_intersect_key($attributes, array_flip($requestedCols));

        if ([] === $appends) {
            return $data;
        }

        // Resolve all callables once
        $resolvedAppends = [];
        foreach ($appends as $field => $resolver) {
            $callable = null;

            if ($resolver instanceof \Closure) {
                $callable = $resolver;
            } elseif (is_array($resolver) && count($resolver) === 2) {
                [$class, $method] = $resolver;
                if (is_string($class) && class_exists($class)) {
                    $callable = [app($class), $method];
                } elseif (is_object($class)) {
                    $callable = [$class, $method];
                }
            } elseif (is_string($resolver)) {
                if (str_contains($resolver, '@')) {
                    [$class, $method] = explode('@', $resolver, 2);
                    if (class_exists($class)) {
                        $callable = [app($class), $method];
                    }
                } elseif (class_exists($resolver)) {
                    $instance = app($resolver);
                    $callable = method_exists($instance, 'handle') ? [$instance, 'handle'] : null;
                }
            }

            if (null !== $callable) {
                $resolvedAppends[$field] = $callable;
            }
        }

        if ([] === $resolvedAppends) {
            return $data;
        }

        $applyToRow = static function (mixed $row) use ($table, $resolvedAppends): mixed {
            $isObject = is_object($row);
            foreach ($resolvedAppends as $field => $callable) {
                $value = $callable($row, $table);
                if ($isObject) {
                    $row->{$field} = $value;
                } else {
                    $row[$field] = $value;
                }
            }
            return $row;
        };

        // Sequential list
        if (is_array($data) && !empty($data) && array_is_list($data)) {
            return array_map($applyToRow, $data);
        }

        // Single record (array or object)
        if (is_array($data) || is_object($data)) {
            return $applyToRow($data);
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

    public static function errorWrapped(string $message, int $status = RecordApiJsonResponseEnum::ERROR->value, array $errors = [], ?int $error_code = null, ?array $debug = null): JsonResponse
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

        $meta = [
            'request_id' => $requestId,
        ];

        if (is_array($debug) && [] !== $debug && self::shouldIncludeDebugDetails()) {
            $meta['debug'] = $debug;
        }

        return response()->json([
            'success' => false,
            'error_code' => $resolvedErrorCode,
            'message' => $message,
            'errors' => $errors,
            'meta' => $meta,
        ], $status);
    }

    public static function errorFromException(Throwable $exception, string $message = 'An error occurred', int $status = RecordApiJsonResponseEnum::SERVER_ERROR->value, array $errors = [], ?int $error_code = null): JsonResponse
    {
        if (self::shouldIncludeDebugDetails()) {
            Log::error('SP Laravel API exception', [
                'message' => $exception->getMessage(),
                'type' => $exception::class,
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'request_id' => request()->attributes->get('request_id'),
                'path' => request()->path(),
                'method' => request()->method(),
            ]);
        }

        $debug = null;
        if (self::shouldIncludeDebugDetails()) {
            $debug = [
                'exception' => $exception::class,
                'exception_code' => (int) $exception->getCode(),
                'exception_message' => $exception->getMessage(),
                'file' => basename($exception->getFile()),
                'line' => $exception->getLine(),
            ];
        }

        return self::errorWrapped(
            message: $message,
            status: $status,
            errors: $errors,
            error_code: $error_code,
            debug: $debug
        );
    }

    private static function shouldIncludeDebugDetails(): bool
    {
        if (RecordConfigService::debugEnabled()) {
            return true;
        }

        $headerValue = request()->headers->get('X-Debug') ?? request()->headers->get('x-debug');
        if (!is_string($headerValue)) {
            return false;
        }

        return in_array(strtolower(trim($headerValue)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Create a success response with data.
     *
     * @param mixed $data The data to return
     */
    public static function success(mixed $data = null): JsonResponse
    {
        return static::jsonResponse(data: $data, statusCode: RecordApiJsonResponseEnum::SUCCESS);
    }

    /**
     * Create a created response (201).
     *
     * @param mixed $data The created resource data
     */
    public static function created(mixed $data = null): JsonResponse
    {
        return static::jsonResponse(data: $data, statusCode: RecordApiJsonResponseEnum::CREATED);
    }

    /**
     * Create an updated response (200).
     *
     * @param mixed $data The updated resource data
     */
    public static function updated(mixed $data = null): JsonResponse
    {
        return static::jsonResponse(data: $data, statusCode: RecordApiJsonResponseEnum::SUCCESS);
    }

    /**
     * Create a deleted response (204).
     */
    public static function deleted(mixed $data = null): JsonResponse
    {
        return static::jsonResponse(data: $data ?? ['deleted' => true], statusCode: RecordApiJsonResponseEnum::DELETED);
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
        return static::jsonResponse(data: ['errors' => $message], statusCode: $statusCode);
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

        return static::jsonResponse(data: $data, statusCode: RecordApiJsonResponseEnum::VALIDATION_ERROR);
    }

    /**
     * Create an unauthorized response (401).
     *
     * @param string $message Error message
     */
    public static function unauthorized(string $message = 'Unauthorized access'): JsonResponse
    {
        return static::jsonResponse(data: ['errors' => $message], statusCode: RecordApiJsonResponseEnum::UNAUTHORIZED);
    }

    /**
     * Create a forbidden response (403).
     *
     * @param string $message Error message
     */
    public static function forbidden(string $message = 'Access forbidden'): JsonResponse
    {
        return static::jsonResponse(data: ['errors' => $message], statusCode: RecordApiJsonResponseEnum::FORBIDDEN);
    }

    /**
     * Create a not found response (404).
     *
     * @param string $message Error message
     */
    public static function notFound(string $message = 'Resource not found'): JsonResponse
    {
        return static::jsonResponse(data: ['errors' => $message], statusCode: RecordApiJsonResponseEnum::NOT_FOUND);
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

        return static::jsonResponse(data: $errorData, statusCode: RecordApiJsonResponseEnum::SERVER_ERROR);
    }

    /**
     * Recursively remove 'deleted_at' fields from arrays and objects.
     *
     * @param mixed $data The data to clean
     *
     * @return mixed The cleaned data
     */
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
