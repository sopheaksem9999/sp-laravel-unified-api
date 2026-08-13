<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Sopheak\Core\Services\RecordCacheService;
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
}
