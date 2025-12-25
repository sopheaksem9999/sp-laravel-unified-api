<?php

namespace Sopheak\Core\Console;

use Throwable;
use Illuminate\Console\Command;
use Sopheak\Core\Services\OpenApiService;
use Sopheak\Core\Support\SchemaRegistry;

class GenerateOpenApiSpec extends Command
{
    protected $signature = 'sp-laravel-api:openapi {--out= : Output file path}';

    protected $description = 'Generate OpenAPI 3 specification based on record configuration.';

    public function __construct(protected OpenApiService $openApiService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $defaultOut = storage_path('openapi-schema.json');
        $out = $this->option('out') ?: $defaultOut;

        $this->info('Generating OpenAPI specification from record configuration...');

        $tables = config('record.tables', []);
        SchemaRegistry::refresh();
        $spec = $this->openApiService->generateInternal();

        try {
            $dir = dirname($out);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }

            file_put_contents($out, json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info('✅ OpenAPI spec written to: ' . $out);

            $tableCount = count($tables);
            $this->info(sprintf('📊 Generated documentation for %d table(s)', $tableCount));

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error('❌ Failed to write spec: ' . $throwable->getMessage());
            return self::FAILURE;
        }
    }
}
