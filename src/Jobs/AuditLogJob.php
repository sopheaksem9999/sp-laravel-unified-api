<?php

namespace Sopheak\Core\Jobs;

use Sopheak\Core\Enums\AuditLogEventEnum;
use Sopheak\Core\Services\AuditLogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AuditLogJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public AuditLogEventEnum $event,
        public string $entityName,
        public ?string $entityType = null,
        public ?array $queryData = null
    ) {
        $this->queryData = $queryData;
        $this->event = $event;
        $this->entityName = $entityName;
        $this->entityType = $entityType;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            AuditLogService::handleAuditDataEntry(
                event: $this->event,
                entityName: $this->entityName,
                entityType: $this->entityType,
                queryData: $this->queryData,
            );
        } catch (\Exception $exception) {
            Log::error('Failed to process audit log job', [
                'error' => $exception->getMessage(),
                'entity_name' => $this->entityName,
                'query_data' => $this->queryData,
                'trace' => $exception->getTraceAsString(),
            ]);

            // Re-throw the exception to trigger job retry mechanism
            throw $exception;
        }
    }
}