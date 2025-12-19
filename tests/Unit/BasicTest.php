<?php

namespace Sopheak\Core\Tests\Unit;

use Illuminate\Http\Request;
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
}
