<?php

namespace Sopheak\Core\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Sopheak\Core\Services\AuditLogService;
use Sopheak\Core\Enums\AuditLogEventEnum;
use Illuminate\Support\Facades\Validator;
use Sopheak\Core\Services\RecordApiResponseService;

/**
 * Controller for handling audit log operations.
 * 
 * This controller provides endpoints for retrieving audit logs, statistics,
 * and managing audit data across the application.
 */
class AuditLogController extends Controller
{
    public function __construct(protected RecordApiResponseService $apiResponseService) {}

    /**
     * Get audit logs for a specific entity.
     */
    public function getLogs(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'entity_type' => 'required|string',
            'entity_id' => 'required|integer',
            'limit' => 'sometimes|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return $this->apiResponseService->validationError($validator->errors()->toArray());
        }

        try {
            $entityType = $request->input('entity_type');
            $entityId = $request->input('entity_id');
            $limit = $request->input('limit', 50);

            $logs = AuditLogService::getEntityAuditLogs($entityType, $entityId, $limit);

            return $this->apiResponseService->successWrapped($logs->toArray());
        } catch (Exception $exception) {
            return $this->apiResponseService->serverError('Failed to retrieve audit logs: ' . $exception->getMessage());
        }
    }

    /**
     * Get audit statistics.
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
            return $this->apiResponseService->validationError($validator->errors()->toArray());
        }

        try {
            $filters = $request->only(['entity_type', 'entity_id', 'start_date', 'end_date', 'event']);
            $stats = AuditLogService::getAuditStats($filters);

            return $this->apiResponseService->successWrapped($stats);
        } catch (Exception $exception) {
            return $this->apiResponseService->serverError('Failed to retrieve audit statistics: ' . $exception->getMessage());
        }
    }

    /**
     * Get field timeline for a specific field.
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
            return $this->apiResponseService->validationError($validator->errors()->toArray());
        }

        try {
            $entityType = $request->input('entity_type');
            $entityId = $request->input('entity_id');
            $field = $request->input('field');
            $limit = $request->input('limit', 50);

            $timeline = AuditLogService::getFieldTimeline($entityType, $entityId, $field, $limit);

            return $this->apiResponseService->successWrapped($timeline);
        } catch (Exception $exception) {
            return $this->apiResponseService->serverError('Failed to retrieve field timeline: ' . $exception->getMessage());
        }
    }

    /**
     * Get field statistics for a specific field.
     */
    public function getFieldStats(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'entity_type' => 'required|string',
            'entity_id' => 'required|integer',
            'field' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->apiResponseService->validationError($validator->errors()->toArray());
        }

        try {
            $entityType = $request->input('entity_type');
            $entityId = $request->input('entity_id');
            $field = $request->input('field');

            $stats = AuditLogService::getFieldStats($entityType, $entityId, $field);

            return $this->apiResponseService->successWrapped($stats);
        } catch (Exception $exception) {
            return $this->apiResponseService->serverError('Failed to retrieve field statistics: ' . $exception->getMessage());
        }
    }

    /**
     * Manually create an audit log entry.
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
            return $this->apiResponseService->validationError($validator->errors()->toArray());
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

            return $this->apiResponseService->successWrapped(null);
        } catch (Exception $exception) {
            return $this->apiResponseService->serverError('Failed to create audit log: ' . $exception->getMessage());
        }
    }

    /**
     * Clean up old audit logs.
     */
    public function cleanup(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'days_to_keep' => 'sometimes|integer|min:1|max:3650', // Max 10 years
        ]);

        if ($validator->fails()) {
            return $this->apiResponseService->validationError($validator->errors()->toArray());
        }

        try {
            $daysToKeep = $request->input('days_to_keep', 365);
            $deletedCount = AuditLogService::cleanupOldLogs($daysToKeep);

            return $this->apiResponseService->successWrapped([
                'deleted_count' => $deletedCount,
                'days_kept' => $daysToKeep
            ]);
        } catch (Exception $exception) {
            return $this->apiResponseService->serverError('Failed to cleanup audit logs: ' . $exception->getMessage());
        }
    }

    /**
     * Get audit log by ID.
     */
    public function show(int $id): JsonResponse
    {
        try {
            $log = DB::table('audit_logs')->where('id', $id)->first();

            if (null === $log) {
                return $this->apiResponseService->notFound('Audit log not found');
            }

            return $this->apiResponseService->successWrapped($log);
        } catch (Exception) {
            return $this->apiResponseService->notFound('Audit log not found');
        }
    }
}
