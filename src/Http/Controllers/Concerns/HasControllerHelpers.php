<?php

namespace Sopheak\Core\Http\Controllers\Concerns;

use Throwable;
use Exception;
use RuntimeException;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Sopheak\Core\Enums\RecordApiJsonResponseEnum;
use Sopheak\Core\Exceptions\RecordNotFoundException;
use Sopheak\Core\Jobs\ProcessBulkOperationJob;
use Sopheak\Core\Services\RecordApiResponseService;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Types\RecordValidationType;
use Sopheak\Core\Utilities\PermissionUtils;
use Sopheak\Core\Utilities\RecordUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

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
            $result = $fn();
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
     */
    private function resolveTenantContext(Request $request, object $tableSchema): array
    {
        $tenantId = $this->recordService->normalizeTenantId(
            $request->header(RecordConfigService::tenantHeader())
        );
        $error = $this->validateTenantIdRequired($tableSchema, $tenantId);
        return [$tenantId, $error];
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
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Authorize the action for the given table.
     */
    private function authorizeAction(string $table, string $action): void
    {
        if (PermissionUtils::isPublicAction($table, $action)) {
            return;
        }

        $guard = RecordConfigService::authGuard();
        $user = auth($guard)->user();
        if (!$user) {
            throw new HttpResponseException(
                RecordApiResponseService::errorWrapped('Unauthenticated', RecordApiJsonResponseEnum::UNAUTHORIZED->value)
            );
        }

        $tableSchema = SchemaRegistryUtils::getTable($table);
        if ($tableSchema instanceof RecordTableType) {
            if (is_null($tableSchema->pmsName)) {
                return;
            }

            if (is_array($tableSchema->pmsName) && [] === $tableSchema->pmsName) {
                return;
            }
        }

        $perms = PermissionUtils::mapPermissions($table, $action);
        $allowed = false;
        foreach ($perms as $perm) {
            if (Gate::forUser($user)->allows($perm)) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed) {
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
        $validators = $this->resolveValidatorConfigs($validatorConfig);

        foreach ($validators as $index => $validatorItem) {
            if (is_callable($validatorItem)) {
                $validator = $this->invokeValidatorCallable($validatorItem, $request, $id);
                if ($validator->fails()) {
                    return RecordApiResponseService::errorWrapped('Validation failed', RecordApiJsonResponseEnum::VALIDATION_ERROR->value, $validator->errors()->toArray());
                }

                continue;
            }

            $config = $this->resolveValidationType($validatorItem, $index);
            $validator = $this->invokeValidationType($config, $request, $id);
            if ($validator->fails()) {
                return RecordApiResponseService::errorWrapped('Validation failed', RecordApiJsonResponseEnum::VALIDATION_ERROR->value, $validator->errors()->toArray());
            }
        }

        return null;
    }

    private function resolveValidatorConfigs(mixed $validatorConfig): array
    {
        if (null === $validatorConfig) {
            return [];
        }

        if ($validatorConfig instanceof RecordValidationType || is_callable($validatorConfig)) {
            return [$validatorConfig];
        }

        if (is_array($validatorConfig)) {
            if (is_callable($validatorConfig)) {
                return [$validatorConfig];
            }

            if ($this->isValidatorConfigArray($validatorConfig)) {
                return [$validatorConfig];
            }

            return $this->flattenValidatorConfigs($validatorConfig);
        }

        throw new RuntimeException(sprintf(
            'Invalid validator configuration. Expected callable, %s, or array, got %s',
            RecordValidationType::class,
            get_debug_type($validatorConfig)
        ));
    }

    private function flattenValidatorConfigs(array $items): array
    {
        $resolved = [];

        foreach ($items as $item) {
            if (null === $item) {
                continue;
            }

            if ($item instanceof RecordValidationType || is_callable($item)) {
                $resolved[] = $item;
                continue;
            }

            if (is_array($item)) {
                if (is_callable($item) || $this->isValidatorConfigArray($item)) {
                    $resolved[] = $item;
                    continue;
                }

                $resolved = array_merge($resolved, $this->flattenValidatorConfigs($item));
                continue;
            }

            throw new RuntimeException(sprintf(
                'Invalid validator configuration. Expected callable, %s, or array, got %s',
                RecordValidationType::class,
                get_debug_type($item)
            ));
        }

        return $resolved;
    }

    private function isValidatorConfigArray(array $config): bool
    {
        return isset($config['class']) || isset($config['functionName']);
    }

    private function invokeValidatorCallable(callable $callback, Request $request, ?string $id): ValidatorContract
    {
        $validator = $callback($request, $id);
        if (!$validator instanceof ValidatorContract) {
            throw new RuntimeException('Validator callback must return a Validator instance');
        }

        return $validator;
    }

    private function resolveValidationType(mixed $item, int|string $index): RecordValidationType
    {
        if ($item instanceof RecordValidationType) {
            return $item;
        }

        if (is_array($item) && $this->isValidatorConfigArray($item)) {
            return RecordValidationType::fromArray($item);
        }

        throw new RuntimeException(sprintf(
            'Invalid validator item at index %s. Expected callable, %s, or array, got %s',
            (string) $index,
            RecordValidationType::class,
            get_debug_type($item)
        ));
    }

    private function invokeValidationType(RecordValidationType $config, Request $request, ?string $id): ValidatorContract
    {
        $className = $config->class;
        $method = $config->functionName;

        if (!class_exists($className)) {
            throw new RuntimeException(sprintf("Validator class '%s' does not exist", $className));
        }

        if (!method_exists($className, $method)) {
            throw new RuntimeException(sprintf("Validator method '%s::%s' does not exist", $className, $method));
        }

        $validator = call_user_func([$className, $method], $request, $id);
        if (!$validator instanceof ValidatorContract) {
            throw new RuntimeException('Validator callback must return a Validator instance');
        }

        return $validator;
    }
}
