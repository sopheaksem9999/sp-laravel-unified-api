<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Services\QueryCacheService;
use Sopheak\Core\Services\RecordCacheService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class CacheInvalidationCorrectnessTest extends TestCase
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

    public function test_table_clear_invalidates_cursor_paginated_list_cache(): void
    {
        $cursorKey = 'record_cursor:table:products:tenant:acme:hash:abc';

        QueryCacheService::put($cursorKey, ['data' => ['stale']], 3600);
        $this->assertNotNull(QueryCacheService::get($cursorKey));

        QueryCacheService::invalidateTableForTenant('products', 'acme');

        $this->assertNull(
            QueryCacheService::get($cursorKey),
            'A table-level clear must reach record_cursor: keys'
        );
    }

    public function test_a_second_write_in_one_request_invalidates_a_read_cached_between_them(): void
    {
        $listKey = 'record_index:table:products:tenant:acme:hash:abc';

        // write #1
        QueryCacheService::invalidateTableForTenant('products', 'acme');
        // a read caches the state as of write #1
        QueryCacheService::put($listKey, ['data' => ['A only']], 3600);
        // write #2 must invalidate what that read just cached
        QueryCacheService::invalidateTableForTenant('products', 'acme');

        $this->assertNull(
            QueryCacheService::get($listKey),
            'The second invalidation in a request must not be deduped away'
        );
    }

    public function test_repeated_bumps_each_advance_the_namespace_version(): void
    {
        QueryCacheService::invalidateTableForTenant('products', 'acme');
        $first = QueryCacheService::inspectNamespaceVersion('table', 'products', 'acme');

        QueryCacheService::invalidateTableForTenant('products', 'acme');
        $second = QueryCacheService::inspectNamespaceVersion('table', 'products', 'acme');

        QueryCacheService::invalidateTableForTenant('products', 'acme');
        $third = QueryCacheService::inspectNamespaceVersion('table', 'products', 'acme');

        $this->assertSame(2, $first);
        $this->assertSame(3, $second);
        $this->assertSame(4, $third);
    }

    public function test_table_clear_invalidates_single_record_show_cache(): void
    {
        $showKey = 'record_show:table:products:id:5:tenant:acme:select:abc';

        QueryCacheService::put($showKey, ['id' => 5, 'name' => 'stale'], 3600);
        $this->assertNotNull(QueryCacheService::get($showKey));

        app(RecordCacheService::class)->clearTableCache('products', 'acme');

        $this->assertNull(
            QueryCacheService::get($showKey),
            'clearTableCache() must reach record_show: keys for that table'
        );
    }

    public function test_record_clear_still_only_affects_that_record(): void
    {
        $keyFive = 'record_show:table:products:id:5:tenant:acme:select:abc';
        $keySeven = 'record_show:table:products:id:7:tenant:acme:select:abc';

        QueryCacheService::put($keyFive, ['id' => 5], 3600);
        QueryCacheService::put($keySeven, ['id' => 7], 3600);

        QueryCacheService::invalidateRecordForTenant('products', 5, 'acme');

        $this->assertNull(QueryCacheService::get($keyFive));
        $this->assertNotNull(
            QueryCacheService::get($keySeven),
            'Per-record precision must survive the table-version cascade'
        );
    }

    public function test_table_clear_for_one_tenant_leaves_another_tenants_record_cache(): void
    {
        $acme = 'record_show:table:products:id:5:tenant:acme:select:abc';
        $globex = 'record_show:table:products:id:5:tenant:globex:select:abc';

        QueryCacheService::put($acme, ['tenant' => 'acme'], 3600);
        QueryCacheService::put($globex, ['tenant' => 'globex'], 3600);

        app(RecordCacheService::class)->clearTableCache('products', 'acme');

        $this->assertNull(QueryCacheService::get($acme));
        $this->assertNotNull(
            QueryCacheService::get($globex),
            'Tenant isolation must survive the table-version cascade'
        );
    }
}
