<?php

namespace Sopheak\Core\Listeners;

use Sopheak\Core\Events\RecordCreated;
use Sopheak\Core\Events\RecordDeleted;
use Sopheak\Core\Events\RecordUpdated;
use Sopheak\Core\Services\QueryCacheService;

class InvalidateRecordCacheListener
{
    public function __construct(
        protected QueryCacheService $cacheService
    ) {}

    public function handle(RecordCreated|RecordUpdated|RecordDeleted $event): void
    {
        $this->cacheService->invalidateTableCache($event->table);
    }
}
