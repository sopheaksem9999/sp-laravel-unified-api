<?php

namespace Sopheak\Core\Tests\Unit;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use PDO;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Utilities\PermissionUtils;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Utilities\RecordUtils;
use Sopheak\Core\Types\RecordTableType;


class BasicTest extends TestCase
{
    /** @test */
    public function it_can_run_basic_test(): void
    {
        $this->assertTrue(true);
    }

    /** @test */
    public function it_has_laravel_application(): void
    {
        $this->assertNotNull($this->app);
    }

    /** @test */
    public function it_can_access_config(): void
    {
        $this->assertIsArray(config('record.tables'));
    }

    /** @test */
    public function it_serves_openapi_json_without_authentication(): void
    {
        $filePath = storage_path('openapi-schema.json');

        if (!is_dir(dirname($filePath))) {
            mkdir(dirname($filePath), 0777, true);
        }

        file_put_contents($filePath, json_encode(['openapi' => '3.0.3'], JSON_THROW_ON_ERROR));

        $matchedRoute = app('router')->getRoutes()->match(Request::create('/api/docs/openapi', 'GET'));
        $this->assertSame('Closure', $matchedRoute->getActionName());

        $testResponse = $this->getJson('/api/docs/openapi');

        $testResponse
            ->assertStatus(200)
            ->assertJson([
                'openapi' => '3.0.3',
            ]);

        @unlink($filePath);
    }

    /** @test */
    public function it_throws_when_table_trigger_class_does_not_exist(): void
    {
        $service = new RecordService();
        $request = Request::create('/test', 'GET');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Table trigger class 'App\\DoesNotExist\\Trigger' does not exist");

        $service->executeTableTrigger(
            [
                [
                    'class' => 'App\\DoesNotExist\\Trigger',
                    'functionName' => 'handle',
                ],
            ],
            [$request, 'users', []]
        );
    }

    /** @test */
    public function it_executes_table_trigger_and_merges_array_result_into_request(): void
    {
        $service = new RecordService();
        $request = Request::create('/test', 'GET');

        $params = $service->executeTableTrigger(
            [
                [
                    'class' => TestTriggerHandler::class,
                    'functionName' => 'handle',
                ],
            ],
            [$request, 'users', []]
        );

        $this->assertInstanceOf(Request::class, $params[0]);
        $this->assertSame('bar', $params[0]->get('foo'));
    }

    /** @test */
    public function it_builds_pgsql_composite_payload_from_array(): void
    {
        DB::shouldReceive('getDriverName')->andReturn('pgsql');
        DB::shouldReceive('connection')->andReturnSelf();
        DB::shouldReceive('getPdo')->andReturn(new PDO('sqlite::memory:'));
        DB::shouldReceive('raw')->andReturnUsing(fn(string $sql): Expression => new Expression($sql));

        $payload = [
            'name' => 'Example',
            'location' => [
                'lat' => 11.5,
                'lng' => 104.9,
            ],
        ];

        $columns = [
            'location' => [
                'type' => 'USER-DEFINED',
                'udt_name' => 'geo_point',
                'udt_schema' => 'public',
                'compositeFields' => ['lat', 'lng'],
            ],
        ];

        $result = RecordUtils::applyCompositeTypes($payload, $columns);

        $this->assertInstanceOf(Expression::class, $result['location']);
        $this->assertSame('Example', $result['name']);
    }

    /** @test */
    public function it_builds_pgsql_composite_payload_from_json_string(): void
    {
        DB::shouldReceive('getDriverName')->andReturn('pgsql');
        DB::shouldReceive('connection')->andReturnSelf();
        DB::shouldReceive('getPdo')->andReturn(new PDO('sqlite::memory:'));
        DB::shouldReceive('raw')->andReturnUsing(fn(string $sql): Expression => new Expression($sql));

        $payload = [
            'location' => json_encode(['lat' => 11.5, 'lng' => 104.9]),
        ];

        $columns = [
            'location' => [
                'type' => 'USER-DEFINED',
                'udt_name' => 'geo_point',
                'udt_schema' => 'public',
                'compositeFields' => ['lat', 'lng'],
            ],
        ];

        $result = RecordUtils::applyCompositeTypes($payload, $columns);

        $this->assertInstanceOf(Expression::class, $result['location']);
    }

    /** @test */
    public function it_maps_permission_for_string_pmsName(): void
    {
        Config::set('record.permission_separator', ':');
        Config::set('record.tables', [
            'departments' => (object) ['pmsName' => 'department'],
        ]);

        $perms = PermissionUtils::mapPermissions('departments', 'read');

        $this->assertSame(['view:department'], $perms);
        $this->assertSame('view:department', PermissionUtils::mapPermission('departments', 'read'));
    }

    /** @test */
    public function it_maps_permissions_for_array_pmsName(): void
    {
        Config::set('record.permission_separator', ':');
        Config::set('record.tables', [
            'departments' => (object) ['pmsName' => ['department', 'dept']],
        ]);

        $perms = PermissionUtils::mapPermissions('departments', 'read');

        $this->assertSame(['view:department', 'view:dept'], $perms);
        $this->assertSame('view:department', PermissionUtils::mapPermission('departments', 'read'));
    }

    /** @test */
    public function it_allows_public_action_when_public_is_true(): void
    {
        Config::set('record.tables', [
            'departments' => new RecordTableType(
                table: 'departments',
                pmsName: 'department',
                public: true,
            ),
        ]);

        $this->assertTrue(PermissionUtils::isPublicAction('departments', 'read'));
        $this->assertTrue(PermissionUtils::isPublicAction('departments', 'create'));
    }
}

class TestTriggerHandler
{
    public static function handle(Request $request, string $table, array $context): array
    {
        return ['foo' => 'bar'];
    }
}
