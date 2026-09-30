<?php

declare(strict_types=1);

namespace Sopheak\Core\Listeners;

use Sopheak\Core\Enums\AuditLogEventEnum;
use Sopheak\Core\Events\RecordCreated;
use Sopheak\Core\Events\RecordDeleted;
use Sopheak\Core\Events\RecordUpdated;
use Sopheak\Core\Services\AuditLogService;
use Sopheak\Core\Services\RecordConfigService;

/**
 * Audits RecordService::executeCreate/Update/Delete.
 *
 * Deliberately not ShouldQueue: `audit.queue_enabled` is the one switch that
 * decides whether an audit entry is queued (AuditLogService dispatches
 * AuditLogJob for that). A queued listener ran in a worker on any non-sync
 * connection, where there is no request and no authenticated user.
 */
class LogRecordAuditListener
{
    public function __construct(
        protected AuditLogService $auditService
    ) {}

    public function handle(RecordCreated|RecordUpdated|RecordDeleted $event): void
    {
        [$eventType, $auditData, $record] = match (true) {
            $event instanceof RecordCreated => [AuditLogEventEnum::CREATED, ['id' => $event->id, 'new_data' => $event->payload], $event->payload],
            $event instanceof RecordUpdated => [AuditLogEventEnum::UPDATED, ['id' => $event->id, 'old_data' => $event->oldPayload, 'new_data' => $event->newPayload], $event->newPayload],
            $event instanceof RecordDeleted => [AuditLogEventEnum::DELETED, ['id' => $event->id, 'old_data' => $event->oldPayload], $event->oldPayload],
        };

        $tenantColumn = RecordConfigService::tenantColumn();
        $auditData['tenant_id'] = $event->auditContext['tenant_id'] ?? $record[$tenantColumn] ?? null;

        $this->auditService->log($eventType, $event->table, $auditData);
    }
}
