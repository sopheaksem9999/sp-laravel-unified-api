<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Enums\RecordFunctionMethodEnum;
use Sopheak\Core\Mcp\SchemaTools;
use Sopheak\Core\Tests\Concerns\BuildsGuidanceFixture;
use Sopheak\Core\Tests\Concerns\ResolvesRefs;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordFunctionType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * What each action and RPC of sp_api_get_endpoint says about headers, paging,
 * search, bulk limits and throttling (spec §6.6: M1, C2–C6, W3).
 */
class McpEndpointContextTest extends TestCase
{
    use BuildsGuidanceFixture;
    use RefreshDatabase;
    use ResolvesRefs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildGuidanceFixture();
    }

    /** @return array<string, mixed> */
    private function endpoint(string $name = 'invoices'): array
    {
        return $this->resolveRefs(app(SchemaTools::class)->getEndpoint(['endpoint' => $name]));
    }

    private function requireAuth(bool $read, bool $write): void
    {
        $tables = Config::get('record.tables');
        $tables['invoices']->isAuthRead = $read;
        $tables['invoices']->isAuthWrite = $write;
        Config::set('record.tables', $tables);
        SchemaRegistryUtils::refresh();
    }

    /** @param array<string, RecordFunctionType> $functions */
    private function withFunctions(array $functions, string $table = 'invoices'): void
    {
        $tables = Config::get('record.tables');
        $tables[$table]->functions = $functions;
        Config::set('record.tables', $tables);
        SchemaRegistryUtils::refresh();
    }

    public function test_every_action_has_headers(): void
    {
        $this->requireAuth(true, true);

        foreach ($this->endpoint()['actions'] as $name => $action) {
            $this->assertSame('Bearer <token>', $action['headers']['Authorization'] ?? null, $name);
        }
    }

    public function test_public_reads_need_no_authorization_header(): void
    {
        $this->requireAuth(false, true);

        $actions = $this->endpoint()['actions'];

        $this->assertArrayNotHasKey('headers', $actions['list'], 'a public read needs no header, so the block is left out');
        $this->assertArrayNotHasKey('headers', $actions['read']);
        $this->assertArrayHasKey('Authorization', $actions['create']['headers']);
    }

    public function test_tenant_header_appears_exactly_for_tenant_scoped_tables(): void
    {
        foreach ($this->endpoint()['actions'] as $name => $action) {
            $this->assertArrayNotHasKey('X-Tenant-ID', $action['headers'] ?? [], $name);
        }

        foreach (['invoice_tag', 'tags', 'invoice_items', 'invoices', 'customers'] as $table) {
            Schema::dropIfExists($table);
        }

        $this->buildGuidanceFixture(tenant: true);
        foreach ($this->endpoint()['actions'] as $name => $action) {
            $this->assertSame('<tenant id>', $action['headers']['X-Tenant-ID'] ?? null, $name);
        }

        Config::set('record.tenant_header', 'X-Org');
        foreach ($this->endpoint()['actions'] as $name => $action) {
            $this->assertArrayHasKey('X-Org', $action['headers'], $name);
            $this->assertArrayNotHasKey('X-Tenant-ID', $action['headers'], $name);
        }
    }

    public function test_list_query_parameters_describe_filters_once(): void
    {
        $parameters = $this->endpoint()['actions']['list']['request']['queryParameters'];

        $this->assertSame('{column}={operator}.{value}', $parameters[0]);
        $this->assertNotContains('filters', $parameters);
        foreach (['select', 'with', 'search', 'sortby', 'limit', 'with_trashed', 'only_trashed'] as $name) {
            $this->assertContains($name, $parameters, $name);
        }

        $read = $this->endpoint()['actions']['read']['request']['queryParameters'];
        $this->assertNotContains('search', $read);
        $this->assertContains('with_trashed', $read);
    }

    public function test_list_carries_search_and_the_live_paging_limits(): void
    {
        Config::set('record.limit_max', 77);
        Config::set('record.per_page_max', 55);

        $list = $this->endpoint()['actions']['list'];

        $this->assertSame(['enabled' => true, 'columns' => ['ref_number', 'customer.name']], $list['search']);
        $this->assertSame(['limit_max' => 77, 'per_page_max' => 55], $list['pagination']);

        $customers = $this->endpoint('customers')['actions']['list'];
        $this->assertArrayNotHasKey('search', $customers);
        $this->assertNotContains('search', $customers['request']['queryParameters']);
        $this->assertNotContains('with_trashed', $customers['request']['queryParameters']);

        $read = $this->endpoint()['actions']['read'];
        $this->assertArrayNotHasKey('pagination', $read);
        $this->assertArrayNotHasKey('search', $read);
    }

    public function test_bulk_actions_carry_max_items_and_async_only_with_a_queue(): void
    {
        Config::set('record.bulk_max', 25);
        Config::set('queue.default', 'database');

        $actions = $this->endpoint()['actions'];
        $this->assertSame(25, $actions['bulkCreate']['request']['payload']['maxItems']);
        $this->assertSame('X-Async-Process: 1', $actions['bulkCreate']['async']['header']);
        $this->assertSame('async=true', $actions['bulkUpdate']['async']['query']);

        Config::set('queue.default', 'sync');
        $actions = $this->endpoint()['actions'];
        $this->assertArrayNotHasKey('async', $actions['bulkCreate']);
        $this->assertSame(25, $actions['bulkCreate']['request']['payload']['maxItems']);

        Config::set('record.bulk_operations', false);
        $this->assertArrayNotHasKey('bulkCreate', $this->endpoint()['actions']);
    }

    public function test_actions_name_their_throttle_group(): void
    {
        RateLimiter::for('api-reads', fn() => Limit::perMinute(123));
        RateLimiter::for('api-writes', fn() => Limit::perMinute(45));

        $actions = $this->endpoint()['actions'];

        $this->assertSame(['group' => 'api-reads', 'limit' => 123], $actions['list']['rateLimit'], 'limits are per minute unless perSeconds says otherwise');
        $this->assertSame(['group' => 'api-writes', 'limit' => 45], $actions['create']['rateLimit']);
        $this->assertSame('api-writes', $actions['bulkCreate']['rateLimit']['group']);
        $this->assertSame('api-writes', $actions['forceDelete']['rateLimit']['group']);
    }

    public function test_a_window_that_is_not_a_minute_is_spelled_out(): void
    {
        RateLimiter::for('api-reads', fn() => Limit::perMinutes(5, 10));

        $this->assertSame(['group' => 'api-reads', 'limit' => 10, 'perSeconds' => 300], $this->endpoint()['actions']['list']['rateLimit']);
    }

    public function test_an_unreadable_limiter_still_names_the_group(): void
    {
        RateLimiter::for('api-reads', fn(): string => 'not a limit');

        $this->assertSame(['group' => 'api-reads'], $this->endpoint()['actions']['list']['rateLimit']);
    }

    public function test_upload_rpc_says_multipart_and_points_at_request_payload(): void
    {
        $this->withFunctions([
            'import' => new RecordFunctionType(
                httpMethod: [RecordFunctionMethodEnum::POST->value],
                class: 'App\\Http\\Controllers\\ImportController',
                functionName: 'import',
                description: 'Import a file',
                payloadSchema: ['type' => 'object', 'properties' => ['file' => ['type' => 'string', 'format' => 'binary'], 'note' => ['type' => 'string']]],
            ),
            'recalc' => new RecordFunctionType(
                httpMethod: [RecordFunctionMethodEnum::POST->value],
                class: 'App\\Http\\Controllers\\ImportController',
                functionName: 'recalc',
                payloadSchema: ['type' => 'object', 'properties' => ['year' => ['type' => 'integer']]],
            ),
            'ping' => new RecordFunctionType(httpMethod: [RecordFunctionMethodEnum::GET->value], class: 'App\\Http\\Controllers\\ImportController', functionName: 'ping'),
        ]);

        $rpc = array_column($this->endpoint()['rpcFunctions'], null, 'name');

        $this->assertStringContainsString('multipart/form-data', $rpc['import']['guidance']);
        $this->assertStringContainsString('request.payload', $rpc['import']['guidance']);
        $this->assertSame('Send a JSON request body that conforms to request.payload.', $rpc['recalc']['guidance']);
        $this->assertStringContainsString('do not invent a payload', $rpc['ping']['guidance']);

        foreach ($rpc as $function) {
            $this->assertStringNotContainsString('payloadSchema', $function['guidance']);
        }

        $listed = array_column(app(SchemaTools::class)->listEndpoints([]), null, 'name');
        $this->assertStringContainsString('multipart/form-data', $listed['invoices.import']['guidance']);
    }

    public function test_the_attachments_upload_rpc_says_multipart(): void
    {
        Config::set('attachments.enabled', true);
        Config::set('attachments.tables', (require dirname(__DIR__, 2) . '/config/sp-attachments.php')['tables']);
        SchemaRegistryUtils::refresh();

        $rpc = array_column($this->endpoint('sp_attachments')['rpcFunctions'], null, 'name');

        $this->assertStringContainsString('multipart/form-data', $rpc['upload']['guidance']);
        $this->assertStringNotContainsString('JSON', $rpc['upload']['guidance']);
        $this->assertStringContainsString('JSON request body', $rpc['folders']['guidance'], 'a JSON-only RPC keeps the JSON wording');
    }

    public function test_rpc_headers_follow_the_function_visibility(): void
    {
        $this->withFunctions([
            'open' => new RecordFunctionType(httpMethod: [RecordFunctionMethodEnum::GET->value], class: 'App\\X', functionName: 'open', isPublic: true),
            'secret' => new RecordFunctionType(httpMethod: [RecordFunctionMethodEnum::GET->value], class: 'App\\X', functionName: 'secret'),
        ]);

        $rpc = array_column($this->endpoint()['rpcFunctions'], null, 'name');

        $this->assertArrayNotHasKey('headers', $rpc['open']);
        $this->assertSame('Bearer <token>', $rpc['secret']['headers']['Authorization']);
        $this->assertSame('api-functions', $rpc['secret']['rateLimit']['group']);
    }

    public function test_view_rpc_exposes_resizing_params_only_when_enabled(): void
    {
        if (!Schema::hasTable('sp_attachments')) {
            Schema::create('sp_attachments', function (Blueprint $table): void {
                $table->id();
                $table->string('filename')->nullable();
            });
        }

        Config::set('record.tables', [
            'sp_attachments' => new RecordTableType(
                table: 'sp_attachments',
                pmsName: 'sp_attachments',
                public: new RecordTablePublic(read: true, write: true),
                functions: [
                    '{id}/view' => new RecordFunctionType(httpMethod: [RecordFunctionMethodEnum::GET->value], class: 'App\\X', functionName: 'view', responseSchema: ['type' => 'string', 'format' => 'binary']),
                ],
            ),
        ]);
        SchemaRegistryUtils::refresh();

        Config::set('attachments.read_resizing', false);
        $view = $this->endpoint('sp_attachments')['rpcFunctions'][0];
        $this->assertNull($view['request']['querySchema']);

        Config::set('attachments.read_resizing', true);
        Config::set('attachments.read_resizing_formats', ['webp', 'png']);
        Config::set('attachments.read_resizing_min', 40);
        Config::set('attachments.read_resizing_max', 900);
        Config::set('attachments.image_sizes', ['thumb' => ['w' => 64, 'h' => 64, 'fit' => 'crop']]);
        $properties = $this->endpoint('sp_attachments')['rpcFunctions'][0]['request']['querySchema']['properties'];

        foreach (['w', 'h', 'fit', 'format', 'size_name'] as $name) {
            $this->assertArrayHasKey($name, $properties, $name);
        }

        $this->assertSame(['webp', 'png'], $properties['format']['enum']);
        $this->assertSame(40, $properties['w']['minimum']);
        $this->assertSame(900, $properties['h']['maximum']);
        $this->assertSame(['thumb'], $properties['size_name']['enum']);
        $this->assertSame(['contain', 'crop'], $properties['fit']['enum']);
    }
}
