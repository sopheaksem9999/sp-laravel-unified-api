<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Sopheak\Core\Console\SetupPackageCommand;
use ReflectionClass;
use Sopheak\Core\Tests\TestCase;

/**
 * `php artisan config:cache` and `config:clear` must not error because of this
 * package's config.
 *
 * `config/sp-record.php` ships with `'autoloaded' => true`, so it scans
 * `records/tables` and `records/global-functions` while the file is being
 * evaluated. Everything those directories produce therefore has to survive
 * `var_export()`, because that is what `config:cache` does: export the whole
 * config tree to a file, then require it back to verify it. A `Closure`
 * validator var_exports as the non-functional `\Closure::__set_state(array())`
 * and the require throws — surfacing as Laravel's "Your configuration files
 * are not serializable." at a client's *deploy* time, not in development.
 *
 * WHY THIS TEST PUBLISHES THE CONFIG FIRST. `ConfigCacheCommand` does not cache
 * the running application's config. It bootstraps a *fresh* app from
 * `bootstrapPath('app.php')` and exports that. Under Testbench that skeleton
 * does not load this package's provider, so a naive `config:cache` here caches
 * a config tree containing none of our keys — it would pass no matter how
 * broken the package config was. Verified: the cached payload contained zero
 * keys belonging to this package — no `record`, no `sp-` prefixed namespace,
 * and none of `attachments`, `audit`, `permissions` or `webhooks`.
 *
 * So these tests place the real shipped `sp-record.php` — plus a scaffolded
 * table config in the directory it autoloads — into `config_path()` first, and
 * clean up afterwards. That makes the fresh app load the file for real,
 * execute the directory scan, and var_export the result: the actual client
 * failure path, end to end.
 */
class ConfigCacheCommandsTest extends TestCase
{
    private const PACKAGE_CONFIG = 'sp-record.php';

    /**
     * Absolute paths this test created and must remove, deepest first.
     *
     * @var string[]
     */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearCachedConfig();
    }

    protected function tearDown(): void
    {
        $this->clearCachedConfig();
        $this->removeCreatedPaths();

        parent::tearDown();
    }

    /** @test */
    public function config_cache_succeeds_with_the_shipped_record_config_present(): void
    {
        $this->publishRecordConfig();

        $exitCode = Artisan::call('config:cache');

        $this->assertSame(
            0,
            $exitCode,
            'config:cache failed with the shipped sp-record.php present: ' . Artisan::output()
        );

        $cached = require $this->app->getCachedConfigPath();

        $this->assertArrayHasKey(
            'sp-record',
            $cached,
            'the shipped config must actually be in the cached payload, or this test proves nothing'
        );
        $this->assertTrue(
            $cached['sp-record']['autoloaded'],
            'autoloaded must survive caching — it is what tells the service to skip its runtime scan'
        );
    }

    /** @test */
    public function config_cache_succeeds_with_a_scaffolded_table_in_the_autoloaded_directory(): void
    {
        $this->publishRecordConfig();
        $this->scaffoldUsersTable();

        $this->assertSame(
            0,
            Artisan::call('config:cache'),
            'config:cache failed with the scaffolded users table present: ' . Artisan::output()
        );

        $cached = require $this->app->getCachedConfigPath();

        $this->assertArrayHasKey(
            'users',
            $cached['sp-record']['tables'],
            'the autoloaded directory scan must have run and survived var_export'
        );
    }

    /** @test */
    public function config_clear_succeeds_and_removes_the_cache(): void
    {
        $this->publishRecordConfig();

        $this->assertSame(0, Artisan::call('config:cache'), Artisan::output());
        $this->assertFileExists($this->app->getCachedConfigPath());

        $this->assertSame(
            0,
            Artisan::call('config:clear'),
            'config:clear failed: ' . Artisan::output()
        );
        $this->assertFileDoesNotExist($this->app->getCachedConfigPath());
    }

    /** @test */
    public function config_cache_is_repeatable(): void
    {
        $this->publishRecordConfig();

        // config:cache runs config:clear internally first; running it twice
        // catches a cache that cannot be rebuilt over itself.
        $this->assertSame(0, Artisan::call('config:cache'), Artisan::output());
        $this->assertSame(0, Artisan::call('config:cache'), Artisan::output());

        $this->assertFileExists($this->app->getCachedConfigPath());
    }

    /** @test */
    public function config_clear_succeeds_when_nothing_is_cached(): void
    {
        $this->clearCachedConfig();

        $this->assertSame(
            0,
            Artisan::call('config:clear'),
            'config:clear must be safe when no cache exists: ' . Artisan::output()
        );
    }

    /**
     * Copy the real shipped config into the app's config path, exactly as
     * `vendor:publish --tag=sp-laravel-api-config` would.
     */
    private function publishRecordConfig(): void
    {
        $source = __DIR__ . '/../../config/' . self::PACKAGE_CONFIG;
        $target = config_path(self::PACKAGE_CONFIG);

        $this->assertFileExists($source, 'the shipped config must exist to be published');

        File::copy($source, $target);
        $this->created[] = $target;
    }

    /**
     * Drop the command's own scaffolded users table into the directory that
     * `sp-record.php` autoloads, so the scan has something real to find.
     */
    private function scaffoldUsersTable(): void
    {
        $command = new SetupPackageCommand();
        $reflection = new ReflectionClass($command);

        $validatorDir = base_path('app/Record/Validators');
        $tableDir = config_path('records/tables');

        foreach ([$validatorDir, $tableDir] as $dir) {
            if (!File::isDirectory($dir)) {
                // Track the OUTERMOST directory that does not exist yet, not the
                // leaf: makeDirectory(recursive: true) creates every missing
                // ancestor, and tracking only the leaf orphans its parents.
                $this->created[] = $this->outermostMissingAncestor($dir);
                File::makeDirectory($dir, 0755, true);
            }
        }

        $validatorPath = $validatorDir . '/UserValidator.php';
        File::put($validatorPath, $reflection->getMethod('defaultUserValidatorClass')->invoke($command));
        $this->created[] = $validatorPath;

        $tablePath = $tableDir . '/users.php';
        File::put($tablePath, $reflection->getMethod('defaultUsersTableConfig')->invoke($command));
        $this->created[] = $tablePath;
    }

    /**
     * Walk up from $path to the highest ancestor that does not exist yet, so
     * removing it later takes the whole tree this test introduced.
     */
    private function outermostMissingAncestor(string $path): string
    {
        $outermost = $path;

        while (true) {
            $parent = dirname($outermost);

            if ($parent === $outermost || File::isDirectory($parent)) {
                return $outermost;
            }

            $outermost = $parent;
        }
    }

    private function clearCachedConfig(): void
    {
        $path = $this->app->getCachedConfigPath();

        if (File::exists($path)) {
            File::delete($path);
        }
    }

    /**
     * Remove everything this test wrote, deepest path first so directories are
     * empty by the time they are removed. Runs even when an assertion fails —
     * leaving a published config behind would poison every later test.
     */
    private function removeCreatedPaths(): void
    {
        usort($this->created, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($this->created as $path) {
            if (File::isDirectory($path)) {
                File::deleteDirectory($path);
                continue;
            }

            if (File::exists($path)) {
                File::delete($path);
            }
        }

        $this->created = [];
    }
}
