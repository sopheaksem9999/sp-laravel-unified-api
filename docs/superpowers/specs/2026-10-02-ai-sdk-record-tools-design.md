---
title: "AI SDK Record Tools"
description: "Give Laravel AI SDK agents in-process tools for the package's tables, backed by the same tool core as the MCP servers, so agents inherit tenant isolation, permissions, viewOwn and hidden-column sanitisation."
keywords:
  - ai sdk
  - laravel/ai
  - agents
  - tools
  - tool approval
  - tenant isolation
---

# AI SDK Record Tools — Design

> **Status:** Implemented (2026-10-02) — phase 0 spiked against `laravel/ai` v1.0.1; see the phase 0 result and implementation notes in §10
> **Date:** 2026-10-02
> **Package:** `sopheak/sp-laravel-api` (0.5.03)
> **Depends on:** `laravel/ai` ^1.0.1 (optional);
> [MCP on Laravel MCP](/superpowers/specs/2026-10-02-mcp-on-laravel-mcp-design), phases S, 1 and 3

## 1. Goal

Let an application's Laravel AI SDK agent read and write the package's tables
with one line of code:

```php
public function tools(): iterable
{
    return [
        ...RecordTools::readOnly(['invoices', 'customers']),
        ...RecordTools::for('invoice_items')->only(['create', 'update']),
    ];
}
```

These tools must give an agent exactly the guarantees an HTTP or MCP caller
gets, because the same code enforces them:

- tenant isolation;
- permission checks through Gate;
- `viewOwn` scoping;
- hidden-column stripping;
- nested-write authorization;
- the corrected tool schemas and guidance.

Writes are approved by a person unless the developer opts out.

## 2. Background

- **Today:** the package has no AI SDK code. [module-ai-sdk.md](/modules/module-ai-sdk)
  shows only how to call an app's own agent from a function endpoint, and its
  example config doesn't run (`type:` and `required_params:` are not
  `RecordFunctionType` parameters). Fixing the guide is tracked separately
  (§4).
- **The alternative, routing through MCP:** `laravel/ai` can load an MCP
  server's tools (`Client::local('php', ['artisan', 'sp-laravel-api:mcp'])->tools()`).
  For an agent inside the same app that is the wrong tool:
  - it starts a second PHP process;
  - it loses the current request's user and tenant, so the stdio command
    needs `--tenant`, and there is no user at all;
  - it serialises every call through JSON-RPC.

  In-process tools avoid all three.
- **What already exists to build on:** the MCP spec extracts a
  transport-free core:
  - `ToolCatalog` decides which tools exist and holds their definitions;
  - `ToolExecutor` runs one call — tenant, then authorize, then execute;
  - `ToolDefinition`, `ToolResult` and `ToolError` carry the data.

  That core is already the single place where MCP tool behaviour lives. This
  design adds a third adapter next to the two MCP drivers.

## 3. Facts about `laravel/ai` this design relies on

Each fact below was checked in the v1.0.1 source, not only the docs.

- **Requirements:** PHP `^8.3`, `illuminate/*` `^12.0|^13.0`. It suggests
  `laravel/mcp`. This package supports PHP `^8.2`, so `laravel/ai` can only be
  an optional dependency.
- **The tool contract** (`Contracts\Tool`) has `description()`,
  `handle(Tools\Request): Stringable|string` and
  `schema(JsonSchema): array<string, Type>`. `handle()` returns a string; there
  is no structured-result channel.
- **Per-instance names:** `Tools\ToolNameResolver` uses a public `name()`
  method when the tool has one, and the class basename otherwise. So per-table
  instances of one class can carry distinct names.
- **Raw JSON Schema:** `Tools\McpTool::schema()` converts raw JSON Schema
  through
  `Illuminate\JsonSchema\JsonSchema::fromArray(SchemaNormalizer::normalize($input))`
  and returns the resulting object's properties. On any conversion error it
  silently returns `[]`, which means a tool with no parameters.
- **Approvals:** `Contracts\Approvable` and `Concerns\InteractsWithApprovals`
  provide `requireApproval()`, `withoutApproval()` and an overridable
  `needsApproval(Request): Approval|bool`, which defaults to `true`.
- **When approvals throw:** if an approval is requested on a prompt that is
  neither `Conversational` nor carrying ad-hoc messages,
  `Providers\Concerns\GeneratesText::throwIfNotResumable()` throws
  `ApprovalNotResumableException` ("Tool approval requires a conversational
  agent…"). It is thrown only when an approval is actually requested, not
  merely because an `Approvable` tool is attached.
- **Arguments:** `Tools\Request` provides `all()`, `toArray()` and array access
  over the tool-call arguments.
- **Large catalogs:** `Providers\Tools\ToolSearch` defers loading of large tool
  sets.

## 4. Scope

**In scope**

1. The `RecordTools` factory and the `RecordToolSet` fluent set (§6).
2. `RecordTool`, a `laravel/ai` tool over one `ToolDefinition`, approvable for
   writes.
3. `ToolContext`, an explicit user and tenant for queued and background
   agents.
4. Converting catalog schemas to `laravel/ai` types, with no silent loss.
5. A new docs page, `docs/guide/modules/module-ai-sdk.md` (rewritten), plus a
   changelog entry.

**Out of scope**

- Fixing the current guide's broken example, and the two small executor fixes:
  passing `Responsable` results through, and creating function classes through
  the container. These are separate, small, test-first changes, and can ship
  before this design.
- Embeddings, `SimilaritySearch`, and conversation tables. These belong in docs
  recipes in the rewritten guide, not in code.
- Agents that act across tenants.

## 5. Dependencies on the MCP spec

| Needs | From the MCP spec | Why |
|---|---|---|
| `ToolCatalog`, `ToolExecutor`, `ToolDefinition`, `ToolResult`, `ToolError` | Phase 1 (core extraction) | One implementation of tool behaviour for all adapters |
| Corrected schemas and guidance (W1–W6, M1–M5, C1–C9) | Phase 3 | Agents follow hints literally. Wrong hints are worse for an autonomous agent than for a human |
| Nested-write authorization | Phase S | A nested `create_invoices` payload must not write child rows the user can't write |

It needs nothing from the `laravel` MCP driver (phase 2).

**Amendment to the MCP spec:** `ToolExecutor::call()` gains an optional
`?ToolContext $context = null` (§7.4). Both MCP drivers pass `null`, which
leaves their behaviour unchanged.

## 6. Developer API

```php
use Sopheak\Core\Ai\RecordTools;

RecordTools::for('invoices');                    // list, read, create, update, delete
RecordTools::for(['invoices', 'customers']);     // several tables
RecordTools::readOnly(['invoices', 'customers']);// list + read only
RecordTools::schema();                           // the four sp_api_* discovery tools (no data)

RecordTools::for('invoices')
    ->only(['list', 'create'])                   // or ->except(['delete'])
    ->withoutApproval()                          // writes run without a human decision
    ->requireApproval('Finance must approve.')   // or a custom reason (writes only)
    ->actingAs($user)->forTenant($tenantId);     // queued/background agents (§7.4)
```

- **The set:** `RecordToolSet` is `IteratorAggregate` and `Arrayable`, so
  `...RecordTools::for(...)` spreads into `tools()`.
- **Errors at build time:** an unknown table or action throws
  `InvalidArgumentException`.
- **Approvals apply to writes only.** Approval affects only `create`, `update`
  and `delete`; reads never pause.
- **Permissions:** the developer picks which tools exist, and permissions still
  apply per call. Giving an agent `delete_invoices` doesn't let a user without
  `delete:invoice` delete anything.

## 7. Architecture

```
app Agent::tools()
   └─ RecordTools::for(...)  ──►  RecordToolSet  ──►  RecordTool (one per ToolDefinition)
                                                         │ name() / description() / schema()
                                                         │ handle(Request) ─► ToolContext::run(
                                                         │                       ToolExecutor::call(...))
                                                         └ needsApproval(): writes only
   shared core (MCP spec phase 1):  ToolCatalog · ToolExecutor · ToolDefinition · ToolResult · ToolError
```

### 7.1 Units

| Unit | Responsibility | Depends on |
|---|---|---|
| `Sopheak\Core\Ai\RecordTools` | Static factory. Resolves table names to `ToolCatalog::data()` / `schema()` definitions, and validates tables and actions | `ToolCatalog` |
| `Sopheak\Core\Ai\RecordToolSet` | Immutable fluent set. Every method returns a new set: `only`, `except`, `withoutApproval`, `requireApproval`, `actingAs`, `forTenant`. Iterating it yields `RecordTool`s | `RecordTool` |
| `Sopheak\Core\Ai\RecordTool` (`implements Laravel\Ai\Contracts\Tool, Laravel\Ai\Contracts\Approvable`, `use InteractsWithApprovals`) | Wraps one `ToolDefinition`. `name()` and `description()` come from the definition. `schema()` comes from `SchemaConverter`. `needsApproval()` returns `Approval::required(...)` for write actions and `false` for reads. `handle()` is described in §7.3 | `ToolExecutor`, `SchemaConverter`, `ToolContext` |
| `Sopheak\Core\Ai\SchemaConverter` | Converts a definition's raw `inputSchema` into `array<string, Type>` with `Illuminate\JsonSchema\JsonSchema::fromArray()` and `SchemaNormalizer`. Unlike `McpTool`, it **throws** when conversion fails, naming the tool | `illuminate/json-schema`, `laravel/ai` |
| `Sopheak\Core\Mcp\ToolContext` (core, not AI-specific) | Value object holding an optional user (`Authenticatable`) and an optional tenant id. `run(Closure)` installs them for the duration of the call (§7.4) and restores the previous state in `finally` | `RecordConfigService` |

Every `Sopheak\Core\Ai\*` class is referenced only when
`interface_exists(\Laravel\Ai\Contracts\Tool::class)`. Without `laravel/ai`,
calling `RecordTools::…` throws a `RuntimeException` explaining that
`laravel/ai` is required. Nothing autoloads otherwise.

### 7.2 Names and schemas

- **Names:** tool names are the catalog names (`list_invoices`,
  `create_invoices`, `sp_api_get_endpoint`), so an agent, the MCP tools and
  the docs all use the same words. Names longer than the provider limit are an
  error at build time, naming the table. The limit is confirmed against each
  `laravel/ai` gateway in phase 0. They are never silently truncated.
- **Descriptions** are the catalog descriptions, corrected per the MCP spec's
  §6.6.
- **Schemas:** `SchemaConverter` converts the catalog `inputSchema`. Phase 0
  verifies that every catalog schema shape converts — the union `id` type
  (`["string","integer"]`), open `queryParams` objects with
  `additionalProperties: true`, and the payload objects — and decides how to
  represent any shape that does not convert. It is not allowed to drop it.

### 7.3 `handle()` results and errors

`handle(Request $request)` calls
`ToolContext::run(fn () => $executor->call($name, $request->all(), $context))`
and maps the outcome to the string `laravel/ai` expects:

| Outcome | Returned string |
|---|---|
| Success | Compact JSON of `ToolResult::structuredContent`, the same data the MCP tools return |
| `ToolError`: forbidden, unknown table, tenant refused, validation | `{"error": {"code": <code>, "message": "<message>"}}`. The model sees the refusal and can explain it; the agent run continues |
| Any other exception | Not caught. It propagates, so `laravel/ai` and the app's error handling treat it as a failure. A database or programming error must not look like a normal answer |

### 7.4 Who the call runs as: `ToolContext`

- **Synchronous agents** (prompted inside a request): no context is needed. The
  call runs as the current request's user and tenant, exactly as MCP does.
- **Queued and background agents** (`->queue()`, jobs, commands) have no
  request and no user. `RecordToolSet::actingAs($user)->forTenant($tenantId)`
  captures them at build time. `ToolContext::run()` then, for the duration of
  one tool call:
  1. sets the user on the configured guard (`RecordConfigService::authGuard()`)
     and on the default guard, which `OwnRecordsScope` reads;
  2. sets the `resolved_tenant_id` attribute on the current request, which
     `RecordUtils::resolveTenantIdFromRequest()` reads first;
  3. restores the previous user and attribute in `finally`.

  Worker processes reuse the request object across jobs, so the restore step is
  mandatory, not hygiene.
- **No context and no user:** a call to a non-public table fails as
  `Unauthenticated`, returned as a `ToolError` string. It does not run as
  anonymous.
- **Tool arguments never choose the user or tenant.** `ToolExecutor`'s
  existing `tenantId`-argument refusal applies unchanged.

Queued agents serialise the agent, including its tools. `RecordToolSet`
stores the user as its key and resolves it lazily, the way `SerializesModels`
does, so a queued payload never carries a user object or credentials. Phase 0
confirms how `laravel/ai` serialises queued tools.

### 7.5 Approvals

- **Writes need approval by default.** `create`, `update` and `delete` request
  approval with the reason "`<action>` on `<table>` changes data", or the
  developer's `requireApproval()` reason.
- **Conversational agents only.** Approvals can only resume on a conversational
  agent (§3). The docs state it plainly, and the error a developer sees is
  `laravel/ai`'s own `ApprovalNotResumableException`. Developers with a
  non-conversational agent choose between `->withoutApproval()`, for trusted
  automation, and making the agent `Conversational`.
- **The approval request shows the real payload.** The arguments are the exact
  tool-call arguments, so a reviewer approves the payload that will actually
  be written.

### 7.6 Large tool sets

Five tools per table add up quickly. The docs recommend wrapping large sets in
`laravel/ai`'s `ToolSearch` — `new ToolSearch(tools: RecordTools::for($many)->toArray())`
— so the provider loads tool definitions on demand. The package doesn't wrap
them automatically: `ToolSearch` is a provider tool, and not every provider
supports it.

## 8. Security invariants

These must hold, and each is pinned by a test:

1. **No bypass.** Every `RecordTool::handle()` goes through `ToolExecutor`,
   which enforces the same tenant resolution, `authorizeAction` (Gate),
   `OwnRecordsScope`, hidden-column stripping and nested-write authorization as
   HTTP and MCP. There is no direct `RecordService` call.
2. **Tenant and user come from server context only:** the request, or
   `ToolContext`. Never from model-supplied arguments.
3. **Context doesn't leak.** After a tool call, the guard user and the request
   tenant attribute are exactly what they were before, including when the call
   throws.
4. **Approval isn't bypassable through nesting.** A nested write inside an
   approved `update_invoices` is covered by that approval, and is still
   authorised per child table.
5. **Hidden columns stay hidden.** They never reach the model, including in
   error messages.

## 9. Compatibility

| Who | Effect |
|---|---|
| Apps without `laravel/ai` | None. Nothing loads |
| Apps on PHP 8.2 | Cannot install `laravel/ai`, so the feature is unavailable. Documented |
| Apps with `laravel/ai` | New, opt-in API. No existing behaviour changes |
| The MCP drivers | `ToolExecutor::call()` gains an optional parameter; both drivers pass `null` |

`composer.json`: add `laravel/ai` to `suggest` and to `require-dev`. On PHP
8.2, CI skips the AI tests when `Laravel\Ai\Contracts\Tool` is missing, so the
8.2 job doesn't install it.

## 10. Delivery phases

0. **Spike (throwaway).** Confirm:
   - every `ToolCatalog` schema shape converts through `SchemaConverter` with
     nothing dropped;
   - the provider tool-name limit, per gateway;
   - `ToolContext::run()` restores state when the callback throws;
   - how `laravel/ai` serialises a queued agent's tools, and that
     `RecordToolSet` survives it without carrying a user object;
   - an approval round trip on a conversational agent, under `AgentFake`.

   If any of these fails, stop and revise this spec.

   **Phase 0 result (2026-10-02, laravel/ai v1.0.1, Laravel 13.34, PHP 8.4).**
   Nothing failed; the findings that shaped the implementation:
   - **Schemas:** every `ToolCatalog` schema converts through
     `JsonSchema::fromArray(SchemaNormalizer::normalize(...))` with its property
     names, `required` list and union `id` type (`["string","integer"]`) intact;
     `additionalProperties: true` objects become plain `{"type":"object"}`. The
     normalizer can silently drop keywords and non-array property definitions, so
     `SchemaConverter` verifies names and `required` after converting and throws
     (naming the tool) on any difference.
   - **Tool-name limit:** `laravel/ai` enforces none. The providers it targets cap
     function names at 64 characters of `[A-Za-z0-9_-]`, which is the build-time
     rule.
   - **Approvals:** `needsApproval()` is `protected`. A gated write pauses (the
     tool does not run; the pending approval carries the real arguments and the
     reason) and a non-conversational agent throws
     `ApprovalNotResumableException`. `Ai::fakeAgent()` does not execute a
     resumed approval; the real-path tests swap the provider's gateway for a
     scripted `FakeTextGateway` instead. `Decision::edit()` lets an approver
     change the arguments, and the tool receives the edited ones.
   - **Queueing:** `InvokeAgent` serialises the agent, and `Promptable` uses
     `SerializesModels`, which only swaps *direct* model properties; a user held
     inside a tool is serialised whole. `ToolContext` therefore serialises the
     user's key and resolves the user lazily through the guard's user provider.

   **Implementation notes.**
   - `ToolExecutor` gained a second optional constructor argument,
     `honourReadOnly` (default `true`), and `ToolCatalog::data()` an optional
     `$readOnly` argument, so the AI tools can ignore `record.mcp.read_only`
     (Q3) without changing either MCP driver.
   - A failure *inside* the record operation (a trigger error, a database error)
     is already returned by `ToolExecutor` as an `isError` result — the text MCP
     returns — so `RecordTool` hands the model `{"error": {"message": …}}` for it;
     what escapes the executor (a broken permission hook, an `Error`) is not
     caught and fails the run (§7.3).
   - A `ToolContext` that names a user who no longer exists runs the call as
     nobody, never as whoever the process held.
   - `RecordToolSet::only()` throws when a named action is unavailable for a table
     in the set (its `can*` flag); `for()` simply leaves such actions out.
   - **Final review changes (2026-10-02).** (a) The catalog's free-form `payload` /
     `queryParams` objects cannot reach a provider: its mapper turns an object with
     no declared properties into `{"type":"object","additionalProperties":false}`
     (Gemini rejects it), which reads as "send no keys". `SchemaConverter`
     therefore declares them as strings holding a JSON object, `RecordTool` decodes
     them (an object is also accepted) and answers a wrongly-shaped argument with an
     error string; a free-form object anywhere below the top level, or any property
     or `required` entry lost at any depth, is a conversion error. (b) `handle()`
     reports an unexpected `Throwable` and rethrows `RuntimeException("The '<tool>'
     tool failed unexpectedly…")` with the original attached: `laravel/ai` turns a
     failure on the approval-resume path into a tool result the model reads, so the
     message must carry no internals. (c) A `ToolContext` that names a user it
     cannot resolve refuses to run (`Unauthenticated`) — clearing the guards is not
     enough because a session or token guard re-resolves the request's own user —
     and it stores and verifies the user's class as well as key. (d) The shared
     `ToolExecutor` answers database errors with a generic message and refuses
     tenant-scoped includes while no tenant is resolved (also fixes MCP).
1. **Core context.** `ToolContext` and the optional parameter on
   `ToolExecutor::call()`. The MCP suites pass unchanged.
2. **Tools.** `RecordTools`, `RecordToolSet`, `RecordTool` and
   `SchemaConverter`, with approvals.
3. **Docs.** Rewrite `module-ai-sdk.md`:
   - the API in §6;
   - synchronous vs queued use;
   - the approval requirements;
   - `ToolSearch` for large sets;
   - recipes: streaming a function response, queueing with
     `->queue()->then()`, files through `Files\Document::fromStorage()` after
     the package's visibility check, conversation history behind an ownership
     check, testing with `AgentFake`.

   Plus a changelog entry.

## 11. Verification

- **Uses the real tool core:** with `AgentFake`, an agent calls each tool.
  Assert that the database effect and the returned JSON match a direct
  `ToolExecutor::call()`.
- **Security (§8):**
  - a user without `view:invoice` → `list_invoices` returns the `-32002`
    error string, and the run continues;
  - another tenant's id → refused;
  - a `tenantId` argument from the model → refused;
  - a `viewOwn` user sees only their own rows;
  - hidden columns are absent from results and errors;
  - a nested child write without the child's permission → refused.
- **Context (§7.4):**
  - a queued-style call with `actingAs` / `forTenant` writes userstamps and
    tenant correctly;
  - after the call — including a throwing one — the guard user and request
    attribute equal their previous values;
  - with no context and no user → `Unauthenticated`.
- **Approvals (§7.5):**
  - writes pause on a conversational agent and resume on `Decision::approve()`;
  - `Decision::reject()` writes nothing;
  - `withoutApproval()` writes immediately;
  - reads never pause.
- **Schema fidelity:** for every catalog tool in the fixture, the converted
  `schema()` has the same property names and required list as the raw
  `inputSchema`.
- **Build-time errors:** an unknown table, an unknown action or an over-long
  name throws with a message naming it.
- **Gate:** `composer test` on PHP 8.3+, plus the 8.2 job with the AI tests
  skipped. `phpstan`, `rector --dry-run` and `php bin/validate-docs.php`.

## 12. Review focus

1. **No path around the executor.** Every `RecordTool` call must go through
   `ToolExecutor`; no shortcut into `RecordService`.
2. **Restore in `finally`.** `ToolContext` must restore state in `finally`.
   Worker processes reuse the request object, and a leaked tenant means
   cross-tenant data.
3. **Schema conversion never fails silently.** `McpTool`'s behaviour of
   returning `[]` on error is exactly what must not happen here.
4. **Queued agents carry no secrets.** A queued agent's serialised tools must
   not include the user model or any credentials.
5. **Approval arguments match what is written.** The arguments shown in an
   approval request must equal what will be written.

## 13. Risks

| Risk | Mitigation |
|---|---|
| `laravel/ai` 1.x changes the tool contract or the approval internals | Pin `^1.0.1`. The spike and these tests run in CI against the locked version |
| Approvals confuse developers on non-conversational agents | The docs lead with it. The error comes from `laravel/ai` with its clear message. `withoutApproval()` is one call away, and is a deliberate choice |
| Many tools waste context and tokens | `readOnly()` / `only()` keep sets small; `ToolSearch` is documented for large ones; the catalog's size work (MCP spec §6.6) shrinks every definition |
| A model loops on refusals | Refusals return a clear error string with the code, and `laravel/ai`'s `MaxSteps` bounds the loop. The docs recommend setting `#[MaxSteps]` |

## 14. Open questions

| # | Question | Proposed answer |
|---|---|---|
| Q1 | Should writes need approval by default? | Yes. An autonomous model writing business data should start gated; `withoutApproval()` is explicit |
| Q2 | Return `ToolError`s to the model, or throw? | Return them (§7.3), so the model can explain the refusal. Unexpected exceptions still throw |
| Q3 | Should `RecordTools` honour `record.mcp.read_only`? | No. It's an MCP transport setting, and here the developer chooses the actions in code. A separate global kill switch can come later if asked for |
| Q4 | Also ship an `RecordTools::agent()` ready-made agent? | No (YAGNI). Apps own their agents, instructions and providers |
