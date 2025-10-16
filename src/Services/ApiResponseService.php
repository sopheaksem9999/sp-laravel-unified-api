<?php

namespace Sopheak\Core\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

class ApiResponseService
{
    public function success($data = null, array $metaExtra = [], int $status = 200): JsonResponse
    {
        return $this->json(true, $data, $metaExtra, $status);
    }

    public function created($data = null, array $metaExtra = []): JsonResponse
    {
        return $this->json(true, $data, $metaExtra, 201);
    }

    public function updated($data = null, array $metaExtra = []): JsonResponse
    {
        return $this->json(true, $data, $metaExtra, 200);
    }

    public function deleted($data = null, array $metaExtra = []): JsonResponse
    {
        return $this->json(true, $data, $metaExtra, 200);
    }

    public function error(string $message = 'Error', array $errors = [], array $metaExtra = [], int $status = 400): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
            'errors' => $errors,
            'meta' => $this->meta($metaExtra),
        ];

        return response()->json($payload, $status);
    }

    public function validationError(array $errors, array $metaExtra = []): JsonResponse
    {
        return $this->error('Validation error', $errors, $metaExtra, 422);
    }

    public function unauthorized(string $message = 'Unauthorized', array $metaExtra = []): JsonResponse
    {
        return $this->error($message, [], $metaExtra, 401);
    }

    public function forbidden(string $message = 'Forbidden', array $metaExtra = []): JsonResponse
    {
        return $this->error($message, [], $metaExtra, 403);
    }

    public function notFound(string $message = 'Not Found', array $metaExtra = []): JsonResponse
    {
        return $this->error($message, [], $metaExtra, 404);
    }

    public function serverError(string $message = 'Server Error', array $metaExtra = []): JsonResponse
    {
        return $this->error($message, [], $metaExtra, 500);
    }

    private function json(bool $success, $data = null, array $metaExtra = [], int $status = 200): JsonResponse
    {
        $cleanData = $this->removeDeletedAtFields($data);

        $payload = [
            'success' => $success,
            'data' => $cleanData,
            'meta' => $this->meta($metaExtra),
        ];

        return response()->json($payload, $status);
    }

    private function meta(array $extra = []): array
    {
        $meta = [];
        if (config('sp-laravel-api.response.include_request_id', true)) {
            $requestId = request()?->attributes->get('request_id') ?? request()?->headers->get('X-Request-ID');
            if ($requestId) {
                $meta['request_id'] = $requestId;
            }
        }
        return array_merge($meta, $extra);
    }

    private function removeDeletedAtFields($data)
    {
        if (is_array($data)) {
            return array_map(function ($item) {
                if (is_array($item)) {
                    return Arr::except($item, ['deleted_at']);
                }
                return $item;
            }, $data);
        }

        if (is_object($data)) {
            if (method_exists($data, 'toArray')) {
                $array = $data->toArray();
                return Arr::except($array, ['deleted_at']);
            }
        }

        return $data;
    }
}
