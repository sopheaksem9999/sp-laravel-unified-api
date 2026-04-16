<?php

namespace Sopheak\Core\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Sopheak\Core\Enums\AuditLogEventEnum;
use Sopheak\Core\Events\RecordCreated;
use Sopheak\Core\Events\RecordDeleted;
use Sopheak\Core\Events\RecordUpdated;
use Sopheak\Core\Services\AuditLogService;

class LogRecordAuditListener implements ShouldQueue
{
    public function __construct(
        protected AuditLogService $auditService
    ) {}

    public function handle(RecordCreated|RecordUpdated|RecordDeleted $event): void
    {
        $eventType = match (true) {
            $event instanceof RecordCreated => AuditLogEventEnum::CREATED,
            $event instanceof RecordUpdated => AuditLogEventEnum::UPDATED,
            $event instanceof RecordDeleted => AuditLogEventEnum::DELETED,
            default => null,
        };

        if (!$eventType) {
            return;
        }

        $this->auditService->log($eventType, $event->table, $event->auditContext);
    }
}
