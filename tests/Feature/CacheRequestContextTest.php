<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Services\QueryCacheService;
use Sopheak\Core\Support\CacheRequestContext;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class CacheRequestContextTest extends TestCase
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

    public function test_the_context_is_resolvable_and_starts_empty(): void
    {
        $context = app(CacheRequestContext::class);

        $this->assertNull($context->namespaceVersion('anything'));
        $this->assertSame([], $context->stats());
    }

    public function test_reset_clears_memoized_versions_and_stats(): void
    {
        $context = app(CacheRequestContext::class);
        $context->rememberNamespaceVersion('ns:table:products', 7);
        $context->incrementStat('cache_hits');

        $this->assertSame(7, $context->namespaceVersion('ns:table:products'));

        $context->reset();

        $this->assertNull($context->namespaceVersion('ns:table:products'));
        $this->assertSame([], $context->stats());
    }

    public function test_a_memoized_version_does_not_leak_across_http_requests(): void
    {
        DB::table('products')->insert([
            ['id' => 1, 'name' => 'Widget', 'tenant_id' => 'acme', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->withHeaders(['X-Tenant-ID' => 'acme'])->getJson('/api/products')->assertStatus(200);

        // An out-of-band write, exactly as another process would do it.
        DB::table('products')->where('id', 1)->update(['name' => 'Updated']);

        // Simulate ANOTHER PROCESS bumping the namespace: write the store directly and
        // never touch this process's memo. Going through invalidateTableForTenant() would
        // call memoizeNamespaceVersion() and hand this process the new version for free,
        // which is exactly the leak this test exists to detect.
        $namespaceKey = 'sp_laravel_api:ns:table:products:tenant:acme';
        Cache::add($namespaceKey, 1, 315360000);
        Cache::increment($namespaceKey);

        $this->withHeaders(['X-Tenant-ID' => 'acme'])
            ->getJson('/api/products')
            ->assertStatus(200)
            ->assertJsonPath('data.0.name', 'Updated');
    }

    public function test_request_stats_keep_their_public_shape(): void
    {
        $stats = QueryCacheService::requestStats();

        foreach ([
            'cache_hits',
            'cache_misses',
            'cache_puts',
            'namespace_reads',
            'namespace_memo_hits',
            'invalidation_bumps',
            'invalidation_dedupe_hits',
        ] as $key) {
            $this->assertArrayHasKey($key, $stats);
            $this->assertIsInt($stats[$key]);
        }
    }
}
