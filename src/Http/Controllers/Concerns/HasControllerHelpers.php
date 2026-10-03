<?php

declare(strict_types=1);

namespace Sopheak\Core\Http\Controllers\Concerns;

use Throwable;
use Exception;
use InvalidArgumentException;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Sopheak\Core\Enums\RecordApiJsonResponseEnum;
use Sopheak\Core\Exceptions\RecordNotFoundException;
use Sopheak\Core\Jobs\ProcessBulkOperationJob;
use Sopheak\Core\Services\RecordApiResponseService;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Utilities\NestedWriteAuthorizer;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\PermissionUtils;
use Sopheak\Core\Utilities\RecordUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Sopheak\Core\Utilities\TableValidatorRunner;
use Sopheak\Core\Utilities\TenantScopedIncludes;

/**
 * @property RecordService $recordService
 */
trait HasControllerHelpers
{
    /**
     * Run a callable inside a DB transaction, rolling back on any exception.
     */
    private function withinTransaction(callable $fn): mixed
    {
        DB::beginTransaction();
        try {
            // Nested child writes made by this request are authorised as
            // direct requests on the child table (NestedWriteAuthorizer).
            $result = NestedWriteAuthorizer::enforce(fn(): mixed => $fn());
            DB::commit();
            return $result;
        } catch (Throwable $throwable) {
            DB::rollBack();
            throw $throwable;
        }
    }

    /**
     * Resolve the table schema or throw RecordNotFoundException.
     */
    private function resolveSchemaOrFail(string $table): RecordTableType
    {
        $schema = SchemaRegistryUtils::getTable($table);
        if (!$schema instanceof RecordTableType) {
            throw new RecordNotFoundException('Resource not available');
        }

        return $schema;
    }

    /**
     * Normalize and validate the tenant ID from the request.
     * Returns [$tenantId, ?JsonResponse] — caller returns the JsonResponse if non-null.
     * @return array<int, mixed>
     */
    private function resolveTenantContext(Request $request, object $tableSchema, bool $checkIncludes = true, ?string $table = null): array
    {
        $tenantId = $this->recordService->resolveTenantFromRequest($request, $tableSchema);
        $this->recordService->attachRequestContext(
            request: $request,
            table: (string) ($request->route('table') ?? ''),
            action: $this->resolveRouteAction($request),
            tableSchema: $tableSchema,
            tenantId: $tenantId
        );
        $error = $this->validateTenantIdRequired($tableSchema, $tenantId)
            ?? ($checkIncludes ? $this->refuseTenantScopedIncludes($request, $table) : null);

        return [$tenantId, $error];
    }

    /**
     * Without a tenant, rows of a tenant-scoped relationship would be embedded
     * for every tenant (see TenantScopedIncludes). The controller's own tenant
     * is null for a table that is not tenant-scoped, so the request's tenant —
     * attribute, record context, then header — decides.
     */
    private function refuseTenantScopedIncludes(Request $request, ?string $table): ?JsonResponse
    {
        // The caller's table; the route parameter only for a caller that did not pass one.
        $table ??= (string) ($request->route('table') ?? '');
        if ('' === $table || !RecordConfigService::enableTenantId() || !RecordUtils::isTenantIdMissing(RecordUtils::resolveTenantIdFromRequest($request))) {
            return null;
        }

        $aliases = TenantScopedIncludes::requestedBy($request, $table);

        return [] === $aliases ? null : TenantScopedIncludes::refusal($aliases);
    }

    private function resolveRouteAction(Request $request): string
    {
        $route = $request->route();
        if (!is_object($route) || !method_exists($route, 'getActionMethod')) {
            return '';
        }

        $action = $route->getActionMethod();

        return is_string($action) ? $action : '';
    }

    private function validateTenantIdRequired(object $tableSchema, mixed $tenantId): ?JsonResponse
    {
        if (!RecordUtils::shouldApplyTenantId($tableSchema)) {
            return null;
        }

        if (RecordUtils::isTenantIdMissing($tenantId)) {
            return RecordApiResponseService::errorWrapped('Validation failed', RecordApiJsonResponseEnum::VALIDATION_ERROR->value, [
                RecordConfigService::tenantHeader() => ['header ' . RecordConfigService::tenantHeader() . ' cannot be empty'],
            ]);
        }

        return null;
    }

    private function resolveActualTableName(string $table): string
    {
        $schema = SchemaRegistryUtils::get();
        $config = $schema[$table] ?? null;

        if ($config instanceof RecordTableType) {
            return $config->table ?? $table;
        }

        return $table;
    }

    private function resourceNotAvailableResponse(): JsonResponse
    {
        return RecordApiResponseService::errorWrapped('Resource not available', RecordApiJsonResponseEnum::NOT_FOUND->value);
    }

    private function isReadEndpointEnabled(object $tableSchema): bool
    {
        return (bool) ($tableSchema->canRead ?? true);
    }

    private function isCreateEndpointEnabled(object $tableSchema): bool
    {
        return (bool) ($tableSchema->canCreate ?? true);
    }

    private function isUpdateEndpointEnabled(object $tableSchema): bool
    {
        return (bool) ($tableSchema->canUpdate ?? true);
    }

    private function isDeleteEndpointEnabled(object $tableSchema): bool
    {
        return (bool) ($tableSchema->canDelete ?? true);
    }

    private function isUpsertEndpointEnabled(object $tableSchema): bool
    {
        return (bool) ($tableSchema->canUpsert ?? false);
    }

    private function fetchRecordData(Request $request, string $table, mixed $id, mixed $tenantId): mixed
    {
        try {
            $result = $this->recordService->getRecord($request, $table, $id, $tenantId);

            return $result['data'] ?? null;
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Authorize the action for the given table.
     */
    private function authorizeAction(string $table, string $action): void
    {
        $decision = PermissionUtils::actionDecision(auth(RecordConfigService::authGuard())->user(), $table, $action);

        if (PermissionUtils::DECISION_UNAUTHENTICATED === $decision) {
            throw new HttpResponseException(
                RecordApiResponseService::errorWrapped('Unauthenticated', RecordApiJsonResponseEnum::UNAUTHORIZED->value)
            );
        }

        if (PermissionUtils::DECISION_FORBIDDEN === $decision) {
            throw new HttpResponseException(
                RecordApiResponseService::errorWrapped('Forbidden', RecordApiJsonResponseEnum::FORBIDDEN->value)
            );
        }
    }

    /**
     * Dispatch a bulk operation as an async queue job.
     */
    private function dispatchAsyncBulk(Request $request, string $table, string $operation, array $items, mixed $tenantId): JsonResponse
    {
        $guard = RecordConfigService::authGuard();
        $user = auth($guard)->user();

        $context = [
            'headers' => $request->headers->all(),
            'server'  => $request->server->all(),
            'user_id' => $user?->id,
            'guard'   => $guard,
        ];

        ProcessBulkOperationJob::dispatch($operation, $table, $items, $tenantId, $context);

        return RecordApiResponseService::successWrapped(
            ['status' => 'queued', 'message' => 'Bulk operation queued for processing'],
            [],
            202
        );
    }

    // -------------------------------------------------------------------------
    // Validator helpers (used by CRUD methods)
    // -------------------------------------------------------------------------

    private function runTableValidators(mixed $validatorConfig, Request $request, ?string $id): ?JsonResponse
    {
        $validator = TableValidatorRunner::firstFailure($validatorConfig, $request, $id);

        return $validator instanceof ValidatorContract
            ? RecordApiResponseService::errorWrapped('Validation failed', RecordApiJsonResponseEnum::VALIDATION_ERROR->value, $validator->errors()->toArray())
            : null;
    }
}
