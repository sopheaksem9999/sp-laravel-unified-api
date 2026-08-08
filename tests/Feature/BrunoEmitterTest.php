<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Sopheak\Core\Services\ApiClient\BrunoEmitter;
use Sopheak\Core\Services\ApiClient\ExportFolder;
use Sopheak\Core\Services\ApiClient\ExportRequest;
use Sopheak\Core\Services\ApiClient\ExportResult;
use Sopheak\Core\Tests\TestCase;

/**
 * @internal
 */
class BrunoEmitterTest extends TestCase
{
    private BrunoEmitter $emitter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->emitter = new BrunoEmitter();
    }

    public function test_renders_bruno_json_and_collection_bru(): void
    {
        $result = $this->buildResult(appName: 'MyApp', baseUrl: 'http://localhost:8000', apiPrefix: '/api/v1');

        $output = $this->emitter->render($result);

        $this->assertArrayHasKey('bruno.json', $output);
        $this->assertArrayHasKey('collection.bru', $output);

        $brunoJson = json_decode($output['bruno.json'], true);
        $this->assertSame('MyApp API', $brunoJson['name']);
        $this->assertSame('collection', $brunoJson['type']);

        $collectionBru = $output['collection.bru'];
        $this->assertStringContainsString('name: MyApp API', $collectionBru);
        $this->assertStringContainsString('mode: bearer', $collectionBru);
        $this->assertStringNotContainsString('baseUrl:', $collectionBru);
        $this->assertStringNotContainsString('apiPrefix:', $collectionBru);
    }

    public function test_renders_local_environment_with_base_url_and_api_prefix(): void
    {
        $result = $this->buildResult(baseUrl: 'http://localhost:8000', apiPrefix: '/api/v1');

        $output = $this->emitter->render($result);

        $this->assertArrayHasKey('environments/Local.bru', $output);

        $envBru = $output['environments/Local.bru'];
        $this->assertStringContainsString('baseUrl: http://localhost:8000', $envBru);
        $this->assertStringContainsString('apiPrefix: /api/v1', $envBru);
        $this->assertStringContainsString('vars:secret [', $envBru);
        $this->assertStringContainsString('bearerToken', $envBru);
    }

    public function test_renders_subfolders_and_bru_files(): void
    {
        $result = $this->buildResult(folders: [
            new ExportFolder('Users', [$this->req('List Users', 'GET', '/users')]),
            new ExportFolder('Orders', [$this->req('List Orders', 'GET', '/orders')]),
        ]);

        $output = $this->emitter->render($result);

        $this->assertArrayHasKey('Users/List Users.bru', $output);
        $this->assertArrayHasKey('Orders/List Orders.bru', $output);

        $this->assertStringContainsString('name: List Users', $output['Users/List Users.bru']);
        $this->assertStringContainsString('name: List Orders', $output['Orders/List Orders.bru']);
    }

    public function test_renders_request_with_method_url_params_headers_docs(): void
    {
        $request = new ExportRequest(
            name: 'List Users',
            method: 'GET',
            urlTemplate: '{{baseUrl}}{{apiPrefix}}/users',
            description: 'Retrieve users',
            pathParams: [],
            queryParams: [
                ['name' => 'page', 'value' => '1', 'enabled' => true, 'type' => 'query'],
            ],
            headers: [
                ['name' => 'Accept', 'value' => 'application/json', 'enabled' => true],
            ],
        );

        $result = $this->buildResult(folders: [new ExportFolder('Users', [$request])]);

        $output = $this->emitter->render($result);
        $bru = $output['Users/List Users.bru'];

        $this->assertStringContainsString('name: List Users', $bru);
        $this->assertStringContainsString('get {', $bru);
        $this->assertStringContainsString('url: {{baseUrl}}{{apiPrefix}}/users', $bru);
        $this->assertStringContainsString('page: 1', $bru);
        $this->assertStringContainsString('~select:', $bru);
        $this->assertStringContainsString('Accept: application/json', $bru);
        $this->assertStringContainsString('Retrieve users', $bru);
    }

    public function test_injects_disabled_select_param_on_get_requests(): void
    {
        $request = $this->req('List Users', 'GET', '/users', queryParams: [
            ['name' => 'page', 'value' => '1', 'enabled' => true, 'type' => 'query'],
        ]);

        $result = $this->buildResult(folders: [new ExportFolder('Users', [$request])]);

        $output = $this->emitter->render($result);
        $bru = $output['Users/List Users.bru'];

        $this->assertStringContainsString('~select:', $bru);
    }

    public function test_does_not_inject_select_param_on_post_requests(): void
    {
        $request = $this->req('Create Users', 'POST', '/users');

        $result = $this->buildResult(folders: [new ExportFolder('Users', [$request])]);

        $output = $this->emitter->render($result);
        $bru = $output['Users/Create Users.bru'];

        $this->assertStringNotContainsString('select:', $bru);
    }

    public function test_includes_body_for_post_put_patch_requests(): void
    {
        $request = new ExportRequest(
            name: 'Create Users',
            method: 'POST',
            urlTemplate: '{{baseUrl}}{{apiPrefix}}/users',
            description: 'Create a user',
            pathParams: [],
            queryParams: [],
            headers: [],
            bodyJson: '{"name": ""}',
        );

        $result = $this->buildResult(folders: [new ExportFolder('Users', [$request])]);

        $output = $this->emitter->render($result);
        $bru = $output['Users/Create Users.bru'];

        $this->assertStringContainsString('body: json', $bru);
        $this->assertStringContainsString('body:json {', $bru);
        $this->assertStringContainsString('{"name": ""}', $bru);
    }

    public function test_omits_body_for_get_and_delete_requests(): void
    {
        $get = $this->req('List Users', 'GET', '/users');
        $delete = $this->req('Delete Users', 'DELETE', '/users/{id}');

        $result = $this->buildResult(folders: [
            new ExportFolder('Users', [$get, $delete]),
        ]);

        $output = $this->emitter->render($result);

        $this->assertStringContainsString('body: none', $output['Users/List Users.bru']);
        $this->assertStringContainsString('body: none', $output['Users/Delete Users.bru']);
        $this->assertStringNotContainsString('body:json {', $output['Users/List Users.bru']);
    }

    public function test_renders_auth_inherit_when_request_requires_auth(): void
    {
        $request = $this->req('List Users', 'GET', '/users');

        $result = $this->buildResult(folders: [new ExportFolder('Users', [$request])]);

        $output = $this->emitter->render($result);

        $this->assertStringContainsString('auth: inherit', $output['Users/List Users.bru']);
    }

    public function test_renders_auth_none_when_request_does_not_require_auth(): void
    {
        $request = new ExportRequest(
            name: 'List Public Products',
            method: 'GET',
            urlTemplate: '{{baseUrl}}{{apiPrefix}}/public_products',
            description: 'desc',
            pathParams: [],
            queryParams: [],
            headers: [],
            requiresAuth: false,
        );

        $result = $this->buildResult(folders: [new ExportFolder('Products', [$request])]);

        $output = $this->emitter->render($result);
        $bru = $output['Products/List Public Products.bru'];

        $this->assertStringContainsString('auth: none', $bru);
        $this->assertStringNotContainsString('auth: inherit', $bru);
    }

    public function test_attaches_post_response_login_script_to_login_request(): void
    {
        $request = new ExportRequest(
            name: 'RPC - Login',
            method: 'POST',
            urlTemplate: '{{baseUrl}}{{apiPrefix}}/rpc/auth/login',
            description: 'desc',
            pathParams: [],
            queryParams: [],
            headers: [],
            bodyJson: '{"email": "", "password": ""}',
            requiresAuth: false,
            isLoginRequest: true,
        );

        $result = $this->buildResult(folders: [new ExportFolder('RPC - Auth', [$request])]);
        $output = $this->emitter->render($result);
        $bru = $output['RPC - Auth/RPC - Login.bru'];

        $this->assertStringContainsString('script:post-response {', $bru);
        $this->assertStringContainsString('"access_token"', $bru);
        $this->assertStringContainsString('bru.setVar("bearerToken", token)', $bru);
    }

    public function test_uses_configured_access_token_key_in_login_script(): void
    {
        $request = new ExportRequest(
            name: 'RPC - Login',
            method: 'POST',
            urlTemplate: '{{baseUrl}}{{apiPrefix}}/rpc/auth/login',
            description: 'desc',
            pathParams: [],
            queryParams: [],
            headers: [],
            isLoginRequest: true,
        );

        $result = $this->buildResult(folders: [new ExportFolder('RPC - Auth', [$request])], accessTokenKey: 'token');
        $output = $this->emitter->render($result);
        $bru = $output['RPC - Auth/RPC - Login.bru'];

        $this->assertStringContainsString('"token"', $bru);
    }

    public function test_does_not_attach_login_script_to_non_login_requests(): void
    {
        $request = $this->req('List Users', 'GET', '/users');

        $result = $this->buildResult(folders: [new ExportFolder('Users', [$request])]);
        $output = $this->emitter->render($result);
        $bru = $output['Users/List Users.bru'];

        $this->assertStringNotContainsString('script:post-response', $bru);
    }

    public function test_extracts_request_names_from_existing_bruno_collection(): void
    {
        $existing = [
            'Users/List Users.bru' => "meta {\n  name: List Users\n}",
            'Users/Create Users.bru' => "meta {\n  name: Create Users\n}",
            'Orders/List Orders.bru' => "meta {\n  name: List Orders\n}",
        ];

        $names = $this->emitter->extractRequestNames($existing);

        $this->assertSame(['List Users', 'Create Users', 'List Orders'], $names);
    }

    public function test_extract_request_names_returns_empty_for_null_existing(): void
    {
        $this->assertSame([], $this->emitter->extractRequestNames(null));
    }

    public function test_extract_request_names_returns_empty_for_invalid_existing(): void
    {
        $this->assertSame([], $this->emitter->extractRequestNames([]));
    }

    /**
     * @param  ExportFolder[] $folders
     */
    private function buildResult(
        string $appName = 'TestApp',
        string $baseUrl = 'http://localhost',
        string $apiPrefix = '/api/v1',
        array $folders = [],
        string $accessTokenKey = 'access_token',
    ): ExportResult {
        return new ExportResult(
            appName: $appName,
            baseUrl: $baseUrl,
            apiPrefix: $apiPrefix,
            folders: $folders,
            accessTokenKey: $accessTokenKey,
        );
    }

    /**
     * @param  array<int, array<string, mixed>> $queryParams
     */
    private function req(
        string $name,
        string $method,
        string $path,
        array $queryParams = [],
    ): ExportRequest {
        return new ExportRequest(
            name: $name,
            method: $method,
            urlTemplate: '{{baseUrl}}{{apiPrefix}}' . $path,
            description: 'desc for ' . $name,
            pathParams: [],
            queryParams: $queryParams,
            headers: [],
        );
    }
}
