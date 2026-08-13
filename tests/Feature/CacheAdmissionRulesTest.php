<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Sopheak\Core\Services\RecordCacheService;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class CacheAdmissionRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('record.cache.enabled', true);
        Config::set('record.cache.prefix', 'sp_laravel_api');
        Config::set('record.cache.ttl', 3600);
        Config::set('record.tables', [
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
        Cache::flush();
    }

    /**
     * @return array<string, string[]>
     */
    public static function dynamicQueryParamProvider(): array
    {
        return [
            'search only' => ['search=foo'],
            'filter only' => ['filter=x'],
            'where only' => ['where=y'],
            'search and filter' => ['search=foo&filter=x'],
            'all three' => ['search=foo&filter=x&where=y'],
        ];
    }

    /**
     * @dataProvider dynamicQueryParamProvider
     */
    public function test_dynamic_query_params_make_a_request_non_cacheable(string $query): void
    {
        $service = app(RecordCacheService::class);

        $this->assertFalse(
            $service->isCacheableRequest(Request::create('/api/products?' . $query, 'GET'), 'products')
        );
    }

    public function test_a_plain_get_is_still_cacheable(): void
    {
        $service = app(RecordCacheService::class);

        $this->assertTrue(
            $service->isCacheableRequest(Request::create('/api/products?limit=5', 'GET'), 'products')
        );
    }

    /**
     * @dataProvider dynamicQueryParamProvider
     */
    public function test_global_function_requests_honour_the_same_guard(string $query): void
    {
        $service = app(RecordCacheService::class);

        $this->assertFalse(
            $service->isCacheableGlobalRequest(Request::create('/api/rpc/report?' . $query, 'GET'))
        );
    }

    public function test_global_function_requests_honour_skip_query_params(): void
    {
        Config::set('record.cache.admission.skip_query_params', ['nocache']);
        $service = app(RecordCacheService::class);

        $this->assertFalse(
            $service->isCacheableGlobalRequest(Request::create('/api/rpc/report?nocache=1', 'GET'))
        );
        $this->assertTrue(
            $service->isCacheableGlobalRequest(Request::create('/api/rpc/report', 'GET'))
        );
    }

    public function test_global_function_requests_are_not_cached_for_writes(): void
    {
        $service = app(RecordCacheService::class);

        $this->assertFalse(
            $service->isCacheableGlobalRequest(Request::create('/api/rpc/report', 'POST'))
        );
    }

    /**
     * isCacheableGlobalRequest() has no route to resolve an action from --
     * Request::create() never sets one, so resolveCacheAction() only ever
     * sees an action when it is provided via the record_cache_action request
     * attribute, exactly as the existing
     * test_cache_admission_can_limit_cache_to_specific_actions test in
     * SchemaRegistryTest.php does for the table-scoped counterpart.
     */
    public function test_global_function_only_actions_admits_the_matching_action(): void
    {
        Config::set('record.cache.admission', [
            'only_tables' => [],
            'except_tables' => [],
            'only_actions' => ['global_function'],
            'except_actions' => [],
            'skip_query_params' => [],
        ]);

        $service = app(RecordCacheService::class);
        $request = Request::create('/api/rpc/report', 'GET');
        $request->attributes->set('record_cache_action', 'global_function');

        $this->assertTrue($service->isCacheableGlobalRequest($request));
    }

    public function test_global_function_only_actions_rejects_a_non_matching_action(): void
    {
        Config::set('record.cache.admission', [
            'only_tables' => [],
            'except_tables' => [],
            'only_actions' => ['list'],
            'except_actions' => [],
            'skip_query_params' => [],
        ]);

        $service = app(RecordCacheService::class);
        $request = Request::create('/api/rpc/report', 'GET');
        $request->attributes->set('record_cache_action', 'global_function');

        $this->assertFalse($service->isCacheableGlobalRequest($request));
    }

    public function test_global_function_except_actions_rejects_the_matching_action(): void
    {
        Config::set('record.cache.admission', [
            'only_tables' => [],
            'except_tables' => [],
            'only_actions' => [],
            'except_actions' => ['global_function'],
            'skip_query_params' => [],
        ]);

        $service = app(RecordCacheService::class);
        $request = Request::create('/api/rpc/report', 'GET');
        $request->attributes->set('record_cache_action', 'global_function');

        $this->assertFalse($service->isCacheableGlobalRequest($request));
    }

    /**
     * End-to-end proof for the actual defect site: the one-line wiring change
     * in RecordService::executeGlobalFunction(). Mirrors the
     * CachedGlobalFunctionCounter pattern in
     * tests/Feature/LegacyConfigTest.php (test_global_function_response_is_cached),
     * but a plain ?foo=bar query -- as used there -- is cached identically by
     * both the old buggy inline check and the new guard, so it cannot tell
     * them apart. This test adds the missing case (?search=x) plus a positive
     * control (a plain query) in the same test, so a broken cache that never
     * caches anything cannot make it pass by accident.
     */
    public function test_global_function_end_to_end_search_param_bypasses_the_cache(): void
    {
        Cache::flush();
        GlobalFunctionGuardCounter::$count = 0;

        Config::set('record.global_functions', [
            'guarded_global' => [
                'httpMethod' => ['GET'],
                'class' => GlobalFunctionGuardCounter::class,
                'functionName' => 'handle',
                'disableCache' => false,
            ],
        ]);

        $service = new RecordService();

        // Positive control: a plain query has neither search, filter, nor
        // where, so it IS cacheable -- the handler must run only once across
        // two identical calls. Without this, a cache that caches nothing
        // would make the assertions below pass for the wrong reason.
        $plainRequest = Request::create('/api/v1/rpc/guarded_global', 'GET', ['foo' => 'bar']);
        $response = $service->executeGlobalFunction($plainRequest, 'guarded_global');
        $this->assertEquals(1, $response->getData()->data->count);

        $response = $service->executeGlobalFunction($plainRequest, 'guarded_global');
        $this->assertEquals(1, $response->getData()->data->count);
        $this->assertSame(1, GlobalFunctionGuardCounter::$count);

        // The guard under test: a `search` query param must bypass the cache
        // entirely, so the handler -- and the counter -- runs again on every
        // call instead of being served from the first call's cached response.
        $searchRequest = Request::create('/api/v1/rpc/guarded_global', 'GET', ['search' => 'x']);
        $response = $service->executeGlobalFunction($searchRequest, 'guarded_global');
        $this->assertEquals(2, $response->getData()->data->count);

        $response = $service->executeGlobalFunction($searchRequest, 'guarded_global');
        $this->assertEquals(3, $response->getData()->data->count);
        $this->assertSame(3, GlobalFunctionGuardCounter::$count);
    }

    /**
     * generateGlobalFunctionCacheKey() (and its table-function sibling) were
     * keyed off queryParams: $request->query() only, unlike the three other
     * key generators which fold in RecordCacheService::queryFingerprint() --
     * a fingerprint that also reads the JSON body. A GET with
     * Content-Type: application/json carries its read-shaping params in the
     * body, invisible to query(), so two requests with different bodies but
     * an identical (empty) query string collapsed onto the same cache key
     * and the handler ran only once.
     */
    public function test_global_function_requests_with_different_json_bodies_do_not_share_a_cache_entry(): void
    {
        Cache::flush();
        GlobalFunctionGuardCounter::$count = 0;

        Config::set('record.global_functions', [
            'guarded_global' => [
                'httpMethod' => ['GET'],
                'class' => GlobalFunctionGuardCounter::class,
                'functionName' => 'handle',
                'disableCache' => false,
            ],
        ]);

        $service = new RecordService();

        $requestOne = Request::create(
            '/api/v1/rpc/guarded_global',
            'GET',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['month' => '01'])
        );
        $response = $service->executeGlobalFunction($requestOne, 'guarded_global');
        $this->assertEquals(1, $response->getData()->data->count);

        $requestTwo = Request::create(
            '/api/v1/rpc/guarded_global',
            'GET',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['month' => '02'])
        );
        $response = $service->executeGlobalFunction($requestTwo, 'guarded_global');
        $this->assertEquals(2, $response->getData()->data->count);
        $this->assertSame(2, GlobalFunctionGuardCounter::$count);
    }
}

class GlobalFunctionGuardCounter
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
