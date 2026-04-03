<?php

namespace Sopheak\Core\Http\Controllers\Concerns;

use Exception;
use Illuminate\Http\Request;
use Sopheak\Core\Enums\RecordApiJsonResponseEnum;
use Sopheak\Core\Services\RecordApiResponseService;
use Sopheak\Core\Services\RecordService;
use Symfony\Component\HttpFoundation\Response;

/**
 * @property RecordService $recordService
 */
trait HasFunctionOperations
{
    /**
     * Execute a table-specific custom function.
     */
    public function executeTableFunction(Request $request, string $table, string $functionName): Response
    {
        try {
            $tableSchema = $this->resolveSchemaOrFail($table);
            $action = match (strtoupper($request->method())) {
                'POST' => 'create',
                'PUT', 'PATCH' => 'update',
                'DELETE' => 'delete',
                default => 'read',
            };

            $isEndpointEnabled = match ($action) {
                'create' => $this->isCreateEndpointEnabled($tableSchema),
                'update' => $this->isUpdateEndpointEnabled($tableSchema),
                'delete' => $this->isDeleteEndpointEnabled($tableSchema),
                default => $this->isReadEndpointEnabled($tableSchema),
            };

            if (!$isEndpointEnabled) {
                return $this->resourceNotAvailableResponse();
            }

            $this->authorizeAction($table, $action);

            return $this->recordService->executeTableFunction($request, $table, $functionName);
        } catch (Exception $exception) {
            $status = $exception->getCode();
            if (!is_int($status) || $status < 100 || $status > 599) {
                $status = RecordApiJsonResponseEnum::SERVER_ERROR->value;
            }

            return RecordApiResponseService::errorFromException($exception, $exception->getMessage(), $status);
        }
    }

    /**
     * Execute a global custom function.
     * Supports patterns like: function_name or function_name/{id}.
     */
    public function executeGlobalFunction(Request $request, string $functionName): Response
    {
        try {
            return $this->recordService->executeGlobalFunction($request, $functionName);
        } catch (Exception $exception) {
            $status = $exception->getCode();
            if (!is_int($status) || $status < 100 || $status > 599) {
                $status = RecordApiJsonResponseEnum::SERVER_ERROR->value;
            }

            return RecordApiResponseService::errorFromException($exception, $exception->getMessage(), $status);
        }
    }
}
