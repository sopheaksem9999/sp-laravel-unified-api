<?php

namespace Sopheak\Core\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Support\SchemaRegistry;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;

class SchemaRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        SchemaRegistry::clearAllCache();
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
        SchemaRegistry::clearAllCache();
        Cache::flush();

        parent::tearDown();
    }

    public function test_it_can_get_schema_registry(): void
    {
        Config::set('record.tables', [
            'users' => new RecordTableType(
                pms_name: 'users',
                table: 'users',
                soft_deletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
                has_tenant_id: false,
            ),
        ]);

        $schema = SchemaRegistry::get();

        $this->assertArrayHasKey('users', $schema);
        $this->assertArrayHasKey('id', $schema['users']->columns);
        $this->assertArrayHasKey('name', $schema['users']->columns);
        $this->assertArrayHasKey('email', $schema['users']->columns);
    }

    public function test_it_can_refresh_cache(): void
    {
        Config::set('record.tables', [
            'users' => new RecordTableType(
                pms_name: 'users',
                table: 'users',
                soft_deletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
                has_tenant_id: false,
            ),
        ]);

        $schema1 = SchemaRegistry::get();
        SchemaRegistry::refresh();
        $schema2 = SchemaRegistry::get();

        $this->assertArrayHasKey('users', $schema1);
        $this->assertArrayHasKey('users', $schema2);
    }

    public function test_it_can_clear_all_cache(): void
    {
        Config::set('record.tables', [
            'users' => new RecordTableType(
                pms_name: 'users',
                table: 'users',
                soft_deletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
                has_tenant_id: false,
            ),
        ]);

        $schema1 = SchemaRegistry::get();
        SchemaRegistry::clearAllCache();
        $schema2 = SchemaRegistry::get();

        $this->assertArrayHasKey('users', $schema1);
        $this->assertArrayHasKey('users', $schema2);
    }

    public function test_it_can_clear_table_cache(): void
    {
        Config::set('record.tables', [
            'users' => new RecordTableType(
                pms_name: 'users',
                table: 'users',
                soft_deletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
                has_tenant_id: false,
            ),
        ]);

        $schema1 = SchemaRegistry::get();
        SchemaRegistry::clearTableCache('users');
        $schema2 = SchemaRegistry::get();

        $this->assertArrayHasKey('users', $schema1);
        $this->assertArrayHasKey('users', $schema2);
    }

    public function test_it_returns_empty_array_when_no_tables_configured(): void
    {
        Config::set('record.tables', []);

        $schema = SchemaRegistry::get();

        $this->assertIsArray($schema);
        $this->assertEmpty($schema);
    }

    public function test_it_uses_memory_cache_when_available(): void
    {
        Config::set('record.tables', [
            'users' => new RecordTableType(
                pms_name: 'users',
                table: 'users',
                soft_deletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
                has_tenant_id: false,
            ),
        ]);

        $schema1 = SchemaRegistry::get();
        Config::set('record.tables', []);
        $schema2 = SchemaRegistry::get();

        $this->assertArrayHasKey('users', $schema1);
        $this->assertArrayHasKey('users', $schema2);
    }

    public function test_is_cacheable_request_respects_per_table_cache_disable(): void
    {
        Config::set('record.cache.enabled', true);
        Config::set('record.cache.per_table', ['users' => false]);
        Config::set('record.tables', [
            'users' => new RecordTableType(
                pms_name: 'users',
                table: 'users',
                soft_deletes: false,
                disable_cache: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
                has_tenant_id: false,
            ),
        ]);

        SchemaRegistry::refresh();
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
                pms_name: 'users',
                table: 'users',
                soft_deletes: false,
                disable_cache: true,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
                has_tenant_id: false,
            ),
        ]);

        SchemaRegistry::refresh();
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
                pms_name: 'users',
                table: 'users',
                soft_deletes: false,
                disable_cache: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
                has_tenant_id: false,
            ),
        ]);

        SchemaRegistry::refresh();
        $service = new RecordService();
        $request = Request::create('/api/users', 'GET');

        $this->assertTrue($service->isCacheableRequest($request, 'users'));
    }
}
