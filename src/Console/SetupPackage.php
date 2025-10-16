<?php

namespace Sopheak\Core\Console;

use Illuminate\Support\Str;

use Illuminate\Console\Command;

class SetupPackage extends Command
{
    protected $signature = 'sp-laravel-api:setup {--force : Overwrite existing configs}';
    protected $description = 'Setup defaults: publish package config and create record/audit/jwt configs if missing.';

    public function handle(): int
    {
        // Publish package config
        try {
            $this->call('vendor:publish', [
                '--tag' => 'sp-laravel-api-config',
                '--force' => true,
            ]);
        } catch (\Throwable $e) {
            $this->warn('Vendor publish failed: ' . $e->getMessage());
        }

        $force = (bool) $this->option('force');

        $created = 0; $skipped = 0;
        $created += $this->ensureFile('config/record.php', $this->defaultRecordConfig(), $force);
        $created += $this->ensureFile('config/audit.php', $this->defaultAuditConfig(), $force);
        $created += $this->ensureFile('config/jwt.php', $this->defaultJwtConfig(), $force);

        $this->info("Setup complete. Created/updated {$created} config file(s).");
        if (!$force) {
            $this->line('Use --force to overwrite existing configs.');
        }
        return self::SUCCESS;
    }

    private function ensureFile(string $path, string $contents, bool $force): int
    {
        if (!file_exists($path)) {
            $this->writeFile($path, $contents);
            $this->info("Created: {$path}");
            return 1;
        }

        // If file has only opening tag or empty, treat as missing
        $existing = trim(@file_get_contents($path) ?: '');
        if (!$existing || $existing === '<?php' || $force) {
            $this->writeFile($path, $contents);
            $this->info("Updated: {$path}");
            return 1;
        }

        $this->line("Skipped (exists): {$path}");
        return 0;
    }

    private function writeFile(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($path, $contents);
    }

    private function defaultRecordConfig(): string
    {
        return <<<'PHP'
<?php

return [
    'cache' => [
        'enabled' => true,
        'ttl' => 3600,
    ],
];
PHP;
    }

    private function defaultAuditConfig(): string
    {
        return <<<'PHP'
<?php

return [
    'enabled' => true,
    'retention_days' => 90,
];
PHP;
    }

    private function defaultJwtConfig(): string
    {
        return <<<'PHP'
<?php

return [
    // Use environment variables; generate a secret via `php artisan jwt:secret`
    'secret' => env('JWT_SECRET'),
    'ttl' => floatval(env('JWT_TTL', 60)),
    'refresh_ttl' => floatval(env('JWT_REFRESH_TTL', 20160)),
];
PHP;
    }
}
