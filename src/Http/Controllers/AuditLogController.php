<?php

declare(strict_types=1);

namespace Sopheak\Core\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Sopheak\Core\Services\AuditLogService;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Utilities\RecordUtils;

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
            throw new AuthenticationException('Unauthorized.');
        }

        if (!Gate::forUser($user)->allows('manage-audit-logs')) {
            throw new AuthorizationException('Forbidden. You do not have permission to manage audit logs.');
        }
    }

    /**
     * Tenant every audit read is pinned to, resolved like table reads. A
     * `tenant_id` param may repeat it but can never widen or switch it.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    protected function resolveAuditTenantId(Request $request): mixed
    {
        if (!RecordConfigService::enableTenantId()) {
            return null;
        }

        $tenantId = RecordUtils::resolveTenantIdFromRequest($request);
        if (RecordUtils::isTenantIdMissing($tenantId)) {
            throw ValidationException::withMessages([
                RecordConfigService::tenantHeader() => ['header ' . RecordConfigService::tenantHeader() . ' cannot be empty'],
            ]);
        }

        $supplied = $request->input(RecordConfigService::tenantColumn());
        if (null !== $supplied && '' !== $supplied && (string) $supplied !== (string) $tenantId) {
            throw new AuthorizationException('Forbidden. The tenant does not match the request tenant.');
        }

        return $tenantId;
    }

    public function getStats(Request $request): array
    {
        $this->authorizeManageAuditLogs($request);

        return AuditLogService::getAuditStats($request->all(), $this->resolveAuditTenantId($request));
    }

    public function getFieldTimeline(Request $request, string $entityType, string $entityId, string $field): array
    {
        $this->authorizeManageAuditLogs($request);

        $limit = (int) $request->input('limit', 50);

        return AuditLogService::getFieldTimeline($entityType, $entityId, $field, $limit, $this->resolveAuditTenantId($request));
    }

    public function getFieldStats(Request $request, string $entityType, string $entityId, string $field): array
    {
        $this->authorizeManageAuditLogs($request);

        return AuditLogService::getFieldStats($entityType, $entityId, $field, $this->resolveAuditTenantId($request));
    }
}
