<?php

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Services\QueryCacheService;
use Sopheak\Core\Services\RecordCacheService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class ListCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('tenant_id')->nullable();
            $table->timestamps();
        });

        Config::set('record.enable_tenant_id', true);
        Config::set('record.tenant_column', 'tenant_id');
        Config::set('record.tenant_header', 'X-Tenant-ID');
        Config::set('record.cache.enabled', true);
        Config::set('record.cache.prefix', 'sp_laravel_api');
        Config::set('record.cache.ttl', 3600);
        Config::set('record.cache.per_table', ['products' => true]);
        Config::set('record.tables', [
            'products' => new RecordTableType(
                table: 'products',
                pmsName: 'products',
                hasTenantId: true,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
            ),
        ]);

        SchemaRegistryUtils::refresh();
        Cache::flush();
    }

    /**
     * Scenario A: list via HTTP → cache populated → manual clearTableCache → next list should be fresh
     */
    public function test_list_cache_is_invalidated_by_manual_clear(): void
    {
        DB::table('products')->insert([
            ['id' => 1, 'name' => 'Widget', 'tenant_id' => 'acme', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $response1 = $this->withHeaders(['X-Tenant-ID' => 'acme'])->getJson('/api/products');
        $response1->assertStatus(200);
        $response1->assertJsonPath('data.0.name', 'Widget');

        // Simulate an external edit on a record in tenant acme
        DB::table('products')->where('id', 1)->update(['name' => 'Updated Widget']);

        // Custom endpoint manually clears the products cache
        app(RecordCacheService::class)->clearTableCache('products', 'acme');

        // Next list should reflect the updated name
        $response2 = $this->withHeaders(['X-Tenant-ID' => 'acme'])->getJson('/api/products');
        $response2->assertStatus(200);
        $response2->assertJsonPath('data.0.name', 'Updated Widget');
    }

    /**
     * Scenario B: list via HTTP → cache populated → dispatch RecordUpdated event → next list should be fresh
     */
    public function test_list_cache_is_invalidated_by_record_updated_event(): void
    {
        DB::table('products')->insert([
            ['id' => 1, 'name' => 'Widget', 'tenant_id' => 'acme', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $response1 = $this->withHeaders(['X-Tenant-ID' => 'acme'])->getJson('/api/products');
        $response1->assertStatus(200);
        $response1->assertJsonPath('data.0.name', 'Widget');

        // Simulate an external edit
        DB::table('products')->where('id', 1)->update(['name' => 'Updated Widget']);

        // Dispatch the event the same way the package would after an internal update
        \Sopheak\Core\Events\RecordUpdated::dispatch(
            'products',
            ['id' => 1, 'name' => 'Widget', 'tenant_id' => 'acme'],
            ['id' => 1, 'name' => 'Updated Widget', 'tenant_id' => 'acme'],
            1
        );

        $response2 = $this->withHeaders(['X-Tenant-ID' => 'acme'])->getJson('/api/products');
        $response2->assertStatus(200);
        $response2->assertJsonPath('data.0.name', 'Updated Widget');
    }

    /**
     * Scenario C: list → cache populated → user calls executeUpdate via service → next list should be fresh
     * (This mirrors what the package's own update endpoint does.)
     */
    public function test_list_cache_is_invalidated_after_execute_update(): void
    {
        DB::table('products')->insert([
            ['id' => 1, 'name' => 'Widget', 'tenant_id' => 'acme', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $response1 = $this->withHeaders(['X-Tenant-ID' => 'acme'])->getJson('/api/products');
        $response1->assertStatus(200);
        $response1->assertJsonPath('data.0.name', 'Widget');

        $this->putJson('/api/products/1', ['name' => 'Updated Widget'], ['X-Tenant-ID' => 'acme'])
            ->assertStatus(200);

        $response2 = $this->withHeaders(['X-Tenant-ID' => 'acme'])->getJson('/api/products');
        $response2->assertStatus(200);
        $response2->assertJsonPath('data.0.name', 'Updated Widget');
    }
}
