<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Sopheak\Core\Tests\TestCase;

/**
 * `sp-laravel-api:setup` used to run `vendor:publish --force` unconditionally.
 *
 * On a client who had customized `config/record.php` and not yet migrated to
 * the sp-* filenames, that wrote `config/sp-record.php` with the package's
 * packaged defaults — and ConfigNamespaceBridge::adopt() gives the sp-* file
 * precedence for every key both files set. So running the package's own
 * documented setup command silently reverted `record.api_prefix` from a
 * client's `api/v9` to `api/v1` and `record.id_type` from `uuid` to `integer`,
 * while `config/record.php` stayed byte-identical on disk: no `git diff`, no
 * error, and the command even printed "Skipped (exists)" for the old file it
 * had just superseded.
 *
 * These tests run the real command against a scratch application directory.
 * The application's config path and the process working directory are both
 * redirected into it, and `config/` sits *inside* the working directory as it
 * does in a real Laravel app — the command publishes to `config_path()` but
 * scaffolds to paths relative to the working directory, and the interaction
 * between those two writes is exactly what
 * {@see self::force_leaves_the_published_packaged_config_intact()} pins.
 */
class SetupPackageLegacyConfigGuardTest extends TestCase
{
    private string $appDir;

    private string $configDir;

    private string $originalCwd;

    /** The exact bytes of the customized old-named config used by the tests. */
    private const CUSTOMIZED_LEGACY_RECORD_CONFIG
        = "<?php\n\nreturn [\n    'api_prefix' => 'api/v9',\n    'id_type' => 'uuid',\n];\n";

    protected function setUp(): void
    {
        $this->appDir = sys_get_temp_dir() . '/sp_setup_guard_app_' . uniqid('', true);
        $this->configDir = $this->appDir . '/config';

        // Plain mkdir, not the File facade: this must exist before
        // parent::setUp() so getEnvironmentSetUp() can point config_path() at
        // it, and no facade root exists that early.
        mkdir($this->configDir, 0o755, true);

        parent::setUp();

        $this->originalCwd = (string) getcwd();
        chdir($this->appDir);
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);

        File::deleteDirectory($this->appDir);

        parent::tearDown();
    }

    /**
     * Runs before the package provider boots, so `config_path()` — including
     * the one baked into the provider's `publishes()` map — resolves into the
     * scratch application rather than the real config directory.
     */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app->useConfigPath($this->configDir);
    }

    private function writeCustomizedLegacyRecordConfig(): void
    {
        file_put_contents($this->configDir . '/record.php', self::CUSTOMIZED_LEGACY_RECORD_CONFIG);
    }

    private function packagedRecordConfig(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../config/sp-record.php');
    }

    /** @test */
    public function setup_refuses_to_publish_sp_names_over_a_still_present_old_named_config(): void
    {
        $this->writeCustomizedLegacyRecordConfig();

        // One expectation per output line: Mockery lets only the first matching
        // expectation handle a given doWrite() call, so two substrings that
        // only ever co-occur on the same line would leave the second unmet.
        $this->artisan('sp-laravel-api:setup')
            ->expectsOutputToContain('git mv config/record.php config/sp-record.php')
            ->expectsOutputToContain('Aborted without publishing')
            ->assertExitCode(Command::FAILURE);

        $this->assertFileDoesNotExist(
            $this->configDir . '/sp-record.php',
            "setup must not publish packaged defaults to a name that would shadow the client's "
            . 'customized config/record.php'
        );
        $this->assertSame(
            self::CUSTOMIZED_LEGACY_RECORD_CONFIG,
            file_get_contents($this->configDir . '/record.php'),
            "the client's own file must be left exactly as it was"
        );
    }

    /** @test */
    public function setup_publishes_and_scaffolds_normally_when_no_old_named_config_remains(): void
    {
        $this->artisan('sp-laravel-api:setup')
            ->doesntExpectOutputToContain('Aborted without publishing')
            ->assertExitCode(Command::SUCCESS);

        $this->assertFileExists(
            $this->configDir . '/sp-record.php',
            'a migrated (or fresh) install must still get the packaged configs published'
        );
        $this->assertFileExists(
            $this->configDir . '/records/tables/users.php',
            'a migrated (or fresh) install must still get the scaffold'
        );
    }

    /** @test */
    public function force_publishes_over_an_old_named_config_but_says_so_first(): void
    {
        $this->writeCustomizedLegacyRecordConfig();

        $this->artisan('sp-laravel-api:setup', ['--force' => true])
            ->expectsOutputToContain('--force given')
            ->assertExitCode(Command::SUCCESS);

        $this->assertFileExists(
            $this->configDir . '/sp-record.php',
            '--force is the documented explicit opt-in, so it must still publish'
        );
    }

    /**
     * The guard tells an aborted client that `--force` makes "the packaged
     * defaults win". That has to be true.
     *
     * It was not. `vendor:publish --force` wrote the full packaged
     * config/sp-record.php and then `ensureFile(..., $force)` immediately
     * overwrote it with the minimal scaffold — which carries no `autoloaded`
     * key and no RecordConfigLoader calls, silently switching off config:cache
     * baking of table configs, and which omits keys the packaged file sets
     * (`id_type` among them) so those survived from wherever they already
     * were. The result was neither the packaged defaults nor the client's own
     * config, but a hybrid of the two.
     *
     * Asserting byte-identity with the shipped file is deliberately strict: it
     * is the only assertion that cannot pass for a file the scaffold wrote.
     *
     * @test
     */
    public function force_leaves_the_published_packaged_config_intact(): void
    {
        $this->writeCustomizedLegacyRecordConfig();

        $this->artisan('sp-laravel-api:setup', ['--force' => true])
            ->assertExitCode(Command::SUCCESS);

        $onDisk = (string) file_get_contents($this->configDir . '/sp-record.php');

        // Spot checks first, so a failure reads as "the scaffold won" rather
        // than just "the files differ".
        $this->assertStringContainsString(
            "'autoloaded' => true",
            $onDisk,
            'the scaffold has no autoloaded key; finding none here means it overwrote the '
            . 'published config and config:cache silently stopped baking table configs'
        );
        $this->assertStringContainsString('RecordConfigLoader::tables(', $onDisk);
        $this->assertStringContainsString("'id_type' => 'integer'", $onDisk);

        $this->assertSame(
            $this->packagedRecordConfig(),
            $onDisk,
            "after --force, config/sp-record.php must be the package's published file, not the "
            . 'minimal scaffold written over the top of it'
        );
    }
}
