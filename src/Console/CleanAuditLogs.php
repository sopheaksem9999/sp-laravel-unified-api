<?php

namespace Sopheak\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Sopheak\Core\Services\RecordConfigService;

class CleanAuditLogs extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'sp-laravel-api:clean-audit-logs 
                            {--dry-run : Show what would be deleted without actually deleting}
                            {--force : Force deletion without confirmation}
                            {--days= : Override retention days from config}
                            {--batch-size=1000 : Number of records to delete per batch}';

    /**
     * The console command description.
     */
    protected $description = 'Clean old audit logs based on retention configuration';

    /*
     * Execute the console command.
     */
    public function handle(): int
    {
        $retentionDays = $this->option('days') ?? RecordConfigService::auditRetentionDays();
        $batchSize = (int) $this->option('batch-size');
        $isDryRun = $this->option('dry-run');
        $isForced = $this->option('force');

        // Validate retention days
        if ($retentionDays === null) {
            $this->error('No retention period configured. Set AUDIT_LOG_RETENTION_DAYS or use --days option.');
            return Command::FAILURE;
        }

        if ($retentionDays <= 0) {
            $this->error('Retention days must be greater than 0.');
            return Command::FAILURE;
        }

        $cutoffDate = Carbon::now()->subDays($retentionDays);
        
        $this->info("Audit Log Cleanup");
        $this->info("================");
        $this->line(sprintf('Retention period: %s days', $retentionDays));
        $this->line('Cutoff date: ' . $cutoffDate->format('Y-m-d H:i:s'));
        $this->line('Batch size: ' . $batchSize);
        
        if ($isDryRun) {
            $this->warn("DRY RUN MODE - No data will be deleted");
        }

        // Get count of records to be deleted
        $totalCount = DB::table('audit_logs')
            ->where('created_at', '<', $cutoffDate)
            ->count();

        if ($totalCount === 0) {
            $this->info(sprintf('No audit logs found older than %s days.', $retentionDays));
            return Command::SUCCESS;
        }

        $this->line('Records to be deleted: ' . $totalCount);

        // Confirm deletion unless forced or dry run
        if (!$isDryRun && !$isForced && !$this->confirm(sprintf('Are you sure you want to delete %s audit log records?', $totalCount))) {
            $this->info('Operation cancelled.');
            return Command::SUCCESS;
        }

        if ($isDryRun) {
            $this->info(sprintf('DRY RUN: Would delete %s audit log records.', $totalCount));
            return Command::SUCCESS;
        }

        // Perform deletion in batches
        $deletedCount = 0;
        $progressBar = $this->output->createProgressBar($totalCount);
        $progressBar->start();

        do {
            $batchDeleted = DB::table('audit_logs')
                ->where('created_at', '<', $cutoffDate)
                ->limit($batchSize)
                ->delete();

            $deletedCount += $batchDeleted;
            $progressBar->advance($batchDeleted);

            // Small delay to prevent overwhelming the database
            if ($batchDeleted > 0) {
                usleep(100000); // 100ms
            }

        } while ($batchDeleted > 0);

        $progressBar->finish();
        $this->newLine(2);

        $this->info(sprintf('Successfully deleted %d audit log records.', $deletedCount));
        
        // Show remaining count
        $remainingCount = DB::table('audit_logs')->count();
        $this->line('Remaining audit logs: ' . $remainingCount);

        return Command::SUCCESS;
    }
}
