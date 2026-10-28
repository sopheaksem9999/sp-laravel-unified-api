<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use ReflectionMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Sopheak\Core\Services\RecordApiResponseService;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Tests\TestCase;

class DebugHeaderSecurityTest extends TestCase
{
    /** @test */
    public function x_debug_header_is_ignored_in_production_environment(): void
    {
        App::detectEnvironment(fn(): string => 'production');
        Config::set('record.debug', false);

        $request = Request::create('/api/test', 'GET', [], [], [], [
            'HTTP_X_DEBUG' => 'true',
        ]);
        $this->app->instance('request', $request);

        $reflection = new ReflectionMethod(RecordApiResponseService::class, 'shouldIncludeDebugDetails');

        $this->assertFalse($reflection->invoke(null));

        $recordService = $this->app->make(RecordService::class);
        $this->assertFalse($recordService->shouldIncludeDebug($request));
    }

    /** @test */
    public function x_debug_header_is_allowed_in_local_environment(): void
    {
        App::detectEnvironment(fn(): string => 'local');
        Config::set('record.debug', false);

        $request = Request::create('/api/test', 'GET', [], [], [], [
            'HTTP_X_DEBUG' => 'true',
        ]);
        $this->app->instance('request', $request);

        $reflection = new ReflectionMethod(RecordApiResponseService::class, 'shouldIncludeDebugDetails');

        $this->assertTrue($reflection->invoke(null));

        $recordService = $this->app->make(RecordService::class);
        $this->assertTrue($recordService->shouldIncludeDebug($request));
    }

    /** @test */
    public function config_debug_enabled_takes_precedence_in_any_environment(): void
    {
        App::detectEnvironment(fn(): string => 'production');
        Config::set('record.debug', true);

        $request = Request::create('/api/test', 'GET');
        $this->app->instance('request', $request);

        $reflection = new ReflectionMethod(RecordApiResponseService::class, 'shouldIncludeDebugDetails');

        $this->assertTrue($reflection->invoke(null));

        $recordService = $this->app->make(RecordService::class);
        $this->assertTrue($recordService->shouldIncludeDebug($request));
    }
}
