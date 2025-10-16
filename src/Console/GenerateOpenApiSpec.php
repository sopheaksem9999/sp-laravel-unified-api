<?php

namespace Sopheak\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerateOpenApiSpec extends Command
{
    protected $signature = 'sp-laravel-api:openapi {--out= : Output file path}';
    protected $description = 'Generate a minimal OpenAPI 3 JSON stub for your API.';

    public function handle(): int
    {
        $defaultOut = storage_path('api-v2.json');
        $out = $this->option('out') ?: $defaultOut;

        $spec = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => config('app.name') . ' API',
                'version' => 'v2'
            ],
            'servers' => [
                ['url' => config('app.url') ?: 'http://localhost']
            ],
            'paths' => new \stdClass(),
            'components' => new \stdClass(),
            'x-generated-at' => now()->toIso8601String(),
            'x-request-id' => (string) Str::uuid(),
        ];

        try {
            $dir = dirname($out);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($out, json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info('OpenAPI spec written to: ' . $out);
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to write spec: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
