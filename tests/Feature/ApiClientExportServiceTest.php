<?php

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Support\Facades\Config;
use Sopheak\Core\Services\ApiClient\ExportFolder;
use Sopheak\Core\Services\ApiClient\ApiClientEmitterInterface;
use Sopheak\Core\Services\ApiClient\ExportResult;
use Sopheak\Core\Services\ApiClientExportService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * @internal
 */
class ApiClientExportServiceTest extends TestCase
{
    private ApiClientExportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        SchemaRegistryUtils::refresh();
        Config::set('record.api_prefix', 'api/v1');
        Config::set('app.name', 'TestApp');
        Config::set('app.url', 'http://localhost');

        $this->service = new ApiClientExportService();
    }

    public function test_returns_all_requests_as_added_when_no_existing_collection(): void
    {
        $spec = $this->buildFixtureSpec();

        $result = $this->service->build($spec, null, null, $this->brunoEmitter());

        $this->assertCount(3, $result->added);
        $this->assertContains('List Users', $result->added);
        $this->assertContains('Create Users', $result->added);
        $this->assertContains('List Orders', $result->added);
        $this->assertSame([], $result->regenerated);
        $this->assertSame([], $result->skipped);
        $this->assertSame([], $result->suggestions);
    }

    public function test_marks_existing_requests_as_skipped_and_new_ones_as_added(): void
    {
        $spec = $this->buildFixtureSpec();
        $existing = [
            'folders' => [
                ['name' => 'Users', 'requests' => [['name' => 'List Users']]],
            ],
        ];

        $result = $this->service->build($spec, $existing, null, $this->brunoEmitter());

        $this->assertContains('List Users', $result->skipped);
        $this->assertContains('Create Users', $result->added);
        $this->assertContains('List Orders', $result->added);
        $this->assertSame([], $result->regenerated);
        $this->assertSame([], $result->suggestions);
    }

    public function test_regenerates_specific_table_when_regen_flag_is_set(): void
    {
        $spec = $this->buildFixtureSpec();
        $existing = [
            'folders' => [
                ['name' => 'Users', 'requests' => [['name' => 'List Users']]],
                ['name' => 'Orders', 'requests' => [['name' => 'List Orders']]],
            ],
        ];

        $result = $this->service->build($spec, $existing, ['users'], $this->brunoEmitter());

        $this->assertContains('List Users', $result->regenerated);
        $this->assertContains('Create Users', $result->added);
        $this->assertContains('List Orders', $result->skipped);
        $this->assertSame([], $result->suggestions);
    }

    public function test_regenerates_everything_when_regen_all_flag_is_set(): void
    {
        $spec = $this->buildFixtureSpec();
        $existing = [
            'folders' => [
                ['name' => 'Users', 'requests' => [['name' => 'List Users'], ['name' => 'Create Users']]],
                ['name' => 'Orders', 'requests' => [['name' => 'List Orders']]],
            ],
        ];

        $result = $this->service->build($spec, $existing, ['all'], $this->brunoEmitter());

        $this->assertContains('List Users', $result->regenerated);
        $this->assertContains('Create Users', $result->regenerated);
        $this->assertContains('List Orders', $result->regenerated);
        $this->assertSame([], $result->added);
        $this->assertSame([], $result->skipped);
    }

    public function test_lists_unprocessed_tables_as_suggestions(): void
    {
        $spec = $this->buildFixtureSpec();

        $result = $this->service->build($spec, null, ['users'], $this->brunoEmitter());

        $this->assertContains('Orders', $result->suggestions);
        $this->assertContains('List Users', $result->added);
        $this->assertContains('Create Users', $result->added);
        $this->assertSame([], $result->skipped);
        $this->assertSame([], $result->regenerated);
    }

    public function test_orders_folders_in_spec_order_with_rpc_last(): void
    {
        $spec = $this->buildFixtureSpec();
        $spec['paths']['/api/v1/rpc/auth/login'] = [
            'post' => [
                'tags' => ['RPC'],
                'summary' => 'RPC - Auth Login',
                'description' => 'Auth login',
            ],
        ];

        $result = $this->service->build($spec, null, null, $this->brunoEmitter());

        $folderNames = array_map(static fn (ExportFolder $f): string => $f->name, $result->folders);
        $this->assertSame(['Users', 'Orders', 'RPC'], $folderNames);
    }

    public function test_appends_existing_rpc_requests_as_skipped_when_table_not_in_regen(): void
    {
        $spec = $this->buildFixtureSpec();
        $spec['paths']['/api/v1/rpc/auth/login'] = [
            'post' => [
                'tags' => ['RPC'],
                'summary' => 'RPC - Auth Login',
                'description' => 'Auth login',
            ],
        ];
        $existing = [
            'folders' => [
                ['name' => 'RPC', 'requests' => [['name' => 'RPC - Auth Login']]],
            ],
        ];

        $result = $this->service->build($spec, $existing, ['users'], $this->brunoEmitter());

        $this->assertContains('RPC - Auth Login', $result->skipped);
    }

    public function test_falls_back_to_operation_id_when_summary_is_missing(): void
    {
        $spec = [
            'openapi' => '3.0.3',
            'info' => ['title' => 'TestApp', 'version' => '1.0.0'],
            'servers' => [['url' => 'http://localhost']],
            'paths' => [
                '/api/v1/users' => [
                    'get' => [
                        'tags' => ['Users'],
                        'operationId' => 'listUsersFallback',
                    ],
                ],
            ],
        ];

        $result = $this->service->build($spec, null, null, $this->brunoEmitter());

        $this->assertContains('listUsersFallback', $result->added);
    }

    public function test_strips_api_prefix_from_path_to_build_url_template(): void
    {
        $spec = [
            'openapi' => '3.0.3',
            'info' => ['title' => 'TestApp', 'version' => '1.0.0'],
            'servers' => [['url' => 'http://localhost']],
            'paths' => [
                '/api/v1/users/{id}' => [
                    'get' => [
                        'tags' => ['Users'],
                        'summary' => 'Get Users by ID',
                        'parameters' => [
                            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->service->build($spec, null, null, $this->brunoEmitter());
        $request = $result->folders[0]->requests[0];

        $this->assertSame('{{baseUrl}}{{apiPrefix}}/users/{id}', $request->urlTemplate);
        $this->assertSame(['id'], $request->pathParams);
    }

    public function test_extracts_query_params_with_default_values_from_spec(): void
    {
        $spec = [
            'openapi' => '3.0.3',
            'info' => ['title' => 'TestApp', 'version' => '1.0.0'],
            'servers' => [['url' => 'http://localhost']],
            'paths' => [
                '/api/v1/users' => [
                    'get' => [
                        'tags' => ['Users'],
                        'summary' => 'List Users',
                        'parameters' => [
                            ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 1]],
                            ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 25]],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->service->build($spec, null, null, $this->brunoEmitter());
        $queryParams = $result->folders[0]->requests[0]->queryParams;

        $this->assertCount(2, $queryParams);
        $names = array_column($queryParams, 'name');
        $this->assertContains('page', $names);
        $this->assertContains('per_page', $names);

        $page = array_values(array_filter($queryParams, static fn (array $p): bool => $p['name'] === 'page'))[0];
        $this->assertSame('1', (string) $page['value']);
        $this->assertTrue($page['enabled']);
    }

    public function test_uses_app_name_as_collection_name(): void
    {
        $spec = $this->buildFixtureSpec();

        $result = $this->service->build($spec, null, null, $this->brunoEmitter());

        $this->assertSame('TestApp', $result->appName);
        $this->assertSame('http://localhost', $result->baseUrl);
        $this->assertSame('/api/v1', $result->apiPrefix);
    }

    /**
     * Build a small OpenAPI spec fixture with two tables (Users, Orders).
     *
     * @return array<string, mixed>
     */
    private function buildFixtureSpec(): array
    {
        return [
            'openapi' => '3.0.3',
            'info' => ['title' => 'TestApp', 'version' => '1.0.0'],
            'servers' => [['url' => 'http://localhost']],
            'paths' => [
                '/api/v1/users' => [
                    'get' => [
                        'tags' => ['Users'],
                        'summary' => 'List Users',
                        'description' => 'List all users',
                        'operationId' => 'listUsers',
                        'parameters' => [
                            ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 1]],
                        ],
                    ],
                    'post' => [
                        'tags' => ['Users'],
                        'summary' => 'Create Users',
                        'description' => 'Create a new user',
                        'operationId' => 'createUsers',
                    ],
                ],
                '/api/v1/orders' => [
                    'get' => [
                        'tags' => ['Orders'],
                        'summary' => 'List Orders',
                        'description' => 'List all orders',
                        'operationId' => 'listOrders',
                    ],
                ],
            ],
        ];
    }

    /**
     * Build an anonymous emitter that parses Bruno v3 collection shape.
     */
    private function brunoEmitter(): ApiClientEmitterInterface
    {
        return new class implements ApiClientEmitterInterface {
            public function render(ExportResult $result): array
            {
                return [];
            }

            public function extractRequestNames(?array $existing): array
            {
                if ($existing === null) {
                    return [];
                }

                $names = [];
                foreach ($existing['folders'] ?? [] as $folder) {
                    foreach ($folder['requests'] ?? [] as $request) {
                        if (isset($request['name'])) {
                            $names[] = $request['name'];
                        }
                    }
                }

                return $names;
            }
        };
    }
}
