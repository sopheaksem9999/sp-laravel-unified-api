<?php

declare(strict_types=1);

namespace Sopheak\Core\Http\Controllers\Concerns;

use Illuminate\Http\Exceptions\HttpResponseException;
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
            [, $tenantError] = $this->resolveTenantContext($request, $tableSchema);
            if ($tenantError instanceof Response) {
                return $tenantError;
            }

            return $this->recordService->executeTableFunction($request, $table, $functionName);
        } catch (HttpResponseException $exception) {
            throw $exception;
        } catch (Exception $exception) {
            $status = $exception->getCode();
            if (!is_int($status) || $status < 100 || $status > 599) {
                $status = RecordApiJsonResponseEnum::SERVER_ERROR->value;
            }

            return RecordApiResponseService::errorFromException($exception, $exception->getMessage() ?: $exception::class, $status);
        }
    }

    /**
     * Execute a table-specific custom function with an ID parameter.
     */
    public function executeTableFunctionWithId(Request $request, string $table, string $id, string $functionName): Response
    {
        // For endpoints like {table}/{id}/{functionName} (e.g. sp_attachments/{id}/download),
        // we can inject the 'id' into the request payload so the custom controller function can access it.
        // However, standard custom functions are usually mapped to methods like `download(Request $request, string $id)`.
        // The executeTableFunction inside RecordService does not natively pass the $id as a separate param
        // to the custom controller method unless we modify it or merge it into the request.
        // For now, we inject it into the request so the controller can retrieve it via $request->route('id') or $request->input('id').
        $request->merge(['id' => $id]);

        // Also ensure the route parameters are set properly if they aren't already
        $request->route()->setParameter('id', $id);

        try {
            $tableSchema = $this->resolveSchemaOrFail($table);
            [, $tenantError] = $this->resolveTenantContext($request, $tableSchema);
            if ($tenantError instanceof Response) {
                return $tenantError;
            }

            return $this->recordService->executeTableFunction($request, $table, $functionName);
        } catch (HttpResponseException $exception) {
            throw $exception;
        } catch (Exception $exception) {
            $status = $exception->getCode();
            if (!is_int($status) || $status < 100 || $status > 599) {
                $status = RecordApiJsonResponseEnum::SERVER_ERROR->value;
            }

            return RecordApiResponseService::errorFromException($exception, $exception->getMessage() ?: $exception::class, $status);
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
        } catch (HttpResponseException $exception) {
            throw $exception;
        } catch (Exception $exception) {
            $status = $exception->getCode();
            if (!is_int($status) || $status < 100 || $status > 599) {
                $status = RecordApiJsonResponseEnum::SERVER_ERROR->value;
            }

            return RecordApiResponseService::errorFromException($exception, $exception->getMessage(), $status);
        }
    }
}
