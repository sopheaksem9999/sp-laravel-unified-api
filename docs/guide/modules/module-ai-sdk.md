---
title: "AI SDK Integration"
description: "Give Laravel AI SDK agents in-process tools for your tables (RecordTools) that inherit tenant isolation, permissions, viewOwn and hidden-column stripping, with approval for writes and queued-agent support; plus recipes for calling agents from function endpoints."
keywords:
  - ai
  - ai sdk
  - laravel ai
  - laravel/ai
  - agents
  - tools
  - tool approval
  - tenant isolation
  - queue
  - functions
---

# Module: Laravel AI SDK (Agents) Integration

`sp-laravel-api` ships **AI SDK record tools**: tools for a [Laravel AI SDK](https://github.com/laravel/ai) agent that list, read, create, update and delete your tables — in the same process, as the current user, with the same rules the HTTP API and the [MCP servers](/guide/module-mcp) enforce.

```php
use Sopheak\Core\Ai\RecordTools;

public function tools(): iterable
{
    return [
        ...RecordTools::readOnly(['invoices', 'customers']),
        ...RecordTools::for('invoice_items')->only(['create', 'update']),
    ];
}
```

The package does **not** depend on `laravel/ai`: the tools load only when it is installed, and your app still owns providers, models, instructions and cost controls.

## Why not MCP over stdio?

`laravel/ai` can load an MCP server's tools, but for an agent inside the same app that starts a second PHP process, loses the current request's user and tenant (the stdio command needs `--tenant`, and has no user at all) and serialises every call through JSON-RPC. The record tools avoid all three: they call the shared tool core directly.

## Requirements

- `composer require laravel/ai` (needs **PHP 8.3+**; the package itself still supports PHP 8.2).
- The tables you expose must be registered as usual (`RecordTableType`).

## Try it in five minutes

The package's tests drive the real agent loop with a scripted gateway, so tool calling, approvals and queue serialisation are verified — but no test talks to a real provider. Try it once with yours:

```bash
composer require laravel/ai
# .env — any provider laravel/ai supports, for example:
OPENAI_API_KEY=sk-...
```

```php
// app/Ai/Agents/InvoiceAssistant.php
namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Sopheak\Core\Ai\RecordTools;

class InvoiceAssistant implements Agent, Conversational, HasTools
{
    use Promptable;

    public function __construct(private mixed $user = null, private mixed $tenantId = null) {}

    public function instructions(): string
    {
        return 'You help staff look things up. Use the tools; never guess.';
    }

    public function messages(): iterable
    {
        return [];
    }

    public function tools(): iterable
    {
        $tools = RecordTools::readOnly(['invoices'])->forTenant($this->tenantId);

        // Inside a request the current user applies; pass one only for console or queued use.
        return $this->user ? $tools->actingAs($this->user) : $tools;
    }
}
```

```php
// php artisan tinker — a console has no request, so the user and tenant are explicit
$user = App\Models\User::find(1);
echo (new App\Ai\Agents\InvoiceAssistant($user, 1))->prompt('Show my three latest invoices');
```

What to look for: the answer is built from your rows; a user without `view:` permission on `invoices` gets a polite refusal (the model is handed `{"error": {"code": -32002, …}}`); another tenant's rows never appear. Then swap `readOnly` for `for('invoices')` and ask it to create one — the call pauses for approval instead of writing. If a provider rejects a tool schema, tell us which one and the message: the wire shapes are checked against the OpenAI, Anthropic and Gemini mappers in `laravel/ai`, not against the live APIs.

## The API

```php
RecordTools::for('invoices');                     // list, read, create, update, delete
RecordTools::for(['invoices', 'customers']);      // several tables
RecordTools::readOnly(['invoices', 'customers']); // list + read only
RecordTools::schema();                            // the four sp_api_* discovery tools (no data)

RecordTools::for('invoices')
    ->only(['list', 'create'])                    // or ->except(['delete'])
    ->withoutApproval()                           // writes run without a human decision
    ->requireApproval('Finance must approve.')    // or a custom reason (writes only)
    ->actingAs($user)->forTenant($tenantId);      // queued/background agents
```

- A set is immutable (`RecordToolSet`): every method returns a new set. It is iterable, so `...RecordTools::for(...)` spreads into `tools()`.
- **Build-time errors** (`InvalidArgumentException`): an unknown table, an unknown action, `only()` naming an action a table does not allow (its `canRead` / `canCreate` / `canUpdate` / `canDelete` flag), and a tool name providers would reject (1–64 characters of letters, digits, `_`, `-`; the table name is named in the message).
- `for()` leaves out the actions a table does not allow; `record.mcp.read_only` is an MCP transport setting and does **not** apply here — you choose the actions in code.
- **You pick which tools exist; permissions still apply per call.** Giving an agent `delete_invoices` does not let a user without `delete:invoice` delete anything.
- Without `laravel/ai` installed, `RecordTools::…` throws a `RuntimeException` saying so.

## What the model sees

| | |
|---|---|
| **Names** | The catalog names: `list_invoices`, `create_invoices`, `sp_api_get_endpoint` — the same words MCP and these docs use |
| **Descriptions and schemas** | The catalog's, including the corrected nested-write hints. A schema that cannot be converted for the AI SDK — or that would silently lose a property or a `required` entry at any depth — throws when the tool's schema is read (the first prompt), naming the tool, instead of becoming a parameterless tool |
| **Free-form objects are JSON text** | `payload` and `queryParams` take any keys, which providers cannot express (their mappers send an object that allows *no* keys, and Gemini rejects it). So they are declared as **strings holding a JSON object** — `{"payload": "{\"ref_number\":\"INV-1\"}"}` — and the tool decodes them; an object sent anyway is accepted too. A value that is not a JSON object, a missing `id` on `read` / `update` / `delete`, or an `id` that is not a string or integer comes back as `{"error": {"message": …}}` for the model to fix |
| **A result** | Compact JSON of the same data an MCP tool returns (`{"response": {"data": …, "meta": …}}`) |
| **A refusal or failure** | `{"error": {"code": -32002, "message": "Forbidden"}}` (`-32001` unauthenticated, unknown table or tenant refused) or `{"error": {"message": "…"}}` for a failed record operation. The run continues, so the model can explain it |

A database error comes back as `{"error": {"message": "The database rejected the operation."}}` — never the SQL or its bound values, which could include a hidden or server-filled column — and the original is reported to your exception handler. A failure outside the record operation (a broken permission hook, say) is reported and rethrown as `The '<tool>' tool failed unexpectedly; see the application log.`: it fails the run, and `laravel/ai` — which turns a failure on the approval-resume path into a tool result the model reads — only ever sees that sentence. Put `#[MaxSteps]` on the agent so a model that keeps retrying a refused call is bounded, and consider `#[RepairToolCalls]`: when a model names a tool that is not in the set (say, a write you left out with `readOnly()`), `laravel/ai` answers with the available tool names so it can correct itself.

## Who the call runs as

- **Synchronous agents** (prompted inside a request) need nothing: the call runs as the request's user and tenant, exactly like MCP.
- **Queued and background agents** (`->queue()`, jobs, commands) have no request and no user. Capture them when you build the tools:

```php
$tools = RecordTools::for('invoices')->actingAs($user)->forTenant($tenantId);

(new SupportAgent($tools))->queue('Close out the stale invoices')->then(fn ($response) => …);
```

For each tool call the package installs that user (on the package's guard and the default guard) and tenant, runs the call, and **always restores** the previous state — also when the call throws — because workers reuse the same request object across jobs.

- A queued agent is serialised with its tools. The set stores the **user's key**, never the user: a model held inside a tool would otherwise be written out whole, password hash included. The user is loaded again, lazily, through the guard's user provider when the job runs, and only if it is the same class that was captured (User #3 and Admin #3 are different people). If it cannot be loaded — deleted, another provider, a guard with no user provider — the call **does not run**: it returns the `-32001` `Unauthenticated` error. It never falls back to whoever the process happens to hold. The user is looked up through `sp-laravel-api.auth.guard` (default `api`), so that guard must have a user provider for queued agents.
- **No context and no user** on a non-public table → `Unauthenticated` (`-32001`) as an error string. It never runs as anonymous.
- **Tool arguments never choose the user or the tenant.** A `tenantId` argument that disagrees with the context is refused, and on a tenancy-enabled app a call that **includes** rows of a tenant-scoped relation (`select=*,pets(*)`, `with`, `pets.name=…`) is refused while no tenant is resolved — build the set with `forTenant()` (or run inside a request that carries the tenant) for agents that need those.
- **Row-level-security databases:** the context sets the request's tenant, not PostgreSQL's `app.tenant_id`. With the package's `OR … IS NULL` policy an unset `app.tenant_id` means RLS does not filter, so a queued agent is scoped by the application-level tenant filter only. If you rely on RLS too, set `app.tenant_id` per job in your own job bootstrapping.
- `actingAs()` needs a user with an auth identifier; an unsaved model is rejected.

## Approvals

Writes (`create`, `update`, `delete`) **wait for a person by default**; reads never do. The approval request carries the reason `<action> on <table> changes data` (or your `requireApproval('…')` reason) and the exact tool-call arguments, so the reviewer approves the payload that will really be written. If the reviewer edits the arguments (`Decision::edit([...])`), the edited arguments are what is written. A nested write inside an approved `update_invoices` is covered by that approval and is still authorised per child table.

Approvals can only resume on a **conversational** agent (one with `Conversational` / stored history). On any other agent, `laravel/ai` throws its own `ApprovalNotResumableException` when a gated write is requested. Choose:

- make the agent `Conversational` (recommended for anything with a person in the loop), or
- `->withoutApproval()` for trusted automation — a deliberate choice, not a default.

```php
$paused = $agent->prompt('Create invoice INV-1 for customer 7');   // pending approval, nothing written
$agent->prompt(Decision::approveAll());                            // now it writes
```

With stored conversations (`RemembersConversations`), resume by id, optionally per call:

```php
$response = (new InvoiceAssistant($user, $tenantId))
    ->continue($conversationId, as: $user)
    ->prompt(Decisions::from([
        $pending->id => Decision::approve(),
        // 'call_xyz' => Decision::reject('Not this one.'),
        // 'call_abc' => Decision::edit(['payload' => [...]]),   // the edited arguments are what is written
    ]));
```

Construct the agent with the **same** `$user` / `$tenantId` on resume: the tool set is rebuilt each time `tools()` runs, and the context decides who the approved write runs as.

## Limits to know

- **Strict mode:** `#[Strict]` is read from a tool's own class, which here is the package's `RecordTool`, so it cannot be applied. Strict schemas also need every parameter required and no free-form objects, which the record tools' optional `queryParams` and JSON-text parameters cannot satisfy. Use the default (non-strict) function calling.
- **Provider tools and sub-agents** (`WebSearch`, `FileSearch`, `CodeExecution`, `CanActAsTool`) are unrelated to the record tools and combine with them freely in `tools()`.
- **Per-request approval rules** (the SDK lets a tool's `needsApproval()` decide per call) are binary per set here: split the actions into two sets — `RecordTools::for('x')->only(['create'])->withoutApproval()` and `...->only(['delete'])` — and spread both.

## Large tool sets

Five tools per table add up. Wrap a large set in `laravel/ai`'s `ToolSearch` so the provider loads tool definitions on demand — `new ToolSearch(tools: RecordTools::for($many)->toArray())`. The package does not do this for you: `ToolSearch` is a provider tool, and `laravel/ai` supports it on **OpenAI and Anthropic** only (Anthropic also needs at least one tool outside the `ToolSearch`, and offers `'regex'` or `'bm25'` strategies). Narrowing with `readOnly()` / `only()` keeps sets small too.

## What an agent inherits

These hold because every call goes through the shared `ToolExecutor` — there is no direct `RecordService` call in the AI layer — and each is pinned by a test:

1. Tenant resolution, the permission decision (Gate, `super_admin_callback`, custom handler), `viewOwn:*` scoping, hidden-column stripping and nested-write authorization are the HTTP and MCP ones.
2. Tenant and user come only from the request or the tool set's context.
3. The context never leaks: after a call the guard users and the request's tenant attribute are what they were before.
4. Approval is not bypassable through nesting.
5. Hidden columns never reach the model: not in results, and not in error text (database errors are replaced by a generic message).
6. Record hooks, table/default validators, after-hooks (and their webhooks) and `RecordMutated` broadcasts, exactly as over MCP — see [MCP Support › Security](/guide/module-mcp#data-mcp). `record.mcp.run_record_hooks` switches them for both. Your own `RecordService::execute*` calls still skip them (see [Internal API Methods](/guide/api-internal-methods-core)).

## Recipes

### Call an agent from a function endpoint

`RecordFunctionType` takes `httpMethod`, `class`, `functionName`, and optional `description`, `querySchema`, `payloadSchema`, `pmsName`, `isPublic`:

```php
// config/records/tables/customers.php
'aiSummary' => new RecordFunctionType(
    httpMethod: [RecordFunctionMethodEnum::POST->value],
    class: \App\Services\Ai\CustomerSummaryService::class,
    functionName: 'aiSummary',
    description: 'Generate an AI summary for a single customer.',
    payloadSchema: [
        'type' => 'object',
        'properties' => ['customer_id' => ['type' => 'integer']],
        'required' => ['customer_id'],
    ],
),
```

Reached at `POST /api/v1/customers/rpc/aiSummary` (the `rpc` segment is `record.rpc_prefix`). The package creates function classes with `new` — no constructor injection — so resolve dependencies in the method:

```php
class CustomerSummaryService
{
    public function aiSummary(Request $request): array
    {
        $agent = app(\App\Agents\CustomerSummaryAgent::class);

        return ['summary' => (string) $agent->prompt('Summarise customer ' . $request->integer('customer_id'))];
    }
}
```

Validate the payload, keep `hasTenantId` on the table, and add per-function middleware for rate limits. Function responses follow the normal caching rules: invalidate when the underlying data changes.

### Long-running work: queue it

Return a job id from a function and let the agent run on the queue with the tool set's `actingAs()` / `forTenant()`; store results in a table (for example `ai_results`) and expose them through normal CRUD reads: `GET /api/v1/ai_results?job_id=eq.{id}`. Use `->queue($prompt)->then(fn ($response) => …)` to write the result back.

### Files

Give a model a file only after the package's own visibility check (read the attachment through its endpoint or `RecordService` as the user), then pass it with `Laravel\Ai\Files\Document::fromStorage($path, $disk)`. Never hand a model a path the user could not fetch.

### Conversation history

Store history in your own table and put an **ownership check** in front of it (`VerifiesConversationOwnership::conversationBelongsTo()`), so a conversation id from one user's request cannot load another user's conversation.

### Testing

`InvoiceAssistant::fake([...])` (the `Promptable` helper, same as `Ai::fakeAgent()`) scripts responses and records prompts (`assertPrompted()`), but a faked agent does **not** execute a resumed approval. To test the real approve/reject path, keep the agent real and script the provider's gateway:

```php
Ai::textProvider()->useTextGateway(new FakeTextGateway([
    new ToolCall('call_1', 'create_invoices', ['payload' => ['ref_number' => 'A-1', 'customer_id' => 1]]),
    'done',
]));
```

Make the test agent return the paused response's `messages` as its history before sending `Decision::approveAll()`. In a package test, register `Laravel\Ai\AiServiceProvider` explicitly.

## Compatibility

| Who | Effect |
|---|---|
| Apps without `laravel/ai` | None — nothing loads |
| Apps on PHP 8.2 | Cannot install `laravel/ai`, so the feature is unavailable. Contributors on 8.2 run `composer remove --dev laravel/ai`; the AI tests skip themselves when it is missing (static analysis of `src/Ai` reports the missing `Laravel\Ai` classes until it is installed) |
| Apps with `laravel/ai` | New, opt-in API; no existing behaviour changes |
| The MCP drivers | Unchanged: `ToolExecutor::call()` gained an optional context argument that they leave `null` |
