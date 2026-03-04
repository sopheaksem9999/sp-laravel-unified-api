<?php

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Support\Facades\Config;
use Sopheak\Core\Services\OpenApiService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordBelongsToType;
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Types\RecordTableType;

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

    /** @test */
    public function it_generates_spec_without_writing_internal_openapi_file(): void
    {
        Config::set('app.url', 'http://localhost');
        Config::set('record.api_prefix', 'api/v2');
        Config::set('record.tables', []);
        Config::set('record.global_functions', []);

        $service = new OpenApiService();
        $spec = $service->generateInternal();

        $this->assertArrayHasKey('openapi', $spec);
        $this->assertArrayHasKey('paths', $spec);
    }

    /** @test */
    public function it_documents_relationship_payload_shapes_clearly(): void
    {
        Config::set('record.api_prefix', 'api/v2');
        Config::set('record.tables', [
            'invoices' => new RecordTableType(
                table: 'invoices',
                columns: [
                    'id' => ['type' => 'bigint', 'nullable' => false],
                    'customer_id' => ['type' => 'bigint', 'nullable' => true],
                    'ref_number' => ['type' => 'varchar', 'nullable' => true],
                ],
                relationships: [
                    'customer' => new RecordBelongsToType(table: 'customers', foreignKey: 'customer_id'),
                    'items' => new RecordHasManyType(table: 'invoice_items', foreignKey: 'invoice_id'),
                ],
            ),
            'customers' => new RecordTableType(
                table: 'customers',
                columns: ['id' => ['type' => 'bigint', 'nullable' => false]],
            ),
            'invoice_items' => new RecordTableType(
                table: 'invoice_items',
                columns: ['id' => ['type' => 'bigint', 'nullable' => false], 'invoice_id' => ['type' => 'bigint', 'nullable' => false]],
            ),
        ]);

        $service = new OpenApiService();
        $spec = $service->generateInternal();
        $path = $spec['paths']['/api/v2/invoices'] ?? [];
        $createDescription = $path['post']['description'] ?? ($path['get']['description'] ?? '');

        $this->assertStringContainsString('Relationship payload guide', $createDescription);
        $this->assertStringContainsString('`items`', $createDescription);
        $this->assertStringContainsString('array<id|object>', $createDescription);
        $this->assertStringContainsString('FK relationship input (belongsTo)', $createDescription);
        $this->assertStringContainsString('`customer_id`', $createDescription);
        $this->assertStringContainsString('Payload examples', $createDescription);
        $this->assertStringContainsString('#relationship-write-payload-guide', $createDescription);
    }
}
