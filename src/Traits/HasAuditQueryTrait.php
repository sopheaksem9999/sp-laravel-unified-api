<?php

namespace Sopheak\Core\Traits;

use BadMethodCallException;
use Illuminate\Support\Collection;
use Exception;
use Sopheak\Core\Services\AuditLogService;
use Sopheak\Core\Enums\AuditLogEventEnum;
use Illuminate\Http\JsonResponse;
use Sopheak\Core\Enums\RecordApiJsonResponseEnum;
use Sopheak\Core\Constants\HttpErrorCodeConstant;
use Sopheak\Core\Services\RecordConfigService;

/**
 * Trait for controllers that implement audit query functionality.
 * 
 * This trait provides common methods for handling audit queries and logging
 * with custom data formatting and relationship handling.
 */
trait HasAuditQueryTrait
{
    protected function resolveAuditEntityClass(): string
    {
        if (method_exists($this, 'getAuditEntityClass')) {
            return $this->getAuditEntityClass();
        }

        if (property_exists($this, 'modelClass') && is_string($this->modelClass)) {
            return $this->modelClass;
        }

        // Try to guess from controller name
        $className = class_basename($this);
        $modelName = str_replace('Controller', '', $className);
        if (class_exists('App\Models\\' . $modelName)) {
            return 'App\Models\\' . $modelName;
        }

        throw new BadMethodCallException('Controller must implement getAuditEntityClass method or define $modelClass property');
    }

    protected function resolveAuditEntityName(): string
    {
        if (method_exists($this, 'getAuditEntityName')) {
            return $this->getAuditEntityName();
        }

        try {
            $entityClass = $this->resolveAuditEntityClass();
            return AuditLogService::getTableNameFromEntityType($entityClass);
        } catch (Exception) {
            // Fallback to guessing from controller name
            $className = class_basename($this);
            return strtolower(str_replace('Controller', '', $className));
        }
    }

    protected function resolveTenantIdFromRequest(): ?string
    {
        if (!RecordConfigService::enableTenantId()) {
            return null;
        }

        $tenantHeader = RecordConfigService::tenantHeader();
        $tenantColumn = RecordConfigService::tenantColumn();
        $request = request();

        return $request->header($tenantHeader)
            ?? $request->input($tenantColumn)
            ?? $request->input('tenant_id');
    }

    /**
     * Log an audit event using the custom audit query.
     *
     * @param int $id The ID of the record
     * @param AuditLogEventEnum $auditLogEventEnum The audit event type
     * @param string|null $subject Optional subject for the audit log
     * @param string|null $recap Optional recap for the audit log
     */
    protected function logAuditWithCustomQuery(
        int $id,
        AuditLogEventEnum $auditLogEventEnum,
        ?string $subject = null,
        ?string $recap = null
    ): void {
        if (!method_exists($this, 'getAuditQuery')) {
            throw new BadMethodCallException('Controller must implement getAuditQuery method');
        }

        $queryData = $this->getAuditQuery($id);
        $entityName = $this->resolveAuditEntityName();
        $entityClass = $this->resolveAuditEntityClass();
        $entityType = AuditLogService::getTableNameFromEntityType($entityClass);
        $tenantColumn = RecordConfigService::tenantColumn();
        $tenantId = is_array($queryData) ? ($queryData[$tenantColumn] ?? null) : null;
        $tenantId ??= $this->resolveTenantIdFromRequest();

        AuditLogService::handleAuditDataEntry(
            event: $auditLogEventEnum,
            entityName: $entityName,
            entityType: $entityType,
            queryData: $queryData,
            subject: $subject,
            recap: $recap,
            tenantId: $tenantId
        );
    }

    /**
     * Get audit logs for a specific record.
     *
     * @param int $id The ID of the record
     * @param int $limit Maximum number of logs to retrieve
     */
    protected function getAuditLogsForRecord(int $id, int $limit = 50): Collection
    {
        $entityClass = $this->resolveAuditEntityClass();
        $entityType = AuditLogService::getTableNameFromEntityType($entityClass);
        $tenantId = $this->resolveTenantIdFromRequest();

        return AuditLogService::getEntityAuditLogs(entityType: $entityType, entityId: $id, tenantId: $tenantId, limit: $limit);
    }

    /**
     * API endpoint to get audit logs for a record.
     *
     * @param int $id The ID of the record
     */
    public function auditLogs(int $id): JsonResponse
    {
        try {
            $logs = $this->getAuditLogsForRecord($id);

            return response()->json([
                'success' => true,
                'error_code' => HttpErrorCodeConstant::SUCCESS,
                'data' => $logs,
                'message' => 'Audit logs retrieved successfully'
            ]);
        } catch (Exception $exception) {
            return response()->json([
                'success' => false,
                'error_code' => HttpErrorCodeConstant::INTERNAL_SERVER_ERROR,
                'message' => 'Failed to retrieve audit logs: ' . $exception->getMessage()
            ], RecordApiJsonResponseEnum::SERVER_ERROR->value);
        }
    }

    /**
     * API endpoint to get audit statistics for a record.
     *
     * @param int $id The ID of the record
     */
    public function auditStats(int $id): JsonResponse
    {
        try {
            $entityClass = $this->resolveAuditEntityClass();
            $entityType = AuditLogService::getTableNameFromEntityType($entityClass);

            $stats = AuditLogService::getAuditStats([
                'entity_type' => $entityType,
                'entity_id' => $id
            ]);

            return response()->json([
                'success' => true,
                'error_code' => HttpErrorCodeConstant::SUCCESS,
                'data' => $stats,
                'message' => 'Audit statistics retrieved successfully'
            ]);
        } catch (Exception $exception) {
            return response()->json([
                'success' => false,
                'error_code' => HttpErrorCodeConstant::INTERNAL_SERVER_ERROR,
                'message' => 'Failed to retrieve audit statistics: ' . $exception->getMessage()
            ], RecordApiJsonResponseEnum::SERVER_ERROR->value);
        }
    }

    /**
     * API endpoint to get field statistics for a specific field.
     *
     * @param int $id The ID of the record
     * @param string $field The field name to get statistics for
     */
    public function fieldStats(int $id, string $field): JsonResponse
    {
        try {
            $entityClass = $this->resolveAuditEntityClass();
            $entityType = AuditLogService::getTableNameFromEntityType($entityClass);

            $stats = AuditLogService::getFieldStats($entityType, $id, $field);

            return response()->json([
                'success' => true,
                'error_code' => HttpErrorCodeConstant::SUCCESS,
                'data' => $stats,
                'message' => 'Field statistics retrieved successfully'
            ]);
        } catch (Exception $exception) {
            return response()->json([
                'success' => false,
                'error_code' => HttpErrorCodeConstant::INTERNAL_SERVER_ERROR,
                'message' => 'Failed to retrieve field statistics: ' . $exception->getMessage()
            ], RecordApiJsonResponseEnum::SERVER_ERROR->value);
        }
    }
}
