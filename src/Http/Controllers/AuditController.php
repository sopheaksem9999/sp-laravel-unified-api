<?php

namespace Sopheak\Core\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Sopheak\Core\Services\AuditLogService;
use Sopheak\Core\Services\ApiResponseService;
use Sopheak\Core\Enums\AuditLogEventEnum;
use Illuminate\Support\Facades\Validator;

/**
 * Controller for handling audit log operations.
 * 
 * This controller provides endpoints for retrieving audit logs, statistics,
 * and managing audit data across the application.
 */
class AuditController extends Controller
{
    protected ApiResponseService $apiResponse;

    public function __construct(ApiResponseService $apiResponse)
    {
        $this->apiResponse = $apiResponse;
    }

    /**
     * Get audit logs for a specific entity.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getLogs(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'entity_type' => 'required|string',
            'entity_id' => 'required|integer',
            'limit' => 'sometimes|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return $this->apiResponse->validationError($validator->errors()->toArray());
        }

        try {
            $entityType = $request->input('entity_type');
            $entityId = $request->input('entity_id');
            $limit = $request->input('limit', 50);

            $logs = AuditLogService::getEntityAuditLogs($entityType, $entityId, $limit);

            return $this->apiResponse->success($logs->toArray(), ['message' => 'Audit logs retrieved successfully']);
        } catch (\Exception $e) {
            return $this->apiResponse->serverError('Failed to retrieve audit logs: ' . $e->getMessage());
        }
    }

    /**
     * Get audit statistics.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getStats(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'entity_type' => 'sometimes|string',
            'entity_id' => 'sometimes|integer',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
            'event' => 'sometimes|string|in:created,updated,deleted,login,logout,failed_login',
        ]);

        if ($validator->fails()) {
            return $this->apiResponse->validationError($validator->errors()->toArray());
        }

        try {
            $filters = $request->only(['entity_type', 'entity_id', 'start_date', 'end_date', 'event']);
            $stats = AuditLogService::getAuditStats($filters);

            return $this->apiResponse->success($stats, ['message' => 'Audit statistics retrieved successfully']);
        } catch (\Exception $e) {
            return $this->apiResponse->serverError('Failed to retrieve audit statistics: ' . $e->getMessage());
        }
    }

    /**
     * Get field timeline for a specific field.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getFieldTimeline(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'entity_type' => 'required|string',
            'entity_id' => 'required|integer',
            'field' => 'required|string',
            'limit' => 'sometimes|integer|min:1|max:50',
        ]);

        if ($validator->fails()) {
            return $this->apiResponse->validationError($validator->errors()->toArray());
        }

        try {
            $entityType = $request->input('entity_type');
            $entityId = $request->input('entity_id');
            $field = $request->input('field');
            $limit = $request->input('limit', 10);

            $timeline = AuditLogService::getFieldTimeline($entityType, $entityId, $field, $limit);

            return $this->apiResponse->success($timeline, ['message' => 'Field timeline retrieved successfully']);
        } catch (\Exception $e) {
            return $this->apiResponse->serverError('Failed to retrieve field timeline: ' . $e->getMessage());
        }
    }

    /**
     * Get field statistics for a specific field.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getFieldStats(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'entity_type' => 'required|string',
            'entity_id' => 'required|integer',
            'field' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->apiResponse->validationError($validator->errors()->toArray());
        }

        try {
            $entityType = $request->input('entity_type');
            $entityId = $request->input('entity_id');
            $field = $request->input('field');

            $stats = AuditLogService::getFieldStats($entityType, $entityId, $field);

            return $this->apiResponse->success($stats, ['message' => 'Field statistics retrieved successfully']);
        } catch (\Exception $e) {
            return $this->apiResponse->serverError('Failed to retrieve field statistics: ' . $e->getMessage());
        }
    }

    /**
     * Manually create an audit log entry.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function createLog(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'event' => 'required|string|in:created,updated,deleted,login,logout,failed_login',
            'entity_type' => 'required|string',
            'entity_id' => 'required|integer',
            'entity_name' => 'required|string',
            'subject' => 'sometimes|string|max:255',
            'recap' => 'sometimes|string|max:1000',
            'metadata' => 'sometimes|array',
        ]);

        if ($validator->fails()) {
            return $this->apiResponse->validationError($validator->errors()->toArray());
        }

        try {
            $event = AuditLogEventEnum::from($request->input('event'));
            $entityType = $request->input('entity_type');
            $entityName = $request->input('entity_name');
            $subject = $request->input('subject');
            $recap = $request->input('recap');
            $metadata = $request->input('metadata', []);

            // Add entity_id to metadata if not present
            if (!isset($metadata['entity_id'])) {
                $metadata['entity_id'] = $request->input('entity_id');
            }

            AuditLogService::handleAuditDataEntry(
                event: $event,
                entityName: $entityName,
                entityType: $entityType,
                queryData: $metadata,
                subject: $subject,
                recap: $recap
            );

            return $this->apiResponse->success(null, ['message' => 'Audit log created successfully']);
        } catch (\Exception $e) {
            return $this->apiResponse->serverError('Failed to create audit log: ' . $e->getMessage());
        }
    }

    /**
     * Clean up old audit logs.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function cleanup(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'days_to_keep' => 'sometimes|integer|min:1|max:3650', // Max 10 years
        ]);

        if ($validator->fails()) {
            return $this->apiResponse->validationError($validator->errors()->toArray());
        }

        try {
            $daysToKeep = $request->input('days_to_keep', 365);
            $deletedCount = AuditLogService::cleanupOldLogs($daysToKeep);

            return $this->apiResponse->success([
                'deleted_count' => $deletedCount,
                'days_kept' => $daysToKeep
            ], ['message' => "Successfully cleaned up {$deletedCount} old audit logs"]);
        } catch (\Exception $e) {
            return $this->apiResponse->serverError('Failed to cleanup audit logs: ' . $e->getMessage());
        }
    }

    /**
     * Get audit log by ID.
     * 
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $auditLogModel = config('audit.audit_log_model', 'App\Models\AuditLog');
            $log = $auditLogModel::with(['user'])->findOrFail($id);

            return $this->apiResponse->success($log, ['message' => 'Audit log retrieved successfully']);
        } catch (\Exception $e) {
            return $this->apiResponse->notFound('Audit log not found');
        }
    }
}