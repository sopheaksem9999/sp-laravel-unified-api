<?php

namespace Sopheak\Core\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;

class SchemaRegistryUtilsTest extends TestCase
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
        SchemaRegistryUtils::clearTableCache('users');
        $schema2 = SchemaRegistryUtils::get();

        $this->assertArrayHasKey('users', $schema1);
        $this->assertArrayHasKey('users', $schema2);
    }

    public function test_it_returns_empty_array_when_no_tables_configured(): void
    {
        Config::set('record.tables', []);

        $schema = SchemaRegistryUtils::get();

        $this->assertIsArray($schema);
        $this->assertEmpty($schema);
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
}
