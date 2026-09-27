---
title: "MCP Tenant Isolation Plan"
description: "Implementation plan for making the request the sole authority on tenant identity in the MCP data tools, closing a cross-tenant read/write leak."
keywords:
  - mcp
  - tenant isolation
  - plan
  - security
---

# MCP Data Module Tenant Isolation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop the MCP data tools from reading or writing another company's rows by making the request the sole authority on tenant identity.

**Architecture:** `McpServerService::handleToolsCall()` currently takes the tenant from the model's own tool arguments (`$args['tenantId']`), never consulting the request. We replace that with `RecordUtils::resolveTenantIdFromRequest(request())` — the same path the HTTP controllers use — reject a `tenantId` argument that disagrees with it, deny outright when a tenant-scoped table resolves no tenant, and drop `tenantId` from the tool input schemas so models stop supplying it.

**Tech Stack:** PHP 8.2+, Laravel 12/13, PHPUnit 11, PHPStan, Rector.

**Spec:** This plan's spec is the audit recorded in `docs/bug-reports/2026-09-27-mcp-tenant-isolation.md` (Task 0 writes it), which captures the reproduction below.

## Global Constraints

- Tenant identity comes from the request only: `RecordUtils::resolveTenantIdFromRequest(request())`. A tool argument is never a source of tenant identity.
- Enforcement applies only when `RecordUtils::shouldApplyTenantId($tableSchema)` is true (tenancy enabled **and** the table declares `hasTenantId`). Non-tenant tables are unaffected.
- Fail closed: a tenant-scoped table with no resolvable tenant denies the call. It must never fall back to "all tenants".
- Errors are thrown **before** the `try` block in `handleToolsCall()` so they surface as JSON-RPC errors, matching `authorizeAction()`. Use `throw new Exception(message: ..., code: -32001)`.
- Backward compatibility is explicitly **not** preserved for callers passing a conflicting `tenantId`; that is the vulnerability. Callers passing a *matching* `tenantId` keep working.
- Run `composer test`, `vendor/bin/phpstan analyse src tests`, and `vendor/bin/rector process --dry-run --no-progress-bar` before each commit. Rector must report no new findings for touched files.
- After code changes, run `graphify update .`.

## Review Focus

1. **Table with `hasTenantId: false` while tenancy is globally enabled** — must keep working with no tenant present; the new denial must not apply. Pinned in Task 2.
2. **`tenantId` argument that matches the resolved tenant** — must be accepted, not rejected, or every well-behaved existing client breaks. Pinned in Task 2.
3. **Loose-typed tenant equality (`"1"` vs `1`)** — a header is always a string, an argument may be an int; naive `!==` would reject a legitimate match. Pinned in Task 2.
4. **Write tools (`create`/`update`/`delete`), not just `list`** — the leak lets a caller *write* into another company's data; read-only tests would miss it. Pinned in Task 3.
5. **`read`-by-id across tenants** — fetching a known id from another company must 404/deny rather than return the row. Pinned in Task 3.

---

### Task 0: Record the audit as a spec

**Files:**
- Create: `docs/bug-reports/2026-09-27-mcp-tenant-isolation.md`

**Interfaces:**
- Consumes: nothing.
- Produces: the spec document the remaining tasks argue from.

- [ ] **Step 1: Write the report**

Include, verbatim, the reproduction table produced against two companies' rows in one table, calling as tenant 1:

| Tool call | Result |
|---|---|
| `tenantId: 1` (own) | `ACME-SECRET` — correct |
| `tenantId: 2` (other company) | `GLOBEX-SECRET` — cross-tenant read |
| `tenantId` omitted | `ACME-SECRET + GLOBEX-SECRET` — all tenants |
| `tenantId: 2` + header `X-Tenant-ID: 1` | `GLOBEX-SECRET` — header does not constrain |
| no `tenantId` + header `X-Tenant-ID: 1` | `ACME-SECRET + GLOBEX-SECRET` — header ignored |

State the root cause (`src/Services/McpServerService.php:419`, `$tenantId = $args['tenantId'] ?? null`), the three distinct failures (argument trusted, omission disables scoping, request tenant never consulted), and that `read_only=false` extends this to writes. Add YAML frontmatter with `title`, `description`, `keywords` so `php bin/validate-docs.php` does not gain a new failure.

- [ ] **Step 2: Verify docs validation gains no new failure**

Run: `php bin/validate-docs.php 2>&1 | grep -c "2026-09-27-mcp-tenant-isolation"`
Expected: `0`

- [ ] **Step 3: Commit**

```bash
git add docs/bug-reports/2026-09-27-mcp-tenant-isolation.md
git commit -m "docs: record MCP cross-tenant data leak audit"
```

---

### Task 1: Resolve the tenant from the request

**Files:**
- Modify: `src/Services/McpServerService.php` (add `resolveToolTenantId()`; change `handleToolsCall()` line 419)
- Test: `tests/Feature/McpTenantIsolationTest.php`

**Interfaces:**
- Consumes: `RecordUtils::resolveTenantIdFromRequest(Request $request): mixed`, `RecordUtils::shouldApplyTenantId(object $tableSchema): bool`, `RecordUtils::isTenantIdMissing(mixed $tenantId): bool`, `RecordUtils::normalizeTenantId(mixed $tenantId): mixed`, `SchemaRegistryUtils::getTable(string $table): ?RecordTableType`.
- Produces: `protected function resolveToolTenantId(string $table, array $args): mixed` — returns the authoritative tenant for this call, or throws `Exception` with code `-32001`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/McpTenantIsolationTest.php`. The fixture seeds two companies' rows in one table and calls the MCP data endpoint over HTTP.

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\CoreSpLaravelApiProvider;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * The MCP data tools took the tenant from the model's own tool arguments and
 * never consulted the request, so a caller could read or write another
 * company's rows by passing their tenant id — or omit it and get every
 * tenant's rows at once. The request is the only authority now.
 *
 * @internal
 */
class McpTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [CoreSpLaravelApiProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('record.mcp.enabled', true);
        $app['config']->set('record.mcp.read_only', false);
        $app['config']->set('record.mcp.middleware', []);
        $app['config']->set('record.enable_tenant_id', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('widgets', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->timestamps();
        });

        DB::table('widgets')->insert([
            ['id' => 1, 'name' => 'ACME-SECRET', 'tenant_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'GLOBEX-SECRET', 'tenant_id' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Config::set('record.tables', ['widgets' => new RecordTableType(
            table: 'widgets',
            pmsName: 'widget',
            hasTenantId: true,
            public: new RecordTablePublic(read: true, write: true),
            columns: [
                'id' => ['type' => 'integer', 'nullable' => false],
                'name' => ['type' => 'string', 'nullable' => false],
                'tenant_id' => ['type' => 'bigInteger', 'nullable' => true],
                'created_at' => ['type' => 'datetime', 'nullable' => true],
                'updated_at' => ['type' => 'datetime', 'nullable' => true],
            ],
        )]);

        SchemaRegistryUtils::refresh();
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, string> $headers
     */
    protected function callTool(string $tool, array $arguments, array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/mcp/message', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ], $headers);
    }

    protected function bodyText(\Illuminate\Testing\TestResponse $response): string
    {
        return (string) ($response->json('result.content.0.text') ?? json_encode($response->json()));
    }

    /** @test */
    public function the_request_tenant_scopes_the_result(): void
    {
        $body = $this->bodyText($this->callTool('list_widgets', [], ['X-Tenant-ID' => '1']));

        $this->assertStringContainsString('ACME-SECRET', $body);
        $this->assertStringNotContainsString('GLOBEX-SECRET', $body);
    }

    /** @test */
    public function a_tenant_id_argument_for_another_company_is_rejected(): void
    {
        $response = $this->callTool('list_widgets', ['tenantId' => 2], ['X-Tenant-ID' => '1']);
        $body = $this->bodyText($response);

        $this->assertStringNotContainsString('GLOBEX-SECRET', $body);
        $this->assertStringContainsString('tenant', strtolower($body));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter McpTenantIsolationTest`
Expected: both FAIL — `the_request_tenant_scopes_the_result` returns both companies' rows (the header is ignored), and `a_tenant_id_argument_for_another_company_is_rejected` returns `GLOBEX-SECRET`.

- [ ] **Step 3: Add the resolver**

Insert immediately above `protected function authorizeAction(` in `src/Services/McpServerService.php`:

```php
    /**
     * Resolve the tenant this tool call is allowed to touch.
     *
     * The tenant is taken from the request — the same authority the HTTP
     * controllers use — and never from the tool arguments. A model can put any
     * value in `arguments`, so trusting `tenantId` there let one company's
     * client read and write another company's rows simply by naming their id,
     * and omitting it disabled scoping altogether.
     *
     * A `tenantId` argument is therefore only an assertion: it must agree with
     * the request, or the call is refused. When a tenant-scoped table resolves
     * no tenant at all, the call is refused rather than silently widened to
     * every tenant.
     *
     * @param array<string, mixed> $args
     * @throws Exception
     */
    protected function resolveToolTenantId(string $table, array $args): mixed
    {
        $tableSchema = SchemaRegistryUtils::getTable($table);

        if (!$tableSchema instanceof RecordTableType || !RecordUtils::shouldApplyTenantId($tableSchema)) {
            return null;
        }

        $resolved = RecordUtils::resolveTenantIdFromRequest(request());

        if (RecordUtils::isTenantIdMissing($resolved)) {
            throw new Exception(
                message: 'Tenant context is required for ' . $table . ' but none was resolved from the request.',
                code: -32001,
            );
        }

        if (array_key_exists('tenantId', $args) && !RecordUtils::isTenantIdMissing($args['tenantId'])) {
            $claimed = RecordUtils::normalizeTenantId($args['tenantId']);

            // Loose comparison: a header is always a string, an argument may be
            // an int, and "1" and 1 name the same tenant.
            if ((string) $claimed !== (string) RecordUtils::normalizeTenantId($resolved)) {
                throw new Exception(
                    message: 'The tenantId argument does not match the authenticated tenant context.',
                    code: -32001,
                );
            }
        }

        return $resolved;
    }

```

- [ ] **Step 4: Use it in `handleToolsCall()`**

Replace this line (currently `src/Services/McpServerService.php:419`):

```php
        $tenantId = $args['tenantId'] ?? null;
```

with:

```php
        $tenantId = $this->resolveToolTenantId($table, $args);
```

It sits after `$this->authorizeAction($table, $authAction);` and before the `try {`, so a refusal surfaces as a JSON-RPC error rather than a tool result.

- [ ] **Step 5: Add the imports**

Confirm `use Sopheak\Core\Utilities\RecordUtils;` is present in the file's import block; add it if not.

Run: `grep -n "use Sopheak\\\\Core\\\\Utilities\\\\RecordUtils;" src/Services/McpServerService.php`
Expected: one match.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter McpTenantIsolationTest`
Expected: PASS

- [ ] **Step 7: Run the full gate**

Run: `vendor/bin/phpunit && vendor/bin/phpstan analyse src tests`
Expected: all tests pass, `[OK] No errors`

- [ ] **Step 8: Commit**

```bash
git add src/Services/McpServerService.php tests/Feature/McpTenantIsolationTest.php
git commit -m "fix(mcp): resolve tool tenant from the request, never from tool arguments"
```

---

### Task 2: Pin the boundary cases

**Files:**
- Modify: `tests/Feature/McpTenantIsolationTest.php`

**Interfaces:**
- Consumes: `resolveToolTenantId()` from Task 1; `callTool()` and `bodyText()` helpers from Task 1's test.
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Write the tests**

Append to `tests/Feature/McpTenantIsolationTest.php`:

```php
    /** @test */
    public function omitting_the_tenant_id_no_longer_returns_every_tenant(): void
    {
        $body = $this->bodyText($this->callTool('list_widgets', [], ['X-Tenant-ID' => '2']));

        $this->assertStringContainsString('GLOBEX-SECRET', $body);
        $this->assertStringNotContainsString('ACME-SECRET', $body);
    }

    /** @test */
    public function a_matching_tenant_id_argument_is_accepted(): void
    {
        // Well-behaved existing clients pass the tenant they are already scoped
        // to; that must keep working.
        $body = $this->bodyText($this->callTool('list_widgets', ['tenantId' => 1], ['X-Tenant-ID' => '1']));

        $this->assertStringContainsString('ACME-SECRET', $body);
        $this->assertStringNotContainsString('GLOBEX-SECRET', $body);
    }

    /** @test */
    public function a_string_tenant_id_argument_matches_an_integer_tenant(): void
    {
        $body = $this->bodyText($this->callTool('list_widgets', ['tenantId' => '1'], ['X-Tenant-ID' => '1']));

        $this->assertStringContainsString('ACME-SECRET', $body);
    }

    /** @test */
    public function a_tenant_scoped_table_with_no_request_tenant_is_refused(): void
    {
        $body = $this->bodyText($this->callTool('list_widgets', []));

        $this->assertStringNotContainsString('ACME-SECRET', $body);
        $this->assertStringNotContainsString('GLOBEX-SECRET', $body);
        $this->assertStringContainsString('tenant', strtolower($body));
    }

    /** @test */
    public function a_table_without_tenant_id_is_unaffected(): void
    {
        Schema::create('notes', function (Blueprint $t): void {
            $t->id();
            $t->string('body');
            $t->timestamps();
        });
        DB::table('notes')->insert(['id' => 1, 'body' => 'SHARED-NOTE', 'created_at' => now(), 'updated_at' => now()]);

        $tables = Config::get('record.tables');
        $tables['notes'] = new RecordTableType(
            table: 'notes',
            pmsName: 'note',
            hasTenantId: false,
            public: new RecordTablePublic(read: true, write: true),
            columns: [
                'id' => ['type' => 'integer', 'nullable' => false],
                'body' => ['type' => 'string', 'nullable' => false],
                'created_at' => ['type' => 'datetime', 'nullable' => true],
                'updated_at' => ['type' => 'datetime', 'nullable' => true],
            ],
        );
        Config::set('record.tables', $tables);
        SchemaRegistryUtils::refresh();

        // No tenant header at all: a non-tenant table must still answer.
        $body = $this->bodyText($this->callTool('list_notes', []));

        $this->assertStringContainsString('SHARED-NOTE', $body);
    }
```

- [ ] **Step 2: Run them**

Run: `vendor/bin/phpunit --filter McpTenantIsolationTest`
Expected: PASS. If `a_table_without_tenant_id_is_unaffected` fails, `resolveToolTenantId()` is applying the guard to non-tenant tables — its `shouldApplyTenantId()` early return is wrong.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/McpTenantIsolationTest.php
git commit -m "test(mcp): pin tenant-isolation boundary cases"
```

---

### Task 3: Close the write and read-by-id paths

**Files:**
- Modify: `tests/Feature/McpTenantIsolationTest.php`

**Interfaces:**
- Consumes: `callTool()`, `bodyText()` from Task 1's test.
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Write the tests**

The leak is not read-only — `record.mcp.read_only` is `false` in this fixture, so the same argument let a caller write into another company's data.

```php
    /** @test */
    public function reading_another_companys_row_by_id_is_refused(): void
    {
        // id 2 belongs to tenant 2; the caller is tenant 1.
        $body = $this->bodyText($this->callTool('read_widgets', ['id' => 2], ['X-Tenant-ID' => '1']));

        $this->assertStringNotContainsString('GLOBEX-SECRET', $body);
    }

    /** @test */
    public function updating_another_companys_row_does_not_change_it(): void
    {
        $this->callTool('update_widgets', [
            'id' => 2,
            'payload' => ['name' => 'HIJACKED'],
            'tenantId' => 2,
        ], ['X-Tenant-ID' => '1']);

        $this->assertSame('GLOBEX-SECRET', DB::table('widgets')->where('id', 2)->value('name'));
    }

    /** @test */
    public function deleting_another_companys_row_does_not_remove_it(): void
    {
        $this->callTool('delete_widgets', [
            'id' => 2,
            'tenantId' => 2,
        ], ['X-Tenant-ID' => '1']);

        $this->assertSame(1, DB::table('widgets')->where('id', 2)->count());
    }

    /** @test */
    public function a_created_row_is_stamped_with_the_request_tenant(): void
    {
        $this->callTool('create_widgets', [
            'payload' => ['name' => 'NEW-ACME'],
        ], ['X-Tenant-ID' => '1']);

        $this->assertSame(1, (int) DB::table('widgets')->where('name', 'NEW-ACME')->value('tenant_id'));
    }
```

- [ ] **Step 2: Run them**

Run: `vendor/bin/phpunit --filter McpTenantIsolationTest`
Expected: PASS

- [ ] **Step 3: Run the full gate**

Run: `vendor/bin/phpunit && vendor/bin/phpstan analyse src tests`
Expected: all pass, `[OK] No errors`

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/McpTenantIsolationTest.php
git commit -m "test(mcp): pin cross-tenant write and read-by-id refusal"
```

---

### Task 4: Stop advertising `tenantId` to models

**Files:**
- Modify: `src/Services/McpServerService.php` (five `'tenantId' => ['type' => ['string', 'integer', 'null']],` lines in `handleToolsList()`)
- Test: `tests/Feature/McpTenantIsolationTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: tool input schemas with no `tenantId` property.

Leaving `tenantId` in the published `inputSchema` invites a model to send a value that can now only ever be redundant or rejected. Removing it makes the contract honest.

- [ ] **Step 1: Write the failing test**

```php
    /** @test */
    public function data_tools_no_longer_advertise_a_tenant_id_argument(): void
    {
        $response = $this->postJson('/api/mcp/message', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => [],
        ]);

        $tools = (array) $response->json('result.tools');
        $this->assertNotEmpty($tools);

        foreach ($tools as $tool) {
            $properties = (array) ($tool['inputSchema']['properties'] ?? []);
            $this->assertArrayNotHasKey(
                'tenantId',
                $properties,
                sprintf('tool %s must not advertise tenantId', (string) ($tool['name'] ?? '?'))
            );
        }
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter data_tools_no_longer_advertise_a_tenant_id_argument`
Expected: FAIL — `tool list_widgets must not advertise tenantId`

- [ ] **Step 3: Remove the five schema lines**

Delete every occurrence of this exact line in `src/Services/McpServerService.php` (there are five, in the list/read/create/update/delete tool definitions):

```php
                            'tenantId' => ['type' => ['string', 'integer', 'null']],
```

Note two of the five are indented one level deeper (32 spaces rather than 28). Verify none remain:

Run: `grep -c "'tenantId' => \['type'" src/Services/McpServerService.php`
Expected: `0`

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter McpTenantIsolationTest`
Expected: PASS — including the Task 2 case where a *matching* `tenantId` is still accepted, since removing it from the advertised schema does not make it an error to send.

- [ ] **Step 5: Run the full gate**

Run: `vendor/bin/phpunit && vendor/bin/phpstan analyse src tests && vendor/bin/rector process --dry-run --no-progress-bar`
Expected: all pass, `[OK] No errors`, and no rector finding naming `McpServerService.php` or `McpTenantIsolationTest.php` that was not already present.

- [ ] **Step 6: Commit**

```bash
git add src/Services/McpServerService.php tests/Feature/McpTenantIsolationTest.php
git commit -m "fix(mcp): remove tenantId from data tool input schemas"
```

---

### Task 5: Document the contract and release

**Files:**
- Modify: `docs/guide/modules/module-mcp.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/changelog.md`
- Modify: `docs/bug-reports/2026-09-27-mcp-tenant-isolation.md`

**Interfaces:**
- Consumes: the behaviour built in Tasks 1–4.
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Document the tenancy contract**

Add a `## Tenant Isolation` section to `docs/guide/modules/module-mcp.md` stating:

- The tenant comes from the request (`X-Tenant-ID` by default, `record.tenant_header`), or from `resolved_tenant_id` / `record_context` when middleware sets them. Middleware-set values take precedence.
- Tool arguments are never a source of tenant identity. A `tenantId` argument is accepted only when it matches, and is no longer advertised.
- A tenant-scoped table with no resolvable tenant refuses the call; it never widens to all tenants.
- Tables with `hasTenantId: false` are unaffected.
- Deployment note: because the tenant rides on the request, each company's MCP client config must carry that company's header/credential. A shared static token with no tenant context is not sufficient for multi-tenant use.

- [ ] **Step 2: Add the changelog entry**

Under `## [Unreleased]` → `### Fixed` in **both** `CHANGELOG.md` and `docs/changelog.md` (the two files have diverged; write to both, and keep `docs/changelog.md`'s blank line after `###` headings):

```markdown
- **MCP data tools could read and write another company's rows**: `McpServerService::handleToolsCall()` took the tenant from `$args['tenantId']` — a value supplied by the model — and never consulted the request, so a caller could name another tenant's id and get their data, or omit the argument and receive every tenant's rows at once. A correct `X-Tenant-ID` header did not constrain either case. The tenant is now resolved from the request via `RecordUtils::resolveTenantIdFromRequest()`; a `tenantId` argument that disagrees is refused; a tenant-scoped table with no resolvable tenant is refused rather than widened; and `tenantId` no longer appears in the published tool schemas. Tables with `hasTenantId: false` are unaffected. See `tests/Feature/McpTenantIsolationTest.php`.
```

- [ ] **Step 3: Close the audit report**

In `docs/bug-reports/2026-09-27-mcp-tenant-isolation.md`, set the status to fixed and add a resolution section naming the five reproduction rows and how each now behaves.

- [ ] **Step 4: Refresh the knowledge graph**

Run: `graphify update .`

- [ ] **Step 5: Final gate**

Run: `vendor/bin/phpunit && vendor/bin/phpstan analyse src tests && php bin/validate-docs.php`
Expected: all tests pass, `[OK] No errors`, and no *new* docs-validation failure (the pre-existing frontmatter failures under `bug-reports/`, `feature-requests/`, and `superpowers/plans/` remain).

- [ ] **Step 6: Commit**

```bash
git add docs/guide/modules/module-mcp.md CHANGELOG.md docs/changelog.md docs/bug-reports/2026-09-27-mcp-tenant-isolation.md
git commit -m "docs(mcp): document the tenant isolation contract"
```

---

## Out of Scope

Deliberately not addressed here; each needs its own decision:

- **Per-company API key issuance.** The package ships no key model, no `createToken`, no issuance command. The schema MCP uses one global static `sp-api-mcp.token` shared by every consumer; the data MCP delegates to the host app's `auth:sanctum`. Whether key minting becomes a package feature or stays the app's job is an open product decision.
- **Binding a credential to a tenant.** This plan makes the *request* authoritative; it does not decide how a token comes to carry a tenant. Until that exists, a company's MCP client config must supply the tenant header, and anyone able to set that header can still choose a tenant.
- **MCP protocol surface.** No `prompts/*`, `completion/*`, `logging/*`, or subscriptions; `protocolVersion` is `2024-11-05`, predating streamable HTTP. Fine for Claude Desktop and Cursor; a separate uplift if broader clients are needed.
