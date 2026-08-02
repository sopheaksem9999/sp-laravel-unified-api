<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Support\Facades\Config;
use Sopheak\Core\Services\OpenApiService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordBelongsToType;
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class OpenApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SchemaRegistryUtils::refresh();
    }

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
    public function it_marks_public_table_read_endpoints_as_not_requiring_auth(): void
    {
        Config::set('record.api_prefix', 'api/v2');
        Config::set('record.tables', [
            'products' => new RecordTableType(
                table: 'products',
                isAuthRead: false,
                isAuthWrite: true,
                columns: ['id' => ['type' => 'bigint', 'nullable' => false]],
            ),
        ]);

        $service = new OpenApiService();
        $spec = $service->generateInternal();

        $this->assertSame([], $spec['paths']['/api/v2/products']['get']['security']);
        $this->assertSame([['bearerAuth' => []]], $spec['paths']['/api/v2/products']['post']['security']);
    }

    /** @test */
    public function it_marks_protected_table_endpoints_as_requiring_bearer_auth_by_default(): void
    {
        Config::set('record.api_prefix', 'api/v2');
        Config::set('record.tables', [
            'products' => new RecordTableType(
                table: 'products',
                columns: ['id' => ['type' => 'bigint', 'nullable' => false]],
            ),
        ]);

        $service = new OpenApiService();
        $spec = $service->generateInternal();

        $this->assertSame([['bearerAuth' => []]], $spec['paths']['/api/v2/products']['get']['security']);
        $this->assertSame([['bearerAuth' => []]], $spec['paths']['/api/v2/products']['post']['security']);
    }

    /** @test */
    public function it_marks_public_global_rpc_function_as_not_requiring_auth(): void
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

        $this->assertSame([], $spec['paths']['/api/v2/rpc/auth/login']['post']['security']);
    }

    /** @test */
    public function it_marks_non_public_global_rpc_function_as_requiring_bearer_auth(): void
    {
        Config::set('app.url', 'http://localhost');
        Config::set('record.api_prefix', 'api/v2');
        Config::set('record.rpc_prefix', 'rpc');
        Config::set('record.tables', []);
        Config::set('record.global_functions', [
            'auth/logout' => [
                'httpMethod' => ['POST'],
                'description' => 'Logout',
                'isPublic' => false,
            ],
        ]);

        $service = new OpenApiService();
        $spec = $service->generateInternal();

        $this->assertSame([['bearerAuth' => []]], $spec['paths']['/api/v2/rpc/auth/logout']['post']['security']);
    }

    /** @test */
    public function it_marks_table_scoped_rpc_function_as_requiring_auth_by_default(): void
    {
        Config::set('record.api_prefix', 'api/v2');
        Config::set('record.rpc_prefix', 'rpc');
        Config::set('record.tables', [
            'invoices' => new RecordTableType(
                table: 'invoices',
                columns: ['id' => ['type' => 'bigint', 'nullable' => false]],
                functions: [
                    'send' => ['httpMethod' => ['POST'], 'description' => 'Send'],
                ],
            ),
        ]);

        $service = new OpenApiService();
        $spec = $service->generateInternal();

        $this->assertSame([['bearerAuth' => []]], $spec['paths']['/api/v2/invoices/rpc/send']['post']['security']);
    }

    /** @test */
    public function it_marks_public_table_scoped_rpc_function_as_not_requiring_auth(): void
    {
        Config::set('record.api_prefix', 'api/v2');
        Config::set('record.rpc_prefix', 'rpc');
        Config::set('record.tables', [
            'invoices' => new RecordTableType(
                table: 'invoices',
                columns: ['id' => ['type' => 'bigint', 'nullable' => false]],
                functions: [
                    'preview' => ['httpMethod' => ['GET'], 'description' => 'Preview', 'isPublic' => true],
                ],
            ),
        ]);

        $service = new OpenApiService();
        $spec = $service->generateInternal();

        $this->assertSame([], $spec['paths']['/api/v2/invoices/rpc/preview']['get']['security']);
    }

    /** @test */
    public function it_uses_name_over_description_for_global_rpc_function_summary(): void
    {
        Config::set('app.url', 'http://localhost');
        Config::set('record.api_prefix', 'api/v2');
        Config::set('record.rpc_prefix', 'rpc');
        Config::set('record.tables', []);
        Config::set('record.global_functions', [
            'auth/login' => [
                'httpMethod' => ['POST'],
                'name' => 'Login',
                'description' => 'Authenticate a user and return an access token.',
            ],
        ]);

        $service = new OpenApiService();
        $spec = $service->generateInternal();

        $this->assertSame('RPC - Login', $spec['paths']['/api/v2/rpc/auth/login']['post']['summary']);
    }

    /** @test */
    public function it_falls_back_to_description_when_name_is_not_set_for_global_rpc_function(): void
    {
        Config::set('app.url', 'http://localhost');
        Config::set('record.api_prefix', 'api/v2');
        Config::set('record.rpc_prefix', 'rpc');
        Config::set('record.tables', []);
        Config::set('record.global_functions', [
            'auth/login' => [
                'httpMethod' => ['POST'],
                'description' => 'Authenticate a user and return an access token.',
            ],
        ]);

        $service = new OpenApiService();
        $spec = $service->generateInternal();

        $this->assertSame(
            'RPC - Authenticate a user and return an access token.',
            $spec['paths']['/api/v2/rpc/auth/login']['post']['summary'],
        );
    }

    /** @test */
    public function it_uses_name_over_description_for_table_scoped_rpc_function_summary(): void
    {
        Config::set('record.api_prefix', 'api/v2');
        Config::set('record.rpc_prefix', 'rpc');
        Config::set('record.tables', [
            'invoices' => new RecordTableType(
                table: 'invoices',
                columns: ['id' => ['type' => 'bigint', 'nullable' => false]],
                functions: [
                    'send' => [
                        'httpMethod' => ['POST'],
                        'name' => 'Send Invoice',
                        'description' => 'Send the invoice to the customer via email.',
                    ],
                ],
            ),
        ]);

        $service = new OpenApiService();
        $spec = $service->generateInternal();

        $this->assertSame('RPC - Send Invoice', $spec['paths']['/api/v2/invoices/rpc/send']['post']['summary']);
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
