---
title: "MCP Core Extraction Plan"
description: "Implementation plan for phase 1 of the MCP on Laravel MCP spec: extract the transport-free tool catalog and executor, add tool titles and annotations, fix the SSE endpoint, and harden the Schema MCP token check."
keywords:
  - mcp
  - refactor
  - tool catalog
  - plan
---

# MCP Core Extraction Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move everything transport-independent out of `McpServerService` into `Sopheak\Core\Mcp\*` so the existing JSON-RPC server and the future `laravel/mcp` driver share one tool catalog and one executor, without changing what legacy clients receive.

**Architecture:**
- `McpServerService` becomes a thin JSON-RPC adapter over `ToolCatalog` (which tools exist) and `ToolExecutor` (run one call).
- The four schema tools and their helpers move verbatim into `SchemaTools`.
- Before moving anything, golden snapshots of today's output are captured, and the moved code must reproduce them exactly. The only permitted differences are the additive `title` and `annotations` keys.

**Tech Stack:** PHP 8.2+, Laravel 12/13, Orchestra Testbench, PHPUnit 11.

**Spec:** `docs/superpowers/specs/2026-10-02-mcp-on-laravel-mcp-design.md` — §5.1 (units), §6.1 (titles and annotations), §5.3 (SSE, `route_prefix`), §6.5 (Schema token), §8 phase 1, §9 (verification).

## Global Constraints

- "No existing client URL or configuration changes." Wire output for existing fields is byte-identical (spec §1, §7.1).
- Names are unchanged; descriptions and input/output schemas move byte-for-byte in this phase; content corrections wait for phase 3 (spec §6.1).
- `McpServerService` keeps its public surface: the constructor `__construct(bool $schemaOnly = false)` and `handleRequest()`; its protected hooks (`handleToolsList`, `handleToolsCall`, `handleResourcesList`, `handleResourcesRead`, `handleInitialize`, `routeMethod`) stay as delegating methods.
- Error contract unchanged: `ToolError` codes `-32001` Unknown table / Unauthenticated, `-32002` Forbidden, `-32601` Tool not found; failures that return an `isError` result today still do.
- The nested-write scope (`NestedWriteAuthorizer::enforce`) and its error mapping, added in phase S, must survive the move unchanged.
- The human partner commits. Executors skip commit steps and record that in the ledger.
- Gate: `vendor/bin/phpunit`, `vendor/bin/phpstan analyse src tests`, `vendor/bin/rector process --dry-run --no-progress-bar` (no new findings), `php bin/validate-docs.php` (only the 8 pre-existing failures), `graft build`.

## Review Focus

1. **Parity on a rich fixture.** Tools, resources and all four schema tools must match today's output for relationships, soft deletes, tenancy and every built-in module, not only a trivial table. Pinned by Task 1's goldens.
2. **Schema-only mode.** The Schema MCP must still expose only the four schema tools and still answer `-32601` for anything else. Pinned by Task 1 and Task 4.
3. **Read-only mode.** Write tools must stay absent from the list and refused when called. Pinned by Task 4.
4. **Nested-write scope and error mapping.** `NestedWriteRefusedException` must still become a `-32002`/`-32001` JSON-RPC error and a refused child must still roll back. Pinned by the existing `NestedChildWriteAuthorizationTest` MCP cases.
5. **Token check cannot be bypassed.** Moving the Schema token check must not leave the controller reachable without it, and it must not accept a different token. Pinned by Task 7.

---

### Task 1: Capture golden snapshots of today's output

**Files:**
- Create: `tests/Feature/McpExtractionParityTest.php`
- Create: `tests/Fixtures/mcp/*.json` (generated, one file per captured response)

**Interfaces — Produces:** `tests/Fixtures/mcp/{name}.json` goldens; `McpExtractionParityTest::captures(): array<string, array>` (name → response) used by the test, and by the generator when `UPDATE_MCP_GOLDEN=1`.

- [ ] **Step 1: Write the parity test.** It builds a rich fixture and compares every response with its golden file. It strips the additive `title` and `annotations` keys from each tool before comparing, so it stays valid after Task 4.

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Support\Facades\Config;
use Sopheak\Core\Enums\RecordRelationshipsEnum;
use Sopheak\Core\Services\McpServerService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordBelongsToType;
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Types\RecordMetaBelongsToManyType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * Phase 1 moves the MCP tool catalog and executor into Sopheak\Core\Mcp\*. The
 * move must be invisible to clients: every response a fixed fixture produces
 * today is captured as a golden file, and the moved code must reproduce it.
 * Only the additive `title` and `annotations` tool keys may differ.
 *
 * Regenerate deliberately with UPDATE_MCP_GOLDEN=1 (phase 3 changes the
 * guidance content and retires this test).
 *
 * @internal
 * @group extraction
 */
class McpExtractionParityTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('record.enable_tenant_id', true);
        $app['config']->set('record.mcp.enabled', true);
        $app['config']->set('record.mcp.read_only', false);
        $app['config']->set('audit.enabled', true);
        $app['config']->set('permissions.enabled', true);
        $app['config']->set('webhooks.enabled', true);
        $app['config']->set('attachments.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $root = dirname(__DIR__, 2) . '/config/';
        Config::set('attachments.tables', (require $root . 'sp-attachments.php')['tables']);
        Config::set('webhooks.tables', (require $root . 'sp-webhooks.php')['tables']);

        $stamps = ['created_at' => ['type' => 'datetime', 'nullable' => true], 'updated_at' => ['type' => 'datetime', 'nullable' => true], 'deleted_at' => ['type' => 'datetime', 'nullable' => true]];
        $base = ['id' => ['type' => 'integer', 'nullable' => false], 'tenant_id' => ['type' => 'string', 'nullable' => true]] + $stamps;

        Config::set('record.tables', [
            'invoices' => new RecordTableType(
                table: 'invoices',
                pmsName: 'invoice',
                softDeletes: true,
                hasTenantId: true,
                public: new RecordTablePublic(read: true, write: true),
                columns: $base + [
                    'ref_number' => ['type' => 'string', 'nullable' => false],
                    'customer_id' => ['type' => 'bigInteger', 'nullable' => false],
                    'status' => ['type' => 'string', 'nullable' => false],
                    'total' => ['type' => 'decimal', 'nullable' => false],
                    'due_date' => ['type' => 'date', 'nullable' => true],
                    'meta' => ['type' => 'json', 'nullable' => true],
                    'paid' => ['type' => 'boolean', 'nullable' => true],
                ],
                relationships: [
                    'customer' => new RecordBelongsToType(table: 'customers', type: RecordRelationshipsEnum::BELONGS_TO, foreignKey: 'customer_id', ownerKey: 'id'),
                    'items' => new RecordHasManyType(table: 'invoice_items', foreignKey: 'invoice_id', type: RecordRelationshipsEnum::HAS_MANY, localKey: 'id'),
                    'tags' => new RecordMetaBelongsToManyType(related: 'tags', table: 'invoice_tag', foreignPivotKey: 'invoice_id', relatedPivotKey: 'tag_id'),
                ],
            ),
            'customers' => new RecordTableType(table: 'customers', pmsName: 'customer', hasTenantId: true, columns: $base + ['name' => ['type' => 'string', 'nullable' => false]]),
            'invoice_items' => new RecordTableType(table: 'invoice_items', pmsName: 'invoice_item', hasTenantId: true, columns: $base + ['invoice_id' => ['type' => 'bigInteger', 'nullable' => false], 'product' => ['type' => 'string', 'nullable' => false], 'qty' => ['type' => 'integer', 'nullable' => false]]),
            'tags' => new RecordTableType(table: 'tags', pmsName: 'tag', hasTenantId: false, columns: ['id' => ['type' => 'integer', 'nullable' => false], 'name' => ['type' => 'string', 'nullable' => false]]),
        ]);
        SchemaRegistryUtils::refresh();
    }

    /**
     * Every response captured, name => decoded JSON-RPC response.
     *
     * @return array<string, array<string, mixed>>
     */
    private function captures(): array
    {
        $data = new McpServerService();
        $schema = new McpServerService(schemaOnly: true);
        $rpc = static fn (McpServerService $service, string $method, array $params = []): ?array => $service->handleRequest(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
        $call = static fn (McpServerService $service, string $tool, array $arguments = []): ?array => $service->handleRequest(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]]);

        $captures = [
            'initialize' => $rpc($data, 'initialize'),
            'data_tools_list' => $rpc($data, 'tools/list'),
            'schema_tools_list' => $rpc($schema, 'tools/list'),
            'resources_list' => $rpc($data, 'resources/list'),
            'resources_read_invoices' => $rpc($data, 'resources/read', ['uri' => 'schema://invoices']),
            'resources_read_missing' => $rpc($data, 'resources/read', ['uri' => 'schema://nope']),
            'resources_read_bad_uri' => $rpc($data, 'resources/read', ['uri' => 'file://x']),
            'list_endpoints' => $call($data, 'sp_api_list_endpoints'),
            'list_endpoints_search' => $call($data, 'sp_api_list_endpoints', ['search' => 'invoice']),
            'permissions' => $call($data, 'sp_api_list_permissions'),
            'guidance' => $call($data, 'sp_api_get_api_guidance'),
            'unknown_method' => $rpc($data, 'prompts/list'),
            'unknown_tool' => $call($data, 'nope_tool'),
            'bad_action' => $call($data, 'frobnicate_invoices'),
            'schema_only_rejects_data_tool' => $call($schema, 'list_invoices'),
            'endpoint_missing_arg' => $call($data, 'sp_api_get_endpoint'),
            'endpoint_unknown' => $call($data, 'sp_api_get_endpoint', ['endpoint' => 'nope']),
        ];

        foreach (['invoices', 'customers', 'invoice_items', 'tags', 'sp_roles', 'sp_permissions', 'sp_audit_logs', 'sp_attachments', 'sp_webhook_endpoints'] as $endpoint) {
            $captures['endpoint_' . $endpoint] = $call($data, 'sp_api_get_endpoint', ['endpoint' => $endpoint]);
        }

        // Read-only mode removes the write tools.
        Config::set('record.mcp.read_only', true);
        $captures['data_tools_list_read_only'] = $rpc($data, 'tools/list');
        $captures['read_only_rejects_create'] = $call($data, 'create_invoices', ['payload' => []]);

        return $captures;
    }

    /**
     * title and annotations are additive (spec §6.1); everything else must match.
     *
     * @param array<string, mixed>|null $response
     * @return array<string, mixed>|null
     */
    private function withoutAdditiveToolKeys(?array $response): ?array
    {
        if (isset($response['result']['tools']) && is_array($response['result']['tools'])) {
            foreach ($response['result']['tools'] as $i => $tool) {
                unset($response['result']['tools'][$i]['title'], $response['result']['tools'][$i]['annotations']);
            }
        }

        return $response;
    }

    /** @test */
    public function every_captured_response_matches_its_golden_file(): void
    {
        $dir = dirname(__DIR__) . '/Fixtures/mcp';

        foreach ($this->captures() as $name => $response) {
            $file = $dir . '/' . $name . '.json';
            $actual = json_encode($this->withoutAdditiveToolKeys($response), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

            if ('1' === getenv('UPDATE_MCP_GOLDEN')) {
                if (!is_dir($dir)) {
                    mkdir($dir, 0o775, true);
                }

                file_put_contents($file, $actual);

                continue;
            }

            $this->assertFileExists($file, 'Missing golden ' . $name . ' (run once with UPDATE_MCP_GOLDEN=1 before refactoring).');
            $this->assertSame(file_get_contents($file), $actual, 'Output drifted for ' . $name);
        }
    }
}
```

- [ ] **Step 2: Generate the goldens from the CURRENT code, before touching it.**

Run: `UPDATE_MCP_GOLDEN=1 vendor/bin/phpunit --filter McpExtractionParityTest`
Expected: PASS; `tests/Fixtures/mcp/` holds 28 JSON files. Open `endpoint_invoices.json`, `data_tools_list.json` and `guidance.json` and confirm they contain real content (relationships under `includes`, `list_invoices` and friends, `querySyntaxExamples`), not error messages.

- [ ] **Step 3: Verify the test is deterministic**

Run: `vendor/bin/phpunit --filter McpExtractionParityTest` (twice)
Expected: PASS both times. If a golden differs between runs, find the unstable value (timestamp, random id, object hash) and make the fixture or the comparison stable before continuing.

- [ ] **Step 4: Commit** (the human partner commits; skip and record it in the ledger)

```bash
git add tests/Feature/McpExtractionParityTest.php tests/Fixtures/mcp
git commit -m "test: capture golden MCP output before extracting the tool core"
```

---

### Task 2: Value objects

**Files:**
- Create: `src/Mcp/ToolDefinition.php`, `src/Mcp/ToolResult.php`, `src/Mcp/ToolError.php`
- Test: `tests/Unit/McpValueObjectsTest.php`

**Interfaces — Produces:**
- `ToolDefinition` (`final readonly`): constructor `(string $name, string $description, array $inputSchema, array $outputSchema, string $action, ?string $table = null, ?string $title = null, array $annotations = [])`; `toWireArray(): array` returns `name`, `description`, `inputSchema`, `outputSchema`, then `title` and `annotations` when set (an empty annotations array is omitted).
- `ToolResult` (`final`): `static ok(array $structuredContent, mixed $legacyContent): self`, `static error(string $message): self`; `toWireArray(): array` — the exact shapes `McpServerService::toolResult()` and the `isError` arrays produce today.
- `ToolError extends RuntimeException`: `__construct(string $message, int $code = -32603)`.

- [ ] **Step 1: Read the current `toolResult()`** (`src/Services/McpServerService.php`, the private method documented "Build the MCP tool result envelope") and copy its body into `ToolResult::toWireArray()` unchanged: the text content must stay the pretty-printed JSON of `$legacyContent` exactly as `toolResult()` builds it today (Task 6 shrinks it in phase 3, not here).

- [ ] **Step 2: Write the failing test** `tests/Unit/McpValueObjectsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sopheak\Core\Mcp\ToolDefinition;
use Sopheak\Core\Mcp\ToolError;
use Sopheak\Core\Mcp\ToolResult;

/** @internal */
class McpValueObjectsTest extends TestCase
{
    /** @test */
    public function a_definition_serialises_the_legacy_keys_first_then_the_additive_ones(): void
    {
        $definition = new ToolDefinition(
            name: 'list_widgets',
            description: 'List records from widgets',
            inputSchema: ['type' => 'object'],
            outputSchema: ['type' => 'object'],
            action: 'list',
            table: 'widgets',
            title: 'List widgets',
            annotations: ['readOnlyHint' => true],
        );

        $this->assertSame(
            ['name', 'description', 'inputSchema', 'outputSchema', 'title', 'annotations'],
            array_keys($definition->toWireArray())
        );
        $this->assertSame('widgets', $definition->table);
    }

    /** @test */
    public function a_definition_without_title_or_annotations_serialises_only_the_legacy_keys(): void
    {
        $definition = new ToolDefinition('x', 'd', [], [], 'schema');

        $this->assertSame(['name', 'description', 'inputSchema', 'outputSchema'], array_keys($definition->toWireArray()));
    }

    /** @test */
    public function an_error_result_is_an_is_error_envelope_with_the_message(): void
    {
        $this->assertSame(
            ['isError' => true, 'content' => [['type' => 'text', 'text' => 'boom']]],
            ToolResult::error('boom')->toWireArray()
        );
    }

    /** @test */
    public function an_ok_result_carries_structured_content_and_a_text_copy(): void
    {
        $wire = ToolResult::ok(['a' => 1], ['a' => 1])->toWireArray();

        $this->assertSame(['a' => 1], $wire['structuredContent']);
        $this->assertSame('text', $wire['content'][0]['type']);
        $this->assertSame(['a' => 1], json_decode($wire['content'][0]['text'], true));
    }

    /** @test */
    public function a_tool_error_defaults_to_the_internal_error_code(): void
    {
        $this->assertSame(-32603, (new ToolError('x'))->getCode());
        $this->assertSame(-32002, (new ToolError('Forbidden', -32002))->getCode());
    }
}
```

- [ ] **Step 3: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter McpValueObjectsTest`
Expected: errors — `Class "Sopheak\Core\Mcp\ToolDefinition" not found`.

- [ ] **Step 4: Implement** the three classes with the interfaces above.

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Mcp;

/**
 * One MCP tool, independent of any transport. The `legacy` JSON-RPC server and
 * the `laravel` driver both render it.
 */
final readonly class ToolDefinition
{
    /**
     * @param array<string, mixed> $inputSchema
     * @param array<string, mixed> $outputSchema
     * @param array<string, bool>  $annotations
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $inputSchema,
        public array $outputSchema,
        public string $action,
        public ?string $table = null,
        public ?string $title = null,
        public array $annotations = [],
    ) {}

    /**
     * Legacy keys first, in their historical order, then the additive ones.
     *
     * @return array<string, mixed>
     */
    public function toWireArray(): array
    {
        $wire = [
            'name' => $this->name,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema,
            'outputSchema' => $this->outputSchema,
        ];

        if (null !== $this->title) {
            $wire['title'] = $this->title;
        }

        if ([] !== $this->annotations) {
            $wire['annotations'] = $this->annotations;
        }

        return $wire;
    }
}
```

`ToolError`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Mcp;

use RuntimeException;

/**
 * A tool call refused in a way MCP reports as a JSON-RPC error rather than an
 * `isError` tool result: Unauthenticated (-32001), unknown table (-32001),
 * Forbidden (-32002), tool not found (-32601).
 */
final class ToolError extends RuntimeException
{
    public function __construct(string $message, int $code = -32603)
    {
        parent::__construct($message, $code);
    }
}
```

`ToolResult` — `toWireArray()` is the copied body of `toolResult()` for the ok case; the error case returns `['isError' => true, 'content' => [['type' => 'text', 'text' => $message]]]`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Mcp;

final class ToolResult
{
    /**
     * @param array<string, mixed>|null $structuredContent
     */
    private function __construct(
        public readonly ?array $structuredContent,
        public readonly mixed $legacyContent,
        public readonly bool $isError,
        public readonly ?string $message = null,
    ) {}

    /**
     * @param array<string, mixed> $structuredContent
     */
    public static function ok(array $structuredContent, mixed $legacyContent): self
    {
        return new self($structuredContent, $legacyContent, false);
    }

    public static function error(string $message): self
    {
        return new self(null, null, true, $message);
    }

    /**
     * @return array<string, mixed>
     */
    public function toWireArray(): array
    {
        if ($this->isError) {
            return ['isError' => true, 'content' => [['type' => 'text', 'text' => (string) $this->message]]];
        }

        // BODY COPIED VERBATIM from McpServerService::toolResult() in Step 1.
    }
}
```

Replace the final comment with the copied body (using `$this->structuredContent` / `$this->legacyContent` for its two parameters).

- [ ] **Step 5: Run it to verify it passes**

Run: `vendor/bin/phpunit --filter McpValueObjectsTest`
Expected: PASS (5 tests).

- [ ] **Step 6: Commit** (skip; record in the ledger)

```bash
git add src/Mcp tests/Unit/McpValueObjectsTest.php
git commit -m "feat(mcp): add transport-free tool definition, result and error types"
```

---

### Task 3: Move the schema tools into `SchemaTools`

**Files:**
- Create: `src/Mcp/SchemaTools.php`
- Modify: `src/Services/McpServerService.php` (remove the moved methods; delegate)

**Interfaces — Produces:** `SchemaTools` with public `listEndpoints(array $args): array`, `getEndpoint(array $args): array`, `listPermissions(): array`, `apiGuidance(): array`. These are the bodies of `handleSchemaListEndpoints`, `handleSchemaGetEndpoint`, `handleSchemaListPermissions` and `handleSchemaGetApiGuidance` respectively.

- [ ] **Step 1: Move the code with a script, not by hand.** Run this from the repository root. It copies the four `handleSchema*` methods and every private helper they use verbatim into `SchemaTools`, renames the four entry points, and deletes them from the service.

```bash
python3 - <<'PYEOF'
import re
src_path = 'src/Services/McpServerService.php'
s = open(src_path).read()

def method_span(source, name):
    # a method starts at its docblock (if any) and ends at the closing "    }" at 4-space indent
    m = re.search(r'(?:    /\*\*(?:(?!\*/).)*?\*/\n)?    (?:public|protected|private) function ' + re.escape(name) + r'\(', source, re.S)
    assert m, name
    start = m.start()
    end = source.index('\n    }\n', m.end()) + len('\n    }\n')
    return start, end

moved = ['handleSchemaListEndpoints', 'handleSchemaGetEndpoint', 'handleSchemaListPermissions', 'handleSchemaGetApiGuidance',
         'withActionContexts', 'withEndpointSummaries', 'functionCallContext', 'queryParametersForAction', 'payloadSchemaForAction',
         'dataSchemaForAction', 'recordSchema', 'jsonSchemaType', 'filterOperatorsForType', 'describeValidator']
chunks = []
for name in moved:
    a, b = method_span(s, name)
    chunks.append(s[a:b])
    s = s[:a] + s[b:]
open(src_path, 'w').write(s)

body = '\n'.join(chunks)
for old, new in [('handleSchemaListEndpoints', 'listEndpoints'), ('handleSchemaGetEndpoint', 'getEndpoint'),
                 ('handleSchemaListPermissions', 'listPermissions'), ('handleSchemaGetApiGuidance', 'apiGuidance')]:
    body = body.replace('protected function ' + old, 'public function ' + new).replace('$this->' + old, '$this->' + new)
open('/tmp/schema_tools_body.php', 'w').write(body)
PYEOF
```

- [ ] **Step 2: Create `src/Mcp/SchemaTools.php`** wrapping the moved body:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Mcp;

// ...the use-statements the moved code needs, copied from McpServerService.php
// (RecordTableType, RecordFunctionType, RecordValidationType, RecordRelationshipsEnum,
//  SchemaRegistryUtils, RecordUtils, PermissionUtils, RecordConfigService, RecordConstants, ...)

/**
 * The four schema discovery tools (`sp_api_*`): endpoint list, endpoint detail,
 * permissions and API guidance. Moved verbatim from McpServerService so both
 * MCP drivers serve identical content; they never return database rows.
 */
final class SchemaTools
{
    // ← contents of /tmp/schema_tools_body.php
}
```

Add exactly the `use` statements the moved methods reference (PHPStan reports any missing ones in Step 4).

- [ ] **Step 3: Delegate from the service.** In `McpServerService`, add `private ?SchemaTools $schemaTools = null;` and `private function schemaTools(): SchemaTools { return $this->schemaTools ??= new SchemaTools(); }`, and in `handleToolsCall()` change the four match arms to:

```php
                    'sp_api_list_endpoints' => $this->schemaTools()->listEndpoints($args),
                    'sp_api_get_endpoint' => $this->schemaTools()->getEndpoint($args),
                    'sp_api_list_permissions' => $this->schemaTools()->listPermissions(),
                    'sp_api_get_api_guidance' => $this->schemaTools()->apiGuidance(),
```

- [ ] **Step 4: Verify nothing changed**

Run: `vendor/bin/phpunit --filter "McpExtractionParityTest|Mcp"` and `vendor/bin/phpstan analyse src tests --no-progress`
Expected: parity test PASS (goldens unchanged), all Mcp suites PASS, PHPStan `[OK]`. A parity failure means the move altered behaviour — fix the move, never the golden.

- [ ] **Step 5: Commit** (skip; record in the ledger)

```bash
git add src/Mcp/SchemaTools.php src/Services/McpServerService.php
git commit -m "refactor(mcp): move the schema discovery tools into SchemaTools verbatim"
```

---

### Task 4: `ToolCatalog` with titles and annotations

**Files:**
- Create: `src/Mcp/ToolCatalog.php`
- Modify: `src/Services/McpServerService.php` (`handleToolsList`, `handleResourcesList`; remove the output-schema helpers)
- Test: `tests/Unit/McpToolCatalogTest.php` (create)

**Interfaces:**
- Consumes: `ToolDefinition` (Task 2).
- Produces: `ToolCatalog` with `schema(): array<ToolDefinition>`, `data(): array<ToolDefinition>` (honours `record.mcp.read_only`), `tools(bool $schemaOnly): array<ToolDefinition>` (schema tools, then data tools unless `$schemaOnly`), `resources(): array<int, array<string, string>>`, `isReadOnly(): bool`.

- [ ] **Step 1: Write the failing test** `tests/Unit/McpToolCatalogTest.php` (a Testbench feature test, extending `Sopheak\Core\Tests\TestCase`) with a two-table fixture and these cases:

```php
    /** @test */
    public function schema_tools_are_the_four_discovery_tools_marked_read_only(): void
    {
        $tools = (new ToolCatalog())->schema();

        $this->assertSame(['sp_api_list_endpoints', 'sp_api_get_endpoint', 'sp_api_list_permissions', 'sp_api_get_api_guidance'], array_map(fn ($t) => $t->name, $tools));
        foreach ($tools as $tool) {
            $this->assertSame(['readOnlyHint' => true, 'openWorldHint' => false], $tool->annotations);
            $this->assertNotSame('', (string) $tool->title);
        }
    }

    /** @test */
    public function data_tools_carry_the_annotations_from_the_spec_table(): void
    {
        config(['record.mcp.read_only' => false]);
        $byName = [];
        foreach ((new ToolCatalog())->data() as $tool) {
            $byName[$tool->name] = $tool;
        }

        $this->assertSame(['readOnlyHint' => true, 'openWorldHint' => false], $byName['list_widgets']->annotations);
        $this->assertSame(['readOnlyHint' => true, 'openWorldHint' => false], $byName['read_widgets']->annotations);
        $this->assertSame(['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false], $byName['create_widgets']->annotations);
        $this->assertSame(['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false], $byName['update_widgets']->annotations);
        $this->assertSame(['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false], $byName['delete_widgets']->annotations);
        $this->assertSame('Delete widgets', $byName['delete_widgets']->title);
        $this->assertSame('widgets', $byName['delete_widgets']->table);
        $this->assertSame('delete', $byName['delete_widgets']->action);
    }

    /** @test */
    public function read_only_mode_leaves_only_list_and_read(): void
    {
        config(['record.mcp.read_only' => true]);

        $this->assertSame(['list_widgets', 'read_widgets', 'list_gadgets', 'read_gadgets'], array_map(fn ($t) => $t->name, (new ToolCatalog())->data()));
    }

    /** @test */
    public function schema_only_lists_no_data_tools(): void
    {
        config(['record.mcp.read_only' => false]);

        $names = array_map(fn ($t) => $t->name, (new ToolCatalog())->tools(schemaOnly: true));

        $this->assertSame(['sp_api_list_endpoints', 'sp_api_get_endpoint', 'sp_api_list_permissions', 'sp_api_get_api_guidance'], $names);
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter McpToolCatalogTest`
Expected: errors — `Class "Sopheak\Core\Mcp\ToolCatalog" not found`.

- [ ] **Step 3: Implement `ToolCatalog`** by moving, not rewriting: copy the array literals from `handleToolsList()` verbatim, so descriptions and schemas are byte-identical; wrap each in a `ToolDefinition` through one private factory that adds the title and annotations; move the five output-schema helpers (`endpointsOutputSchema`, `endpointOutputSchema`, `permissionsOutputSchema`, `guidanceOutputSchema`, `dataToolOutputSchema`) and the body of `handleResourcesList()` into the class.

```php
    /**
     * @param array{name: string, description: string, inputSchema: array<string, mixed>, outputSchema: array<string, mixed>} $legacy
     */
    private function define(array $legacy, string $action, ?string $table = null): ToolDefinition
    {
        $readOnly = ['readOnlyHint' => true, 'openWorldHint' => false];

        return new ToolDefinition(
            name: $legacy['name'],
            description: $legacy['description'],
            inputSchema: $legacy['inputSchema'],
            outputSchema: $legacy['outputSchema'],
            action: $action,
            table: $table,
            title: match ($action) {
                'list' => 'List ' . $table,
                'read' => 'Read ' . $table,
                'create' => 'Create ' . $table,
                'update' => 'Update ' . $table,
                'delete' => 'Delete ' . $table,
                default => $this->schemaTitle($legacy['name']),
            },
            annotations: match ($action) {
                'create' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false],
                'update', 'delete' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
                default => $readOnly,
            },
        );
    }

    private function schemaTitle(string $name): string
    {
        return match ($name) {
            'sp_api_list_endpoints' => 'List API endpoints',
            'sp_api_get_endpoint' => 'Get API endpoint',
            'sp_api_list_permissions' => 'List API permissions',
            'sp_api_get_api_guidance' => 'Get API guidance',
        };
    }
```

Then each `$tools[] = [ ... ];` in the copied code becomes `$tools[] = $this->define([ ... ], '<action>', $table);` (`'schema'` and no table for the four discovery tools). `isReadOnly()` returns `(bool) config('record.mcp.read_only', true)`.

- [ ] **Step 4: Delegate from the service.** `handleToolsList()` becomes:

```php
    protected function handleToolsList(array $params): array
    {
        return ['tools' => array_map(
            static fn (ToolDefinition $tool): array => $tool->toWireArray(),
            (new ToolCatalog())->tools($this->schemaOnly)
        )];
    }
```

and `handleResourcesList()` returns `['resources' => (new ToolCatalog())->resources()]`. Remove the five output-schema helpers from the service.

- [ ] **Step 5: Run the catalog test, the parity test and the MCP suites**

Run: `vendor/bin/phpunit --filter "McpToolCatalogTest|McpExtractionParityTest|Mcp"`
Expected: all PASS. The parity test passing proves `title`/`annotations` are the only differences.

- [ ] **Step 6: Commit** (skip; record in the ledger)

```bash
git add src/Mcp/ToolCatalog.php src/Services/McpServerService.php tests/Unit/McpToolCatalogTest.php
git commit -m "feat(mcp): extract ToolCatalog and add tool titles and annotations"
```

---

### Task 5: `ToolExecutor` and the thin adapter

**Files:**
- Create: `src/Mcp/ToolExecutor.php`
- Modify: `src/Services/McpServerService.php` (`handleToolsCall`, `handleResourcesRead`; remove `resolveToolTenantId`, `authorizeAction`, `toolResult`, `dataToolResult`)
- Test: `tests/Unit/McpToolExecutorTest.php` (create)

**Interfaces:**
- Consumes: `ToolCatalog`, `SchemaTools`, `ToolResult`, `ToolError` (Tasks 2–4).
- Produces: `ToolExecutor::__construct(bool $schemaOnly = false)`, `call(string $name, array $args): ToolResult` (throws `ToolError` for the JSON-RPC errors), and `readResource(string $uri): array` (throws `ToolError` for an invalid URI or a missing resource).

- [ ] **Step 1: Write the failing test** `tests/Unit/McpToolExecutorTest.php` with these cases, using the same two-table fixture and an authenticated user holding the permissions it needs (granted with `Gate::before`, as `NestedChildWriteAuthorizationTest` does):

```php
    /** @test */
    public function an_unknown_tool_is_a_minus_32601_tool_error(): void
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionCode(-32601);
        $this->expectExceptionMessage('Tool not found: nope');

        (new ToolExecutor())->call('nope', []);
    }

    /** @test */
    public function schema_only_mode_refuses_data_tools_as_not_found(): void
    {
        $this->expectExceptionCode(-32601);

        (new ToolExecutor(schemaOnly: true))->call('list_widgets', []);
    }

    /** @test */
    public function read_only_mode_refuses_write_tools_as_not_found(): void
    {
        config(['record.mcp.read_only' => true]);
        $this->expectExceptionCode(-32601);
        $this->expectExceptionMessage('Tool not found or read-only mode is enabled: create_widgets');

        (new ToolExecutor())->call('create_widgets', ['payload' => []]);
    }

    /** @test */
    public function an_unknown_table_is_a_minus_32001_tool_error(): void
    {
        $this->expectExceptionCode(-32001);
        $this->expectExceptionMessage('Unknown table: nope');

        (new ToolExecutor())->call('list_nope', []);
    }

    /** @test */
    public function a_user_without_the_permission_is_forbidden_minus_32002(): void
    {
        $this->expectExceptionCode(-32002);
        $this->expectExceptionMessage('Forbidden');

        (new ToolExecutor())->call('list_widgets', []);
    }

    /** @test */
    public function a_permitted_list_returns_an_ok_result(): void
    {
        $this->grant('view:widget');

        $result = (new ToolExecutor())->call('list_widgets', []);

        $this->assertFalse($result->isError);
        $this->assertArrayHasKey('response', $result->structuredContent);
    }

    /** @test */
    public function a_record_failure_is_an_is_error_result_not_an_exception(): void
    {
        $this->grant('view:widget');

        $result = (new ToolExecutor())->call('read_widgets', ['id' => 99999]);

        $this->assertTrue($result->isError);
    }

    /** @test */
    public function schema_tools_run_through_the_executor(): void
    {
        $result = (new ToolExecutor())->call('sp_api_list_permissions', []);

        $this->assertFalse($result->isError);
        $this->assertArrayHasKey('permissions', $result->structuredContent);
    }

    /** @test */
    public function resource_reads_validate_the_uri(): void
    {
        $this->expectExceptionMessage('Invalid resource URI: file://x');

        (new ToolExecutor())->readResource('file://x');
    }
```

(The fixture's `widgets` table needs a real SQLite table for `list_widgets`/`read_widgets`: create it in `setUp`.)

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter McpToolExecutorTest`
Expected: errors — class `ToolExecutor` not found.

- [ ] **Step 3: Implement `ToolExecutor`** by moving `handleToolsCall()`, `resolveToolTenantId()`, `authorizeAction()`, `dataToolResult()` and the body of `handleResourcesRead()` into it. The translation rules are exactly these, and nothing else changes:

  - every `throw new Exception(message: X, code: Y)` that today propagates out of `handleToolsCall()` becomes `throw new ToolError(X, Y)`;
  - `handleResourcesRead()`'s two `throw new Exception('…')` become `throw new ToolError('…')` (default code `-32603`, which is what `handleRequest()` maps a zero code to today);
  - the schema-tool branch's `catch (Exception) { return [isError…] }` becomes `catch (Exception $e) { return ToolResult::error($e->getMessage()); }`;
  - the data branch's existing `try { … } catch (NestedWriteRefusedException) { throw … } catch (Exception) { return isError… }` keeps its structure: the refused-child handler throws `ToolError('Unauthenticated', -32001)` / `ToolError('Forbidden', -32002)`, and the generic handler returns `ToolResult::error(…)`;
  - successful results use `ToolResult::ok(...)` with the arguments the old `toolResult()` call passed;
  - `RecordService::stripHiddenColumns`, `NestedWriteAuthorizer::enforce`, `DB::transaction` and the tenant resolution stay exactly as phase S left them.

- [ ] **Step 4: Make `McpServerService` the adapter.** `handleToolsCall()` and `handleResourcesRead()` become:

```php
    protected function handleToolsCall(array $params): array
    {
        try {
            return (new ToolExecutor($this->schemaOnly))
                ->call((string) ($params['name'] ?? ''), $params['arguments'] ?? [])
                ->toWireArray();
        } catch (ToolError $error) {
            throw new Exception(message: $error->getMessage(), code: $error->getCode());
        }
    }

    protected function handleResourcesRead(array $params): array
    {
        try {
            return (new ToolExecutor($this->schemaOnly))->readResource((string) ($params['uri'] ?? ''));
        } catch (ToolError $error) {
            throw new Exception($error->getMessage(), $error->getCode());
        }
    }
```

and remove `resolveToolTenantId`, `authorizeAction`, `toolResult`, `dataToolResult` and now-unused imports from the service. `handleRequest()` is untouched.

- [ ] **Step 5: Run the executor test, the parity test and every MCP and nested-write suite**

Run: `vendor/bin/phpunit --filter "McpToolExecutorTest|McpExtractionParityTest|Mcp|NestedChildWriteAuthorizationTest|HiddenColumnSanitizationMutationChannelsTest|OwnRecords"`
Expected: all PASS.

- [ ] **Step 6: Full suite**

Run: `vendor/bin/phpunit`
Expected: no failures.

- [ ] **Step 7: Commit** (skip; record in the ledger)

```bash
git add src/Mcp/ToolExecutor.php src/Services/McpServerService.php tests/Unit/McpToolExecutorTest.php
git commit -m "refactor(mcp): extract ToolExecutor; McpServerService is now a thin JSON-RPC adapter"
```

---

### Task 6: Replace the SSE endpoint; stop advertising a prefix that does not exist

**Files:**
- Modify: `src/Http/Controllers/McpHttpController.php` (`handleSse`)
- Modify: `src/Mcp/SchemaTools.php` (`apiGuidance()`: the `dataMcp`/`schemaMcp` routes)
- Modify: `config/sp-record.php` and `src/Console/SetupPackageCommand.php` (comment only: mark `route_prefix` deprecated)
- Modify: `tests/Feature/McpHttpControllerTest.php` (the SSE test)
- Test: `tests/Feature/McpEndpointUrlsTest.php` (create)

- [ ] **Step 1: Write the failing tests.** In `tests/Feature/McpEndpointUrlsTest.php` (MCP enabled, `sp-api-mcp.enabled` true):

```php
    /** @test */
    public function the_sse_endpoint_is_method_not_allowed_instead_of_hanging(): void
    {
        $this->get('/api/mcp/sse')->assertStatus(405)->assertHeader('Allow', 'POST');
    }

    /** @test */
    public function the_guidance_advertises_the_urls_that_exist_even_when_route_prefix_is_set(): void
    {
        config(['record.mcp.route_prefix' => 'ai']);

        $guidance = (new SchemaTools())->apiGuidance();

        $this->assertSame('/api/mcp/message', $guidance['dataMcp']['route']);
        $this->assertSame('/api/mcp/schema', $guidance['schemaMcp']['route']);
        $this->postJson($guidance['dataMcp']['route'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])->assertOk();
    }
```

Update the existing `it_can_handle_sse_connection` in `McpHttpControllerTest` to assert the same 405 and rename it `the_sse_endpoint_answers_405`.

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --filter "McpEndpointUrlsTest|McpHttpControllerTest"`
Expected: FAIL — the SSE endpoint returns 200 `text/event-stream`, and the guidance advertises `/api/ai/message`.

- [ ] **Step 3: Implement.**
  - `McpHttpController::handleSse()` returns `response('', 405)->header('Allow', 'POST')`; drop the now-unused `StreamedResponse` import. Keep the route and its name `mcp.sse` registered (a name that existed stays valid).
  - In `SchemaTools::apiGuidance()`, derive the paths from the registered routes instead of the `route_prefix` config:

```php
        $apiPrefix = trim(RecordConfigService::apiPrefix(), '/');
        $dataRoute = Route::has('mcp.message') ? route('mcp.message', [], false) : '/' . $apiPrefix . '/mcp/message';
        $schemaRoute = Route::has('api_schema_mcp') ? route('api_schema_mcp', [], false) : '/' . $apiPrefix . '/mcp/schema';
```

    and use `$dataRoute` / `$schemaRoute` for the two `'route'` entries (add `use Illuminate\Support\Facades\Route;`).
  - In `config/sp-record.php` and the `SetupPackageCommand` stub, change the `route_prefix` comment to: `Deprecated, no effect. The MCP routes are always under /{api_prefix}/mcp. Kept for compatibility.`

- [ ] **Step 4: Run them to verify they pass, then the parity test**

Run: `vendor/bin/phpunit --filter "McpEndpointUrlsTest|McpHttpControllerTest|McpExtractionParityTest"`
Expected: PASS. With the default prefix the guidance golden is unchanged, so no golden edit is needed.

- [ ] **Step 5: Commit** (skip; record in the ledger)

```bash
git add src/Http/Controllers/McpHttpController.php src/Mcp/SchemaTools.php config/sp-record.php src/Console/SetupPackageCommand.php tests/Feature/McpEndpointUrlsTest.php tests/Feature/McpHttpControllerTest.php
git commit -m "fix(mcp): the SSE endpoint answers 405; advertise the real MCP URLs"
```

---

### Task 7: Constant-time Schema MCP token check as middleware

**Files:**
- Create: `src/Http/Middleware/VerifySchemaMcpToken.php`
- Modify: `src/Http/Controllers/ApiSchemaMcpController.php`
- Test: `tests/Feature/SchemaMcpTokenTest.php` (create)

**Interfaces — Produces:** `VerifySchemaMcpToken::handle(Request $request, Closure $next): Response`, with the same decisions and 401 bodies the controller returns today.

- [ ] **Step 1: Write the failing tests** (`sp-api-mcp.enabled` true). Cover the four rules the controller enforces today:

```php
    /** @test */
    public function a_matching_token_is_accepted(): void
    {
        config(['sp-api-mcp.token' => 'secret']);
        $this->rpc('Bearer secret')->assertOk();
    }

    /** @test */
    public function a_wrong_token_is_a_401_with_the_invalid_token_body(): void
    {
        config(['sp-api-mcp.token' => 'secret']);
        $this->rpc('Bearer other')->assertStatus(401)->assertJsonPath('error.code', -32001)->assertJsonPath('error.message', 'Invalid MCP token');
    }

    /** @test */
    public function a_missing_token_is_a_401_when_one_is_configured(): void
    {
        config(['sp-api-mcp.token' => 'secret']);
        $this->rpc(null)->assertStatus(401)->assertJsonPath('error.message', 'Invalid MCP token');
    }

    /** @test */
    public function no_configured_token_outside_local_requires_authentication(): void
    {
        config(['sp-api-mcp.token' => null]);
        $this->app['env'] = 'production';
        $this->rpc(null)->assertStatus(401)->assertJsonPath('error.message', 'MCP schema requires authentication');
    }

    /** @test */
    public function no_configured_token_in_local_is_open(): void
    {
        config(['sp-api-mcp.token' => null]);
        $this->app['env'] = 'local';
        $this->rpc(null)->assertOk();
    }

    /** @test */
    public function the_controller_enforces_the_check_wherever_it_is_routed(): void
    {
        config(['sp-api-mcp.token' => 'secret']);
        \Illuminate\Support\Facades\Route::post('/custom-schema-mcp', [\Sopheak\Core\Http\Controllers\ApiSchemaMcpController::class, 'handle']);

        $this->postJson('/custom-schema-mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertStatus(401);
    }
```

where `rpc(?string $authorization)` posts a `tools/list` JSON-RPC body to `/api/mcp/schema` with that `Authorization` header (omitted when null).

- [ ] **Step 2: Run them to verify the current behaviour**

Run: `vendor/bin/phpunit --filter SchemaMcpTokenTest`
Expected: the first five PASS against the controller's inline check (they characterise today's rules). The last passes too, because the inline check travels with the controller. All six must stay green through Step 3; the pass-before is intentional, since this task refactors an existing behaviour.

- [ ] **Step 3: Implement.** Create the middleware with the controller's current branches moved verbatim, replacing `!==` with `hash_equals((string) $token, (string) $request->bearerToken())`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the Schema MCP. Moved out of ApiSchemaMcpController unchanged except
 * that the token is compared in constant time.
 */
final class VerifySchemaMcpToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('sp-api-mcp.token');

        if (null !== $token) {
            if (!hash_equals((string) $token, (string) $request->bearerToken())) {
                return $this->unauthorized($request, 'Invalid MCP token');
            }
        } elseif (!app()->environment('local')) {
            return $this->unauthorized($request, 'MCP schema requires authentication');
        }

        return $next($request);
    }

    private function unauthorized(Request $request, string $message): Response
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $request->json('id'),
            'error' => ['code' => -32001, 'message' => $message],
        ], 401);
    }
}
```

In `ApiSchemaMcpController`, delete the inline check and attach the middleware to the controller itself, so it applies wherever the controller is routed:

```php
class ApiSchemaMcpController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(VerifySchemaMcpToken::class)];
    }
```

(`use Illuminate\Routing\Controllers\HasMiddleware; use Illuminate\Routing\Controllers\Middleware;`.)

- [ ] **Step 4: Run the token tests and every MCP suite**

Run: `vendor/bin/phpunit --filter "SchemaMcpTokenTest|Mcp"`
Expected: PASS.

- [ ] **Step 5: Final gate**

Run: `vendor/bin/phpunit && vendor/bin/phpstan analyse src tests --no-progress && vendor/bin/rector process --dry-run --no-progress-bar && php bin/validate-docs.php && graft build`
Expected: phpunit green; PHPStan `[OK]`; Rector reports nothing new in files this plan touched; validate-docs only the 8 pre-existing failures.

- [ ] **Step 6: Commit** (skip; record in the ledger)

```bash
git add src/Http/Middleware/VerifySchemaMcpToken.php src/Http/Controllers/ApiSchemaMcpController.php tests/Feature/SchemaMcpTokenTest.php
git commit -m "fix(mcp): compare the Schema MCP token in constant time, in middleware"
```
