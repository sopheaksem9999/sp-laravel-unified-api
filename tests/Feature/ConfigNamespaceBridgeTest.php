<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Config\Repository;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use ReflectionProperty;
use Sopheak\Core\Config\ConfigNamespaceBridge;
use Sopheak\Core\CoreSpLaravelApiProvider;
use Sopheak\Core\Tests\TestCase;

class ConfigNamespaceBridgeTest extends TestCase
{
    public function test_audit_filter_follows_existing_namespace_precedence(): void
    {
        $config = new Repository(['audit' => ['filter' => ['LegacyFilter', 'decide']], 'sp-audit' => ['filter' => null]]);
        ConfigNamespaceBridge::adopt($config);
        $this->assertNull($config->get('audit.filter'));
        $config->set('sp-audit.filter', ['PublishedFilter', 'decide']);
        ConfigNamespaceBridge::adopt($config);
        ConfigNamespaceBridge::mirror($config);
        $this->assertSame(['PublishedFilter', 'decide'], $config->get('audit.filter'));
        $this->assertSame($config->get('audit.filter'), $config->get('sp-audit.filter'));
    }

    /** @test */
    public function adopt_folds_a_published_new_file_into_the_canonical_namespace(): void
    {
        $config = new Repository(['sp-record' => ['id_type' => 'uuid']]);

        ConfigNamespaceBridge::adopt($config);

        $this->assertSame('uuid', $config->get('record.id_type'));
    }

    /** @test */
    public function adopt_lets_the_new_file_win_over_the_old_one(): void
    {
        $config = new Repository([
            'record' => ['id_type' => 'integer', 'api_prefix' => 'api/v1'],
            'sp-record' => ['id_type' => 'uuid'],
        ]);

        ConfigNamespaceBridge::adopt($config);

        $this->assertSame('uuid', $config->get('record.id_type'));
        $this->assertSame(
            'api/v1',
            $config->get('record.api_prefix'),
            'keys absent from the new file must survive from the old one'
        );
    }

    /** @test */
    public function adopt_leaves_the_canonical_namespace_alone_when_no_new_file_exists(): void
    {
        $config = new Repository(['record' => ['id_type' => 'integer']]);

        ConfigNamespaceBridge::adopt($config);

        $this->assertSame(['id_type' => 'integer'], $config->get('record'));
    }

    /** @test */
    public function adopt_merges_nested_keys_rather_than_replacing_them(): void
    {
        $config = new Repository([
            'record' => ['cache' => ['enabled' => false, 'ttl' => 3600]],
            'sp-record' => ['cache' => ['enabled' => true]],
        ]);

        ConfigNamespaceBridge::adopt($config);

        $this->assertTrue($config->get('record.cache.enabled'));
        $this->assertSame(3600, $config->get('record.cache.ttl'));
    }

    /** @test */
    public function mirror_copies_the_resolved_canonical_namespace_to_the_new_name(): void
    {
        $config = new Repository(['record' => ['id_type' => 'uuid', 'tables' => ['a' => 1]]]);

        ConfigNamespaceBridge::mirror($config);

        $this->assertSame('uuid', $config->get('sp-record.id_type'));
        $this->assertSame(['a' => 1], $config->get('sp-record.tables'));
    }

    /** @test */
    public function mirror_covers_every_renamed_namespace(): void
    {
        $config = new Repository();
        foreach (ConfigNamespaceBridge::RENAMES as $canonical => $published) {
            $config->set($canonical, ['marker' => $canonical]);
        }

        ConfigNamespaceBridge::mirror($config);

        foreach (ConfigNamespaceBridge::RENAMES as $canonical => $published) {
            $this->assertSame(
                $canonical,
                $config->get($published . '.marker'),
                sprintf('%s must mirror %s', $published, $canonical)
            );
        }
    }

    /** @test */
    public function the_rename_map_covers_exactly_the_five_unprefixed_configs(): void
    {
        $this->assertSame(
            [
                'record' => 'sp-record',
                'permissions' => 'sp-permissions',
                'audit' => 'sp-audit',
                'attachments' => 'sp-attachments',
                'webhooks' => 'sp-webhooks',
            ],
            ConfigNamespaceBridge::RENAMES
        );
    }

    /** @test */
    public function the_canonical_namespace_still_carries_package_defaults(): void
    {
        $this->assertIsArray(config('attachments.tables'));
        $this->assertNotNull(config('attachments.disk_public'));
    }

    /** @test */
    public function the_published_name_mirrors_the_canonical_one_after_boot(): void
    {
        $this->assertSame(config('record.api_prefix'), config('sp-record.api_prefix'));
        $this->assertSame(config('audit.enabled'), config('sp-audit.enabled'));
    }

    /** @test */
    public function a_value_set_in_environment_setup_reaches_the_mirrored_name(): void
    {
        // record.api_prefix is set to 'api' by TestCase::getEnvironmentSetUp,
        // which runs after register() and before boot(). Seeing it on the
        // mirrored name proves Pass B runs in boot(), not register().
        $this->assertSame('api', config('sp-record.api_prefix'));
    }

    /** @test */
    public function no_notice_is_emitted_when_no_old_named_file_exists(): void
    {
        // A directory with nothing in it stands in for the common case: a client
        // with nothing to migrate gets no log noise.
        $directory = $this->makeTemporaryConfigDirectory();

        try {
            $this->assertSame([], ConfigNamespaceBridge::deprecatedFiles($directory));
        } finally {
            $this->removeTemporaryConfigDirectory($directory);
        }
    }

    /** @test */
    public function deprecated_files_reports_old_names_present_on_disk(): void
    {
        // Scans a directory this test owns rather than config_path(), which
        // under Testbench resolves inside vendor/ — unversioned, wiped by
        // composer install, and littered if the run aborts mid-test.
        $directory = $this->makeTemporaryConfigDirectory();

        try {
            file_put_contents($directory . '/record.php', '<?php return [];');

            $this->assertSame(
                ['record.php' => 'sp-record.php'],
                ConfigNamespaceBridge::deprecatedFiles($directory)
            );
        } finally {
            $this->removeTemporaryConfigDirectory($directory);
        }
    }

    /** @test */
    public function deprecated_files_defaults_to_the_application_config_path(): void
    {
        // The no-argument branch is the only one production uses. Exercised by
        // pointing the application's config path at a directory this test owns,
        // so nothing is written into Testbench's config dir inside vendor/.
        $directory = $this->makeTemporaryConfigDirectory();
        $original = $this->app->configPath();

        try {
            file_put_contents($directory . '/audit.php', '<?php return [];');
            $this->app->useConfigPath($directory);

            $this->assertSame(['audit.php' => 'sp-audit.php'], ConfigNamespaceBridge::deprecatedFiles());
        } finally {
            $this->app->useConfigPath($original);
            $this->removeTemporaryConfigDirectory($directory);
        }
    }

    /** @test */
    public function the_deprecation_notice_names_each_old_file_still_on_disk(): void
    {
        $logged = $this->captureDeprecationNotices(['record.php', 'webhooks.php']);

        $this->assertCount(2, $logged);
        $this->assertStringContainsString('config/record.php', $logged[0]);
        $this->assertStringContainsString('config/sp-record.php', $logged[0]);
        $this->assertStringContainsString('config/webhooks.php', $logged[1]);
        $this->assertStringContainsString('config/sp-webhooks.php', $logged[1]);
    }

    /**
     * "It keeps working" is only true while the old file is alone. Once the
     * sp-* counterpart exists too — the state `sp-laravel-api:setup` used to
     * create on every run — adopt() gives the sp-* file precedence for every
     * key both files set, so the old file has stopped being fully in effect.
     * Repeating the reassuring text there would be a false promise, which is
     * worse than no notice at all.
     *
     * @test
     */
    public function the_notice_stops_promising_the_old_file_works_once_it_is_superseded(): void
    {
        $logged = $this->captureDeprecationNotices(
            ['record.php', 'webhooks.php'],
            newNamedFiles: ['sp-record.php']
        );

        $this->assertCount(2, $logged);

        $this->assertStringNotContainsString('It keeps working', $logged[0]);
        $this->assertStringContainsString('no longer fully in effect', $logged[0]);
        $this->assertStringContainsString('config/sp-record.php', $logged[0]);

        // webhooks.php has no sp-* counterpart on disk, so it keeps the
        // original reassurance — proving the branch is per file, not global.
        $this->assertStringContainsString('It keeps working', $logged[1]);
        $this->assertStringNotContainsString('no longer fully in effect', $logged[1]);
    }

    /** @test */
    public function the_deprecation_notice_is_silenced_by_the_suppression_flag(): void
    {
        // The sole escape hatch for a client who cannot rename yet.
        config()->set('sp-laravel-api.suppress_config_rename_notice', true);

        $this->assertSame([], $this->captureDeprecationNotices(['record.php']));
    }

    /** @test */
    public function the_deprecation_notice_is_not_emitted_outside_the_console(): void
    {
        // PHP-FPM is shared-nothing: it boots the application on every request,
        // so without this gate an unmigrated client with all five old files
        // would get five log lines per HTTP request. The notice is for someone
        // running artisan, not for request serving.
        $this->assertSame(
            [],
            $this->captureDeprecationNotices(['record.php', 'audit.php'], runningInConsole: false)
        );
    }

    /**
     * Run the provider's notice against a temp config directory seeded with the
     * given old-named files, and return the messages it logged.
     *
     * @param  string[]  $oldNamedFiles
     * @param  string[]  $newNamedFiles  sp-* files to seed alongside them
     * @return string[]
     */
    private function captureDeprecationNotices(array $oldNamedFiles, bool $runningInConsole = true, array $newNamedFiles = []): array
    {
        $directory = $this->makeTemporaryConfigDirectory();
        $original = $this->app->configPath();
        $originalConsole = $this->consoleFlag();
        $logged = [];

        Log::listen(function (MessageLogged $message) use (&$logged): void {
            $logged[] = $message->message;
        });

        try {
            foreach ([...$oldNamedFiles, ...$newNamedFiles] as $file) {
                file_put_contents($directory . '/' . $file, '<?php return [];');
            }

            $this->app->useConfigPath($directory);
            $this->setConsoleFlag($runningInConsole);

            (new CoreSpLaravelApiProvider($this->app))->reportDeprecatedConfigFiles();
        } finally {
            $this->setConsoleFlag($originalConsole);
            $this->app->useConfigPath($original);
            $this->removeTemporaryConfigDirectory($directory);
        }

        return $logged;
    }

    /**
     * Application::runningInConsole() memoizes into a protected property, and
     * boot() has already populated it by the time a test runs, so the env var
     * it reads is no longer consulted. Reflection is the only way to flip it.
     */
    private function consoleFlag(): ?bool
    {
        $property = new ReflectionProperty($this->app, 'isRunningInConsole');

        /** @var bool|null $value */
        $value = $property->getValue($this->app);

        return $value;
    }

    private function setConsoleFlag(?bool $value): void
    {
        $property = new ReflectionProperty($this->app, 'isRunningInConsole');
        $property->setValue($this->app, $value);
    }

    private function makeTemporaryConfigDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/sp-config-bridge-' . bin2hex(random_bytes(8));
        mkdir($directory, 0777, true);

        return $directory;
    }

    private function removeTemporaryConfigDirectory(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
}
