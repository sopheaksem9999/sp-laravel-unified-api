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
 * These tests run the real command. The application's config path and the
 * working directory are both redirected into scratch directories, because the
 * command publishes to `config_path()` and scaffolds to paths relative to the
 * process working directory.
 */
class SetupPackageLegacyConfigGuardTest extends TestCase
{
    private string $configDir;

    private string $workDir;

    private string $originalCwd;

    /** The exact bytes of the customized old-named config used by the tests. */
    private const CUSTOMIZED_LEGACY_RECORD_CONFIG
        = "<?php\n\nreturn [\n    'api_prefix' => 'api/v9',\n    'id_type' => 'uuid',\n];\n";

    protected function setUp(): void
    {
        $this->configDir = sys_get_temp_dir() . '/sp_setup_guard_config_' . uniqid('', true);
        $this->workDir = sys_get_temp_dir() . '/sp_setup_guard_work_' . uniqid('', true);

        // Plain mkdir, not the File facade: these directories must exist before
        // parent::setUp() so getEnvironmentSetUp() can point config_path() at
        // one of them, and no facade root exists that early.
        mkdir($this->configDir, 0o755, true);
        mkdir($this->workDir . '/config', 0o755, true);

        parent::setUp();

        $this->originalCwd = (string) getcwd();
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);

        File::deleteDirectory($this->configDir);
        File::deleteDirectory($this->workDir);

        parent::tearDown();
    }

    /**
     * Runs before the package provider boots, so `config_path()` — including
     * the one baked into the provider's `publishes()` map — resolves into the
     * scratch directory rather than the real application config directory.
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

    /** @test */
    public function setup_refuses_to_publish_sp_names_over_a_still_present_old_named_config(): void
    {
        $this->writeCustomizedLegacyRecordConfig();

        chdir($this->workDir);

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
        $this->assertFileDoesNotExist(
            $this->workDir . '/config/sp-record.php',
            'setup must not scaffold an sp-record.php either — the scaffolded defaults shadow the '
            . 'old-named file exactly the same way the published ones do'
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
        chdir($this->workDir);

        $this->artisan('sp-laravel-api:setup')
            ->doesntExpectOutputToContain('Aborted without publishing')
            ->assertExitCode(Command::SUCCESS);

        $this->assertFileExists(
            $this->configDir . '/sp-record.php',
            'a migrated (or fresh) install must still get the packaged configs published'
        );
        $this->assertFileExists(
            $this->workDir . '/config/records/tables/users.php',
            'a migrated (or fresh) install must still get the scaffold'
        );
    }

    /** @test */
    public function force_publishes_over_an_old_named_config_but_says_so_first(): void
    {
        $this->writeCustomizedLegacyRecordConfig();

        chdir($this->workDir);

        $this->artisan('sp-laravel-api:setup', ['--force' => true])
            ->expectsOutputToContain('--force given')
            ->assertExitCode(Command::SUCCESS);

        $this->assertFileExists(
            $this->configDir . '/sp-record.php',
            '--force is the documented explicit opt-in, so it must still publish'
        );
    }
}
