<?php

namespace Sopheak\Core\Console;

use Illuminate\Console\Command;
use RuntimeException;
use Sopheak\Core\Services\RecordConfigService;

class MakeRecordTableCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Usage:
     *  php artisan sp-laravel-api:record customers \
     *      --table=customers \
     *      --pms-name=customer \
     *      --tenant \
     *      --soft-deletes
     */
    protected $signature = 'sp-laravel-api:record|sp-laravel-api:make-record-table
                            {name : Table key / config filename (e.g. customers)}
                            {--table= : Database table name (defaults to name)}
                            {--pms-name= : Permission resource name (defaults to name)}
                            {--tenant : Mark table as tenant-aware (hasTenantId=true)}
                            {--soft-deletes : Enable soft deletes (softDeletes=true)}
                            {--force : Overwrite the config file if it already exists}';

    /**
     * The console command description.
     */
    protected $description = 'Create a new RecordTableType configuration file under config/records/tables.';

    public function handle(): int
    {
        $name = (string) $this->argument('name');

        if ($name === '') {
            $this->error('Table name cannot be empty.');

            return self::FAILURE;
        }

        $table = (string) ($this->option('table') ?: $name);
        $pmsName = (string) ($this->option('pms-name') ?: $name);
        $hasTenant = (bool) $this->option('tenant');
        $softDeletes = (bool) $this->option('soft-deletes');
        $force = (bool) $this->option('force');

        $directory = config_path(RecordConfigService::tableConfigPath());
        $this->ensureDirectory($directory);

        $filePath = $directory . DIRECTORY_SEPARATOR . $name . '.php';

        if (is_file($filePath) && !$force) {
            $this->error(sprintf('Config file already exists: %s (use --force to overwrite)', $this->relativePath($filePath)));

            return self::FAILURE;
        }

        $contents = $this->buildConfigFileContents(
            name: $name,
            table: $table,
            pmsName: $pmsName,
            hasTenant: $hasTenant,
            softDeletes: $softDeletes,
        );

        $this->writeFile($filePath, $contents);

        $this->info('✅ Created RecordTableType config: ' . $this->relativePath($filePath));
        $this->line('   - table: ' . $table);
        $this->line('   - pmsName: ' . $pmsName);
        $this->line('   - hasTenantId: ' . ($hasTenant ? 'true' : 'false'));
        $this->line('   - softDeletes: ' . ($softDeletes ? 'true' : 'false'));

        $this->newLine();
        $this->line('Next steps:');
        $this->line('  - Review and customize the generated config at ' . $this->relativePath($filePath));
        $this->line('  - Run: php artisan sp-laravel-api:sync-record-columns --force  # to populate columns from DB');

        return self::SUCCESS;
    }

    private function buildConfigFileContents(string $name, string $table, string $pmsName, bool $hasTenant, bool $softDeletes): string
    {
        $pmsLiteral = var_export($pmsName, true);
        $tableLiteral = var_export($table, true);
        $hasTenantLiteral = $hasTenant ? 'true' : 'false';
        $softDeletesLiteral = $softDeletes ? 'true' : 'false';

        return <<<PHP
<?php

use Sopheak\\Core\\Types\\RecordTablePublic;
use Sopheak\\Core\\Types\\RecordTableType;

return new RecordTableType(
    pmsName: {$pmsLiteral},
    table: {$tableLiteral},
    public: new RecordTablePublic(
        read: false,
        write: false,
    ),
    relationships: [],
    functions: [],
    softDeletes: {$softDeletesLiteral},
    hasTenantId: {$hasTenantLiteral},
);
PHP;
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (file_exists($path) && !is_dir($path)) {
            throw new RuntimeException('Path exists and is not a directory: ' . $path);
        }

        if (!mkdir($path, 0755, true) && !is_dir($path)) {
            throw new RuntimeException('Failed to create directory: ' . $path);
        }
    }

    private function writeFile(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && (!mkdir($dir, 0755, true) && !is_dir($dir))) {
            throw new RuntimeException('Failed to create directory: ' . $dir);
        }

        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Failed to write file: ' . $path);
        }
    }

    private function relativePath(string $path): string
    {
        $base = base_path();
        if (str_starts_with($path, $base . DIRECTORY_SEPARATOR)) {
            return substr($path, strlen($base . DIRECTORY_SEPARATOR));
        }

        return $path;
    }
}
