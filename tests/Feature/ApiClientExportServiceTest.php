<?php

declare(strict_types=1);

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
        $this->assertTrue($result->shouldRegenerateTag('Users'));
        $this->assertFalse($result->shouldRegenerateTag('Orders'));
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
        $this->assertTrue($result->shouldRegenerateTag('Users'));
        $this->assertTrue($result->shouldRegenerateTag('Orders'));
    }

    public function test_adds_new_endpoints_outside_the_selected_regeneration_scope(): void
    {
        $spec = $this->buildFixtureSpec();

        $result = $this->service->build($spec, null, ['users'], $this->brunoEmitter());

        $this->assertContains('List Users', $result->added);
        $this->assertContains('Create Users', $result->added);
        $this->assertContains('List Orders', $result->added);
        $this->assertSame([], $result->skipped);
        $this->assertSame([], $result->regenerated);
        $this->assertSame([], $result->suggestions);
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

        $folderNames = array_map(static fn(ExportFolder $f): string => $f->name, $result->folders);
        $this->assertSame(['Users', 'Orders', 'RPC'], $folderNames);
    }

    public function test_rpc_subgroups_get_their_own_folder_instead_of_collapsing(): void
    {
        $spec = $this->buildFixtureSpec();
        $spec['paths']['/api/v1/rpc/auth/login'] = [
            'post' => [
                'tags' => ['RPC - Auth'],
                'summary' => 'RPC - Login',
                'description' => 'Auth login',
            ],
        ];
        $spec['paths']['/api/v1/rpc/media/upload'] = [
            'post' => [
                'tags' => ['RPC - Media'],
                'summary' => 'RPC - Upload',
                'description' => 'Media upload',
            ],
        ];

        $result = $this->service->build($spec, null, null, $this->brunoEmitter());

        $folderNames = array_map(static fn(ExportFolder $f): string => $f->name, $result->folders);
        $this->assertSame(['Users', 'Orders', 'RPC - Auth', 'RPC - Media'], $folderNames);
    }

    public function test_regen_rpc_wildcard_regenerates_every_rpc_subgroup(): void
    {
        $spec = $this->buildFixtureSpec();
        $spec['paths']['/api/v1/rpc/auth/login'] = [
            'post' => [
                'tags' => ['RPC - Auth'],
                'summary' => 'RPC - Login',
                'description' => 'Auth login',
            ],
        ];
        $spec['paths']['/api/v1/rpc/media/upload'] = [
            'post' => [
                'tags' => ['RPC - Media'],
                'summary' => 'RPC - Upload',
                'description' => 'Media upload',
            ],
        ];
        $existing = [
            'folders' => [
                ['name' => 'RPC - Auth', 'requests' => [['name' => 'RPC - Login']]],
                ['name' => 'RPC - Media', 'requests' => [['name' => 'RPC - Upload']]],
            ],
        ];

        $result = $this->service->build($spec, $existing, ['rpc'], $this->brunoEmitter());

        $this->assertContains('RPC - Login', $result->regenerated);
        $this->assertContains('RPC - Upload', $result->regenerated);
    }

    public function test_regen_exact_rpc_subgroup_tag_only_regenerates_that_folder(): void
    {
        $spec = $this->buildFixtureSpec();
        $spec['paths']['/api/v1/rpc/auth/login'] = [
            'post' => [
                'tags' => ['RPC - Auth'],
                'summary' => 'RPC - Login',
                'description' => 'Auth login',
            ],
        ];
        $spec['paths']['/api/v1/rpc/media/upload'] = [
            'post' => [
                'tags' => ['RPC - Media'],
                'summary' => 'RPC - Upload',
                'description' => 'Media upload',
            ],
        ];
        $existing = [
            'folders' => [
                ['name' => 'RPC - Auth', 'requests' => [['name' => 'RPC - Login']]],
                ['name' => 'RPC - Media', 'requests' => [['name' => 'RPC - Upload']]],
            ],
        ];

        $result = $this->service->build($spec, $existing, ['RPC - Auth'], $this->brunoEmitter());

        $this->assertContains('RPC - Login', $result->regenerated);
        $this->assertContains('RPC - Upload', $result->skipped);
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

        $page = array_values(array_filter($queryParams, static fn(array $p): bool => $p['name'] === 'page'))[0];
        $this->assertSame('1', (string) $page['value']);
        $this->assertTrue($page['enabled']);
    }

    public function test_marks_request_as_not_requiring_auth_when_spec_security_is_empty(): void
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
                        'security' => [],
                    ],
                ],
            ],
        ];

        $result = $this->service->build($spec, null, null, $this->brunoEmitter());

        $this->assertFalse($result->folders[0]->requests[0]->requiresAuth);
    }

    public function test_marks_request_as_requiring_auth_when_spec_security_is_absent_or_set(): void
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
                        // no 'security' key at all
                    ],
                ],
                '/api/v1/orders' => [
                    'get' => [
                        'tags' => ['Orders'],
                        'summary' => 'List Orders',
                        'security' => [['bearerAuth' => []]],
                    ],
                ],
            ],
        ];

        $result = $this->service->build($spec, null, null, $this->brunoEmitter());

        $this->assertTrue($result->folders[0]->requests[0]->requiresAuth);
        $this->assertTrue($result->folders[1]->requests[0]->requiresAuth);
    }

    public function test_marks_rpc_request_matching_login_api_config_as_login_request(): void
    {
        Config::set('record.api_docs.login_api', '/api/v1/rpc/auth/login');
        $spec = $this->buildFixtureSpec();
        $spec['paths']['/api/v1/rpc/auth/login'] = [
            'post' => [
                'tags' => ['RPC - Auth'],
                'summary' => 'RPC - Login',
            ],
        ];
        $spec['paths']['/api/v1/rpc/auth/logout'] = [
            'post' => [
                'tags' => ['RPC - Auth'],
                'summary' => 'RPC - Logout',
            ],
        ];

        $result = $this->service->build($spec, null, null, $this->brunoEmitter());

        $rpcFolder = array_values(array_filter($result->folders, static fn(ExportFolder $f): bool => $f->name === 'RPC - Auth'))[0];
        $byName = [];
        foreach ($rpcFolder->requests as $request) {
            $byName[$request->name] = $request;
        }

        $this->assertTrue($byName['RPC - Login']->isLoginRequest);
        $this->assertFalse($byName['RPC - Logout']->isLoginRequest);
    }

    public function test_matches_login_api_config_given_as_absolute_url(): void
    {
        Config::set('record.api_docs.login_api', 'https://api.example.com/api/v1/rpc/auth/login');
        $spec = $this->buildFixtureSpec();
        $spec['paths']['/api/v1/rpc/auth/login'] = [
            'post' => [
                'tags' => ['RPC - Auth'],
                'summary' => 'RPC - Login',
            ],
        ];

        $result = $this->service->build($spec, null, null, $this->brunoEmitter());

        $rpcFolder = $result->folders[2];
        $this->assertTrue($rpcFolder->requests[0]->isLoginRequest);
    }

    public function test_no_request_is_marked_as_login_when_login_api_does_not_match_anything(): void
    {
        Config::set('record.api_docs.login_api', '/v1/auth/login');
        $spec = $this->buildFixtureSpec();

        $result = $this->service->build($spec, null, null, $this->brunoEmitter());

        foreach ($result->folders as $folder) {
            foreach ($folder->requests as $request) {
                $this->assertFalse($request->isLoginRequest);
            }
        }
    }

    public function test_uses_configured_access_token_key_on_export_result(): void
    {
        Config::set('record.api_docs.access_token_key', 'auth_token');
        $spec = $this->buildFixtureSpec();

        $result = $this->service->build($spec, null, null, $this->brunoEmitter());

        $this->assertSame('auth_token', $result->accessTokenKey);
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
            /**
             * @return array{}
             */
            public function render(ExportResult $result, ?array $existing = null, bool $force = false): array
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
