---
title: "AI SDK Record Tools Plan"
description: "Implementation plan for the AI SDK record tools spec: RecordTools / RecordToolSet / RecordTool over the shared MCP tool core, ToolContext for queued agents, writes gated by approval."
keywords:
  - ai sdk
  - laravel/ai
  - agents
  - tools
  - plan
---

# AI SDK Record Tools Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `RecordTools::for(['invoices'])` returns `laravel/ai` tools that run through the same `ToolExecutor` as the MCP servers, so an agent inherits tenant isolation, Gate permissions, `viewOwn`, hidden-column stripping and nested-write authorization; writes wait for a person's approval unless the developer opts out.

**Architecture:** A transport-free `ToolContext` (user + tenant installed for one call and always restored) and two small `ToolExecutor`/`ToolCatalog` additions in `src/Mcp`; a new `src/Ai` layer (`SchemaConverter`, `RecordTool`, `RecordToolSet`, `RecordTools`) that is only loaded when `laravel/ai` is installed.

**Tech Stack:** PHP 8.3+ for the AI layer (package floor stays 8.2), `laravel/ai` ^1.0.1 (dev + `suggest`), Orchestra Testbench, PHPUnit 11.

**Spec:** `docs/superpowers/specs/2026-10-02-ai-sdk-record-tools-design.md` (§6 API, §7 architecture, §8 invariants, §11 verification, §12 review focus). It depends on the shared tool core, nested-write authorization and corrected guidance from the MCP spec — all already in the tree.

## Phase 0 result (spike, 2026-10-02, laravel/ai v1.0.1, Laravel 13.34, PHP 8.4)

Verified against the real package; no spec assumption failed, with these refinements:
- **Schema conversion:** `JsonSchema::fromArray(SchemaNormalizer::normalize($schema))` keeps every property name and `required` list of every catalog tool, the union `id` type (`["string","integer"]`), and turns `additionalProperties: true` objects into plain `{"type":"object"}`. `SchemaNormalizer` also silently drops unsupported keywords, so `SchemaConverter` verifies names and `required` after converting and throws on any difference.
- **Tool-name limit:** `laravel/ai` enforces none. The providers it targets cap function names at 64 characters of `[A-Za-z0-9_-]`, so the build-time check uses `^[A-Za-z0-9_-]{1,64}$`.
- **Approvals:** `Approvable` + `InteractsWithApprovals::needsApproval(Request): Approval|bool` (protected, default `true`). A write pauses (tool not run, `pendingApprovals` carries the real arguments and reason), a plain non-conversational agent throws `ApprovalNotResumableException`. `AgentFake` does **not** execute a resumed tool; a real-path resume is tested by swapping the provider's gateway: `Ai::textProvider()->useTextGateway(new FakeTextGateway([...]))` with a stateful `Conversational` agent that returns `$first->messages` as history. `Decision::edit()` lets an approver change the arguments; the tool receives the edited ones, so what is shown is what is written.
- **Queueing:** `InvokeAgent` serialises the agent; `Promptable` uses `SerializesModels`, which only swaps *direct* model properties. A user held inside a tool inside an array is serialised in full, so `ToolContext` serialises a user key and resolves it lazily.
- **Testbench:** the AI service provider must be registered explicitly in package tests.

## Global Constraints

- The AI layer must not load without `laravel/ai`: nothing in `src/Ai` is autoloaded unless `RecordTools` is used, and `RecordTools` throws a `RuntimeException` naming `laravel/ai` when `Laravel\Ai\Contracts\Tool` is missing.
- Both MCP drivers keep their behaviour exactly: `ToolExecutor::call()`'s new third parameter is optional, `ToolCatalog::data()`'s new parameter is optional.
- Every AI tool call goes through `ToolExecutor`; there is no direct `RecordService` call in `src/Ai`.
- Tenant and user come only from the request or a `ToolContext`; never from model-supplied arguments.
- `record.mcp.read_only` is an MCP transport setting and is **not** honoured by the AI tools (spec Q3); the developer picks the actions in code.
- Commit/push only when the user asks; executors skip commit steps and record that. Never `git add -A`.
- Quality gate: `vendor/bin/phpunit`, `SP_MCP_DRIVER=laravel vendor/bin/phpunit --filter "Mcp|OwnRecords|NestedChildWrite|HiddenColumnSanitizationMutationChannelsTest|SchemaMcpToken|ReservedRouteSegment|PermissionGateIntegration"`, `vendor/bin/phpstan analyse src tests`, `vendor/bin/rector process --dry-run --no-progress-bar` (apply to files created here only), `php bin/validate-docs.php` (8 pre-existing frontmatter failures), `graft build`.
- Test helper methods must not be named `call()` or `put()`.

## Review Focus

1. **No path around the executor** — every `RecordTool::handle()` ends in `ToolExecutor::call()`.
2. **Restore in `finally`** — `ToolContext::run()` restores the guard users and the request's tenant attribute even when the callback throws, including a tenant attribute that did not exist before.
3. **Schema conversion never fails silently** — a tool whose schema cannot convert (or loses a property / required entry) throws, naming the tool; it never becomes a parameterless tool.
4. **Queued agents carry no secrets** — a serialised `RecordToolSet` contains no user attributes or credentials.
5. **Approval arguments are what gets written** — the pending approval shows the exact tool-call arguments, and an edited decision is what the executor receives.
6. **Reads never pause; writes always do by default** — and `withoutApproval()` is the only way to skip it.

## File Structure

| File | Responsibility |
|---|---|
| `src/Mcp/ToolContext.php` (new) | Optional user + tenant for one call; `run()` installs and restores; serialises a user key |
| `src/Mcp/ToolExecutor.php` | `call(..., ?ToolContext $context = null)`; `honourReadOnly` constructor option |
| `src/Mcp/ToolCatalog.php` | `data(?bool $readOnly = null)` |
| `src/Ai/SchemaConverter.php` (new) | Catalog `inputSchema` → `array<string, Type>`, verified |
| `src/Ai/RecordTool.php` (new) | One `laravel/ai` tool over one `ToolDefinition`; approvable writes |
| `src/Ai/RecordToolSet.php` (new) | Immutable fluent set |
| `src/Ai/RecordTools.php` (new) | `for()`, `readOnly()`, `schema()` factory with build-time validation |
| `tests/Concerns/UsesLaravelAi.php` (new) | Registers the AI provider, skips without `laravel/ai`, scripted gateway helper |
| `tests/Support/AiRecordAgent.php` (new) | A conversational test agent over given tools |
| `docs/guide/modules/module-ai-sdk.md` | Rewritten |

---

### Task 1: `ToolContext` and the two small core additions

**Files:**
- Create: `src/Mcp/ToolContext.php`, `tests/Feature/ToolContextTest.php`
- Modify: `src/Mcp/ToolExecutor.php`, `src/Mcp/ToolCatalog.php`; extend `tests/Unit/McpToolExecutorTest.php` / `tests/Unit/McpToolCatalogTest.php`

**Interfaces:**
- Produces: `ToolContext::__construct(?Authenticatable $user = null, mixed $tenantId = null)`, `ToolContext::none()`, `withUser(Authenticatable)`, `withTenant(mixed)`, `user(): ?Authenticatable` (lazy), `isEmpty(): bool`, `run(Closure): mixed`; `ToolExecutor::__construct(bool $schemaOnly = false, bool $honourReadOnly = true)`; `ToolExecutor::call(string $name, array $args, ?ToolContext $context = null): ToolResult`; `ToolCatalog::data(?bool $readOnly = null)`.
- `run()` rules: for the configured guard (`RecordConfigService::authGuard()`) and the default guard it records `hasUser() ? user() : null`, sets the context user when there is one, sets the request attribute `resolved_tenant_id` when there is a tenant, runs the callback, and **in `finally`** restores each guard (`setUser(previous)` or `forgetUser()` when there was none) and the attribute (removes it when it did not exist). An empty context runs the callback untouched.
- Serialisation: `__serialize()` stores the tenant and the user's `getAuthIdentifier()`; `__unserialize()` keeps the key and `user()` resolves it through the configured guard's user provider (`retrieveById`).

- [x] **Step 1: Write the failing tests**

`ToolContextTest` (Testbench + `RefreshDatabase`; a `users` table and the `database` user provider that `tests/TestCase.php` configures):
1. `test_run_installs_the_user_on_both_guards_and_the_tenant_then_restores_them` — before: no user on either guard and no `resolved_tenant_id`; inside the callback `auth('api')->user()` and `auth()->user()` are the context user and `request()->attributes->get('resolved_tenant_id')` is the tenant; after: both guards have no user (`hasUser()` false) and the attribute is gone (`has()` false).
2. `test_run_restores_a_previous_user_and_tenant` — set user A and tenant `t1` first; run with user B / `t2`; after the call A and `t1` are back.
3. `test_run_restores_when_the_callback_throws` — a throwing callback; the exception propagates; state restored.
4. `test_an_empty_context_changes_nothing` — `ToolContext::none()->run(...)` returns the callback's value and leaves state untouched.
5. `test_withers_are_immutable` — `withUser()` / `withTenant()` return new instances; the original stays empty.
6. `test_serialising_stores_a_key_not_the_user` — user model with a `password` attribute `SECRET-HASH`; `serialize($context)` does not contain `SECRET-HASH` or the user's class attributes; after `unserialize`, `$context->user()` is the user from the provider (`id` equal) and `tenantId` survives.
7. `test_a_context_whose_user_no_longer_exists_has_no_user` — unserialise a context whose key is not in the table → `user()` is `null`.

`McpToolExecutorTest` additions: `test_a_context_runs_the_call_as_its_user_and_tenant` (executor with a `ToolContext($user, 't1')` reads/writes a tenant-scoped table as that user/tenant and restores state; a call with no context behaves as today); `test_read_only_mode_is_honoured_unless_the_executor_opts_out` (`record.mcp.read_only` true: `new ToolExecutor()` refuses `create_x` with `-32601`; `new ToolExecutor(honourReadOnly: false)` runs it).
`McpToolCatalogTest` addition: `data(readOnly: false)` lists write tools even when `record.mcp.read_only` is true; `data()` is unchanged.

- [x] **Step 2: Run to verify they fail** — `vendor/bin/phpunit --filter "ToolContextTest|McpToolExecutorTest|McpToolCatalogTest"` → FAIL (`ToolContext` not found, unknown named argument).

- [x] **Step 3: Implement** per the Interfaces block. `ToolExecutor::call()` becomes: `return null === $context || $context->isEmpty() ? $this->dispatch($name, $args) : $context->run(fn () => $this->dispatch($name, $args));` with the existing body moved to private `dispatch()`; the read-only refusal gains `$this->honourReadOnly &&`. `ToolCatalog::data(?bool $readOnly = null)` does `$readOnly ??= (bool) config('record.mcp.read_only', true);`.

- [x] **Step 4: Run to verify they pass**, plus `vendor/bin/phpunit --filter Mcp` and `SP_MCP_DRIVER=laravel vendor/bin/phpunit --filter Mcp` (unchanged MCP behaviour).

- [x] **Step 5: Mutation checks, then ledger** — move the restore out of `finally` → test 3 fails; drop the default-guard assignment → test 1 fails; restore. Skip the commit.

---

### Task 2: `SchemaConverter` and `RecordTool`

**Files:**
- Create: `src/Ai/SchemaConverter.php`, `src/Ai/RecordTool.php`, `tests/Concerns/UsesLaravelAi.php`, `tests/Feature/Ai/SchemaConverterTest.php`, `tests/Feature/Ai/RecordToolTest.php`

**Interfaces:**
- Consumes: Task 1; `ToolDefinition` (`name`, `description`, `inputSchema`, `action`, `table`).
- Produces: `SchemaConverter::properties(string $toolName, array $inputSchema): array<string, Type>` — throws `RuntimeException("Tool '<name>': …")` when conversion fails or a property name / `required` entry is lost; `RecordTool::__construct(ToolDefinition $definition, ?ToolContext $context = null)`, `name()`, `description()`, `schema(JsonSchema)`, `handle(Request): string`, `action(): string`, `table(): ?string`, protected `needsApproval(Request): Approval|bool`.
- `handle()` mapping: success → `json_encode($result->structuredContent, JSON_UNESCAPED_SLASHES)`; `ToolError` → `{"error":{"code":<code>,"message":"<message>"}}`; an `isError` result → `{"error":{"message":"<message>"}}`; any other `Throwable` is not caught. The executor is `new ToolExecutor(honourReadOnly: false)` and receives `$request->all()` and the tool's context.
- `needsApproval()`: `Approval::required("<action> on <table> changes data")` for `create`/`update`/`delete`, `false` otherwise.

`UsesLaravelAi` trait: `getPackageProviders()` returns the package provider plus `Laravel\Ai\AiServiceProvider` when the class exists; a `setUp` hook marks the test skipped when `Laravel\Ai\Contracts\Tool` is missing; `scriptedGateway(array $responses): FakeTextGateway` sets `ai.default`/`ai.providers.openai` and installs the gateway on `Ai::textProvider()`.

- [x] **Step 1: Write the failing tests**

`SchemaConverterTest`: (a) for every tool of `ToolCatalog` over the guidance fixture (`BuildsGuidanceFixture`) the converted property names equal the raw `inputSchema` property names and the converted `ObjectType` reports the same required list (build a probe `ObjectType` from the returned properties and compare `toArray()['required']`); (b) the `id` property of `read_invoices` converts to `["string","integer"]`; (c) a hand-made schema with a property that the normalizer drops entirely (e.g. only `{"not":{"type":"string"}}`) makes `properties()` throw a `RuntimeException` whose message names the tool; (d) a non-object root throws naming the tool; (e) a schema with no properties returns `[]` without throwing.

`RecordToolTest` (fixture `invoices`, public read/write): (1) `name()`/`description()` equal the definition's; (2) `handle()` for `create_invoices` creates the row and returns JSON equal to a direct `ToolExecutor::call('create_invoices', …)->structuredContent` (compare after decoding, with a second invoice for the direct call and ignoring ids/timestamps); (3) `list_invoices` returns the structured response; (4) a table the caller cannot read (Gate denies `view:invoices`, `isAuthRead: true`) → `{"error":{"code":-32002,"message":"Forbidden"}}` and no exception; unauthenticated → `-32001`; (5) a model-supplied `tenantId` that disagrees with the request tenant → `-32001` error string; (6) a validation failure that the executor returns as `isError` → `{"error":{"message":…}}` with no `code`; (7) an unexpected exception (stub a `RecordService` failure by making the table's trigger throw) propagates; (8) `schema()` returns the converted properties; (9) `needsApproval` — a write tool's `shouldRequestApproval(new Request([...]))` returns an `Approval` whose reason is `create on invoices changes data`; a read tool's returns `null`.

- [x] **Step 2: Run to verify they fail** — `vendor/bin/phpunit --filter "SchemaConverterTest|RecordToolTest"` → FAIL (classes not found).

- [x] **Step 3: Implement.** `SchemaConverter` uses `JsonSchema::fromArray(SchemaNormalizer::normalize($schema))` inside `try/catch (Throwable)`, requires an `ObjectType`, reads its properties with the same `(fn (): array => $this->properties)->call($type)` closure `laravel/ai`'s own `McpTool` uses, then compares `array_keys($properties)` with the raw property names and the converted `required` with the raw one. `RecordTool` follows the Interfaces block; `final`, `use InteractsWithApprovals`, holds the `ToolDefinition` and optional `ToolContext` only (no executor, no user object).

- [x] **Step 4: Run to verify they pass.**

- [x] **Step 5: Mutation checks, then ledger** — make `handle()` call `RecordService::executeCreate()` directly → test 4 (no permission check) fails; make `SchemaConverter` return `[]` on error → test (c) fails; restore. Skip the commit.

---

### Task 3: `RecordToolSet` and `RecordTools`

**Files:**
- Create: `src/Ai/RecordToolSet.php`, `src/Ai/RecordTools.php`, `tests/Feature/Ai/RecordToolsTest.php`

**Interfaces:**
- Consumes: Tasks 1–2; `ToolCatalog::data(readOnly: false)` and `schema()`; `SchemaRegistryUtils::get()` for table names.
- Produces: `RecordTools::for(string|array $tables): RecordToolSet`, `RecordTools::readOnly(string|array $tables): RecordToolSet`, `RecordTools::schema(): RecordToolSet`; `RecordToolSet implements IteratorAggregate, Arrayable, Countable` with `only(array)`, `except(array)`, `withoutApproval()`, `requireApproval(?string $reason = null)`, `actingAs(Authenticatable)`, `forTenant(mixed)`, `getIterator(): ArrayIterator<int, RecordTool>`, `toArray(): array<int, RecordTool>`, `count(): int`, `names(): array<int, string>`. Every method returns a new set.
- Rules: actions are `list`, `read`, `create`, `update`, `delete` (an unknown action name → `InvalidArgumentException` listing the valid ones); an unknown table → `InvalidArgumentException` naming it and listing the registered tables; `only()` naming an action a table does not allow (its `can*` flag) → `InvalidArgumentException` naming table and action; `for()` simply omits actions a table does not allow; a tool name not matching `^[A-Za-z0-9_-]{1,64}$` → `InvalidArgumentException` naming the table and the name; approvals touch write tools only (`withoutApproval()` → `$tool->withoutApproval()`, `requireApproval($reason)` → `$tool->requireApproval($reason ?? "<action> on <table> changes data")`); `actingAs()`/`forTenant()` build one `ToolContext` handed to every tool; `RecordTools::*` and iterating a set throw a `RuntimeException` mentioning `laravel/ai` when `Laravel\Ai\Contracts\Tool` is missing.

- [x] **Step 1: Write the failing tests** (`RecordToolsTest`):
1. `for('invoices')` yields `list_invoices`, `read_invoices`, `create_invoices`, `update_invoices`, `delete_invoices` (order as the catalog); `for(['invoices','customers'])` yields ten; spreading `...RecordTools::for('invoices')` into an array works.
2. `readOnly()` yields only list + read; `schema()` yields the four `sp_api_*` tools and no data tool.
3. `only(['list','create'])` and `except(['delete'])` filter; both return new sets (the original is unchanged); `record.mcp.read_only = true` does **not** remove write tools (spec Q3).
4. Errors: unknown table, unknown action, `only(['create'])` on a table with `canCreate: false`, a table whose generated tool name exceeds 64 characters, a tool name with a character outside `[A-Za-z0-9_-]` — each throws `InvalidArgumentException` whose message names the table (and action/name).
5. `for()` omits actions a table does not allow (`canDelete: false` → no `delete_x`).
6. Approvals: by default write tools require approval (`shouldRequestApproval` returns an `Approval`) and read tools never do; `withoutApproval()` makes writes return `null`; `requireApproval('Finance must approve.')` carries that reason on writes only.
7. `actingAs($user)->forTenant('t1')`: every tool shares a context whose `user()` is the user and `tenantId` `t1`; the context is also inherited by tools created after later `only()`/`except()` calls.
8. Without `laravel/ai` the factory throws (simulated with an overridable `RecordTools::installed()` seam: a protected static method the test replaces through a tiny subclass is not allowed — instead assert the guard through `RecordTools::assertInstalled(?callable $classExists = null)` exactly as `McpDriver::assertInstalled()` is tested).

- [x] **Step 2: Run to verify they fail** → FAIL (classes not found).
- [x] **Step 3: Implement** per the Interfaces block (`RecordToolSet` holds `list<ToolDefinition>`, `approval` mode/reason and the optional `ToolContext`; it builds `RecordTool`s lazily in `getIterator()`).
- [x] **Step 4: Run to verify they pass.**
- [x] **Step 5: Mutation checks, then ledger** — drop the name check → test 4 fails; apply `withoutApproval()` to reads too → test 6 fails; make `only()` mutate in place → test 3 fails; restore. Skip the commit.

---

### Task 4: Agent-level behaviour — approvals, security, context, serialisation

**Files:**
- Create: `tests/Support/AiRecordAgent.php`, `tests/Feature/Ai/AgentApprovalTest.php`, `tests/Feature/Ai/AgentSecurityTest.php`, `tests/Feature/Ai/AgentContextTest.php`
- Production changes only if a test exposes a defect.

**Interfaces:**
- Consumes: Tasks 1–3; `Laravel\Ai\Promptable`, `Ai::textProvider()->useTextGateway(FakeTextGateway)` through `UsesLaravelAi::scriptedGateway()`.
- Produces: `AiRecordAgent` — `implements Agent, Conversational, HasTools`, constructed with `iterable $tools`, public `array $history` returned by `messages()`; helper `resume(Decision|Decisions)` copies `$first->messages` into history before prompting.

- [x] **Step 1: Write the tests**

`AgentApprovalTest` (scripted gateway returns a `ToolCall` for `create_invoices` with `{payload:{…}}`, then a final text): (1) the first prompt returns one pending approval with the real arguments and the reason `create on invoices changes data`, and no row exists; (2) resuming with `Decision::approveAll()` creates exactly one row; (3) `Decision::rejectAll()` writes nothing; (4) `Decision::edit([...])` writes the **edited** payload (Review Focus 5); (5) `withoutApproval()` writes on the first prompt with no pending approval; (6) a read (`list_invoices`) never pauses; (7) a non-conversational agent whose write is gated throws `ApprovalNotResumableException`; (8) a custom `requireApproval('Finance must approve.')` reason reaches the pending approval.

`AgentSecurityTest` (each through an agent run, asserting against the tool result recorded in the response's `toolResults`): (1) a user without `view:invoices` → the `list_invoices` result is the `-32002` error string and the run still finishes with the scripted text; (2) another tenant's row is not returned and a tenant-scoped table without a tenant → `-32001`; (3) a `tenantId` argument from the model that disagrees → `-32001`; (4) a `viewOwn` user lists only rows they created; (5) a hidden column never appears in a `read_` result; (6) a nested child write (`create_invoices` with `items`) without the child's permission → `-32002` and nothing written; with it → written; (7) the database effect and returned JSON of a tool call equal a direct `ToolExecutor::call()` (Review Focus 1).

`AgentContextTest`: (1) a queued-style tool set (`->actingAs($user)->forTenant('t1')`) run with **no** request user writes a row with the tenant stamped and `created_by_id` equal to the user; (2) afterwards the guard has no user and the request has no `resolved_tenant_id` (also when the tool call throws); (3) no context and no user on a non-public table → the `-32001` error string; (4) `serialize($agent)` of an agent holding a context-bearing tool set contains neither the user's `password` attribute value nor the user's class name as a serialised object, and the unserialised set still runs as that user (lazy resolve through the user provider) (Review Focus 4).

- [x] **Step 2: Run to verify** — most pass once Tasks 1–3 are right; every failure is a defect to fix in production code, test-first.
- [x] **Step 3: Mutation checks** — remove the approval from writes → `AgentApprovalTest` 1 fails; remove `finally` restore → context test 2 fails; keep the user object in `ToolContext::__serialize` → serialisation test fails.
- [x] **Step 4: Ledger.** Skip the commit.

---

### Task 5: Docs, changelog, spec status, gate

**Files:**
- Rewrite: `docs/guide/modules/module-ai-sdk.md` (keep the frontmatter shape; replace the broken `type:`/`required_params:` example)
- Modify: `CHANGELOG.md`, `docs/changelog.md`, `docs/superpowers/specs/2026-10-02-ai-sdk-record-tools-design.md` (status + phase 0 result), `.agents/rules/architecture.md` (one paragraph), `composer.json` (confirm `laravel/ai` in `require-dev` and `suggest`)

- [x] **Step 1: Write the guide.** Sections: what it is and why not MCP-over-stdio; install (`composer require laravel/ai`, PHP 8.3+); the API of §6 with the examples; names and schemas; who the call runs as (sync vs queued, `actingAs`/`forTenant`, "no context and no user → Unauthenticated"); approvals (default on for writes, conversational agents only, `withoutApproval()`, the reason, `Decision::edit`); large tool sets with `ToolSearch`; security guarantees (the five invariants); recipes (function endpoint calling an agent with the corrected `RecordFunctionType` example, streaming, `->queue()->then()`, files through `Files\Document::fromStorage()` after the package's visibility check, conversation history behind an ownership check, testing with `Ai::fakeAgent` and the real-path gateway swap); compatibility (PHP 8.2 apps cannot install `laravel/ai`; contributors on 8.2 run `composer remove --dev laravel/ai` — the AI tests skip themselves when it is missing).
- [x] **Step 2: Changelog** — `### Added` entry in both files, and a Notes line about the dev-dependency / PHP 8.2 contributor note.
- [x] **Step 3: Spec status** — set the status to implemented and add the "Phase 0 result" block from this plan under §10.
- [x] **Step 4: Gate** — full suite, laravel-driver matrix, phpstan, rector (new files only), validate-docs, `graft build`; record results in the ledger.

## Self-Review

- **Spec coverage:** §6 API → Task 3; §7.1 units → Tasks 1–3; §7.2 names/schemas → Tasks 2–3; §7.3 results/errors → Task 2; §7.4 context → Tasks 1 and 4; §7.5 approvals → Tasks 2–4; §7.6 `ToolSearch` → Task 5 docs; §8 invariants 1–5 → Tasks 2 and 4; §9 compatibility → Task 5; §10 phases → this plan (phase 0 done above); §11 verification → Tasks 2–4; §12 review focus → the six items above.
- **Placeholders:** none; the one deliberate seam (`RecordTools::assertInstalled(?callable)`) mirrors `McpDriver::assertInstalled()`.
- **Type consistency:** `ToolContext`, `ToolExecutor::call(…, ?ToolContext)`, `ToolCatalog::data(?bool)`, `SchemaConverter::properties`, `RecordTool`, `RecordToolSet`, `RecordTools` keep the same names and signatures in every task.
