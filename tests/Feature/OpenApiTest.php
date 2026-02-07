<?php

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Support\Facades\Config;
use Sopheak\Core\Services\OpenApiService;
use Sopheak\Core\Tests\TestCase;

class OpenApiTest extends TestCase
{
    /** @test */
    public function it_generates_correct_server_url_without_duplicate_api_path(): void
    {
        // Set configuration to match the reported issue
        Config::set('app.url', 'http://mylekha_task_management_back.test');
        Config::set('record.api_prefix', 'api/v1');
        // Clear tables to avoid SchemaRegistryUtils errors due to array vs object mismatch in TestCase defaults
        Config::set('record.tables', []);

        $service = new OpenApiService();
        $spec = $service->generateInternal();

        // Check server URL
        $serverUrl = $spec['servers'][0]['url'];
        
        // The fix should remove the appended '/api', so it should just be the app.url
        $this->assertEquals('http://mylekha_task_management_back.test', $serverUrl);
    }

    /** @test */
    public function it_generates_global_rpc_paths_with_rpc_prefix(): void
    {
        Config::set('app.url', 'http://localhost');
        Config::set('record.api_prefix', 'api/v2');
        Config::set('record.rpc_prefix', 'rpc');
        Config::set('record.tables', []);
        Config::set('record.global_functions', [
            'auth/login' => [
                'httpMethod' => ['POST'],
                'description' => 'Login',
            ],
        ]);

        $service = new OpenApiService();
        $spec = $service->generateInternal();

        $this->assertArrayHasKey('paths', $spec);
        $this->assertArrayHasKey('/api/v2/rpc/auth/login', $spec['paths']);
        $this->assertSame(['RPC - Auth'], $spec['paths']['/api/v2/rpc/auth/login']['post']['tags']);
    }

    /** @test */
    public function it_generates_global_rpc_paths_without_rpc_prefix(): void
    {
        Config::set('app.url', 'http://localhost');
        Config::set('record.api_prefix', 'api/v2');
        Config::set('record.rpc_prefix', '');
        Config::set('record.tables', []);
        Config::set('record.global_functions', [
            'auth/login' => [
                'httpMethod' => ['POST'],
                'description' => 'Login',
            ],
        ]);

        $service = new OpenApiService();
        $spec = $service->generateInternal();

        $this->assertArrayHasKey('paths', $spec);
        $this->assertArrayHasKey('/api/v2/auth/login', $spec['paths']);
        $this->assertArrayNotHasKey('/api/v2/rpc/auth/login', $spec['paths']);
        $this->assertSame(['RPC - Auth'], $spec['paths']['/api/v2/auth/login']['post']['tags']);
    }
}
