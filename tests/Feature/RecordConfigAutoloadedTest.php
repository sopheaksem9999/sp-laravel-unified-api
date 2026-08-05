<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Throwable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Support\RecordConfigLoader;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;

class RecordConfigAutoloadedTest extends TestCase
{
    /** @var string[] scan directories created by a test, removed in tearDown */
    private array $createdDirs = [];

    /**
     * Individual fixture files dropped into the fixed, shared
     * records/globalFunctions and records/global-functions directories.
     * Only the file is removed in tearDown, never the directory itself,
     * since other tests (e.g. AttributeFunctionDiscoveryTest) share it.
     *
     * @var string[]
     */
    private array $createdFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        RecordConfigLoader::flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdDirs as $dir) {
            if (File::isDirectory($dir)) {
                File::deleteDirectory($dir);
            }
        }

        $this->createdDirs = [];

        foreach ($this->createdFiles as $file) {
            if (File::exists($file)) {
                File::delete($file);
            }
        }

        $this->createdFiles = [];

        RecordConfigLoader::flush();

        parent::tearDown();
    }

    /**
     * Points record.table_config_path at a fresh, unique directory and
     * returns its absolute path so a test can drop a fixture file into it.
     */
    private function makeScanDir(string $relative): string
    {
        Config::set('record.table_config_path', $relative);
        $dir = config_path(RecordConfigService::tableConfigPath());
        File::ensureDirectoryExists($dir);
        $this->createdDirs[] = $dir;

        return $dir;
    }

    /**
     * Drops a fixture file into one of the fixed global-function scan
     * directories (config_path('records/globalFunctions') or
     * config_path('records/global-functions')), tracked for per-file cleanup.
     */
    private function putGlobalFunctionFixture(string $relativeDir, string $filename, string $contents): string
    {
        $dir = config_path($relativeDir);
        File::ensureDirectoryExists($dir);
        $path = $dir . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($path, $contents);
        $this->createdFiles[] = $path;

        return $path;
    }

    /** @test */
    public function the_runtime_scan_runs_when_the_flag_is_absent(): void
    {
        // record.autoloaded is never touched here: this is the literal
        // "key does not exist" case, not merely "set to a falsy value".
        $dir = $this->makeScanDir('records/autoload-test-absent');
        file_put_contents(
            $dir . DIRECTORY_SEPARATOR . 'scanned.php',
            '<?php return new \Sopheak\Core\Types\RecordTableType(table: "scanned");'
        );

        Config::set('record.tables', []);

        $tables = RecordConfigService::getTableConfig();

        $this->assertArrayHasKey(
            'scanned',
            $tables,
            'the flag being absent must fall back to the runtime directory scan'
        );
    }

    /** @test */
    public function declared_tables_still_resolve_when_the_flag_is_set(): void
    {
        // Deliberately leave a file sitting in the would-be scan directory so
        // this test can tell "declared table resolves" apart from "declared
        // table resolves because the scan also silently ran and nothing
        // conflicted": if the guard only pretended to skip the scan, the
        // 'scanned' key below would leak through too.
        $dir = $this->makeScanDir('records/autoload-test-set');
        file_put_contents(
            $dir . DIRECTORY_SEPARATOR . 'scanned.php',
            '<?php return new \Sopheak\Core\Types\RecordTableType(table: "scanned");'
        );

        Config::set('record.autoloaded', true);
        Config::set('record.tables', ['declared' => new RecordTableType(table: 'declared')]);

        $tables = RecordConfigService::getTableConfig();

        $this->assertArrayHasKey(
            'declared',
            $tables,
            'the flag must skip only the directory scan, never config-declared tables'
        );
        $this->assertArrayNotHasKey(
            'scanned',
            $tables,
            'the flag must actually suppress the directory scan, not merely coexist with it'
        );
    }

    /** @test */
    public function a_client_without_the_autoloaded_key_keeps_the_old_merged_behavior(): void
    {
        // The entire backward-compatibility promise: a client still on the
        // old published record.php has no 'autoloaded' key at all, so both
        // its config-declared tables and its scanned directory tables must
        // keep showing up together, exactly as before this feature existed.
        $dir = $this->makeScanDir('records/autoload-test-back-compat');
        file_put_contents(
            $dir . DIRECTORY_SEPARATOR . 'scanned.php',
            '<?php return new \Sopheak\Core\Types\RecordTableType(table: "scanned");'
        );

        Config::set('record.tables', ['declared' => new RecordTableType(table: 'declared')]);

        $tables = RecordConfigService::getTableConfig();

        $this->assertArrayHasKey('declared', $tables);
        $this->assertArrayHasKey(
            'scanned',
            $tables,
            'a client without the autoloaded key must keep getting the runtime scan merged in'
        );
    }

    /** @test */
    public function the_runtime_global_function_scan_runs_when_the_flag_is_absent(): void
    {
        $this->putGlobalFunctionFixture(
            'records/global-functions',
            'zz_autoload_guard_absent.php',
            '<?php return ["scanned" => ["type" => "query", "query" => "select 1"]];'
        );

        Config::set('record.global_functions', []);

        $functions = RecordConfigService::globalFunctions();

        $this->assertArrayHasKey(
            'zz_autoload_guard_absent/scanned',
            $functions,
            'the flag being absent must fall back to the runtime global-function directory scan'
        );
    }

    /** @test */
    public function declared_global_functions_still_resolve_when_the_flag_is_set(): void
    {
        $this->putGlobalFunctionFixture(
            'records/global-functions',
            'zz_autoload_guard_set.php',
            '<?php return ["scanned" => ["type" => "query", "query" => "select 1"]];'
        );

        Config::set('record.autoloaded', true);
        Config::set('record.global_functions', ['declared' => ['type' => 'query', 'query' => 'select 2']]);

        $functions = RecordConfigService::globalFunctions();

        $this->assertArrayHasKey(
            'declared',
            $functions,
            'the flag must skip only the directory scan, never config-declared global functions'
        );
        $this->assertArrayNotHasKey(
            'zz_autoload_guard_set/scanned',
            $functions,
            'the flag must actually suppress the global-function directory scan, not merely coexist with it'
        );
    }

    /** @test */
    public function a_loaded_table_config_survives_var_export(): void
    {
        $tables = ['widgets' => new RecordTableType(table: 'widgets', primaryKey: 'id')];

        $exported = var_export($tables, true);
        $restored = eval('return ' . $exported . ';');

        $this->assertInstanceOf(RecordTableType::class, $restored['widgets']);
        $this->assertSame('widgets', $restored['widgets']->table);
    }

    /** @test */
    public function a_closure_validator_cannot_be_config_cached(): void
    {
        // Observed on this PHP version (8.4.18): var_export() does NOT throw
        // or warn on a Closure. It silently emits the non-functional
        // `\Closure::__set_state(array())`, since Closure has no such static
        // method. The failure only appears when that generated code is
        // evaluated -- which is exactly what Laravel's config:cache command
        // does immediately after writing the cache file: it `require`s the
        // file it just wrote and wraps any Throwable as "Your configuration
        // files are not serializable." (see
        // Illuminate\Foundation\Console\ConfigCacheCommand::handle()). So
        // this test pins the failure at the point it actually surfaces:
        // evaluating the exported code, not the var_export() call itself.
        $table = new RecordTableType(
            table: 'widgets',
            createValidator: static fn (): array => ['name' => 'required'],
        );

        $exported = var_export(['widgets' => $table], true);

        $this->assertStringContainsString(
            'Closure::__set_state',
            $exported,
            'var_export must still attempt to serialize the Closure so the eval below reproduces the real config:cache failure'
        );

        $this->expectException(Throwable::class);

        eval('return ' . $exported . ';');
    }
}
