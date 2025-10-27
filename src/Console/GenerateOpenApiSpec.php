<?php

namespace Sopheak\Core\Console;

use Illuminate\Console\Command;
use Sopheak\Core\Services\OpenApiService;

class GenerateOpenApiSpec extends Command
{
    protected $signature = 'sp-laravel-api:openapi {--out= : Output file path}';
    protected $description = 'Generate OpenAPI 3 specification based on record configuration.';

    protected OpenApiService $openApiService;

    public function __construct(OpenApiService $openApiService)
    {
        parent::__construct();
        $this->openApiService = $openApiService;
    }

    public function handle(): int
    {
        $defaultOut = storage_path('api-v2.json');
        $out = $this->option('out') ?: $defaultOut;

        $this->info('Generating OpenAPI specification from record configuration...');

        $tables = config('record.tables', []);
        $spec = $this->openApiService->generateSpecification();

        try {
            $dir = dirname($out);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($out, json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info('✅ OpenAPI spec written to: ' . $out);
            
            $tableCount = count($tables);
            $this->info("📊 Generated documentation for {$tableCount} table(s)");
            
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('❌ Failed to write spec: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
