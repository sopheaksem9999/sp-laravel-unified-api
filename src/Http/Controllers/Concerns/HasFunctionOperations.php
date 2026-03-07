<?php

namespace Sopheak\Core\Http\Controllers\Concerns;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Sopheak\Core\Enums\RecordApiJsonResponseEnum;
use Sopheak\Core\Services\RecordApiResponseService;
use Sopheak\Core\Services\RecordService;

/**
 * @property RecordService $recordService
 */
trait HasFunctionOperations
{
    /**
     * Execute a table-specific custom function.
     */
    public function executeTableFunction(Request $request, string $table, string $functionName): JsonResponse
    {
        try {
            $tableSchema = $this->resolveSchemaOrFail($table);

            if (!$this->isReadEndpointEnabled($tableSchema)) {
                return $this->resourceNotAvailableResponse();
            }

            $this->authorizeAction($table, 'read');

            return $this->recordService->executeTableFunction($request, $table, $functionName);
        } catch (Exception $exception) {
            return RecordApiResponseService::errorWrapped(
                $exception->getMessage(),
                $exception->getCode() ?: RecordApiJsonResponseEnum::SERVER_ERROR->value
            );
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
            return RecordApiResponseService::errorWrapped(
                $exception->getMessage(),
                $exception->getCode() ?: RecordApiJsonResponseEnum::SERVER_ERROR->value
            );
        }
    }
}
