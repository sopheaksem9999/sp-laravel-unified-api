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

    /** @test */
    public function it_declares_concise_filter_parameters_and_references_configured_filter_documentation_once(): void
    {
        Config::set('record.api_prefix', 'api/v2');
        Config::set('sp-laravel-api.openapi.filter_documentation_url', 'https://docs.example.test/guide/api-filter-operators');
        Config::set('record.tables', [
            'invoices' => new RecordTableType(
                table: 'invoices',
                columns: [
                    'id' => ['type' => 'bigint', 'nullable' => false],
                    'status' => ['type' => 'string', 'nullable' => false],
                    'total' => ['type' => 'decimal', 'nullable' => true],
                    'created_at' => ['type' => 'datetime', 'nullable' => true],
                ],
            ),
        ]);

        $spec = (new OpenApiService())->generateInternal();
        $parameters = $spec['paths']['/api/v2/invoices']['get']['parameters'] ?? [];
        $operation = $spec['paths']['/api/v2/invoices']['get'];
        $byName = collect($parameters)->keyBy('name');

        $this->assertSame([
            'description' => 'Filter syntax and supported operators',
            'url' => 'https://docs.example.test/guide/api-filter-operators',
        ], $operation['externalDocs']);

        foreach (['id', 'status', 'total', 'created_at'] as $column) {
            $this->assertTrue($byName->has($column), sprintf("Expected a '%s' filter parameter on the list operation", $column));
            $param = $byName->get($column);
            $this->assertSame('query', $param['in']);
            $this->assertSame('string', $param['schema']['type']);
            $this->assertSame(sprintf('Filter value for `%s`; use `{operator}.{value}` syntax.', $column), $param['description']);
            $this->assertStringNotContainsString('contains.', $param['description']);
            $this->assertStringNotContainsString('filter[', $param['description']);
        }

        $this->assertSame('gte.100', $byName->get('total')['example']);
        $this->assertSame('gte.2026-01-01', $byName->get('created_at')['example']);
        $this->assertSame('eq.value', $byName->get('status')['example']);
    }

    /** @test */
    public function it_declares_select_sortby_order_and_search_as_structured_parameters(): void
    {
        Config::set('record.api_prefix', 'api/v2');
        Config::set('record.tables', [
            'invoices' => new RecordTableType(
                table: 'invoices',
                columns: [
                    'id' => ['type' => 'bigint', 'nullable' => false],
                    'ref_number' => ['type' => 'varchar', 'nullable' => true],
                ],
                searchable: ['ref_number'],
                relationships: [
                    'customer' => new RecordBelongsToType(table: 'customers', foreignKey: 'customer_id'),
                ],
            ),
            'customers' => new RecordTableType(
                table: 'customers',
                columns: ['id' => ['type' => 'bigint', 'nullable' => false]],
            ),
        ]);

        $spec = (new OpenApiService())->generateInternal();
        $parameters = $spec['paths']['/api/v2/invoices']['get']['parameters'] ?? [];
        $byName = collect($parameters)->keyBy('name');

        $this->assertTrue($byName->has('select'));
        $this->assertSame('*,customer(*)', $byName->get('select')['example']);

        $this->assertTrue($byName->has('sortby'));
        $this->assertSame(['id', 'ref_number'], $byName->get('sortby')['schema']['enum']);

        $this->assertTrue($byName->has('order'));
        $this->assertSame(['asc', 'desc'], $byName->get('order')['schema']['enum']);

        $this->assertTrue($byName->has('search'));
        $this->assertStringContainsString('ref_number', $byName->get('search')['description']);
    }

    /** @test */
    public function list_only_parameters_do_not_leak_onto_the_create_operation(): void
    {
        Config::set('record.api_prefix', 'api/v2');
        Config::set('record.tables', [
            'invoices' => new RecordTableType(
                table: 'invoices',
                columns: [
                    'id' => ['type' => 'bigint', 'nullable' => false],
                    'status' => ['type' => 'string', 'nullable' => false],
                ],
            ),
        ]);

        $spec = (new OpenApiService())->generateInternal();
        $path = $spec['paths']['/api/v2/invoices'];

        // Pagination/filter/select params belong to the 'get' operation only.
        $this->assertArrayHasKey('parameters', $path['get']);
        $getNames = collect($path['get']['parameters'])->pluck('name');
        $this->assertTrue($getNames->contains('page'));
        $this->assertTrue($getNames->contains('status'));
        $this->assertTrue($getNames->contains('select'));

        // The 'post' (create) operation has no parameters of its own, and the shared
        // path-level 'parameters' (if present) must not include list-only params.
        $this->assertArrayNotHasKey('parameters', $path['post']);
        foreach ($path['parameters'] ?? [] as $sharedParam) {
            $this->assertNotContains($sharedParam['name'], ['page', 'per_page', 'status', 'select', 'sortby', 'order', 'search']);
        }
    }

    /** @test */
    public function it_documents_auth_permissions_and_tenant_on_table_operations(): void
    {
        Config::set('record.api_prefix', 'api/v2');
        Config::set('record.tables', [
            'products' => new RecordTableType(
                table: 'products',
                hasTenantId: true,
                isAuthRead: true,
                isAuthWrite: true,
                columns: ['id' => ['type' => 'bigint', 'nullable' => false]],
                permissions: [
                    'read' => ['products:view'],
                    'create' => ['products:create', 'admin:inventory'],
                ],
            ),
        ]);

        $spec = (new OpenApiService())->generateInternal();
        $path = $spec['paths']['/api/v2/products'];

        $list = $path['get'];
        $this->assertSame('read', $list['x-sp-auth']['mode']);
        $this->assertSame('isAuthRead', $list['x-sp-auth']['flag']);
        $this->assertTrue($list['x-sp-auth']['flag_value']);
        $this->assertSame('bearer', $list['x-sp-auth']['auth']);
        $this->assertFalse($list['x-sp-auth']['public']);
        $this->assertSame(['products:view'], $list['x-sp-auth']['permissions']);
        $this->assertTrue($list['x-sp-auth']['tenant']);
        $this->assertSame('config/records/tables/products.php', $list['x-sp-auth']['source']);
        $this->assertStringContainsString('**Authorization:** Bearer token required — read auth (`isAuthRead=true` in config/records/tables/products.php)', $list['description']);
        $this->assertStringContainsString('**Permission scope(s):** `products:view`', $list['description']);

        $create = $path['post'];
        $this->assertSame('write', $create['x-sp-auth']['mode']);
        $this->assertSame('isAuthWrite', $create['x-sp-auth']['flag']);
        $this->assertSame(['products:create', 'admin:inventory'], $create['x-sp-auth']['permissions']);
        $this->assertStringContainsString('**Authorization:** Bearer token required — write auth (`isAuthWrite=true` in config/records/tables/products.php)', $create['description']);
        $this->assertStringContainsString('**Permission scope(s):** `products:create`, `admin:inventory`', $create['description']);
    }

    /** @test */
    public function it_documents_public_table_operations_without_permission_scopes(): void
    {
        Config::set('record.api_prefix', 'api/v2');
        Config::set('record.tables', [
            'products' => new RecordTableType(
                table: 'products',
                isAuthRead: false,
                isAuthWrite: false,
                columns: ['id' => ['type' => 'bigint', 'nullable' => false]],
            ),
        ]);

        $spec = (new OpenApiService())->generateInternal();
        $list = $spec['paths']['/api/v2/products']['get'];

        $this->assertSame('public', $list['x-sp-auth']['auth']);
        $this->assertTrue($list['x-sp-auth']['public']);
        $this->assertFalse($list['x-sp-auth']['flag_value']);
        $this->assertSame([], $list['x-sp-auth']['permissions']);
        $this->assertStringContainsString('**Authorization:** Public — no authentication required (`isAuthRead=false` in config/records/tables/products.php)', $list['description']);
    }

    /** @test */
    public function it_documents_middleware_from_the_middleware_map_merging_default_and_table_entries(): void
    {
        Config::set('record.api_prefix', 'api/v2');
        Config::set('record.middleware_map', [
            'default' => [
                '*' => ['throttle:api'],
                'write' => ['auth:sanctum'],
            ],
            'tables' => [
                'products' => [
                    'write' => ['subscribed'],
                ],
            ],
        ]);
        Config::set('record.tables', [
            'products' => new RecordTableType(
                table: 'products',
                columns: ['id' => ['type' => 'bigint', 'nullable' => false]],
            ),
        ]);

        $spec = (new OpenApiService())->generateInternal();
        $create = $spec['paths']['/api/v2/products']['post'];

        $this->assertSame(['throttle:api', 'auth:sanctum', 'subscribed'], $create['x-sp-auth']['middleware']);
        $this->assertStringContainsString('**Route middleware:** `throttle:api`, `auth:sanctum`, `subscribed`', $create['description']);

        $list = $spec['paths']['/api/v2/products']['get'];
        $this->assertSame(['throttle:api'], $list['x-sp-auth']['middleware']);
    }

    /** @test */
    public function it_documents_global_function_publicity_from_config(): void
    {
        Config::set('record.api_prefix', 'api/v2');
        Config::set('record.rpc_prefix', '');
        Config::set('record.tables', []);
        Config::set('record.global_functions', [
            'auth/login' => [
                'httpMethod' => ['POST'],
            ],
            'health' => [
                'httpMethod' => ['GET'],
                'isPublic' => false,
            ],
        ]);

        $spec = (new OpenApiService())->generateInternal();

        $login = $spec['paths']['/api/v2/auth/login']['post'];
        $this->assertSame('public', $login['x-sp-auth']['auth']);
        $this->assertTrue($login['x-sp-auth']['public']);
        $this->assertStringContainsString('Public — no authentication required', $login['description']);

        $health = $spec['paths']['/api/v2/health']['get'];
        $this->assertSame('bearer', $health['x-sp-auth']['auth']);
        $this->assertFalse($health['x-sp-auth']['public']);
        $this->assertStringContainsString('**Authorization:** Bearer token required — function call (`isPublic=false` in config/records/global-functions/health.php)', $health['description']);
    }

    /** @test */
    public function it_documents_table_rpc_function_publicity_from_config(): void
    {
        Config::set('record.api_prefix', 'api/v2');
        Config::set('record.rpc_prefix', '');
        Config::set('record.tables', [
            'invoices' => new RecordTableType(
                table: 'invoices',
                columns: ['id' => ['type' => 'bigint', 'nullable' => false]],
                functions: [
                    'send' => [
                        'httpMethod' => ['POST'],
                    ],
                    'preview' => [
                        'httpMethod' => ['GET'],
                        'isPublic' => true,
                    ],
                ],
            ),
        ]);

        $spec = (new OpenApiService())->generateInternal();

        $send = $spec['paths']['/api/v2/invoices/send']['post'];
        $this->assertSame('bearer', $send['x-sp-auth']['auth']);
        $this->assertFalse($send['x-sp-auth']['public']);
        $this->assertStringContainsString('`isPublic=false`', $send['description']);

        $preview = $spec['paths']['/api/v2/invoices/preview']['get'];
        $this->assertSame('public', $preview['x-sp-auth']['auth']);
        $this->assertTrue($preview['x-sp-auth']['public']);
        $this->assertStringContainsString('`isPublic=true`', $preview['description']);
    }

    /** @test */
    public function it_documents_pagination_defaults_in_main_document_and_list_operation(): void
    {
        Config::set('record.api_prefix', 'api/v2');
        Config::set('record.pagination.default_mode', 'cursor');
        Config::set('record.pagination.cursor.default_column', 'created_at');
        Config::set('record.tables', [
            'invoices' => new RecordTableType(
                table: 'invoices',
                columns: ['id' => ['type' => 'bigint', 'nullable' => false]],
            ),
        ]);

        $spec = (new OpenApiService())->generateInternal();

        $this->assertStringContainsString('`record.pagination.default_mode` = `cursor`', $spec['info']['description']);
        $this->assertStringContainsString('cursor default column: `created_at`', $spec['info']['description']);

        $list = $spec['paths']['/api/v2/invoices']['get'];
        $this->assertStringContainsString('**Pagination:** Default mode: `cursor` (config `record.pagination.default_mode`); cursor pagination via `cursor` parameter (default cursor column `created_at`).', $list['description']);
    }

    /** @test */
    public function it_documents_offset_pagination_when_that_is_the_configured_default(): void
    {
        Config::set('record.api_prefix', 'api/v2');
        Config::set('record.pagination.default_mode', 'offset');
        Config::set('record.tables', [
            'invoices' => new RecordTableType(
                table: 'invoices',
                columns: ['id' => ['type' => 'bigint', 'nullable' => false]],
            ),
        ]);

        $spec = (new OpenApiService())->generateInternal();

        $this->assertStringContainsString('`record.pagination.default_mode` = `offset`', $spec['info']['description']);
        $this->assertStringContainsString('**Pagination:** Default mode: `offset`', $spec['paths']['/api/v2/invoices']['get']['description']);
    }

    /** @test */
    public function it_excludes_column_hiddens_from_read_schemas_but_keeps_them_writable(): void
    {
        Config::set('record.api_prefix', 'api/v2');
        Config::set('record.tables', [
            'users' => new RecordTableType(
                table: 'users',
                columns: [
                    'id' => ['type' => 'bigint', 'nullable' => false],
                    'email' => ['type' => 'string', 'nullable' => false],
                    'password' => ['type' => 'string', 'nullable' => false],
                ],
                columnHiddens: ['password'],
            ),
        ]);

        $spec = (new OpenApiService())->generateInternal();
        $schemas = $spec['components']['schemas'];

        $this->assertArrayNotHasKey('password', $schemas['Users']['properties']);
        $this->assertArrayNotHasKey('password', $schemas['UsersRead']['properties']);
        $this->assertArrayHasKey('password', $schemas['UsersWrite']['properties']);
        $this->assertStringContainsString('columnHiddens', $schemas['Users']['description']);
    }

    /** @test */
    public function it_marks_column_write_disabled_fields_as_read_only_in_the_write_schema(): void
    {
        Config::set('record.api_prefix', 'api/v2');
        Config::set('record.tables', [
            'invoices' => new RecordTableType(
                table: 'invoices',
                columns: [
                    'id' => ['type' => 'bigint', 'nullable' => false],
                    'total' => ['type' => 'decimal', 'nullable' => false],
                ],
                columnWriteDisabled: ['total'],
            ),
        ]);

        $spec = (new OpenApiService())->generateInternal();
        $write = $spec['components']['schemas']['InvoicesWrite']['properties']['total'];

        $this->assertTrue($write['readOnly']);
        $this->assertStringContainsString('columnWriteDisabled', $write['description']);
        $this->assertStringContainsString('Write schema', $spec['components']['schemas']['InvoicesWrite']['description']);
    }

    /** @test */
    public function it_excludes_auto_increment_id_from_the_write_schema_but_keeps_uuid_id(): void
    {
        Config::set('record.api_prefix', 'api/v2');
        Config::set('record.id_type', 'integer');
        Config::set('record.tables', [
            'invoices' => new RecordTableType(
                table: 'invoices',
                columns: [
                    'id' => ['type' => 'bigint', 'nullable' => false],
                    'ref' => ['type' => 'string', 'nullable' => false],
                ],
            ),
        ]);

        $spec = (new OpenApiService())->generateInternal();
        $this->assertArrayNotHasKey('id', $spec['components']['schemas']['InvoicesWrite']['properties']);
        $this->assertStringContainsString('auto-increment', $spec['components']['schemas']['InvoicesWrite']['description']);
        $this->assertSame('integer', $spec['paths']['/api/v2/invoices/{id}']['parameters'][0]['schema']['type']);

        Config::set('record.id_type', 'uuid');
        $spec = (new OpenApiService())->generateInternal();
        $writeId = $spec['components']['schemas']['InvoicesWrite']['properties']['id'];
        $this->assertSame('string', $writeId['type']);
        $this->assertSame('uuid', $writeId['format']);
        $this->assertStringContainsString('server generate one', $spec['components']['schemas']['InvoicesWrite']['description']);
        $this->assertSame('string', $spec['paths']['/api/v2/invoices/{id}']['parameters'][0]['schema']['type']);
        $this->assertSame('uuid', $spec['paths']['/api/v2/invoices/{id}']['parameters'][0]['schema']['format']);
    }

    /** @test */
    public function it_declares_the_lazy_parameter_on_list_operations(): void
    {
        Config::set('record.api_prefix', 'api/v2');
        Config::set('record.tables', [
            'invoices' => new RecordTableType(
                table: 'invoices',
                columns: ['id' => ['type' => 'bigint', 'nullable' => false]],
            ),
        ]);

        $spec = (new OpenApiService())->generateInternal();
        $parameters = $spec['paths']['/api/v2/invoices']['get']['parameters'];
        $lazy = collect($parameters)->firstWhere('name', 'lazy');

        $this->assertNotNull($lazy);
        $this->assertSame('query', $lazy['in']);
        $this->assertSame('boolean', $lazy['schema']['type']);
        $this->assertStringContainsString('lazy=true', $lazy['description']);
    }

    /** @test */
    public function it_does_not_emit_an_orphaned_audit_tag(): void
    {
        Config::set('record.api_prefix', 'api/v2');
        Config::set('record.tables', []);
        Config::set('audit.enabled', true);

        $spec = (new OpenApiService())->generateInternal();
        $tagNames = collect($spec['tags'])->pluck('name')->all();

        $this->assertNotContains('Audit', $tagNames);
    }

    /** @test */
    public function it_documents_id_type_max_depth_and_rate_limits_in_the_main_document(): void
    {
        Config::set('record.api_prefix', 'api/v2');
        Config::set('record.id_type', 'uuid');
        Config::set('record.max_depth', 4);
        Config::set('record.rate_limits', [
            'users' => [
                'create' => ['limit' => 50, 'decay_minutes' => 1],
            ],
        ]);
        Config::set('record.tables', [
            'invoices' => new RecordTableType(
                table: 'invoices',
                columns: ['id' => ['type' => 'bigint', 'nullable' => false]],
            ),
        ]);

        $spec = (new OpenApiService())->generateInternal();
        $description = $spec['info']['description'];

        $this->assertStringContainsString('record.id_type', $description);
        $this->assertStringContainsString('uuid', $description);
        $this->assertStringContainsString('limited to `4` levels (config `record.max_depth`)', $description);
        $this->assertStringContainsString('Rate Limits', $description);
        $this->assertStringContainsString('`users` — create: 50/1min', $description);
        $this->assertStringContainsString('Public', $description);
        $this->assertStringContainsString('x-sp-auth', $description);
    }
}
