<?php

namespace Sopheak\Core\Tests\Unit;

use Illuminate\Cache\RedisStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Services\QueryCacheService;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Services\RecordCacheService;
use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;

class SchemaRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        SchemaRegistryUtils::clearAllCache();
        Cache::flush();

        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('email');
                $table->timestamps();
            });
        }
    }

    protected function tearDown(): void
    {
        SchemaRegistryUtils::clearAllCache();
        Cache::flush();

        parent::tearDown();
    }

    public function test_it_can_get_schema_registry(): void
    {
        Config::set('record.tables', [
            'users' => new RecordTableType(
                table: 'users',
                pmsName: 'users',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
            ),
        ]);

        $schema = SchemaRegistryUtils::get();

        $this->assertArrayHasKey('users', $schema);
        $this->assertArrayHasKey('id', $schema['users']->columns);
        $this->assertArrayHasKey('name', $schema['users']->columns);
        $this->assertArrayHasKey('email', $schema['users']->columns);
    }

    public function test_it_can_refresh_cache(): void
    {
        Config::set('record.tables', [
            'users' => new RecordTableType(
                table: 'users',
                pmsName: 'users',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
            ),
        ]);

        $schema1 = SchemaRegistryUtils::get();
        SchemaRegistryUtils::refresh();
        $schema2 = SchemaRegistryUtils::get();

        $this->assertArrayHasKey('users', $schema1);
        $this->assertArrayHasKey('users', $schema2);
    }

    public function test_it_can_clear_all_cache(): void
    {
        Config::set('record.tables', [
            'users' => new RecordTableType(
                table: 'users',
                pmsName: 'users',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
            ),
        ]);

        $schema1 = SchemaRegistryUtils::get();
        SchemaRegistryUtils::clearAllCache();
        $schema2 = SchemaRegistryUtils::get();

        $this->assertArrayHasKey('users', $schema1);
        $this->assertArrayHasKey('users', $schema2);
    }

    public function test_it_can_clear_table_cache(): void
    {
        Config::set('record.tables', [
            'users' => new RecordTableType(
                table: 'users',
                pmsName: 'users',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
            ),
        ]);

        $schema1 = SchemaRegistryUtils::get();
        $this->assertArrayHasKey('users', $schema1);

        // clearTableCache removes only the specific table; permission
        // tables keep the cache alive, so the cleared table is not
        // re-added on next get() without an explicit refresh().
        SchemaRegistryUtils::clearTableCache('users');
        $schema2 = SchemaRegistryUtils::get();
        $this->assertArrayNotHasKey('users', $schema2);

        // refresh() rebuilds the full registry from config.
        SchemaRegistryUtils::refresh();
        $schema3 = SchemaRegistryUtils::get();
        $this->assertArrayHasKey('users', $schema3);
    }

    public function test_it_returns_permission_tables_when_no_record_tables_configured(): void
    {
        Config::set('record.tables', []);

        $schema = SchemaRegistryUtils::get();

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('sp_permissions', $schema);
        $this->assertArrayHasKey('sp_roles', $schema);
    }

    public function test_it_uses_memory_cache_when_available(): void
    {
        Config::set('record.tables', [
            'users' => new RecordTableType(
                table: 'users',
                pmsName: 'users',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
            ),
        ]);

        $schema1 = SchemaRegistryUtils::get();
        Config::set('record.tables', []);
        $schema2 = SchemaRegistryUtils::get();

        $this->assertArrayHasKey('users', $schema1);
        $this->assertArrayHasKey('users', $schema2);
    }

    public function test_is_cacheable_request_respects_per_table_cache_disable(): void
    {
        Config::set('record.cache.enabled', true);
        Config::set('record.cache.per_table', ['users' => false]);
        Config::set('record.tables', [
            'users' => new RecordTableType(
                table: 'users',
                pmsName: 'users',
                hasTenantId: false,
                softDeletes: false,
                disableCache: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
            ),
        ]);

        SchemaRegistryUtils::refresh();
        $service = new RecordService();
        $request = Request::create('/api/users', 'GET');

        $this->assertFalse($service->isCacheableRequest($request, 'users'));
    }

    public function test_is_cacheable_request_respects_record_table_type_disable_cache(): void
    {
        Config::set('record.cache.enabled', true);
        Config::set('record.cache.per_table', []);
        Config::set('record.tables', [
            'users' => new RecordTableType(
                table: 'users',
                pmsName: 'users',
                hasTenantId: false,
                softDeletes: false,
                disableCache: true,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
            ),
        ]);

        SchemaRegistryUtils::refresh();
        $service = new RecordService();
        $request = Request::create('/api/users', 'GET');

        $this->assertFalse($service->isCacheableRequest($request, 'users'));
    }

    public function test_is_cacheable_request_allows_cache_when_enabled_and_not_disabled(): void
    {
        Config::set('record.cache.enabled', true);
        Config::set('record.cache.per_table', []);
        Config::set('record.tables', [
            'users' => new RecordTableType(
                table: 'users',
                pmsName: 'users',
                hasTenantId: false,
                softDeletes: false,
                disableCache: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
            ),
        ]);

        SchemaRegistryUtils::refresh();
        $service = new RecordService();
        $request = Request::create('/api/users', 'GET');

        $this->assertTrue($service->isCacheableRequest($request, 'users'));
    }

    public function test_cache_admission_can_limit_cache_to_specific_tables(): void
    {
        Config::set('record.cache.enabled', true);
        Config::set('record.cache.per_table', []);
        Config::set('record.cache.admission', [
            'only_tables' => ['users'],
            'except_tables' => [],
            'only_actions' => [],
            'except_actions' => [],
            'skip_query_params' => [],
        ]);
        Config::set('record.tables', [
            'users' => new RecordTableType(
                table: 'users',
                pmsName: 'users',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
            ),
            'products' => new RecordTableType(
                table: 'products',
                pmsName: 'products',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
            ),
        ]);

        SchemaRegistryUtils::refresh();
        $service = new RecordService();

        $this->assertTrue($service->isCacheableRequest(Request::create('/api/users', 'GET'), 'users'));
        $this->assertFalse($service->isCacheableRequest(Request::create('/api/products', 'GET'), 'products'));
    }

    public function test_cache_admission_can_skip_high_cardinality_query_params(): void
    {
        Config::set('record.cache.enabled', true);
        Config::set('record.cache.per_table', []);
        Config::set('record.cache.admission', [
            'only_tables' => [],
            'except_tables' => [],
            'only_actions' => [],
            'except_actions' => [],
            'skip_query_params' => ['nonce', 'timestamp'],
        ]);
        Config::set('record.tables', [
            'users' => new RecordTableType(
                table: 'users',
                pmsName: 'users',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
            ),
        ]);

        SchemaRegistryUtils::refresh();
        $service = new RecordService();

        $this->assertFalse($service->isCacheableRequest(Request::create('/api/users?nonce=abc', 'GET'), 'users'));
        $this->assertTrue($service->isCacheableRequest(Request::create('/api/users?status=active', 'GET'), 'users'));
    }

    public function test_cache_admission_can_limit_cache_to_specific_actions(): void
    {
        Config::set('record.cache.enabled', true);
        Config::set('record.cache.per_table', []);
        Config::set('record.cache.admission', [
            'only_tables' => [],
            'except_tables' => [],
            'only_actions' => ['show'],
            'except_actions' => [],
            'skip_query_params' => [],
        ]);
        Config::set('record.tables', [
            'users' => new RecordTableType(
                table: 'users',
                pmsName: 'users',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
            ),
        ]);

        SchemaRegistryUtils::refresh();
        $service = new RecordService();
        $showRequest = Request::create('/api/users/1', 'GET');
        $showRequest->attributes->set('record_cache_action', 'show');
        $listRequest = Request::create('/api/users', 'GET');
        $listRequest->attributes->set('record_cache_action', 'list');

        $this->assertTrue($service->isCacheableRequest($showRequest, 'users'));
        $this->assertFalse($service->isCacheableRequest($listRequest, 'users'));
    }

    public function test_cache_keys_include_tenant_when_enabled(): void
    {
        Config::set('record.tenant_column', 'tenant_id');
        $service = new RecordService();

        $indexKey = $service->generateOptimizedCacheKey(
            'users',
            ['status' => 'active', 'tenant_id' => 'tenant-1', 'tenant_enabled' => true],
            [],
            1,
            25,
            true
        );
        $recordKey = $service->generateRecordCacheKey('users', 10, 'tenant-1', null, true);

        $this->assertStringContainsString('record_index:table:users:tenant:tenant-1:', $indexKey);
        $this->assertStringContainsString('record_show:table:users:id:10:tenant:tenant-1:', $recordKey);
    }

    public function test_record_cache_service_generates_cache_keys(): void
    {
        Config::set('record.tenant_column', 'tenant_id');
        $service = new RecordCacheService();

        $indexKey = $service->generateOptimizedCacheKey(
            'users',
            ['status' => 'active', 'tenant_id' => 'tenant-1', 'tenant_enabled' => true],
            [],
            1,
            25,
            true
        );
        $recordKey = $service->generateRecordCacheKey('users', 10, 'tenant-1', null, true);

        $this->assertStringContainsString('record_index:table:users:tenant:tenant-1:', $indexKey);
        $this->assertStringContainsString('record_show:table:users:id:10:tenant:tenant-1:', $recordKey);
    }

    public function test_cache_keys_use_disabled_tenant_marker_when_disabled(): void
    {
        Config::set('record.tenant_column', 'tenant_id');
        $service = new RecordService();

        $indexKey = $service->generateOptimizedCacheKey(
            'users',
            ['status' => 'active', 'tenant_enabled' => false],
            [],
            1,
            25,
            false
        );
        $recordKey = $service->generateRecordCacheKey('users', 10, null, null, false);

        $this->assertStringContainsString('record_index:table:users:tenant:disabled:', $indexKey);
        $this->assertStringContainsString('record_show:table:users:id:10:tenant:disabled:', $recordKey);
    }

    public function test_non_redis_invalidation_clears_table_tenant_cache(): void
    {
        Config::set('record.cache.enabled', true);
        $store = Cache::getStore();
        if ($store instanceof RedisStore) {
            $this->markTestSkipped('Test only applies to non-Redis stores');
        }

        QueryCacheService::put('record_index:table:settings:tenant:missing:hash:abc', 'value', 600);
        QueryCacheService::put('unrelated', 'keep', 600);

        QueryCacheService::invalidateTableForTenant('settings', 'missing');

        $this->assertNull(QueryCacheService::get('record_index:table:settings:tenant:missing:hash:abc'));
        $this->assertSame('keep', QueryCacheService::get('unrelated'));
    }

    public function test_non_redis_invalidation_clears_global_function_tenant_cache(): void
    {
        Config::set('record.cache.enabled', true);
        $store = Cache::getStore();
        if ($store instanceof RedisStore) {
            $this->markTestSkipped('Test only applies to non-Redis stores');
        }

        QueryCacheService::put('record_func_global:function:auth/login:tenant:missing:hash:abc', 'value', 600);
        QueryCacheService::put('unrelated', 'keep', 600);

        QueryCacheService::invalidateGlobalFunctionForTenant('auth/login', 'missing');

        $this->assertNull(QueryCacheService::get('record_func_global:function:auth/login:tenant:missing:hash:abc'));
        $this->assertSame('keep', QueryCacheService::get('unrelated'));
    }

    public function test_get_table_columns_adds_composite_fields_for_pgsql(): void
    {
        DB::shouldReceive('getDriverName')
            ->once()
            ->andReturn('pgsql');

        DB::shouldReceive('select')
            ->once()
            ->with(
                'select column_name, data_type, udt_name, udt_schema, is_nullable, column_default from information_schema.columns where table_name = ? and table_schema = current_schema()',
                ['places']
            )
            ->andReturn([
                (object) [
                    'column_name' => 'location',
                    'data_type' => 'USER-DEFINED',
                    'udt_name' => 'geo_point',
                    'udt_schema' => 'public',
                    'is_nullable' => 'YES',
                    'column_default' => null,
                ],
                (object) [
                    'column_name' => 'name',
                    'data_type' => 'character varying',
                    'udt_name' => 'varchar',
                    'udt_schema' => 'pg_catalog',
                    'is_nullable' => 'NO',
                    'column_default' => null,
                ],
            ]);

        DB::shouldReceive('select')
            ->once()
            ->with(
                'select a.attname as field_name from pg_type t join pg_namespace n on n.oid = t.typnamespace join pg_class c on c.oid = t.typrelid join pg_attribute a on a.attrelid = c.oid where t.typtype = ? and n.nspname = ? and t.typname = ? and a.attnum > 0 and not a.attisdropped order by a.attnum',
                ['c', 'public', 'geo_point']
            )
            ->andReturn([
                (object) ['field_name' => 'lat'],
                (object) ['field_name' => 'lng'],
            ]);

        $columns = SchemaRegistryUtils::getTableColumns('places');

        $this->assertSame(['lat', 'lng'], $columns['location']['compositeFields']);
        $this->assertArrayNotHasKey('compositeFields', $columns['name']);
    }
}
