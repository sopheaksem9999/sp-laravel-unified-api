<?php

namespace Sopheak\Core\Traits;

use Sopheak\Core\Services\AuditLogService;
use Sopheak\Core\Enums\AuditLogEventEnum;
use Illuminate\Http\JsonResponse;

/**
 * Trait for controllers that implement audit query functionality.
 * 
 * This trait provides common methods for handling audit queries and logging
 * with custom data formatting and relationship handling.
 */
trait HasAuditQuery
{
    /**
     * Log an audit event using the custom audit query.
     * 
     * @param int $id The ID of the record
     * @param AuditLogEventEnum $event The audit event type
     * @param string|null $subject Optional subject for the audit log
     * @param string|null $recap Optional recap for the audit log
     * @return void
     */
    protected function logAuditWithCustomQuery(
        int $id, 
        AuditLogEventEnum $event, 
        ?string $subject = null, 
        ?string $recap = null
    ): void {
        if (!method_exists($this, 'getAuditQuery')) {
            throw new \BadMethodCallException('Controller must implement getAuditQuery method');
        }

        if (!method_exists($this, 'getAuditEntityName')) {
            throw new \BadMethodCallException('Controller must implement getAuditEntityName method');
        }

        if (!method_exists($this, 'getAuditEntityClass')) {
            throw new \BadMethodCallException('Controller must implement getAuditEntityClass method');
        }

        $queryData = $this->getAuditQuery($id);
        $entityName = $this->getAuditEntityName();
        $entityClass = $this->getAuditEntityClass();

        AuditLogService::handleAuditDataEntry(
            event: $event,
            entityName: $entityName,
            entityType: $entityClass,
            queryData: $queryData,
            subject: $subject,
            recap: $recap
        );
    }

    /**
     * Get audit logs for a specific record.
     * 
     * @param int $id The ID of the record
     * @param int $limit Maximum number of logs to retrieve
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function getAuditLogsForRecord(int $id, int $limit = 50)
    {
        if (!method_exists($this, 'getAuditEntityClass')) {
            throw new \BadMethodCallException('Controller must implement getAuditEntityClass method');
        }

        $entityClass = $this->getAuditEntityClass();
        
        return AuditLogService::getEntityAuditLogs($entityClass, $id, $limit);
    }

    /**
     * API endpoint to get audit logs for a record.
     * 
     * @param int $id The ID of the record
     * @return JsonResponse
     */
    public function auditLogs(int $id): JsonResponse
    {
        try {
            $logs = $this->getAuditLogsForRecord($id);
            
            return response()->json([
                'success' => true,
                'data' => $logs,
                'message' => 'Audit logs retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve audit logs: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * API endpoint to get audit statistics for a record.
     * 
     * @param int $id The ID of the record
     * @return JsonResponse
     */
    public function auditStats(int $id): JsonResponse
    {
        try {
            if (!method_exists($this, 'getAuditEntityClass')) {
                throw new \BadMethodCallException('Controller must implement getAuditEntityClass method');
            }

            $entityClass = $this->getAuditEntityClass();
            
            $stats = AuditLogService::getAuditStats([
                'entity_type' => $entityClass,
                'entity_id' => $id
            ]);
            
            return response()->json([
                'success' => true,
                'data' => $stats,
                'message' => 'Audit statistics retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve audit statistics: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * API endpoint to get field timeline for a specific field.
     * 
     * @param int $id The ID of the record
     * @param string $field The field name to get timeline for
     * @param int $limit Maximum number of timeline entries
     * @return JsonResponse
     */
    public function fieldTimeline(int $id, string $field, int $limit = 10): JsonResponse
    {
        try {
            if (!method_exists($this, 'getAuditEntityClass')) {
                throw new \BadMethodCallException('Controller must implement getAuditEntityClass method');
            }

            $entityClass = $this->getAuditEntityClass();
            
            $timeline = AuditLogService::getFieldTimeline($entityClass, $id, $field, $limit);
            
            return response()->json([
                'success' => true,
                'data' => $timeline,
                'message' => 'Field timeline retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve field timeline: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * API endpoint to get field statistics for a specific field.
     * 
     * @param int $id The ID of the record
     * @param string $field The field name to get statistics for
     * @return JsonResponse
     */
    public function fieldStats(int $id, string $field): JsonResponse
    {
        try {
            if (!method_exists($this, 'getAuditEntityClass')) {
                throw new \BadMethodCallException('Controller must implement getAuditEntityClass method');
            }

            $entityClass = $this->getAuditEntityClass();
            
            $stats = AuditLogService::getFieldStats($entityClass, $id, $field);
            
            return response()->json([
                'success' => true,
                'data' => $stats,
                'message' => 'Field statistics retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve field statistics: ' . $e->getMessage()
            ], 500);
        }
    }
}