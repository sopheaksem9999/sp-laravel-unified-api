<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Support\Facades\File;
use Sopheak\Core\Tests\TestCase;

class ValidateSetupCommandTest extends TestCase
{
    /**
     * Scratch config directory for the filename tests, or null to leave the
     * application's real config path alone (the two legacy smoke tests below).
     */
    private ?string $configDir = null;

    protected function tearDown(): void
    {
        if ($this->configDir !== null) {
            File::deleteDirectory($this->configDir);
            $this->configDir = null;
        }

        parent::tearDown();
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        if ($this->configDir !== null) {
            $app->useConfigPath($this->configDir);
        }
    }

    /**
     * Point config_path() at a scratch directory holding exactly $filenames,
     * each a valid non-empty config file, and rebuild the application so the
     * override takes effect.
     *
     * @param string[] $filenames
     */
    private function useScratchConfigDirectory(array $filenames): void
    {
        $directory = sys_get_temp_dir() . '/sp_validate_config_' . uniqid('', true);
        mkdir($directory, 0o755, true);

        foreach ($filenames as $filename) {
            file_put_contents($directory . '/' . $filename, "<?php\n\nreturn ['enabled' => true];\n");
        }

        $this->configDir = $directory;
        $this->refreshApplication();
    }

    /** @return string[] */
    private function migratedConfigFilenames(): array
    {
        return [
            'sp-record.php',
            'sp-audit.php',
            'sp-attachments.php',
            'sp-webhooks.php',
            'sp-permissions.php',
            'sp-laravel-api.php',
        ];
    }

    /** @return string[] */
    private function legacyConfigFilenames(): array
    {
        return [
            'record.php',
            'audit.php',
            'attachments.php',
            'webhooks.php',
            'permissions.php',
            'sp-laravel-api.php',
        ];
    }

    /**
     * The upgrade guide's step 3 tells the reader to run this command to
     * confirm the rename worked. It used to check only the old filenames, so a
     * correctly-migrated install got four "Missing config file" errors and a
     * non-zero exit — and `--fix` then called `sp-laravel-api:setup`, which
     * could never converge because the files it wanted were already there
     * under their new names.
     *
     * Exit code is deliberately not asserted: this scratch install has no
     * database tables, no record directories and no rate limiters, so the
     * command legitimately still reports errors. What is pinned is that none
     * of them are about the config filenames.
     *
     * @test
     */
    public function a_migrated_install_reports_no_missing_config_files(): void
    {
        $this->useScratchConfigDirectory($this->migratedConfigFilenames());

        $this->artisan('sp-laravel-api:validate')
            ->expectsOutputToContain('Config file exists: sp-record.php')
            ->expectsOutputToContain('Config file exists: sp-permissions.php')
            ->doesntExpectOutputToContain('Missing config file')
            ->run();
    }

    /**
     * The other half of the same promise: an old-named install still works, so
     * it must still validate clean — with a warning pointing at the rename,
     * not an error.
     *
     * @test
     */
    public function an_unmigrated_install_validates_with_a_rename_warning(): void
    {
        $this->useScratchConfigDirectory($this->legacyConfigFilenames());

        $this->artisan('sp-laravel-api:validate')
            ->expectsOutputToContain('Config file exists: record.php')
            ->expectsOutputToContain('rename it to config/sp-record.php')
            ->doesntExpectOutputToContain('Missing config file')
            ->run();
    }

    /**
     * Proves the two tests above are not vacuous: with neither spelling on
     * disk the check does report the file as missing, under its new name.
     *
     * @test
     */
    public function an_install_with_neither_filename_reports_the_file_missing(): void
    {
        $this->useScratchConfigDirectory(['sp-laravel-api.php']);

        $this->artisan('sp-laravel-api:validate')
            ->expectsOutputToContain('Missing config file: sp-record.php')
            ->expectsOutputToContain('Missing config file: sp-permissions.php')
            ->assertExitCode(1);
    }

    public function test_it_runs_validate_command_without_exceptions(): void
    {
        // The command will check configuration files, directories, DB, etc.
        // It might return 1 if there are missing tables (since this is a test env),
        // but it should output the validation headers correctly.
        $this->artisan('sp-laravel-api:validate')
            ->expectsOutputToContain('Validating SP Laravel API Setup...')
            ->expectsOutputToContain('Checking Configuration Files...')
            ->expectsOutputToContain('Checking Database Connection...')
            ->expectsOutputToContain('Checking SchemaRegistryUtils...')
            ->assertExitCode(1); // Usually fails in test environment because real config files aren't published
    }

    public function test_it_runs_validate_command_with_verbose_flag(): void
    {
        $this->artisan('sp-laravel-api:validate', ['--verbose' => true])
            ->expectsOutputToContain('Validating SP Laravel API Setup...')
            ->assertExitCode(1);
    }
}
