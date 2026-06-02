<?php

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

    public function test_renders_meta_with_app_name_and_v3_version(): void
    {
        $result = $this->buildResult(appName: 'MyApp');

        $output = $this->emitter->render($result);

        $this->assertSame('MyApp API', $output['meta']['name']);
        $this->assertSame('collection', $output['meta']['type']);
        $this->assertSame('v3', $output['meta']['version']);
    }

    public function test_renders_collection_level_bearer_auth_with_token_var(): void
    {
        $result = $this->buildResult();

        $output = $this->emitter->render($result);

        $this->assertSame('bearer', $output['auth']['mode']);
        $this->assertSame('{{bearerToken}}', $output['auth']['bearer']['token']);
    }

    public function test_renders_baseUrl_apiPrefix_and_bearerToken_vars(): void
    {
        $result = $this->buildResult(baseUrl: 'http://localhost:8000', apiPrefix: '/api/v1');

        $output = $this->emitter->render($result);

        $this->assertSame('http://localhost:8000', $output['vars']['baseUrl']['value']);
        $this->assertFalse($output['vars']['baseUrl']['secret']);
        $this->assertSame('/api/v1', $output['vars']['apiPrefix']['value']);
        $this->assertFalse($output['vars']['apiPrefix']['secret']);
        $this->assertSame('', $output['vars']['bearerToken']['value']);
        $this->assertTrue($output['vars']['bearerToken']['secret']);
    }

    public function test_renders_folders_in_input_order(): void
    {
        $result = $this->buildResult(folders: [
            new ExportFolder('Users', [$this->req('List Users', 'GET', '/users')]),
            new ExportFolder('Orders', [$this->req('List Orders', 'GET', '/orders')]),
        ]);

        $output = $this->emitter->render($result);

        $this->assertCount(2, $output['folders']);
        $this->assertSame('Users', $output['folders'][0]['name']);
        $this->assertSame('Orders', $output['folders'][1]['name']);
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
        $r = $output['folders'][0]['requests'][0];

        $this->assertSame('List Users', $r['name']);
        $this->assertSame('http', $r['type']);
        $this->assertSame('GET', $r['method']);
        $this->assertSame('{{baseUrl}}{{apiPrefix}}/users', $r['url']);
        $this->assertSame('Retrieve users', $r['docs']);
        $this->assertCount(2, $r['params']); // page + injected select
        $this->assertCount(1, $r['headers']);
    }

    public function test_injects_disabled_select_param_on_get_requests(): void
    {
        $request = $this->req('List Users', 'GET', '/users', queryParams: [
            ['name' => 'page', 'value' => '1', 'enabled' => true, 'type' => 'query'],
        ]);

        $result = $this->buildResult(folders: [new ExportFolder('Users', [$request])]);

        $output = $this->emitter->render($result);
        $params = $output['folders'][0]['requests'][0]['params'];

        $this->assertCount(2, $params);
        $select = array_values(array_filter($params, static fn (array $p): bool => $p['name'] === 'select'))[0];
        $this->assertSame('', $select['value']);
        $this->assertFalse($select['enabled']);
        $this->assertSame('query', $select['type']);
        $this->assertStringContainsString('relationships', $select['description']);
    }

    public function test_does_not_inject_select_param_on_post_requests(): void
    {
        $request = $this->req('Create Users', 'POST', '/users');

        $result = $this->buildResult(folders: [new ExportFolder('Users', [$request])]);

        $output = $this->emitter->render($result);
        $params = $output['folders'][0]['requests'][0]['params'];

        $paramNames = array_column($params, 'name');
        $this->assertNotContains('select', $paramNames);
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
        $r = $output['folders'][0]['requests'][0];

        $this->assertSame('json', $r['body']['mode']);
        $this->assertSame('{"name": ""}', $r['body']['json']);
    }

    public function test_omits_body_for_get_and_delete_requests(): void
    {
        $get = $this->req('List Users', 'GET', '/users');
        $delete = $this->req('Delete Users', 'DELETE', '/users/{id}');

        $result = $this->buildResult(folders: [
            new ExportFolder('Users', [$get, $delete]),
        ]);

        $output = $this->emitter->render($result);

        $this->assertArrayNotHasKey('body', $output['folders'][0]['requests'][0]);
        $this->assertArrayNotHasKey('body', $output['folders'][0]['requests'][1]);
    }

    public function test_extracts_request_names_from_existing_bruno_collection(): void
    {
        $existing = [
            'folders' => [
                ['name' => 'Users', 'requests' => [
                    ['name' => 'List Users'],
                    ['name' => 'Create Users'],
                ]],
                ['name' => 'Orders', 'requests' => [
                    ['name' => 'List Orders'],
                ]],
            ],
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
        $this->assertSame([], $this->emitter->extractRequestNames(['not' => 'a bruno collection']));
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
    ): ExportResult {
        return new ExportResult(
            appName: $appName,
            baseUrl: $baseUrl,
            apiPrefix: $apiPrefix,
            folders: $folders,
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
