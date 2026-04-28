<?php

namespace Sopheak\Core\Listeners;

use Sopheak\Core\Events\RecordCreated;
use Sopheak\Core\Events\RecordDeleted;
use Sopheak\Core\Events\RecordUpdated;
use Sopheak\Core\Services\RecordCacheService;
use Sopheak\Core\Services\RecordConfigService;

class InvalidateRecordCacheListener
{
    public function __construct(
        protected RecordCacheService $recordCacheService
    ) {}

    public function handle(RecordCreated|RecordUpdated|RecordDeleted $event): void
    {
        $tenantColumn = RecordConfigService::tenantColumn();
        $tenantId = null;

        if ($event instanceof RecordCreated) {
            $tenantId = $event->payload[$tenantColumn] ?? null;
        } elseif ($event instanceof RecordUpdated) {
            $tenantId = $event->newPayload[$tenantColumn] ?? $event->oldPayload[$tenantColumn] ?? null;
        } elseif ($event instanceof RecordDeleted) {
            $tenantId = $event->oldPayload[$tenantColumn] ?? null;
        }

        $this->recordCacheService->clearTableCache($event->table, $tenantId);
    }
}
