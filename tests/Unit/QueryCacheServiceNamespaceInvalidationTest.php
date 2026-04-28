<?php

namespace Sopheak\Core\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Sopheak\Core\Services\QueryCacheService;
use Sopheak\Core\Tests\TestCase;

class QueryCacheServiceNamespaceInvalidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('record.cache.enabled', true);
        Config::set('record.cache.prefix', 'sp_laravel_api');
        Config::set('record.cache.ttl', 3600);

        Cache::flush();
    }

    public function test_it_invalidates_only_the_target_tenant_for_table_cache(): void
    {
        $keyTenant1 = 'record_index:table:users:tenant:tenant-1:hash:abc';
        $keyTenant2 = 'record_index:table:users:tenant:tenant-2:hash:abc';

        QueryCacheService::put($keyTenant1, 't1', 3600);
        QueryCacheService::put($keyTenant2, 't2', 3600);

        $this->assertSame('t1', QueryCacheService::get($keyTenant1));
        $this->assertSame('t2', QueryCacheService::get($keyTenant2));

        QueryCacheService::invalidateTableForTenant('users', 'tenant-1');

        $this->assertNull(QueryCacheService::get($keyTenant1));
        $this->assertSame('t2', QueryCacheService::get($keyTenant2));
    }

    public function test_it_invalidates_all_tenants_when_table_global_namespace_is_bumped(): void
    {
        $keyTenant1 = 'record_index:table:users:tenant:tenant-1:hash:abc';
        $keyTenant2 = 'record_index:table:users:tenant:tenant-2:hash:abc';

        QueryCacheService::put($keyTenant1, 't1', 3600);
        QueryCacheService::put($keyTenant2, 't2', 3600);

        $this->assertSame('t1', QueryCacheService::get($keyTenant1));
        $this->assertSame('t2', QueryCacheService::get($keyTenant2));

        QueryCacheService::invalidateTable('users');

        $this->assertNull(QueryCacheService::get($keyTenant1));
        $this->assertNull(QueryCacheService::get($keyTenant2));
    }

    public function test_it_invalidates_global_function_cache_for_specific_tenant(): void
    {
        $keyTenant1 = 'record_func_global:function:stats:tenant:tenant-1:hash:abc';
        $keyTenant2 = 'record_func_global:function:stats:tenant:tenant-2:hash:abc';

        QueryCacheService::put($keyTenant1, 't1', 3600);
        QueryCacheService::put($keyTenant2, 't2', 3600);

        $this->assertSame('t1', QueryCacheService::get($keyTenant1));
        $this->assertSame('t2', QueryCacheService::get($keyTenant2));

        QueryCacheService::invalidateGlobalFunctionForTenant('stats', 'tenant-1');

        $this->assertNull(QueryCacheService::get($keyTenant1));
        $this->assertSame('t2', QueryCacheService::get($keyTenant2));
    }
}

