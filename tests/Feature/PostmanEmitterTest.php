<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Sopheak\Core\Services\ApiClient\ExportFolder;
use Sopheak\Core\Services\ApiClient\ExportRequest;
use Sopheak\Core\Services\ApiClient\ExportResult;
use Sopheak\Core\Services\ApiClient\PostmanEmitter;
use Sopheak\Core\Tests\TestCase;

/**
 * @internal
 */
class PostmanEmitterTest extends TestCase
{
    private PostmanEmitter $emitter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->emitter = new PostmanEmitter();
    }

    public function test_renders_info_with_app_name_and_v21_schema_url(): void
    {
        $result = $this->buildResult(appName: 'MyApp');

        $output = $this->emitter->render($result);

        $this->assertSame('MyApp API', $output['info']['name']);
        $this->assertSame(
            'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            $output['info']['schema']
        );
    }

    public function test_renders_collection_level_bearer_auth_with_token_var(): void
    {
        $result = $this->buildResult();

        $output = $this->emitter->render($result);

        $this->assertSame('bearer', $output['auth']['type']);
        $this->assertSame('{{bearerToken}}', $output['auth']['bearer'][0]['value']);
    }

    public function test_renders_baseUrl_apiPrefix_and_bearerToken_as_variables(): void
    {
        $result = $this->buildResult(baseUrl: 'http://localhost:8000', apiPrefix: '/api/v1');

        $output = $this->emitter->render($result);

        $vars = array_column($output['variable'], null, 'key');
        $this->assertSame('http://localhost:8000', $vars['baseUrl']['value']);
        $this->assertSame('/api/v1', $vars['apiPrefix']['value']);
        $this->assertSame('', $vars['bearerToken']['value']);
    }

    public function test_renders_folders_in_input_order_with_sub_items(): void
    {
        $result = $this->buildResult(folders: [
            new ExportFolder('Users', [$this->req('List Users', 'GET', '/users')]),
            new ExportFolder('Orders', [$this->req('List Orders', 'GET', '/orders')]),
        ]);

        $output = $this->emitter->render($result);

        $this->assertCount(2, $output['item']);
        $this->assertSame('Users', $output['item'][0]['name']);
        $this->assertCount(1, $output['item'][0]['item']);
        $this->assertSame('Orders', $output['item'][1]['name']);
    }

    public function test_renders_url_with_raw_host_and_path_segments(): void
    {
        $request = $this->req('List Users', 'GET', '/users');

        $result = $this->buildResult(folders: [new ExportFolder('Users', [$request])]);
        $output = $this->emitter->render($result);
        $url = $output['item'][0]['item'][0]['request']['url'];

        $this->assertSame('{{baseUrl}}{{apiPrefix}}/users', $url['raw']);
        $this->assertSame(['{{baseUrl}}{{apiPrefix}}'], $url['host']);
        $this->assertSame(['users'], $url['path']);
    }

    public function test_renders_path_segments_for_nested_path(): void
    {
        $request = $this->req('Get Users by ID', 'GET', '/users/{id}/posts/{postId}');

        $result = $this->buildResult(folders: [new ExportFolder('Users', [$request])]);
        $output = $this->emitter->render($result);
        $url = $output['item'][0]['item'][0]['request']['url'];

        $this->assertSame(['users', '{id}', 'posts', '{postId}'], $url['path']);
    }

    public function test_omits_select_param_from_get_request_query(): void
    {
        $request = $this->req('List Users', 'GET', '/users', queryParams: [
            ['name' => 'page', 'value' => '1', 'enabled' => true, 'type' => 'query'],
        ]);

        $result = $this->buildResult(folders: [new ExportFolder('Users', [$request])]);
        $output = $this->emitter->render($result);
        $queryKeys = array_column($output['item'][0]['item'][0]['request']['url']['query'], 'key');

        $this->assertNotContains('select', $queryKeys);
        $this->assertContains('page', $queryKeys);
    }

    public function test_appends_relationship_tip_to_get_request_description(): void
    {
        $request = new ExportRequest(
            name: 'List Users',
            method: 'GET',
            urlTemplate: '{{baseUrl}}{{apiPrefix}}/users',
            description: 'Retrieve users',
            pathParams: [],
            queryParams: [],
            headers: [],
        );

        $result = $this->buildResult(folders: [new ExportFolder('Users', [$request])]);
        $output = $this->emitter->render($result);
        $description = $output['item'][0]['item'][0]['request']['description'];

        $this->assertStringContainsString('Retrieve users', $description);
        $this->assertStringContainsString('Tip:', $description);
        $this->assertStringContainsString('?select=', $description);
    }

    public function test_does_not_append_relationship_tip_to_post_request(): void
    {
        $request = $this->req('Create Users', 'POST', '/users');

        $result = $this->buildResult(folders: [new ExportFolder('Users', [$request])]);
        $output = $this->emitter->render($result);
        $description = $output['item'][0]['item'][0]['request']['description'];

        $this->assertStringNotContainsString('Tip:', $description);
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
        $body = $output['item'][0]['item'][0]['request']['body'];

        $this->assertSame('raw', $body['mode']);
        $this->assertSame('{"name": ""}', $body['raw']);
        $this->assertSame('json', $body['options']['raw']['language']);
    }

    public function test_omits_body_for_get_and_delete_requests(): void
    {
        $get = $this->req('List Users', 'GET', '/users');
        $delete = $this->req('Delete Users', 'DELETE', '/users/{id}');

        $result = $this->buildResult(folders: [new ExportFolder('Users', [$get, $delete])]);
        $output = $this->emitter->render($result);

        $this->assertArrayNotHasKey('body', $output['item'][0]['item'][0]['request']);
        $this->assertArrayNotHasKey('body', $output['item'][0]['item'][1]['request']);
    }

    public function test_renders_query_params_with_disabled_flag(): void
    {
        $request = $this->req('List Users', 'GET', '/users', queryParams: [
            ['name' => 'page', 'value' => '1', 'enabled' => true, 'type' => 'query'],
            ['name' => 'archived', 'value' => 'true', 'enabled' => false, 'type' => 'query'],
        ]);

        $result = $this->buildResult(folders: [new ExportFolder('Users', [$request])]);
        $output = $this->emitter->render($result);
        $query = $output['item'][0]['item'][0]['request']['url']['query'];

        $this->assertFalse($query[0]['disabled']);
        $this->assertTrue($query[1]['disabled']);
    }

    public function test_extracts_request_names_from_existing_postman_collection(): void
    {
        $existing = [
            'item' => [
                ['name' => 'Users', 'item' => [
                    ['name' => 'List Users'],
                    ['name' => 'Create Users'],
                ]],
                ['name' => 'Orders', 'item' => [
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
        $this->assertSame([], $this->emitter->extractRequestNames(['not' => 'a postman collection']));
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
