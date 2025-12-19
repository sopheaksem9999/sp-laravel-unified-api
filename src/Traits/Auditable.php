<?php

namespace Sopheak\Core\Traits;

use Sopheak\Core\Enums\AuditLogEventEnum;
use Illuminate\Database\Eloquent\Model;
use Sopheak\Core\Jobs\AuditLogJob;
use Sopheak\Core\Services\AuditLogService;

/**
 * Trait Auditable.
 *
 * Provides automatic audit logging functionality for Eloquent models.
 * This trait automatically logs create, update, and delete operations.
 */
trait Auditable
{
    /**
     * Default implementation of getAuditQuery method.
     *
     * This method provides a basic audit query that returns the current model instance.
     * Models can override this method to include specific relationships or custom data
     * needed for audit logging.
     *
     * @return null|Model The model instance for audit logging
     */
    protected function getAuditQuery(?int $id = null): array
    {
        return $this->toArray();
    }

    /**
     * Boot the auditable trait for a model.
     *
     * Registers model event listeners for created, updated, and deleted events.
     */
    protected static function bootAuditable(): void
    {
        static::created(function ($model): void {
            $model->auditLog(AuditLogEventEnum::CREATED);
        });

        static::updated(function ($model): void {
            $model->auditLog(AuditLogEventEnum::UPDATED);
        });

        static::deleted(function ($model): void {
            $model->auditLog(AuditLogEventEnum::DELETED);
        });
    }

    /**
     * Create an audit log entry for the model.
     *
     * @param AuditLogEventEnum $auditLogEventEnum The event performed (created, updated, deleted)
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

        // Prepare common audit data
        $entityName = AuditLogService::getTableNameFromEntityType($this::class);
        $entityType = $this::class;
        $queryData = $this->handleMapQueryData($auditLogEventEnum);

        // Handle audit logging based on queue configuration
        if (AuditLogService::isAuditQueueEnabled()) {
            AuditLogJob::dispatch(
                event: $auditLogEventEnum,
                entityName: $entityName,
                entityType: $entityType,
                queryData: $queryData
            );

            return;
        }

        // Process audit log immediately if queue is disabled
        AuditLogService::handleAuditDataEntry(
            event: $auditLogEventEnum,
            entityName: $entityName,
            entityType: $entityType,
            queryData: $queryData
        );
    }

    /**
     * Manually trigger audit logging for the model.
     * This method allows controllers to manually trigger audit logging
     * after completing related operations (e.g., after inserting items).
     *
     * @param AuditLogEventEnum $auditLogEventEnum The event performed (created, updated, deleted)
     */
    public function triggerAuditLog(AuditLogEventEnum $auditLogEventEnum): void
    {
        $this->auditLog($auditLogEventEnum);
    }

    /**
     * Temporarily disable automatic audit logging for this model instance.
     * This is useful when you want to manually control when audit logging occurs.
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
     * @param string $event The event being performed
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
