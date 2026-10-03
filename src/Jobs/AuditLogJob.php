<?php

declare(strict_types=1);

namespace Sopheak\Core\Jobs;

use Exception;
use Sopheak\Core\Enums\AuditLogEventEnum;
use Sopheak\Core\Services\AuditLogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queued only once the surrounding transaction commits: a write that is rolled
 * back — by a failing after-hook, a nested-write refusal or a database error
 * later in the request — must not leave an audit entry behind.
 */
class AuditLogJob implements ShouldQueueAfterCommit
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
        public ?array $queryData = null,
        public ?string $subject = null,
        public ?string $recap = null,
        public mixed $tenantId = null,
    ) {}

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
                subject: $this->subject,
                recap: $this->recap,
                tenantId: $this->tenantId,
            );
        } catch (Exception $exception) {
            Log::error('sp-laravel-api - Failed to process audit log job', [
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
