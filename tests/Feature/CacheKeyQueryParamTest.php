<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class CacheKeyQueryParamTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('tenant_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Config::set('record.enable_tenant_id', true);
        Config::set('record.tenant_column', 'tenant_id');
        Config::set('record.tenant_header', 'X-Tenant-ID');
        Config::set('record.cache.enabled', true);
        Config::set('record.cache.prefix', 'sp_laravel_api');
        Config::set('record.cache.ttl', 3600);
        Config::set('record.tables', [
            'products' => new RecordTableType(
                table: 'products',
                pmsName: 'products',
                hasTenantId: true,
                softDeletes: true,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
            ),
        ]);

        SchemaRegistryUtils::refresh();
        Cache::flush();
    }

    public function test_with_trashed_show_request_does_not_poison_the_plain_show_cache(): void
    {
        DB::table('products')->insert([[
            'id' => 1,
            'name' => 'Widget',
            'tenant_id' => 'acme',
            'deleted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]]);

        $this->withHeaders(['X-Tenant-ID' => 'acme'])
            ->getJson('/api/products/1?with_trashed=true')
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Widget');

        $this->withHeaders(['X-Tenant-ID' => 'acme'])
            ->getJson('/api/products/1')
            ->assertStatus(404);
    }

    public function test_plain_show_request_does_not_poison_the_with_trashed_cache(): void
    {
        DB::table('products')->insert([[
            'id' => 1,
            'name' => 'Widget',
            'tenant_id' => 'acme',
            'deleted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]]);

        $this->withHeaders(['X-Tenant-ID' => 'acme'])
            ->getJson('/api/products/1')
            ->assertStatus(404);

        $this->withHeaders(['X-Tenant-ID' => 'acme'])
            ->getJson('/api/products/1?with_trashed=true')
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Widget');
    }

    public function test_add_total_list_request_does_not_share_a_cache_entry_with_the_plain_list(): void
    {
        DB::table('products')->insert([
            ['id' => 1, 'name' => 'A', 'tenant_id' => 'acme', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'B', 'tenant_id' => 'acme', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $withTotal = $this->withHeaders(['X-Tenant-ID' => 'acme'])
            ->getJson('/api/products?limit=2&add_total=true');
        $withTotal->assertStatus(200);
        $this->assertSame(2, $withTotal->json('meta.total'));

        $plain = $this->withHeaders(['X-Tenant-ID' => 'acme'])
            ->getJson('/api/products?limit=2');
        $plain->assertStatus(200);
        $this->assertNull(
            $plain->json('meta.total'),
            'A plain list must not inherit meta.total from an add_total request'
        );
    }
}
