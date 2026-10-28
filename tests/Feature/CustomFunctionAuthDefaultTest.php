<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use ReflectionMethod;
use Illuminate\Http\Request;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Tests\TestCase;

class DummyRpcController
{
    public function customAction(Request $request)
    {
        return response()->json(['ok' => true]);
    }
}

class CustomFunctionAuthDefaultTest extends TestCase
{
    /** @test */
    public function array_configured_custom_functions_default_to_requiring_auth(): void
    {
        $service = app(RecordService::class);
        $request = Request::create('/api/v1/test_table/rpc/custom_action', 'POST');

        $functionConfig = [
            'class' => DummyRpcController::class,
            'functionName' => 'customAction',
        ];

        $reflection = new ReflectionMethod(RecordService::class, 'executeCustomFunction');

        $response = $reflection->invoke($service, $request, $functionConfig, []);

        $this->assertSame(401, $response->getStatusCode());
    }

    /** @test */
    public function explicitly_public_custom_functions_do_not_require_auth(): void
    {
        $service = app(RecordService::class);
        $request = Request::create('/api/v1/test_table/rpc/custom_action', 'GET');

        $functionConfig = [
            'class' => DummyRpcController::class,
            'functionName' => 'customAction',
            'isPublic' => true,
        ];

        $reflection = new ReflectionMethod(RecordService::class, 'executeCustomFunction');

        $response = $reflection->invoke($service, $request, $functionConfig, []);

        $this->assertSame(200, $response->getStatusCode());
    }
}
