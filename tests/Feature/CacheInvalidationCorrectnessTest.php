<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Services\QueryCacheService;
use Sopheak\Core\Services\RecordCacheService;
use Sopheak\Core\Services\RecordService;
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

    public function test_a_dependent_cache_entry_is_invalidated_by_a_table_clear(): void
    {
        $globalKey = 'record_func_global:function:sales_report:tenant:acme:hash:abc';
        $dependencies = [['scope' => 'table', 'name' => 'products', 'tenant' => 'acme']];

        QueryCacheService::put($globalKey, ['data' => ['total' => 1], 'status' => 200], 3600, $dependencies);
        $this->assertNotNull(QueryCacheService::get($globalKey, $dependencies));

        app(RecordCacheService::class)->clearCacheForTables(['products'], 'acme');

        $this->assertNull(
            QueryCacheService::get($globalKey, $dependencies),
            'A cache entry depending on products must die when products is cleared'
        );
    }

    public function test_a_dependent_cache_entry_survives_an_unrelated_table_clear(): void
    {
        $globalKey = 'record_func_global:function:sales_report:tenant:acme:hash:abc';
        $dependencies = [['scope' => 'table', 'name' => 'products', 'tenant' => 'acme']];

        QueryCacheService::put($globalKey, ['data' => ['total' => 1], 'status' => 200], 3600, $dependencies);

        QueryCacheService::invalidateTableForTenant('orders', 'acme');

        $this->assertNotNull(
            QueryCacheService::get($globalKey, $dependencies),
            'An unrelated table clear must not invalidate the entry'
        );
    }

    public function test_dependencies_are_order_independent(): void
    {
        $key = 'record_func_global:function:sales_report:tenant:acme:hash:abc';
        $forward = [
            ['scope' => 'table', 'name' => 'products', 'tenant' => 'acme'],
            ['scope' => 'table', 'name' => 'orders', 'tenant' => 'acme'],
        ];
        $reversed = array_reverse($forward);

        QueryCacheService::put($key, 'cached', 3600, $forward);

        $this->assertSame(
            'cached',
            QueryCacheService::get($key, $reversed),
            'Declaring the same dependencies in a different order must hit the same entry'
        );
    }

    /**
     * End-to-end proof for the RecordService wiring itself.
     *
     * Every test above hand-builds the dependency array and calls QueryCacheService
     * directly -- deleting $cacheDependencies from both get() and put(), in both
     * executeTableFunction() and executeGlobalFunction(), left every one of them
     * green, because nothing exercised the real wiring. These four drive
     * RecordService::executeGlobalFunction() itself, through a function that
     * declares clearCacheTables: ['products'], invalidated by a genuine CRUD
     * write via createRecord() -- the same write path a real request takes.
     */
    public function test_end_to_end_global_function_cache_busts_on_write_when_tenancy_is_off(): void
    {
        SalesReportDependencyCounter::$count = 0;

        Config::set('record.enable_tenant_id', false);
        Config::set('record.tables', [
            'products' => new RecordTableType(
                table: 'products',
                pmsName: 'products',
                hasTenantId: false,
                softDeletes: true,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
            ),
        ]);
        SchemaRegistryUtils::refresh();
        Config::set('record.global_functions', [
            'sales_report' => [
                'httpMethod' => ['GET'],
                'class' => SalesReportDependencyCounter::class,
                'functionName' => 'handle',
                'disableCache' => false,
                'clearCacheTables' => ['products'],
            ],
        ]);

        $service = new RecordService();
        $request = Request::create('/api/v1/rpc/sales_report', 'GET', ['foo' => 'bar']);

        $response = $service->executeGlobalFunction($request, 'sales_report');
        $this->assertSame(1, $response->getData()->data->count);

        // Still cached.
        $response = $service->executeGlobalFunction($request, 'sales_report');
        $this->assertSame(1, $response->getData()->data->count);

        $service->createRecord('products', ['name' => 'Widget'], null);

        $response = $service->executeGlobalFunction($request, 'sales_report');
        $this->assertSame(
            2,
            $response->getData()->data->count,
            'A CRUD write to a declared dependency table must bust the global function cache'
        );
    }

    public function test_end_to_end_global_function_cache_busts_on_write_when_dependency_table_has_tenant_id(): void
    {
        SalesReportDependencyCounter::$count = 0;

        Config::set('record.enable_tenant_id', true);
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
        Config::set('record.global_functions', [
            'sales_report' => [
                'httpMethod' => ['GET'],
                'class' => SalesReportDependencyCounter::class,
                'functionName' => 'handle',
                'disableCache' => false,
                'clearCacheTables' => ['products'],
            ],
        ]);

        $service = new RecordService();
        $request = Request::create('/api/v1/rpc/sales_report', 'GET', ['foo' => 'bar']);
        $request->attributes->set('resolved_tenant_id', 'acme');

        $response = $service->executeGlobalFunction($request, 'sales_report');
        $this->assertSame(1, $response->getData()->data->count);

        $response = $service->executeGlobalFunction($request, 'sales_report');
        $this->assertSame(1, $response->getData()->data->count);

        $service->createRecord('products', ['name' => 'Widget'], 'acme');

        $response = $service->executeGlobalFunction($request, 'sales_report');
        $this->assertSame(
            2,
            $response->getData()->data->count,
            "A write to a tenant-scoped dependency table must bust that tenant's global function cache"
        );
    }

    /**
     * This is the scenario Finding 1 fixed. Tenancy is globally ON, but the
     * dependency table itself opts out (hasTenantId: false). A single hoisted
     * tenant key derived from the calling function's own tenancy would compute
     * "tenant:acme" for the dependency, while createRecord()'s write -- which
     * resolves tenancy per table, exactly like clearTableCache() -- bumps
     * "tenant:disabled" for a table with hasTenantId: false. Those two
     * namespaces never meet unless functionCacheDependencies() also resolves
     * tenancy per table.
     */
    public function test_end_to_end_global_function_cache_busts_on_write_when_dependency_table_lacks_tenant_id(): void
    {
        SalesReportDependencyCounter::$count = 0;

        Config::set('record.enable_tenant_id', true);
        Config::set('record.tables', [
            'products' => new RecordTableType(
                table: 'products',
                pmsName: 'products',
                hasTenantId: false,
                softDeletes: true,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
            ),
        ]);
        SchemaRegistryUtils::refresh();
        Config::set('record.global_functions', [
            'sales_report' => [
                'httpMethod' => ['GET'],
                'class' => SalesReportDependencyCounter::class,
                'functionName' => 'handle',
                'disableCache' => false,
                'clearCacheTables' => ['products'],
            ],
        ]);

        $service = new RecordService();
        $request = Request::create('/api/v1/rpc/sales_report', 'GET', ['foo' => 'bar']);
        $request->attributes->set('resolved_tenant_id', 'acme');

        $response = $service->executeGlobalFunction($request, 'sales_report');
        $this->assertSame(1, $response->getData()->data->count);

        $response = $service->executeGlobalFunction($request, 'sales_report');
        $this->assertSame(1, $response->getData()->data->count);

        $service->createRecord('products', ['name' => 'Widget'], 'acme');

        $response = $service->executeGlobalFunction($request, 'sales_report');
        $this->assertSame(
            2,
            $response->getData()->data->count,
            'A write must bust the cache even when the dependency table has hasTenantId: false while tenancy is globally on'
        );
    }

    public function test_end_to_end_global_function_cache_survives_a_different_tenants_write(): void
    {
        SalesReportDependencyCounter::$count = 0;

        Config::set('record.enable_tenant_id', true);
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
        Config::set('record.global_functions', [
            'sales_report' => [
                'httpMethod' => ['GET'],
                'class' => SalesReportDependencyCounter::class,
                'functionName' => 'handle',
                'disableCache' => false,
                'clearCacheTables' => ['products'],
            ],
        ]);

        $service = new RecordService();
        $request = Request::create('/api/v1/rpc/sales_report', 'GET', ['foo' => 'bar']);
        $request->attributes->set('resolved_tenant_id', 'acme');

        $response = $service->executeGlobalFunction($request, 'sales_report');
        $this->assertSame(1, $response->getData()->data->count);

        // A write for a DIFFERENT tenant must not reach acme's cached report.
        $service->createRecord('products', ['name' => 'Widget'], 'globex');

        $response = $service->executeGlobalFunction($request, 'sales_report');
        $this->assertSame(
            1,
            $response->getData()->data->count,
            "A write for an unrelated tenant must not invalidate another tenant's cached global function output"
        );
    }
}

class SalesReportDependencyCounter
{
    public static int $count = 0;

    public function handle(Request $request): JsonResponse
    {
        self::$count++;

        return response()->json([
            'success' => true,
            'count' => self::$count,
        ]);
    }
}
