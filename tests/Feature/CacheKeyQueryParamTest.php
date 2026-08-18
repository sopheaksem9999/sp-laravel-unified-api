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
                disableCache: false,
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

    public function test_json_body_with_trashed_flag_does_not_poison_the_plain_show_cache(): void
    {
        DB::table('products')->insert([[
            'id' => 1,
            'name' => 'Widget',
            'tenant_id' => 'acme',
            'deleted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]]);

        // with_trashed arrives in the JSON body here, not the query string.
        // Request::boolean()/input() source from the JSON body instead of
        // the query string whenever Content-Type: application/json is set
        // (see Request::getInputSource()), so this request behaves like
        // ?with_trashed=true even though query() is empty for it -- the
        // same as a plain GET. The cache key must discriminate on this too.
        $this->call(
            'GET',
            '/api/products/1',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_TENANT_ID' => 'acme',
            ],
            json_encode(['with_trashed' => true])
        )
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

    /**
     * Every other test in this file asserts freshness-after-invalidation or
     * response shape -- all of them would still pass even if caching had
     * silently stopped happening at all. The only real proof of a cache HIT
     * is a STALE read: mutate the row directly in the database (bypassing
     * the package entirely, so no cache invalidation fires) and confirm the
     * next HTTP read still reflects the value from before the mutation.
     *
     * Do NOT change this assertion to expect the mutated value -- that would
     * make the test pass whether or not caching actually works, which is the
     * exact regression this test exists to catch.
     */
    public function test_a_repeat_read_is_served_from_cache(): void
    {
        DB::table('products')->insert([[
            'id' => 1,
            'name' => 'Original',
            'tenant_id' => 'acme',
            'created_at' => now(),
            'updated_at' => now(),
        ]]);

        $this->withHeaders(['X-Tenant-ID' => 'acme'])
            ->getJson('/api/products/1')
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Original');

        // Bypasses RecordService entirely -- no invalidation is triggered.
        DB::table('products')->where('id', 1)->update(['name' => 'Mutated']);

        // Deliberately asserting the STALE value: this is the evidence the
        // second request was served from cache rather than re-querying.
        $this->withHeaders(['X-Tenant-ID' => 'acme'])
            ->getJson('/api/products/1')
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Original');
    }

    /**
     * Same proof as test_a_repeat_read_is_served_from_cache, for the list
     * path (generateOptimizedCacheKey rather than generateRecordCacheKey).
     * See that test's docblock for why the stale assertion is intentional.
     */
    public function test_a_repeat_list_read_is_served_from_cache(): void
    {
        DB::table('products')->insert([
            ['id' => 1, 'name' => 'Original', 'tenant_id' => 'acme', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->withHeaders(['X-Tenant-ID' => 'acme'])
            ->getJson('/api/products')
            ->assertStatus(200)
            ->assertJsonPath('data.0.name', 'Original');

        // Bypasses RecordService entirely -- no invalidation is triggered.
        DB::table('products')->where('id', 1)->update(['name' => 'Mutated']);

        // Deliberately asserting the STALE value: proof the second list read
        // came from cache, not the database.
        $this->withHeaders(['X-Tenant-ID' => 'acme'])
            ->getJson('/api/products')
            ->assertStatus(200)
            ->assertJsonPath('data.0.name', 'Original');
    }
}
