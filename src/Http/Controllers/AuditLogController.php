<?php

declare(strict_types=1);

namespace Sopheak\Core\Http\Controllers;

use Exception;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Sopheak\Core\Enums\RecordApiJsonResponseEnum;
use Sopheak\Core\Services\AuditLogService;

class AuditLogController extends Controller
{
    /**
     * Authorize the user to manage audit logs.
     *
     * @throws HttpResponseException
     */
    protected function authorizeManageAuditLogs(Request $request): void
    {
        $user = $request->user();

        if (!$user) {
            throw new Exception(
                message: 'Unauthorized.',
                code: (int) RecordApiJsonResponseEnum::UNAUTHORIZED->value
            );
        }

        if (!Gate::forUser($user)->allows('manage-audit-logs')) {
            throw new Exception(
                message: 'Forbidden. You do not have permission to manage audit logs.',
                code: (int) RecordApiJsonResponseEnum::FORBIDDEN->value
            );
        }
    }

    public function getStats(Request $request): array
    {
        $this->authorizeManageAuditLogs($request);

        return AuditLogService::getAuditStats($request->all());
    }

    public function getFieldTimeline(Request $request, string $entityType, string $entityId, string $field): array
    {
        $this->authorizeManageAuditLogs($request);

        $limit = (int) $request->input('limit', 50);

        return AuditLogService::getFieldTimeline($entityType, (int) $entityId, $field, $limit);
    }

    public function getFieldStats(Request $request, string $entityType, string $entityId, string $field): array
    {
        $this->authorizeManageAuditLogs($request);

        return AuditLogService::getFieldStats($entityType, (int) $entityId, $field);
    }
}
