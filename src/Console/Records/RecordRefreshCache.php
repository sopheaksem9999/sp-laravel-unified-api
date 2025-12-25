<?php

namespace Sopheak\Core\Console\Records;

use Throwable;
use Sopheak\Core\Support\QueryBuilderFilters;
use Sopheak\Core\Support\RelationshipResolver;
use Sopheak\Core\Support\SchemaRegistry;
use Sopheak\Core\Services\OpenApiService;
use Illuminate\Console\Command;

class RecordRefreshCache extends Command
{
    protected $signature = 'sp-laravel-api:cache-refresh {--force : Force cache refresh even if cache is fresh} {--openapi : Generate OpenAPI spec after cache refresh} {--out= : Output file path for OpenAPI spec}';

    protected $description = 'Refresh record table cache and optionally generate OpenAPI specification';

    public function handle(): int
    {
        try {
            $this->info('Starting cache refresh process...');

            // Clear existing caches
            $this->line('Clearing SchemaRegistry cache...');
            SchemaRegistry::clearAllCache();

            $this->line('Clearing RelationshipResolver cache...');
            RelationshipResolver::clearSchemaCache();

            $this->line('Clearing QueryBuilderFilters cache...');
            QueryBuilderFilters::clearCache();

            // Rebuild cache
            $this->line('Rebuilding SchemaRegistry cache...');
            SchemaRegistry::get();

            $this->info('✅ Record table cache refreshed successfully');

            // Generate OpenAPI spec if requested
            if ($this->option('openapi')) {
                $this->line('');
                $this->info('Generating OpenAPI specification...');
                $this->generateOpenApiSpec();
            }

            return self::SUCCESS;

        } catch (Throwable $throwable) {
            $this->error('❌ Failed to refresh cache: ' . $throwable->getMessage());

            if ($this->option('verbose')) {
                $this->error('Stack trace: ' . $throwable->getTraceAsString());
            }

            return self::FAILURE;
         }
     }

    /**
     * Generate OpenAPI specification
     */
    protected function generateOpenApiSpec(): void
    {
        try {
            $defaultOut = storage_path('openapi-schema.json');
            $out = $this->option('out') ?: $defaultOut;

            $spec = app(OpenApiService::class)->generateInternal();

            $dir = dirname($out);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }

            file_put_contents($out, json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info('📄 OpenAPI spec written to: ' . $out);

        } catch (Throwable $throwable) {
            $this->error('❌ Failed to generate OpenAPI spec: ' . $throwable->getMessage());

            if ($this->option('verbose')) {
                $this->error('Stack trace: ' . $throwable->getTraceAsString());
            }
        }
    }
}
