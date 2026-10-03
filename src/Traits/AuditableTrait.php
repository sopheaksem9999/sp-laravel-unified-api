<?php

declare(strict_types=1);

namespace Sopheak\Core\Traits;

use Closure;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Enums\AuditLogEventEnum;
use Illuminate\Database\Eloquent\Model;
use Sopheak\Core\Jobs\AuditLogJob;
use Sopheak\Core\Services\AuditLogService;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * Trait AuditableTrait.
 *
 * Adds automatic audit logging for Eloquent model lifecycle events.
 * Records create, update, and delete operations with configurable payloads.
 *
 * @mixin Model
 *
 * @method static void created((Closure|string) $callback)
 * @method static void updating((Closure|string) $callback)
 * @method static void updated((Closure|string) $callback)
 * @method static void deleting((Closure|string) $callback)
 * @method static void deleted((Closure|string) $callback)
 */
trait AuditableTrait
{
    protected ?array $auditOldData = null;

    /**
     * Default audit payload builder.
     *
     * Models may override this method to include relationships or custom
     * data needed for audit logging.
     *
     * @return array The serialized model payload for audit logging.
     */
    protected function getAuditQuery(?int $id = null): array
    {
        return $this->toArray();
    }

    /**
     * Boot the audit listeners for the model.
     *
     * Registers handlers for created, updating/updated, and deleting/deleted events.
     */
    protected static function bootAuditableTrait(): void
    {
        static::created(function ($model): void {
            $model->auditLog(AuditLogEventEnum::CREATED);
        });

        static::updating(function ($model): void {
            $model->auditOldData = $model->buildAuditSnapshot(AuditLogEventEnum::UPDATED, true);
        });

        static::updated(function ($model): void {
            $model->auditLog(AuditLogEventEnum::UPDATED);
        });

        static::deleting(function ($model): void {
            $model->auditOldData = $model->buildAuditSnapshot(AuditLogEventEnum::DELETED, true);
        });

        static::deleted(function ($model): void {
            $model->auditLog(AuditLogEventEnum::DELETED);
        });
    }

    /**
     * Create an audit log entry for the model.
     *
     * @param AuditLogEventEnum $auditLogEventEnum The event performed.
     */
    protected function auditLog(AuditLogEventEnum $auditLogEventEnum): void
    {
        // Early return if audit logging is disabled globally
        if (!AuditLogService::isAuditEnabled()) {
            return;
        }

        // Skip audit logging if disabled for this specific model
        if (!$this->shouldAudit($auditLogEventEnum->value)) {
            return;
        }

        $entityName = AuditLogService::getTableNameFromEntityType($this::class);
        $entityType = $this::class;
        $queryData = $this->buildAuditPayload($auditLogEventEnum);
        $tenantColumn = RecordConfigService::tenantColumn();
        $tenantId = $queryData[$tenantColumn] ?? $this->getAttribute($tenantColumn) ?? null;

        // Handle audit logging based on queue configuration
        if (AuditLogService::isAuditQueueEnabled()) {
            AuditLogService::rememberRequestContext();
            AuditLogJob::dispatch(
                event: $auditLogEventEnum,
                entityName: $entityName,
                entityType: $entityType,
                queryData: $queryData,
                tenantId: $tenantId
            );

            return;
        }

        // Process audit log immediately if queue is disabled
        AuditLogService::handleAuditDataEntry(
            event: $auditLogEventEnum,
            entityName: $entityName,
            entityType: $entityType,
            queryData: $queryData,
            tenantId: $tenantId
        );
    }

    protected function buildAuditPayload(AuditLogEventEnum $event): array
    {
        $id = method_exists($this, 'getKey') ? $this->getKey() : null;
        $tenantColumn = RecordConfigService::tenantColumn();
        $tenantId = method_exists($this, 'getAttribute') ? $this->getAttribute($tenantColumn) : null;

        if ($event === AuditLogEventEnum::CREATED) {
            $newData = $this->buildAuditSnapshot($event, true);

            return array_filter([
                'id' => $id,
                $tenantColumn => $tenantId,
                'new_data' => $newData,
            ], static fn($value): bool => null !== $value);
        }

        if ($event === AuditLogEventEnum::UPDATED) {
            $oldData = $this->auditOldData ?? $this->buildAuditSnapshot($event, true);
            $newData = $this->buildAuditSnapshot($event, true);

            return array_filter([
                'id' => $id,
                $tenantColumn => $tenantId,
                'old_data' => $oldData,
                'new_data' => $newData,
            ], static fn($value): bool => null !== $value);
        }

        if ($event === AuditLogEventEnum::DELETED) {
            $oldData = $this->auditOldData ?? $this->buildAuditSnapshot($event, false);

            return array_filter([
                'id' => $id,
                $tenantColumn => $tenantId,
                'old_data' => $oldData,
            ], static fn($value): bool => null !== $value);
        }

        return array_filter([
            'id' => $id,
            $tenantColumn => $tenantId,
        ], static fn($value): bool => null !== $value);
    }

    protected function buildAuditSnapshot(AuditLogEventEnum $event, bool $fromDatabase): array
    {
        $with = $this->resolveAuditRelations();
        $data = $fromDatabase ? $this->fetchAuditModelFromDatabase($with)?->toArray() : $this->toArray();
        $data = is_array($data) ? $data : [];

        if (!isset($data['id']) && method_exists($this, 'getKey') && null !== $this->getKey()) {
            $data['id'] = $this->getKey();
        }

        $data = $this->sanitizeAuditData($data);

        $businessMetrics = method_exists($this, 'getAuditBusinessMetrics')
            ? $this->getAuditBusinessMetrics($data)
            : [];

        $auditContext = method_exists($this, 'getAuditContext')
            ? $this->getAuditContext($event->value, $data)
            : [];

        if (!empty($businessMetrics)) {
            $data['business_metrics'] = $businessMetrics;
        }

        if (!empty($auditContext)) {
            $data['audit_context'] = $auditContext;
        }

        return $data;
    }

    protected function fetchAuditModelFromDatabase(array $with): ?Model
    {
        if (!method_exists($this, 'getKey')) {
            return null;
        }

        $key = $this->getKey();
        if (null === $key) {
            return null;
        }

        if (!method_exists($this, 'newQueryWithoutScopes')) {
            return null;
        }

        $query = $this->newQueryWithoutScopes();
        if (!empty($with) && method_exists($query, 'with')) {
            $query->with($with);
        }

        return $query->find($key);
    }

    protected function resolveAuditRelations(): array
    {
        if (!RecordConfigService::auditLogRelationships()) {
            return [];
        }

        $with = [];

        if (method_exists($this, 'getAuditWith')) {
            $with = $this->getAuditWith();
        } elseif (property_exists($this, 'auditWith')) {
            $with = $this->auditWith;
        } else {
            $with = $this->resolveAuditRelationsFromRecordConfig();
        }

        if (!is_array($with)) {
            $with = [];
        }

        $with = array_values(array_filter(array_unique($with), static fn($value): bool => is_string($value) && '' !== $value));
        $max = RecordConfigService::auditPerformanceMaxRelationships();

        return array_slice($with, 0, $max);
    }

    protected function resolveAuditRelationsFromRecordConfig(): array
    {
        if (!method_exists($this, 'getTable')) {
            return [];
        }

        $tableName = $this->getTable();
        if (null === $tableName || '' === $tableName) {
            return [];
        }

        $schema = SchemaRegistryUtils::get();
        foreach ($schema as $resourceName => $tableConfig) {
            if (!($tableConfig instanceof RecordTableType)) {
                continue;
            }

            $configTable = $tableConfig->table ?? $resourceName;
            if ($configTable === $tableName) {
                $relationships = $tableConfig->relationships ?? [];
                if (is_array($relationships)) {
                    return array_keys($relationships);
                }

                return [];
            }
        }

        return [];
    }

    protected function sanitizeAuditData(array $data): array
    {
        $excluded = RecordConfigService::auditExcludedAttributes();
        if (!is_array($excluded)) {
            $excluded = [];
        }

        $schema = method_exists($this, 'getTable') ? SchemaRegistryUtils::getTable($this->getTable()) : null;
        $hidden = is_array($schema?->columnHiddens ?? null) ? $schema->columnHiddens : [];

        $excluded = array_values(array_unique(array_merge($excluded, $hidden, ['created_at', 'updated_at', 'deleted_at'])));

        foreach ($excluded as $key) {
            if (is_string($key) && array_key_exists($key, $data)) {
                unset($data[$key]);
            }
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sanitizeAuditData($value);
            }
        }

        return $data;
    }

    /**
     * Manually trigger audit logging for the model.
     *
     * Use this after completing related operations that should be captured in the audit trail.
     *
     * @param AuditLogEventEnum $auditLogEventEnum The event performed.
     */
    public function triggerAuditLog(AuditLogEventEnum $auditLogEventEnum): void
    {
        $this->auditLog($auditLogEventEnum);
    }

    /**
     * Temporarily disable automatic audit logging for this model instance.
     */
    public function disableAuditLogging(): void
    {
        $this->auditEnabled = false;
    }

    /**
     * Re-enable automatic audit logging for this model instance.
     */
    public function enableAuditLogging(): void
    {
        $this->auditEnabled = true;
    }

    protected function handleMapQueryData(AuditLogEventEnum $auditLogEventEnum): ?array
    {
        $queryData = $this->getAuditQuery();

        // Get business metrics if method exists
        $businessMetrics = method_exists($this, 'getAuditBusinessMetrics')
            ? $this->getAuditBusinessMetrics($queryData)
            : [];

        // Get audit context if method exists
        $auditContext = method_exists($this, 'getAuditContext')
            ? $this->getAuditContext($auditLogEventEnum->value, $queryData)
            : [];

        // Add business metrics and context
        if (!empty($businessMetrics)) {
            $queryData['business_metrics'] = $businessMetrics;
        }

        if (!empty($auditContext)) {
            $queryData['audit_context'] = $auditContext;
        }

        return $queryData;
    }

    /**
     * Determine if the model should be audited for the given event.
     *
     * @param string $event The event being performed.
     */
    protected function shouldAudit(string $event): bool
    {
        // Check if audit is globally disabled
        if (property_exists($this, 'auditEnabled') && !$this->auditEnabled) {
            return false;
        }

        // Check if specific events are excluded
        if (property_exists($this, 'auditExclude') && in_array($event, $this->auditExclude)) {
            return false;
        }

        // Check if only specific events are included
        return !(property_exists($this, 'auditInclude') && !in_array($event, $this->auditInclude));
    }
}
