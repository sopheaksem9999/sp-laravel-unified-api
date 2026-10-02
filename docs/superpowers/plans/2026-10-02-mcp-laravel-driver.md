---
title: "MCP Laravel Driver Plan"
description: "Implementation plan for phases 2 and 4 of the MCP on Laravel MCP spec: the opt-in laravel/mcp driver, stdio, a driver test matrix, per-user tool visibility and opt-in OAuth."
keywords:
  - mcp
  - laravel/mcp
  - oauth
  - plan
---

# MCP Laravel Driver Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an app opt in (`record.mcp.driver = laravel`) to serving the Data and Schema MCP through the official `laravel/mcp` package — Streamable HTTP, current protocol versions, `ping`, stdio, the Inspector, optional OAuth — while every tool, error code, tenant rule and permission check keeps coming from the shared `ToolCatalog` and `ToolExecutor`.

**Architecture:**
- Two `Laravel\Mcp\Server` subclasses publish the catalog's definitions as `CatalogTool` instances. A custom `tools/call` method runs every call through `ToolExecutor`, so JSON-RPC error codes `-32001/-32002/-32601` reach clients unchanged. The stock handler would turn them into generic `isError` results (verified in the phase 0 spike).
- Driver selection is explicit config. Existing URLs stay; `POST /api/{prefix}/mcp` is added.
- The existing MCP suites run under both drivers by exporting `SP_MCP_DRIVER=laravel`.

**Tech Stack:** PHP 8.2+, Laravel 12/13, `laravel/mcp` ^1.0.1 (optional; installed as a dev dependency), Orchestra Testbench, PHPUnit 11.

**Spec:** `docs/superpowers/specs/2026-10-02-mcp-on-laravel-mcp-design.md` — §3 (facts), §5.1–5.3 (units, drivers, endpoints), §6.3 (visibility), §6.4 (errors), §6.5 (auth), §8 phases 2 and 4, §9 (verification).

## Global Constraints

- The default driver stays `legacy`; the opt-in is explicit, never inferred from the package being installed (spec §5.2).
- "No existing client URL or configuration changes": `/api/mcp/message` and `/api/mcp/schema` keep their paths, middleware, token rules and error codes on both drivers (spec §7.1).
- `laravel/mcp` is optional. No `Sopheak\Core\Mcp\Servers|Tools|Resources` class may be autoloaded unless the `laravel` driver is selected (spec §5.1).
- Tenant, permission, `viewOwn`, hidden-column and nested-write behaviour come only from `ToolExecutor`; nothing in the driver calls `RecordService` directly (spec §10 item 2).
- JSON-RPC error codes: `-32001` unauthenticated / unknown table, `-32002` forbidden, `-32601` tool not found (spec §6.4).
- The human partner commits. Executors skip commit steps and record that in the ledger.
- Gate: `vendor/bin/phpunit`, `SP_MCP_DRIVER=laravel vendor/bin/phpunit --filter Mcp`, `vendor/bin/phpstan analyse src tests`, `vendor/bin/rector process --dry-run --no-progress-bar` (no new findings in files this plan touched), `php bin/validate-docs.php` (only the 8 pre-existing failures), `graft build`.

## Review Focus

1. **Same bytes for a plain JSON-RPC client.** A client with no MCP headers must get the same result fields and error codes from both drivers on `/api/mcp/message` and `/api/mcp/schema`. Pinned by Task 5's matrix and conformance tests.
2. **Error codes survive.** A refused call must be a JSON-RPC error with `-32002`/`-32001`, not an `isError` result with "An internal server error occurred." Pinned by Task 2.
3. **No autoload without the driver.** With `record.mcp.driver = legacy`, no `Laravel\Mcp\*` class may be loaded by the package's own code. Pinned by Task 1.
4. **Tool visibility never costs N queries.** `tools/list` with per-user filtering must not issue a permission query per tool. Pinned by Task 6.
5. **Hiding a tool is not authorization.** A hidden tool called anyway must still be refused by the executor. Pinned by Task 6.

---

### Task 1: Driver configuration and registration

**Files:**
- Create: `src/Mcp/McpDriver.php`
- Modify: `config/sp-record.php` (the `mcp` array), `src/Console/SetupPackageCommand.php` (the same stub)
- Modify: `src/CoreSpLaravelApiProvider.php` (`register()`)
- Test: `tests/Unit/McpDriverTest.php` (create)

**Interfaces — Produces:**
- `McpDriver::LEGACY = 'legacy'`, `McpDriver::LARAVEL = 'laravel'`.
- `McpDriver::current(): string`: the configured driver, `'legacy'` for any unknown value.
- `McpDriver::isLaravel(): bool`.
- `McpDriver::isActive(): bool`: the `laravel` driver is selected **and** an MCP endpoint is enabled (`record.mcp.enabled` or `sp-api-mcp.enabled`).
- `McpDriver::assertInstalled(?callable $classExists = null): void` throws `RuntimeException` when `isActive()` and `laravel/mcp` is missing.

- [ ] **Step 1: Write the failing test** `tests/Unit/McpDriverTest.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use RuntimeException;
use Sopheak\Core\Mcp\McpDriver;
use Sopheak\Core\Tests\TestCase;

/** @internal */
class McpDriverTest extends TestCase
{
    /** @test */
    public function the_default_driver_is_legacy(): void
    {
        $this->assertSame('legacy', McpDriver::current());
        $this->assertFalse(McpDriver::isLaravel());
    }

    /** @test */
    public function an_unknown_value_falls_back_to_legacy(): void
    {
        config(['record.mcp.driver' => 'typo']);

        $this->assertSame('legacy', McpDriver::current());
    }

    /** @test */
    public function the_laravel_driver_is_active_only_when_an_endpoint_is_enabled(): void
    {
        config(['record.mcp.driver' => 'laravel', 'record.mcp.enabled' => false, 'sp-api-mcp.enabled' => false]);
        $this->assertTrue(McpDriver::isLaravel());
        $this->assertFalse(McpDriver::isActive());

        config(['sp-api-mcp.enabled' => true]);
        $this->assertTrue(McpDriver::isActive());
    }

    /** @test */
    public function a_missing_package_is_a_clear_error_when_the_driver_is_active(): void
    {
        config(['record.mcp.driver' => 'laravel', 'record.mcp.enabled' => true]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('composer require laravel/mcp');

        McpDriver::assertInstalled(static fn (): bool => false);
    }

    /** @test */
    public function a_missing_package_is_fine_when_the_driver_is_not_active(): void
    {
        config(['record.mcp.driver' => 'legacy', 'record.mcp.enabled' => true]);

        McpDriver::assertInstalled(static fn (): bool => false);

        $this->assertTrue(true);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter McpDriverTest`
Expected: errors — class `Sopheak\Core\Mcp\McpDriver` not found.

- [ ] **Step 3: Implement** `src/Mcp/McpDriver.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Mcp;

use RuntimeException;

/**
 * Which implementation serves the MCP endpoints. The opt-in is explicit: apps
 * often have laravel/mcp installed only as a dev dependency of laravel/boost,
 * so detecting it would make development and production behave differently.
 */
final class McpDriver
{
    public const LEGACY = 'legacy';

    public const LARAVEL = 'laravel';

    public static function current(): string
    {
        return self::LARAVEL === config('record.mcp.driver', self::LEGACY) ? self::LARAVEL : self::LEGACY;
    }

    public static function isLaravel(): bool
    {
        return self::LARAVEL === self::current();
    }

    public static function isActive(): bool
    {
        return self::isLaravel() && ((bool) config('record.mcp.enabled', false) || (bool) config('sp-api-mcp.enabled', false));
    }

    /**
     * @param null|callable(string): bool $classExists
     */
    public static function assertInstalled(?callable $classExists = null): void
    {
        $classExists ??= static fn (string $class): bool => class_exists($class);

        if (self::isActive() && !$classExists('Laravel\Mcp\Server')) {
            throw new RuntimeException('record.mcp.driver is "laravel" but laravel/mcp is not installed. Run: composer require laravel/mcp');
        }
    }
}
```

- [ ] **Step 4: Config.** In `config/sp-record.php`, extend the `mcp` comment block and array (and mirror both in the `SetupPackageCommand` stub):

```php
    | - driver: 'legacy' (default) serves MCP from the package's own JSON-RPC
    |   server. 'laravel' serves it through laravel/mcp (Streamable HTTP, current
    |   protocol versions, OAuth, the Inspector); install it with
    |   `composer require laravel/mcp`.
    | - oauth: With the 'laravel' driver, publish OAuth discovery routes
    |   (requires laravel/passport). Default: false.
```

```php
        'driver' => env('SP_MCP_DRIVER', 'legacy'),
        'oauth' => env('SP_MCP_OAUTH', false),
```

- [ ] **Step 5: Register `laravel/mcp`'s provider.** In `CoreSpLaravelApiProvider::register()`, after the `mergeConfigFrom` lines add:

```php
        // The `laravel` MCP driver needs laravel/mcp's container callback, which
        // populates a tool's arguments; package auto-discovery provides it, but an
        // app (or test) with discovery off would get empty arguments. Registering
        // a provider twice is harmless.
        McpDriver::assertInstalled();
        if (McpDriver::isActive()) {
            $this->app->register(\Laravel\Mcp\Server\McpServiceProvider::class);
        }
```

(add `use Sopheak\Core\Mcp\McpDriver;`).

- [ ] **Step 6: Run the test, then the no-autoload check**

Run: `vendor/bin/phpunit --filter McpDriverTest` → Expected: PASS (5 tests).
Then add this to `McpDriverTest` and run it:

```php
    /** @test */
    public function the_legacy_driver_loads_no_laravel_mcp_class(): void
    {
        $before = array_filter(get_declared_classes(), static fn (string $c): bool => str_starts_with($c, 'Laravel\\Mcp\\Server\\Methods'));
        $this->postJson('/api/mcp/message', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);

        $after = array_filter(get_declared_classes(), static fn (string $c): bool => str_starts_with($c, 'Laravel\\Mcp\\Server\\Methods'));
        $this->assertSame(count($before), count($after));
    }
```

Expected: PASS. (The test class registers no MCP route, so it also proves nothing from `Sopheak\Core\Mcp\Servers` is loaded; `laravel/mcp` classes already loaded by other tests in the same process are subtracted by the `$before` count.)

- [ ] **Step 7: Commit** (skip; record in the ledger)

```bash
git add src/Mcp/McpDriver.php src/CoreSpLaravelApiProvider.php config/sp-record.php src/Console/SetupPackageCommand.php tests/Unit/McpDriverTest.php
git commit -m "feat(mcp): add the explicit record.mcp.driver switch"
```

---

### Task 2: The servers, tools, resources and the error-preserving `tools/call`

**Files:**
- Create: `src/Mcp/Servers/CatalogServer.php`, `src/Mcp/Servers/DataServer.php`, `src/Mcp/Servers/SchemaServer.php`
- Create: `src/Mcp/Tools/CatalogTool.php`, `src/Mcp/Tools/CatalogCallTool.php`, `src/Mcp/Tools/SchemaCatalogCallTool.php`
- Create: `src/Mcp/Resources/CatalogResource.php`
- Test: `tests/Feature/McpLaravelServerTest.php` (create)

**Interfaces:**
- Consumes: `ToolCatalog`, `ToolExecutor`, `ToolDefinition`, `ToolResult`, `ToolError` (plan 1).
- Produces: `DataServer`/`SchemaServer` (`Laravel\Mcp\Server` subclasses; the schema one is schema-only); `CatalogTool::__construct(ToolDefinition $definition, bool $schemaOnly = false)`; `CatalogResource::__construct(string $uri, string $name, string $description)`.

- [ ] **Step 1: Write the failing test** `tests/Feature/McpLaravelServerTest.php`. It drives the servers over an in-memory transport (the same technique phase 0 used for stdio), with `record.mcp.driver = laravel` and `laravel/mcp`'s provider registered by the package (Task 1):

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Closure;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Server\Contracts\Transport;
use Sopheak\Core\Mcp\Servers\DataServer;
use Sopheak\Core\Mcp\Servers\SchemaServer;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class McpLaravelServerArrayTransport implements Transport
{
    /** @var array<int, array<string, mixed>> */
    public array $sent = [];

    private ?Closure $handler = null;

    public function onReceive(Closure $handler): void
    {
        $this->handler = $handler;
    }

    public function run()
    {
        return null;
    }

    public function send(string $message): void
    {
        $this->sent[] = json_decode($message, true);
    }

    public function stream(Closure $stream): void
    {
        $stream();
    }

    public function feed(array $request): array
    {
        $before = count($this->sent);
        ($this->handler)(json_encode($request));

        return $this->sent[$before] ?? [];
    }
}

/**
 * The `laravel` driver's servers, driven over an in-memory transport.
 *
 * @internal
 */
class McpLaravelServerTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $granted = [];

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('record.mcp.enabled', true);
        $app['config']->set('record.mcp.read_only', false);
        $app['config']->set('record.mcp.driver', 'laravel');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('widgets', function (Blueprint $t): void {
            $t->id();
            $t->string('name')->nullable();
            $t->timestamps();
        });
        DB::table('widgets')->insert(['id' => 1, 'name' => 'ONE']);

        $columns = [
            'id' => ['type' => 'integer', 'nullable' => false],
            'name' => ['type' => 'string', 'nullable' => true],
            'created_at' => ['type' => 'datetime', 'nullable' => true],
            'updated_at' => ['type' => 'datetime', 'nullable' => true],
        ];
        config(['record.tables' => ['widgets' => new RecordTableType(table: 'widgets', pmsName: 'widget', hasTenantId: false, softDeletes: false, columns: $columns)]]);
        SchemaRegistryUtils::refresh();

        Gate::before(fn($user, string $ability): ?bool => in_array($ability, $this->granted, true) ? true : null);
        $this->actingAs(new GenericUser(['id' => 5, 'name' => 'u']), 'api');
    }

    private function server(string $class = DataServer::class): array
    {
        $transport = new McpLaravelServerArrayTransport();
        $server = new $class($transport);
        $server->start();

        return [$server, $transport];
    }

    private function callTool(McpLaravelServerArrayTransport $transport, string $tool, array $arguments = [], int $id = 1): array
    {
        return $transport->feed(['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]]);
    }

    /** @test */
    public function initialize_negotiates_the_protocol_version_and_names_the_server(): void
    {
        [, $transport] = $this->server();

        $response = $transport->feed(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']]);

        $this->assertSame('2025-06-18', $response['result']['protocolVersion']);
        $this->assertSame('sp-laravel-api-mcp', $response['result']['serverInfo']['name']);
        $this->assertNotEmpty($response['result']['instructions']);
    }

    /** @test */
    public function tools_list_publishes_the_catalog_with_titles_annotations_and_union_types(): void
    {
        [, $transport] = $this->server();

        $tools = $transport->feed(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])['result']['tools'];
        $byName = array_column($tools, null, 'name');

        $this->assertArrayHasKey('sp_api_get_endpoint', $byName);
        $this->assertSame('Delete widgets', $byName['delete_widgets']['title']);
        $this->assertTrue($byName['delete_widgets']['annotations']['destructiveHint']);
        $this->assertSame(['string', 'integer'], $byName['read_widgets']['inputSchema']['properties']['id']['type']);
        $this->assertArrayHasKey('outputSchema', $byName['list_widgets']);
    }

    /** @test */
    public function the_schema_server_lists_only_the_four_schema_tools(): void
    {
        [, $transport] = $this->server(SchemaServer::class);

        $names = array_column($transport->feed(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])['result']['tools'], 'name');

        $this->assertSame(['sp_api_list_endpoints', 'sp_api_get_endpoint', 'sp_api_list_permissions', 'sp_api_get_api_guidance'], $names);
    }

    /** @test */
    public function a_permitted_call_returns_structured_content_and_the_legacy_text_copy(): void
    {
        $this->granted = ['view:widget'];
        [, $transport] = $this->server();

        $result = $this->callTool($transport, 'list_widgets', ['queryParams' => ['limit' => 5]])['result'];

        $this->assertArrayHasKey('response', $result['structuredContent']);
        $this->assertSame('text', $result['content'][0]['type']);
    }

    /** @test */
    public function a_forbidden_call_is_a_json_rpc_error_with_the_original_code(): void
    {
        [, $transport] = $this->server();

        $response = $this->callTool($transport, 'list_widgets');

        $this->assertSame(-32002, $response['error']['code']);
        $this->assertSame('Forbidden', $response['error']['message']);
        $this->assertArrayNotHasKey('result', $response);
    }

    /** @test */
    public function an_unauthenticated_call_is_minus_32001(): void
    {
        auth('api')->forgetUser();
        [, $transport] = $this->server();

        $this->assertSame(-32001, $this->callTool($transport, 'list_widgets')['error']['code']);
    }

    /** @test */
    public function an_unknown_tool_is_minus_32601_with_the_legacy_message(): void
    {
        [, $transport] = $this->server();

        $response = $this->callTool($transport, 'frobnicate_widgets');

        $this->assertSame(-32601, $response['error']['code']);
        $this->assertSame('Tool not found: frobnicate_widgets', $response['error']['message']);
    }

    /** @test */
    public function read_only_mode_refuses_a_write_tool_with_the_legacy_message(): void
    {
        config(['record.mcp.read_only' => true]);
        [, $transport] = $this->server();

        $response = $this->callTool($transport, 'create_widgets', ['payload' => ['name' => 'x']]);

        $this->assertSame(-32601, $response['error']['code']);
        $this->assertSame('Tool not found or read-only mode is enabled: create_widgets', $response['error']['message']);
    }

    /** @test */
    public function a_record_failure_is_an_is_error_result_with_the_real_message(): void
    {
        $this->granted = ['create:widget'];
        [, $transport] = $this->server();

        $result = $this->callTool($transport, 'create_widgets', ['payload' => ['not_a_column' => 'x']])['result'];

        $this->assertTrue($result['isError']);
        $this->assertStringNotContainsString('internal server error', $result['content'][0]['text']);
    }

    /** @test */
    public function the_schema_server_refuses_a_data_tool_as_not_found(): void
    {
        $this->granted = ['view:widget'];
        [, $transport] = $this->server(SchemaServer::class);

        $this->assertSame(-32601, $this->callTool($transport, 'list_widgets')['error']['code']);
    }

    /** @test */
    public function resources_list_and_read_the_table_schemas(): void
    {
        [, $transport] = $this->server();

        $uris = array_column($transport->feed(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/list'])['result']['resources'], 'uri');
        $this->assertContains('schema://widgets', $uris);

        $read = $transport->feed(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/read', 'params' => ['uri' => 'schema://widgets']]);
        $this->assertStringContainsString('"table": "widgets"', $read['result']['contents'][0]['text']);
    }

    /** @test */
    public function ping_answers(): void
    {
        [, $transport] = $this->server();

        $this->assertArrayHasKey('result', $transport->feed(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']));
    }

    /** @test */
    public function a_non_http_transport_still_sees_the_request_tenant(): void
    {
        // stdio has no HTTP request; the operator binds the tenant on the console request.
        config(['record.enable_tenant_id' => true]);
        $this->granted = ['view:widget'];
        request()->attributes->set('resolved_tenant_id', 'tenant-1');
        [, $transport] = $this->server();

        $result = $this->callTool($transport, 'list_widgets')['result'];

        $this->assertArrayHasKey('structuredContent', $result);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter McpLaravelServerTest`
Expected: errors — classes `Sopheak\Core\Mcp\Servers\DataServer` not found.

- [ ] **Step 3: Implement the call method.** `src/Mcp/Tools/CatalogCallTool.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Mcp\Tools;

use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use Sopheak\Core\Mcp\ToolError;
use Sopheak\Core\Mcp\ToolExecutor;

/**
 * `tools/call` for the catalog. laravel/mcp's own handler turns anything a tool
 * throws into an `isError` result with a generic message, which would turn
 * Forbidden (-32002) and Unauthenticated (-32001) into "An internal server
 * error occurred." Running every call through ToolExecutor keeps the JSON-RPC
 * codes the `legacy` driver returns, for tools the catalog lists and for names
 * it does not (so a hidden or read-only-disabled tool is still refused by the
 * executor, not merely absent).
 */
class CatalogCallTool extends CallTool
{
    protected bool $schemaOnly = false;

    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        $name = $request->params['name'] ?? null;
        if (!is_string($name) || '' === $name) {
            throw new JsonRpcException('Missing [name] parameter.', -32602, $request->id);
        }

        try {
            $result = (new ToolExecutor($this->schemaOnly))->call($name, $request->toRequest()->all());
        } catch (ToolError $error) {
            throw new JsonRpcException($error->getMessage(), $error->getCode(), $request->id);
        }

        return JsonRpcResponse::result($request->id, $result->toWireArray());
    }
}
```

`src/Mcp/Tools/SchemaCatalogCallTool.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Mcp\Tools;

final class SchemaCatalogCallTool extends CatalogCallTool
{
    protected bool $schemaOnly = true;
}
```

- [ ] **Step 4: Implement the tool.** `src/Mcp/Tools/CatalogTool.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Sopheak\Core\Mcp\ToolDefinition;
use Sopheak\Core\Mcp\ToolExecutor;

/**
 * One catalog definition published through laravel/mcp. toArray() emits the
 * definition's raw JSON Schema, because the catalog uses union types such as
 * ["string","integer"] that laravel/mcp's JsonSchema builder cannot express.
 */
final class CatalogTool extends Tool
{
    public function __construct(private readonly ToolDefinition $definition, private readonly bool $schemaOnly = false)
    {
        $this->name = $definition->name;
        $this->title = (string) $definition->title;
        $this->description = $definition->description;
    }

    public function definition(): ToolDefinition
    {
        return $this->definition;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->definition->toWireArray();
    }

    /**
     * Used by laravel/mcp's test helpers; tools/call goes through
     * CatalogCallTool so JSON-RPC error codes survive.
     */
    public function handle(Request $request): ResponseFactory|Response
    {
        $result = (new ToolExecutor($this->schemaOnly))->call($this->definition->name, $request->all());

        if ($result->isError) {
            return Response::error((string) $result->message);
        }

        return Response::make(Response::text($result->toWireArray()['content'][0]['text']))
            ->withStructuredContent((array) $result->structuredContent);
    }
}
```

- [ ] **Step 5: Implement the resource and servers.** `src/Mcp/Resources/CatalogResource.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Mcp\Resources;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;
use Sopheak\Core\Mcp\ToolError;
use Sopheak\Core\Mcp\ToolExecutor;

/**
 * One `schema://{table}` resource.
 */
final class CatalogResource extends Resource
{
    public function __construct(string $uri, string $name, string $description)
    {
        $this->uri = $uri;
        $this->name = $name;
        $this->title = $name;
        $this->description = $description;
        $this->mimeType = 'application/json';
    }

    public function handle(Request $request): Response
    {
        try {
            $read = (new ToolExecutor())->readResource($this->uri());
        } catch (ToolError $error) {
            return Response::error($error->getMessage());
        }

        return Response::text((string) $read['contents'][0]['text']);
    }
}
```

`src/Mcp/Servers/CatalogServer.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Mcp\Servers;

use Laravel\Mcp\Server;
use Sopheak\Core\Mcp\Resources\CatalogResource;
use Sopheak\Core\Mcp\ToolCatalog;
use Sopheak\Core\Mcp\ToolDefinition;
use Sopheak\Core\Mcp\Tools\CatalogCallTool;
use Sopheak\Core\Mcp\Tools\CatalogTool;
use Sopheak\Core\Mcp\Tools\SchemaCatalogCallTool;

/**
 * Publishes the package's ToolCatalog through laravel/mcp.
 */
abstract class CatalogServer extends Server
{
    protected string $name = 'sp-laravel-api-mcp';

    protected string $version = '1.0.0';

    protected string $instructions = 'Start with sp_api_get_api_guidance, then sp_api_list_endpoints and sp_api_get_endpoint before calling any endpoint. Never invent a request body.';

    protected bool $schemaOnly = false;

    protected function boot(): void
    {
        $this->addMethod('tools/call', $this->schemaOnly ? SchemaCatalogCallTool::class : CatalogCallTool::class);

        $catalog = new ToolCatalog();
        $this->tools = array_map(
            fn (ToolDefinition $definition): CatalogTool => new CatalogTool($definition, $this->schemaOnly),
            $catalog->tools($this->schemaOnly)
        );
        $this->resources = array_map(
            static fn (array $resource): CatalogResource => new CatalogResource($resource['uri'], $resource['name'], $resource['description']),
            $catalog->resources()
        );
    }
}
```

`src/Mcp/Servers/DataServer.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Mcp\Servers;

final class DataServer extends CatalogServer {}
```

`src/Mcp/Servers/SchemaServer.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Mcp\Servers;

final class SchemaServer extends CatalogServer
{
    protected bool $schemaOnly = true;
}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter McpLaravelServerTest` and `vendor/bin/phpstan analyse src --no-progress`
Expected: PASS (13 tests); PHPStan `[OK]`. Where laravel/mcp's PHPDoc generics make PHPStan complain about a property type or override, fix it in the class — never baseline it.

- [ ] **Step 7: Commit** (skip; record in the ledger)

```bash
git add src/Mcp/Servers src/Mcp/Tools src/Mcp/Resources tests/Feature/McpLaravelServerTest.php
git commit -m "feat(mcp): serve the tool catalog through laravel/mcp with the legacy error codes"
```

---

### Task 3: Routes for the `laravel` driver

**Files:**
- Modify: `routes/api.php` (the two MCP blocks)
- Test: `tests/Feature/McpLaravelRoutesTest.php` (create)

- [ ] **Step 1: Write the failing test.** `McpLaravelRoutesTest` enables both endpoints with `record.mcp.driver = laravel`, `record.mcp.middleware = ['api']`, `sp-api-mcp.token = 'secret'`, and a `GenericUser` on the `api` guard, with this table of cases (each its own `@test`):

| Request | Expected |
|---|---|
| `POST /api/mcp/message` `initialize` (protocol 2025-06-18) | 200, `application/json`, `result.protocolVersion` `2025-06-18` |
| `POST /api/mcp` `initialize` | 200 (the new Streamable HTTP endpoint) |
| `POST /api/mcp/message` `ping` | 200 with a `result` |
| `POST /api/mcp/message` notification (`notifications/initialized`, no id) | 202 |
| `GET /api/mcp`, `GET /api/mcp/message`, `GET /api/mcp/schema`, `DELETE /api/mcp` | 405 with `Allow: POST` |
| `GET /api/mcp/sse` | 405 with `Allow: POST` |
| `POST /api/mcp/schema` `tools/list`, no token | 401, body `error.message` `Invalid MCP token` |
| `POST /api/mcp/schema` `tools/list`, `Bearer secret` | 200, exactly the four schema tools |
| `POST /api/mcp/message`, unauthenticated (middleware `auth:api` added) | 401 |
| `route('mcp.message')` and `route('api_schema_mcp')` | `…/api/mcp/message`, `…/api/mcp/schema` |

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter McpLaravelRoutesTest`
Expected: FAIL — the `laravel` driver routes are not registered (the JSON-RPC bodies come from the legacy controller).

- [ ] **Step 3: Implement.** In `routes/api.php`, import `Mcp` lazily (inside the branch, via the facade's FQCN) and replace the two MCP blocks:

```php
    /*
    |--------------------------------------------------------------------------
    | API Schema MCP Endpoint (Schema-only Model Context Protocol)
    |--------------------------------------------------------------------------
    */
    if (config('sp-api-mcp.enabled', false)) {
        if (\Sopheak\Core\Mcp\McpDriver::isLaravel()) {
            \Laravel\Mcp\Facades\Mcp::web('mcp/schema', \Sopheak\Core\Mcp\Servers\SchemaServer::class)
                ->name('api_schema_mcp')
                ->middleware(['throttle:api-reads', \Sopheak\Core\Http\Middleware\VerifySchemaMcpToken::class]);
        } else {
            Route::post('mcp/schema', [\Sopheak\Core\Http\Controllers\ApiSchemaMcpController::class, 'handle'])
                ->name('api_schema_mcp')
                ->middleware(['throttle:api-reads']);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Data MCP Endpoints (Model Context Protocol — full CRUD)
    |--------------------------------------------------------------------------
    */
    if (config('record.mcp.enabled', false)) {
        Route::prefix('mcp')->middleware(config('record.mcp.middleware', []))->group(function () {
            Route::get('sse', [\Sopheak\Core\Http\Controllers\McpHttpController::class, 'handleSse'])->name('mcp.sse');

            if (\Sopheak\Core\Mcp\McpDriver::isLaravel()) {
                \Laravel\Mcp\Facades\Mcp::web('message', \Sopheak\Core\Mcp\Servers\DataServer::class)->name('mcp.message');
            } else {
                Route::post('message', [\Sopheak\Core\Http\Controllers\McpHttpController::class, 'handlePost'])->name('mcp.message');
            }
        });

        if (\Sopheak\Core\Mcp\McpDriver::isLaravel()) {
            // The Streamable HTTP endpoint (spec §5.3): POST /{api}/mcp.
            \Laravel\Mcp\Facades\Mcp::web('mcp', \Sopheak\Core\Mcp\Servers\DataServer::class)
                ->name('mcp.http')
                ->middleware(config('record.mcp.middleware', []));
        }
    }
```

Note for the executor: confirm `Mcp::web()` inside `Route::prefix('mcp')->group()` produces `api/mcp/message` (the phase 0 spike registered at the api-group level only); if the prefix is not applied, register the route as `Mcp::web('mcp/message', …)` outside the group with the same middleware and ledger the ruling.

- [ ] **Step 4: Run the test and the legacy route suites**

Run: `vendor/bin/phpunit --filter "McpLaravelRoutesTest|McpHttpControllerTest|ReservedRouteSegmentTest|McpEndpointUrls|SchemaMcpTokenTest"`
Expected: PASS. The legacy-driver tests are unaffected because the default driver is `legacy`.

- [ ] **Step 5: Commit** (skip; record in the ledger)

```bash
git add routes/api.php tests/Feature/McpLaravelRoutesTest.php
git commit -m "feat(mcp): register the laravel driver's HTTP routes"
```

---

### Task 4: stdio

**Files:**
- Modify: `src/CoreSpLaravelApiProvider.php` (`boot()`: `Mcp::local`)
- Modify: `src/Console/McpServerCommand.php` (`handle()`)
- Test: `tests/Feature/McpLaravelStdioTest.php` (create)

- [ ] **Step 1: Write the failing test.** A process-level test, because the stdio loop owns `STDIN`/`STDOUT`: spawn `php vendor/bin/testbench sp-laravel-api:mcp` with `SP_MCP_DRIVER=laravel`, `SP_MCP_ENABLED=true` in its environment, feed two newline-delimited requests on stdin and close it, and assert on stdout lines:

```php
    /** @test */
    public function the_stdio_command_serves_the_laravel_driver(): void
    {
        $process = new Process([PHP_BINARY, 'vendor/bin/testbench', 'sp-laravel-api:mcp'], dirname(__DIR__, 2), ['SP_MCP_DRIVER' => 'laravel', 'SP_MCP_ENABLED' => 'true'], null, 60);
        $process->setInput(
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']]) . "\n"
            . json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'sp_api_list_permissions', 'arguments' => (object) []]]) . "\n"
        );
        $process->run();

        $lines = array_values(array_filter(explode("\n", $process->getOutput())));
        $first = json_decode($lines[0], true);
        $second = json_decode($lines[1], true);

        $this->assertSame('2025-06-18', $first['result']['protocolVersion']);
        $this->assertArrayHasKey('permissions', $second['result']['structuredContent']);
    }
```

(`use Symfony\Component\Process\Process;` — it ships with `laravel/framework`.) Add a second case asserting the legacy driver still answers `initialize` with `2024-11-05` through the same command when `SP_MCP_DRIVER` is unset.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter McpLaravelStdioTest`
Expected: FAIL — the command still runs the legacy loop, so `initialize` answers `2024-11-05`. If `vendor/bin/testbench` cannot boot the package provider, add a minimal `testbench.yaml` (`providers: [Sopheak\Core\CoreSpLaravelApiProvider]`) and ledger it.

- [ ] **Step 3: Implement.** In `CoreSpLaravelApiProvider::boot()`, after the existing code that sets up the package, add:

```php
        if (McpDriver::isActive()) {
            \Laravel\Mcp\Facades\Mcp::local('sp-laravel-api', \Sopheak\Core\Mcp\Servers\DataServer::class);
            \Laravel\Mcp\Facades\Mcp::local('sp-laravel-api-schema', \Sopheak\Core\Mcp\Servers\SchemaServer::class);
        }
```

In `McpServerCommand::handle()`, immediately after the tenant is bound onto the console request and before `$mcpService = app(McpServerService::class);`, add:

```php
        if (McpDriver::isLaravel()) {
            McpDriver::assertInstalled();
            $server = app(\Laravel\Mcp\Server\Registrar::class)->getLocalServer('sp-laravel-api');
            if (null === $server) {
                $this->error('The MCP server is not registered: enable record.mcp.enabled.');

                return;
            }

            $server();

            return;
        }
```

(add `use Sopheak\Core\Mcp\McpDriver;`).

- [ ] **Step 4: Run it to verify it passes, with the existing command tests**

Run: `vendor/bin/phpunit --filter "McpLaravelStdioTest|McpServerCommandTest|McpStdioTenantOptionTest"`
Expected: PASS.

- [ ] **Step 5: Commit** (skip; record in the ledger)

```bash
git add src/CoreSpLaravelApiProvider.php src/Console/McpServerCommand.php tests/Feature/McpLaravelStdioTest.php
git commit -m "feat(mcp): serve stdio through laravel/mcp when the laravel driver is selected"
```

---

### Task 5: Run the existing MCP suites on both drivers

**Files:**
- Modify: whichever existing MCP test classes assert legacy-only wire details (decided from the run below)
- Create: `tests/Concerns/SkipsOnLaravelMcpDriver.php`
- Test: `tests/Feature/McpDriverParityTest.php` (create)

**Interfaces — Produces:** trait `SkipsOnLaravelMcpDriver::skipOnLaravelMcpDriver(string $reason): void` — marks a test skipped when `SP_MCP_DRIVER=laravel`.

- [ ] **Step 1: Run the matrix and record the failures**

Run: `SP_MCP_DRIVER=laravel vendor/bin/phpunit --filter "Mcp|OwnRecords|NestedChildWrite|HiddenColumnSanitizationMutationChannelsTest|SchemaMcpToken|ReservedRouteSegment" > .superpowers/sdd/2026-10-02-mcp-laravel-driver/matrix.txt 2>&1`
Expected: some failures. Read every one. Each is either (a) a test asserting a legacy-only wire detail, such as `protocolVersion` `2024-11-05`, the exact `initialize` `capabilities`, 204 on a notification, or `-32603` for a resource error, which gets `skipOnLaravelMcpDriver('<why>')` and an equivalent `laravel`-driver assertion in `McpDriverParityTest`; or (b) a real defect in the driver, which is fixed in the driver with a test that failed first. Ledger each skipped test with its reason.

- [ ] **Step 2: Write `McpDriverParityTest`.** Under `record.mcp.driver = laravel`, send the same plain JSON-RPC requests that `McpExtractionParityTest` sends to the legacy service, over HTTP, and assert that for every tool call the `result` carries the same `content`/`structuredContent`/`isError` fields and every error carries the same `code` and `message`. Cover: the four schema tools, an unknown tool, a read-only refusal, a forbidden call, an unauthenticated call, a record failure, `resources/list`, and `resources/read` of an existing table.

- [ ] **Step 3: Run both configurations to green**

Run: `vendor/bin/phpunit --filter "Mcp"` and `SP_MCP_DRIVER=laravel vendor/bin/phpunit --filter "Mcp|OwnRecords|NestedChildWrite|HiddenColumnSanitizationMutationChannelsTest|SchemaMcpToken|ReservedRouteSegment"`
Expected: both PASS (skips allowed only for the ledgered legacy-only assertions).

- [ ] **Step 4: Commit** (skip; record in the ledger)

```bash
git add tests
git commit -m "test(mcp): run the MCP suites on both drivers"
```

---

### Task 6: Hide the tools the caller cannot use

**Files:**
- Modify: `src/Mcp/Tools/CatalogTool.php` (`shouldRegister()`)
- Test: `tests/Feature/McpToolVisibilityTest.php` (create)

**Interfaces — Consumes:** `PermissionUtils::actionDecision()` (nested-write plan, Task 1).

- [ ] **Step 1: Write the failing tests** (laravel driver, over the in-memory transport, a `widgets` and a `gadgets` table; `permissions.enabled` true with the permission tables, the way `PermissionGateIntegrationTest` sets them up):

```php
    /** @test */
    public function a_user_only_sees_the_tools_they_may_call(): void
    {
        $this->grant(['view:widget', 'update:widget']);

        $names = array_column($this->listTools(), 'name');

        $this->assertContains('list_widgets', $names);
        $this->assertContains('update_widgets', $names);
        $this->assertNotContains('delete_widgets', $names);
        $this->assertNotContains('create_widgets', $names);
        $this->assertNotContains('list_gadgets', $names);
        $this->assertContains('sp_api_get_endpoint', $names, 'schema tools are always listed');
    }

    /** @test */
    public function a_hidden_tool_called_anyway_is_still_forbidden(): void
    {
        $this->grant(['view:widget']);

        $this->assertSame(-32002, $this->callTool('delete_widgets', ['id' => 1])['error']['code']);
    }

    /** @test */
    public function a_super_admin_sees_every_tool(): void
    {
        config(['permissions.super_admin_callback' => fn ($user): bool => 5 === (int) $user->id]);

        $this->assertContains('delete_gadgets', array_column($this->listTools(), 'name'));
    }

    /** @test */
    public function listing_tools_does_not_query_per_tool(): void
    {
        $this->grant(['view:widget']);
        $this->listTools();                       // warm the permission cache
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->listTools();
        $few = count(DB::getQueryLog());

        // Many more tables must not mean many more queries.
        $this->addTables(30);
        $this->listTools();
        DB::flushQueryLog();
        $this->listTools();

        $this->assertLessThanOrEqual($few + 2, count(DB::getQueryLog()));
    }
```

(`grant()`, `listTools()`, `callTool()` and `addTables()` are small private helpers in the test class; `listTools()` feeds `tools/list` over the in-memory transport and returns `result.tools`.)

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --filter McpToolVisibilityTest`
Expected: FAIL — every tool is listed (`delete_widgets` appears for a user holding only `view:widget`).

- [ ] **Step 3: Implement** in `CatalogTool`:

```php
    /**
     * Hide a data tool whose action the caller is not authorised for, so
     * tools/list shows each user only what they can do. Hiding is a courtesy,
     * not authorization: CatalogCallTool still sends every call through
     * ToolExecutor, which refuses a hidden tool with Forbidden.
     */
    public function shouldRegister(): bool
    {
        if ('schema' === $this->definition->action || null === $this->definition->table) {
            return true;
        }

        $authAction = match ($this->definition->action) {
            'create' => RecordConstants::ACTION_CREATE,
            'update' => RecordConstants::ACTION_UPDATE,
            'delete' => RecordConstants::ACTION_DELETE,
            default => RecordConstants::READ,
        };

        return PermissionUtils::DECISION_ALLOWED === PermissionUtils::actionDecision(
            auth(RecordConfigService::authGuard())->user(),
            $this->definition->table,
            $authAction
        );
    }
```

(imports: `Sopheak\Core\Constants\RecordConstants`, `Sopheak\Core\Services\RecordConfigService`, `Sopheak\Core\Utilities\PermissionUtils`). The `actionDecision()` answer for a user comes from the cached per-user permission set (`PermissionRegistrar::getPermissions`) and the cached package-permission names, so the query count stays flat. If the query-count test fails, trace the extra query and cache it rather than loosening the assertion.

- [ ] **Step 4: Run them to verify they pass, plus the driver tests**

Run: `vendor/bin/phpunit --filter "McpToolVisibilityTest|McpLaravelServerTest|McpDriverParityTest"`
Expected: PASS. `McpLaravelServerTest` and `McpDriverParityTest` call tools by name, so they must still pass: the executor path does not depend on listing.

- [ ] **Step 5: Commit** (skip; record in the ledger)

```bash
git add src/Mcp/Tools/CatalogTool.php tests/Feature/McpToolVisibilityTest.php
git commit -m "feat(mcp): hide tools the caller cannot use on the laravel driver"
```

---

### Task 7: Opt-in OAuth discovery

**Files:**
- Modify: `src/CoreSpLaravelApiProvider.php` (`boot()`)
- Modify: `src/Mcp/McpDriver.php` (add `oauthEnabled()` and `assertOAuthAvailable()`)
- Test: `tests/Feature/McpOAuthTest.php` (create)

**Interfaces — Produces:** `McpDriver::oauthEnabled(): bool` is true when the `laravel` driver is active and `record.mcp.oauth` is true; `McpDriver::assertOAuthAvailable(?callable $classExists = null): void` throws `RuntimeException` ("record.mcp.oauth requires laravel/passport") when `oauthEnabled()` and `Laravel\Passport\Passport` is missing.

- [ ] **Step 1: Write the failing tests.**
  - Unit: `oauthEnabled()` is false on the `legacy` driver even with `record.mcp.oauth` true, and false when the flag is off; `assertOAuthAvailable(static fn () => false)` throws; with `static fn () => true` it does not.
  - Feature (laravel driver, `record.mcp.oauth` true, a stub `Laravel\Passport\Passport` class declared in the test file so the provider's check passes): `GET /.well-known/oauth-protected-resource` returns 200 JSON whose `resource` is the app URL and whose `authorization_servers` lists the app, and an unauthenticated `POST /api/mcp/message` (middleware `auth:api`) returns 401 with a `WWW-Authenticate` header pointing at the protected-resource metadata.
  - Feature: with `record.mcp.oauth` false, `GET /.well-known/oauth-protected-resource` is 404.

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --filter McpOAuthTest`
Expected: FAIL — `McpDriver::oauthEnabled()` is undefined.

- [ ] **Step 3: Implement** `McpDriver::oauthEnabled()` / `assertOAuthAvailable()` and, in `CoreSpLaravelApiProvider::boot()` next to the `Mcp::local` registration:

```php
        if (McpDriver::oauthEnabled()) {
            McpDriver::assertOAuthAvailable();
            \Laravel\Mcp\Facades\Mcp::oauthRoutes();
        }
```

- [ ] **Step 4: Run them to verify they pass**

Run: `vendor/bin/phpunit --filter "McpOAuthTest|McpDriverTest"`
Expected: PASS. State plainly in the ledger that the OAuth tests use a stub Passport class, so the real Passport token flow is **not** covered by this suite and needs a manual check against a real Passport install (the docs task lists that check).

- [ ] **Step 5: Final gate**

Run: `vendor/bin/phpunit && SP_MCP_DRIVER=laravel vendor/bin/phpunit --filter "Mcp|OwnRecords|NestedChildWrite|HiddenColumnSanitizationMutationChannelsTest|SchemaMcpToken|ReservedRouteSegment" && vendor/bin/phpstan analyse src tests --no-progress && vendor/bin/rector process --dry-run --no-progress-bar && php bin/validate-docs.php && graft build`
Expected: both phpunit runs green; PHPStan `[OK]`; Rector nothing new in files this plan touched; validate-docs only the 8 pre-existing failures.

- [ ] **Step 6: Commit** (skip; record in the ledger)

```bash
git add src/CoreSpLaravelApiProvider.php src/Mcp/McpDriver.php tests/Feature/McpOAuthTest.php
git commit -m "feat(mcp): opt-in OAuth discovery for the laravel driver"
```
