<?php

namespace Sopheak\Core\Tests\Unit;

use Exception;
use Illuminate\Http\Request;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Tests\TestCase;

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
                    'function_method' => 'handle',
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
                    'function_method' => 'handle',
                ],
            ],
            [$request, 'users', []]
        );

        $this->assertInstanceOf(Request::class, $params[0]);
        $this->assertSame('bar', $params[0]->get('foo'));
    }
}

class TestTriggerHandler
{
    public static function handle(Request $request, string $table, array $context): array
    {
        return ['foo' => 'bar'];
    }
}
