<?php

namespace Sopheak\Core\Console;

use Illuminate\Support\Facades\DB;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class CleanTempAttachmentsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'sp-laravel-api:clean-temp-attachments 
                            {--dry-run : Show what would be deleted without actually deleting}
                            {--force : Force deletion without confirmation}
                            {--minutes= : Override retention minutes from config}';

    /**
     * The console command description.
     */
    protected $description = 'Clean old temporary attachments based on retention configuration';

    /*
     * Execute the console command.
     */
    public function handle(): int
    {
        $retentionMinutes = $this->option('minutes') ?? config('attachments.temp_lifetime', 1440);
        $isDryRun = $this->option('dry-run');
        $isForced = $this->option('force');

        if ($retentionMinutes <= 0) {
            $this->error('Retention minutes must be greater than 0.');
            return Command::FAILURE;
        }

        $now = Carbon::now();
        $cutoffDate = $now->copy()->subMinutes((int) $retentionMinutes);
        $hasTempTimeoutColumn = Schema::hasColumn('sp_attachments', 'temp_timeout');
        
        $this->info("Temporary Attachments Cleanup");
        $this->info("=============================");
        $this->info(sprintf('Fallback retention period: %s minutes', $retentionMinutes));
        $this->info('Cutoff date: ' . $cutoffDate->toDateTimeString());
        $this->info('temp_timeout support: ' . ($hasTempTimeoutColumn ? 'enabled' : 'not found (fallback to created_at only)'));

        if ($isDryRun) {
            $this->info("MODE: Dry Run (No records will be deleted)");
        } elseif (!$isForced && !$this->confirm('Are you sure you want to delete old temporary attachments?')) {
            $this->info('Operation cancelled.');
            return Command::SUCCESS;
        }

        // We need to query the database directly or use RecordService.
        // Since RecordService requires tenant context and we are in a global command,
        // we might need to bypass tenant scope or iterate over tenants.
        // For simplicity, we'll use DB facade to find the records, then delete them.
        
        $query = DB::table('sp_attachments')
            ->whereIn('visibility', ['temp_private', 'temp_public']);

        if ($hasTempTimeoutColumn) {
            $query->where(function ($builder) use ($now, $cutoffDate): void {
                $builder->where(function ($timeoutQuery) use ($now): void {
                    $timeoutQuery->whereNotNull('temp_timeout')
                        ->where('temp_timeout', '<=', $now);
                })->orWhere(function ($fallbackQuery) use ($cutoffDate): void {
                    $fallbackQuery->whereNull('temp_timeout')
                        ->where('created_at', '<', $cutoffDate);
                });
            });
        } else {
            $query->where('created_at', '<', $cutoffDate);
        }

        $count = $query->count();

        if ($count === 0) {
            $this->info('No temporary attachments found to clean up.');
            return Command::SUCCESS;
        }

        $this->info(sprintf('Found %d temporary attachments to delete.', $count));

        if ($isDryRun) {
            return Command::SUCCESS;
        }

        $records = $query->get();
        $deletedCount = 0;

        foreach ($records as $record) {
            try {
                // Delete physical file
                if (Storage::disk($record->disk)->exists($record->path)) {
                    Storage::disk($record->disk)->delete($record->path);
                }

                // Delete database record
                DB::table('sp_attachments')->where('id', $record->id)->delete();
                
                // Delete associated links
                DB::table('sp_attachment_links')->where('attachment_id', $record->id)->delete();

                $deletedCount++;
            } catch (Exception $e) {
                $this->error(sprintf('Failed to delete attachment %s: %s', $record->id, $e->getMessage()));
            }
        }

        $this->info(sprintf('Successfully deleted %d temporary attachments.', $deletedCount));

        return Command::SUCCESS;
    }
}
