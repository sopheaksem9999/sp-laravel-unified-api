<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Sopheak\Core\Types\RecordTablePublic;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Http\Controllers\CoreRecordController;
use Sopheak\Core\Utilities\RelationshipResolverUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Tests\Fixtures\LegacyFunction;

class LegacyConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('legacy_items', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function test_legacy_array_table_config_is_normalized(): void
    {
        // Define a table using a legacy array configuration
        Config::set('record.tables', [
            'legacy_items' => [
                'pmsName' => 'legacy_items',
                'table' => 'legacy_items',
                'softDeletes' => false,
                'public' => ['read' => true, 'write' => true], // Array for public
                'can_write' => false, // Legacy permission
                'canCreate' => true, // Granular permission override
                'functions' => [
                    'legacy_func' => [
                        'httpMethod' => ['GET'],
                        'class' => LegacyFunction::class,
                        'functionName' => 'handle',
                        'description' => 'Legacy function',
                    ],
                ],
            ],
        ]);

        SchemaRegistryUtils::refresh();

        $schema = SchemaRegistryUtils::get();
        $tableConfig = $schema['legacy_items'];

        // Assert it was converted to RecordTableType
        $this->assertInstanceOf(RecordTableType::class, $tableConfig);

        // Assert public property was converted to RecordTablePublic
        $this->assertInstanceOf(RecordTablePublic::class, $tableConfig->public);
        $this->assertTrue($tableConfig->public->read);
        $this->assertTrue($tableConfig->public->write);

        // Assert granular permission override worked
        $this->assertTrue($tableConfig->canCreate);

        // Assert fallback worked (if not overridden)
        // can_write was false, so canUpdate and canDelete should be false (since they fallback to can_write if null)
        $this->assertFalse($tableConfig->canUpdate);
        $this->assertFalse($tableConfig->canDelete);

        // Check functions
        $this->assertIsArray($tableConfig->functions);
        $this->assertArrayHasKey('legacy_func', $tableConfig->functions);
        $this->assertIsArray($tableConfig->functions['legacy_func']);

        // Test executing table function with array config
        $service = new RecordService();
        $request = Request::create('/api/v1/legacy_items/rpc/legacy_func', 'GET');
        $response = $service->executeTableFunction($request, 'legacy_items', 'legacy_func');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Legacy function executed', $response->getData()->data->message);
    }

    public function test_legacy_global_function_array_config(): void
    {
        // Define global function using array
        Config::set('record.global_functions', [
            'legacy_global' => [
                'httpMethod' => ['GET'],
                'class' => LegacyFunction::class,
                'functionName' => 'handle',
                'description' => 'Legacy global function',
            ],
        ]);

        $service = new RecordService();
        $request = Request::create('/api/v1/rpc/legacy_global', 'GET');

        $response = $service->executeGlobalFunction($request, 'legacy_global');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Legacy function executed', $response->getData()->data->message);
    }

    public function test_table_function_response_is_cached(): void
    {
        Cache::flush();
        CachedFunctionCounter::$count = 0;

        Config::set('record.cache.enabled', true);
        Config::set('record.cache.per_table', ['legacy_items' => true]);
        Config::set('record.tables', [
            'legacy_items' => [
                'pmsName' => 'legacy_items',
                'table' => 'legacy_items',
                'softDeletes' => false,
                'public' => ['read' => true, 'write' => true],
                'disableCache' => false,
                'functions' => [
                    'cached_func' => [
                        'httpMethod' => ['GET'],
                        'class' => CachedFunctionCounter::class,
                        'functionName' => 'handle',
                        'disableCache' => false,
                    ],
                ],
            ],
        ]);

        SchemaRegistryUtils::refresh();

        $service = new RecordService();
        $request = Request::create('/api/v1/legacy_items/rpc/cached_func', 'GET', ['foo' => 'bar']);
        $response = $service->executeTableFunction($request, 'legacy_items', 'cached_func');
        $this->assertEquals(1, $response->getData()->data->count);

        $response = $service->executeTableFunction($request, 'legacy_items', 'cached_func');
        $this->assertEquals(1, $response->getData()->data->count);
        $this->assertSame(1, CachedFunctionCounter::$count);
    }

    public function test_table_function_post_clears_cache(): void
    {
        Cache::flush();
        CachedFunctionCounter::$count = 0;

        Config::set('record.cache.enabled', true);
        Config::set('record.cache.per_table', ['legacy_items' => true]);
        Config::set('record.tables', [
            'legacy_items' => [
                'pmsName' => 'legacy_items',
                'table' => 'legacy_items',
                'softDeletes' => false,
                'public' => ['read' => true, 'write' => true],
                'disableCache' => false,
                'functions' => [
                    'cached_func' => [
                        'httpMethod' => ['GET'],
                        'class' => CachedFunctionCounter::class,
                        'functionName' => 'handle',
                        'disableCache' => false,
                    ],
                    'clear_cache' => [
                        'httpMethod' => ['POST'],
                        'class' => LegacyFunction::class,
                        'functionName' => 'handle',
                        'disableCache' => true,
                        'clearCacheTables' => ['legacy_items'],
                    ],
                ],
            ],
        ]);

        SchemaRegistryUtils::refresh();

        $service = new RecordService();
        $getRequest = Request::create('/api/v1/legacy_items/rpc/cached_func', 'GET', ['foo' => 'bar']);
        $response = $service->executeTableFunction($getRequest, 'legacy_items', 'cached_func');
        $this->assertEquals(1, $response->getData()->data->count);

        $response = $service->executeTableFunction($getRequest, 'legacy_items', 'cached_func');
        $this->assertEquals(1, $response->getData()->data->count);
        $this->assertSame(1, CachedFunctionCounter::$count);

        $postRequest = Request::create('/api/v1/legacy_items/rpc/clear_cache', 'POST');
        $service->executeTableFunction($postRequest, 'legacy_items', 'clear_cache');

        $response = $service->executeTableFunction($getRequest, 'legacy_items', 'cached_func');
        $this->assertEquals(2, $response->getData()->data->count);
        $this->assertSame(2, CachedFunctionCounter::$count);
    }

    public function test_global_function_response_is_cached(): void
    {
        Cache::flush();
        CachedGlobalFunctionCounter::$count = 0;

        Config::set('record.cache.enabled', true);
        Config::set('record.global_functions', [
            'cached_global' => [
                'httpMethod' => ['GET'],
                'class' => CachedGlobalFunctionCounter::class,
                'functionName' => 'handle',
                'disableCache' => false,
            ],
        ]);

        $service = new RecordService();
        $request = Request::create('/api/v1/rpc/cached_global', 'GET', ['foo' => 'bar']);
        $response = $service->executeGlobalFunction($request, 'cached_global');
        $this->assertEquals(1, $response->getData()->data->count);

        $response = $service->executeGlobalFunction($request, 'cached_global');
        $this->assertEquals(1, $response->getData()->data->count);
        $this->assertSame(1, CachedGlobalFunctionCounter::$count);
    }

    public function test_table_function_cache_ttl_override(): void
    {
        Cache::flush();
        CachedFunctionCounter::$count = 0;

        Config::set('record.cache.enabled', true);
        Config::set('record.cache.per_table_ttl', ['legacy_items' => 1]);
        Config::set('record.tables', [
            'legacy_items' => [
                'pmsName' => 'legacy_items',
                'table' => 'legacy_items',
                'softDeletes' => false,
                'public' => ['read' => true, 'write' => true],
                'disableCache' => false,
                'functions' => [
                    'cached_func' => [
                        'httpMethod' => ['GET'],
                        'class' => CachedFunctionCounter::class,
                        'functionName' => 'handle',
                        'disableCache' => false,
                        'cacheTTL' => 10,
                    ],
                ],
            ],
        ]);

        SchemaRegistryUtils::refresh();

        $service = new RecordService();
        Carbon::setTestNow(Carbon::now());
        $request = Request::create('/api/v1/legacy_items/rpc/cached_func', 'GET', ['foo' => 'bar']);
        $response = $service->executeTableFunction($request, 'legacy_items', 'cached_func');
        $this->assertEquals(1, $response->getData()->data->count);

        Carbon::setTestNow(Carbon::now()->addSeconds(2));
        $response = $service->executeTableFunction($request, 'legacy_items', 'cached_func');
        $this->assertEquals(1, $response->getData()->data->count);
        $this->assertSame(1, CachedFunctionCounter::$count);
        Carbon::setTestNow();
    }

    public function test_global_function_cache_ttl_override(): void
    {
        Cache::flush();
        CachedGlobalFunctionCounter::$count = 0;

        Config::set('record.cache.enabled', true);
        Config::set('record.cache.ttl', 1);
        Config::set('record.global_functions', [
            'cached_global' => [
                'httpMethod' => ['GET'],
                'class' => CachedGlobalFunctionCounter::class,
                'functionName' => 'handle',
                'disableCache' => false,
                'cacheTTL' => 10,
            ],
        ]);

        $service = new RecordService();
        Carbon::setTestNow(Carbon::now());
        $request = Request::create('/api/v1/rpc/cached_global', 'GET', ['foo' => 'bar']);
        $response = $service->executeGlobalFunction($request, 'cached_global');
        $this->assertEquals(1, $response->getData()->data->count);

        Carbon::setTestNow(Carbon::now()->addSeconds(2));
        $response = $service->executeGlobalFunction($request, 'cached_global');
        $this->assertEquals(1, $response->getData()->data->count);
        $this->assertSame(1, CachedGlobalFunctionCounter::$count);
        Carbon::setTestNow();
    }

    public function test_table_function_post_clears_cache_with_resolved_tenant_attribute(): void
    {
        Cache::flush();

        Config::set('record.enable_tenant_id', true);
        Config::set('record.tenant_column', 'tenant_id');
        Config::set('record.cache.enabled', true);
        Config::set('record.cache.per_table', ['legacy_items' => true]);
        Config::set('record.tables', [
            'legacy_items' => [
                'pmsName' => 'legacy_items',
                'table' => 'legacy_items',
                'hasTenantId' => true,
                'softDeletes' => false,
                'public' => ['read' => true, 'write' => true],
                'functions' => [
                    'clear_cache' => [
                        'httpMethod' => ['POST'],
                        'class' => LegacyFunction::class,
                        'functionName' => 'handle',
                        'disableCache' => true,
                    ],
                ],
            ],
        ]);

        SchemaRegistryUtils::refresh();
        if (!Schema::hasColumn('legacy_items', 'tenant_id')) {
            Schema::table('legacy_items', function (Blueprint $table): void {
                $table->string('tenant_id')->nullable();
            });
        }

        $request = Request::create('/api/v1/legacy_items/rpc/clear_cache', 'POST');
        $request->attributes->set('resolved_tenant_id', 'tenant-1');

        $controller = new CoreRecordController(new RecordService());
        $response = $controller->executeTableFunction($request, 'legacy_items', 'clear_cache');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Legacy function executed', $response->getData()->data->message);
    }

    public function test_table_function_post_clears_cache_when_tenancy_is_disabled(): void
    {
        Cache::flush();

        Config::set('record.enable_tenant_id', false);
        Config::set('record.cache.enabled', true);
        Config::set('record.cache.per_table', ['legacy_items' => true]);
        Config::set('record.tables', [
            'legacy_items' => [
                'pmsName' => 'legacy_items',
                'table' => 'legacy_items',
                'hasTenantId' => true,
                'softDeletes' => false,
                'public' => ['read' => true, 'write' => true],
                'functions' => [
                    'clear_cache' => [
                        'httpMethod' => ['POST'],
                        'class' => LegacyFunction::class,
                        'functionName' => 'handle',
                        'disableCache' => true,
                    ],
                ],
            ],
        ]);

        SchemaRegistryUtils::refresh();

        $request = Request::create('/api/v1/legacy_items/rpc/clear_cache', 'POST');

        $controller = new CoreRecordController(new RecordService());
        $response = $controller->executeTableFunction($request, 'legacy_items', 'clear_cache');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Legacy function executed', $response->getData()->data->message);
    }

    public function test_legacy_relationship_array_config(): void
    {
        // Mock schema with legacy relationship array
        Config::set('record.tables', [
            'legacy_parent' => [
                'pmsName' => 'legacy_parent',
                'table' => 'legacy_parent',
                'relationships' => [
                    'children' => [
                        'type' => 'hasMany',
                        'table' => 'legacy_child',
                        'foreign_key' => 'parent_id',
                        'local_key' => 'id',
                        'allow_create' => true,
                        'allow_update' => true,
                        'allow_delete' => true,
                    ],
                ],
            ],
            'legacy_child' => [
                'pmsName' => 'legacy_child',
                'table' => 'legacy_child',
            ],
        ]);

        SchemaRegistryUtils::refresh();
        RelationshipResolverUtils::clearSchemaCache();

        // Resolve relationship
        $rel = RelationshipResolverUtils::resolveRelationship('legacy_parent', 'children');

        $this->assertIsArray($rel);
        $this->assertEquals('hasMany', $rel['type']);
        $this->assertEquals('legacy_child', $rel['table']);
    }
}

class CachedFunctionCounter
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

class CachedGlobalFunctionCounter
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
