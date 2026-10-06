<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Sopheak\Core\Types\RecordFunctionType;
use Sopheak\Core\Services\RecordService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\QueryBuilderFiltersUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * RecordCacheService::queryFingerprint() is md5(query + body) with no user in
 * it, so a scoped result and an unscoped one shared a cache key. Whoever
 * warmed the cache first decided what every later caller saw: after an admin
 * listed widgets, a viewOwn user was served the admin's full list.
 *
 * @internal
 */
class OwnRecordsCacheIsolationTest extends OwnRecordsWriteScopingTest
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('record.cache.enabled', true);

        $tables = Config::get('record.tables');
        $widgets = $tables['widgets'];
        $tables['widgets'] = new RecordTableType(
            table: 'widgets',
            pmsName: 'widget',
            hasTenantId: false,
            softDeletes: true,
            disableCache: false,
            columns: $widgets->columns,
            functions: [
                'mine' => new RecordFunctionType(
                    httpMethod: 'GET',
                    class: OwnRecordsCacheFunctionHandler::class,
                    functionName: 'mine',
                    disableCache: false,
                ),
            ],
        );
        Config::set('record.tables', $tables);
        SchemaRegistryUtils::refresh();
        QueryBuilderFiltersUtils::clearColumnCache();
        Cache::flush();
    }

    /**
     * @return array<int, string>
     */
    private function listedNames(int $userId): array
    {
        return array_column((array) $this->actAs($userId)->getJson('/api/widgets')->json('data'), 'name');
    }

    /** @test */
    public function an_admin_warming_the_list_cache_does_not_leak_to_a_view_own_user(): void
    {
        $this->assertSame(['MINE', 'THEIRS'], $this->listedNames(self::ADMIN));
        $this->assertSame(['MINE'], $this->listedNames(self::OWNER));
    }

    /** @test */
    public function a_view_own_user_warming_the_cache_does_not_starve_an_admin(): void
    {
        $this->assertSame(['MINE'], $this->listedNames(self::OWNER));
        $this->assertSame(['MINE', 'THEIRS'], $this->listedNames(self::ADMIN));
    }

    /** @test */
    public function two_view_own_users_do_not_share_a_cached_scoped_result(): void
    {
        $this->assertSame(['MINE'], $this->listedNames(self::OWNER));
        // User 43 owns nothing; must not receive user 42's cached list.
        $this->assertSame([], $this->listedNames(43));
    }

    /** @test */
    public function an_admin_warming_the_record_cache_does_not_leak_to_a_view_own_user(): void
    {
        $this->actAs(self::ADMIN)->getJson('/api/widgets/2')->assertJsonPath('data.name', 'THEIRS');

        $foreign = $this->actAs(self::OWNER)->getJson('/api/widgets/2');
        $this->assertStringNotContainsString('THEIRS', (string) $foreign->getContent());
    }

    /** @test */
    public function an_admin_warming_the_mcp_read_cache_does_not_leak_to_a_view_own_user(): void
    {
        // executeGetById()/executeGetByFilter() -> applyRequestFilters() built its
        // cache key without the owner scope, so every MCP read/list shared one.
        $this->assertStringContainsString('THEIRS', $this->mcp(self::ADMIN, 'read_widgets', ['id' => 2]));

        $this->assertStringNotContainsString('THEIRS', $this->mcp(self::OWNER, 'read_widgets', ['id' => 2]));
    }

    /** @test */
    public function an_admin_warming_the_mcp_list_cache_does_not_leak_to_a_view_own_user(): void
    {
        $this->assertStringContainsString('THEIRS', $this->mcp(self::ADMIN, 'list_widgets', []));

        $owner = $this->mcp(self::OWNER, 'list_widgets', []);
        $this->assertStringContainsString('MINE', $owner);
        $this->assertStringNotContainsString('THEIRS', $owner);
    }

    /** @test */
    public function two_view_own_users_do_not_share_a_cached_mcp_list(): void
    {
        $this->assertStringContainsString('MINE', $this->mcp(self::OWNER, 'list_widgets', []));

        $this->assertStringNotContainsString('MINE', $this->mcp(43, 'list_widgets', []));
    }

    /** @test */
    public function a_cached_table_function_does_not_leak_across_owner_scopes(): void
    {
        $call = function (int $userId): array {
            $this->actAs($userId);
            $response = app(RecordService::class)->executeTableFunction(
                Request::create('/api/widgets/rpc/mine', 'GET'),
                'widgets',
                'mine'
            );

            return (array) ($response->getData(true)['data'] ?? []);
        };

        $this->assertSame(['MINE', 'THEIRS'], $call(self::ADMIN));
        $this->assertSame(['MINE'], $call(self::OWNER));
    }
}

class OwnRecordsCacheFunctionHandler
{
    /**
     * A cacheable function whose result is itself own-scoped: the function's
     * own cache key must carry the scope, or the first caller's rows are
     * served to everyone.
     *
     * @return array<int, string>
     */
    public function mine(Request $request): array
    {
        $rows = (array) (RecordService::executeGetByFilter('widgets', [])['data'] ?? []);

        return array_values(array_map(fn($row): string => (string) (is_array($row) ? $row['name'] : $row->name), $rows));
    }
}
