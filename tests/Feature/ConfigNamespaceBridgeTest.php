<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Config\Repository;
use Sopheak\Core\Config\ConfigNamespaceBridge;
use Sopheak\Core\Tests\TestCase;

class ConfigNamespaceBridgeTest extends TestCase
{
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
