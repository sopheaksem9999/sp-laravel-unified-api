<?php

namespace Sopheak\Core\Services;

use stdClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\MessageBag;
use Sopheak\Core\Enums\RecordApiJsonResponseEnum;

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
     * Create a validation error response (400).
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
            $data = ['validation_errors' => ['message' => (string) $errors]];
        }

        return static::jsonResponse($data, RecordApiJsonResponseEnum::ERROR);
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
