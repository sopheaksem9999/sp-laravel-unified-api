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
                "{$published} must mirror {$canonical}"
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
}
