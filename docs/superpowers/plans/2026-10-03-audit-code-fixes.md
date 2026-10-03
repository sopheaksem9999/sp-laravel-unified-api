---
title: "Audit Code Fixes Plan"
description: "Implementation plan for three code fixes found in the docs audit: viewOwn reads the configured guard, HTTP refuses tenant-scoped includes without a tenant, and MCP / AI SDK tool calls run record hooks and validators."
keywords:
  - viewOwn
  - auth guard
  - tenancy
  - tenant-scoped includes
  - record hooks
  - validators
  - mcp
  - ai sdk
  - implementation plan
---

# Audit Code Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the three code defects the 2026-10-03 docs audit found: viewOwn reading the wrong guard, HTTP reads embedding tenant-scoped rows without a tenant, and MCP / AI SDK tool calls skipping record hooks and validators.

**Architecture:** (1) `OwnRecordsScope` reads the user from `sp-laravel-api.auth.guard` — the guard every permission check reads — and falls back to the default guard, so it can only narrow. (2) The tenant-scoped-include detection moves out of `ToolExecutor` into a shared `TenantScopedIncludes` utility; the HTTP controllers' `resolveTenantContext()` uses it to answer `422` when tenancy is on, no tenant resolved and the query embeds a tenant-scoped relation. (3) A new `Mcp\RecordHookPipeline` wraps the existing `RecordService::execute*` data path with the HTTP pipeline's hooks and validators (shared `TableValidatorRunner`, new `RecordService::processAfterWriteHooks()`), behind `record.mcp.run_record_hooks` (default `true`).

**Tech Stack:** PHP 8.2+, Laravel 12/13, Orchestra Testbench, PHPUnit.

**Spec:** none — the findings are recorded in the docs audit of 2026-10-03 (summarised in this plan's Goal and in each task). Rulings made without a spec are provisional.

## Global Constraints

- Maintain backward compatibility: public signatures only gain optional trailing parameters; `RecordService::execute*` keep their behaviour for app callers (no hooks run there).
- Every Bash command starts with `cd "/Users/sopheak/Documents/Sopheak-dev/QBO Finance/Package/sp-laravel-api" &&`.
- Never commit; the user commits. Never `git add -A`.
- Do not use `call`, `put` or `run` as test helper names (PHPUnit `TestCase` clashes).
- Gate after each task: `vendor/bin/phpunit` (full), `vendor/bin/phpstan analyse --memory-limit=2G` and `vendor/bin/rector process --dry-run` on touched files.
- Update `docs/guide/*` and both changelogs (`CHANGELOG.md`, `docs/changelog.md`) for behaviour changes; `php bin/validate-docs.php` must pass.

## Review Focus

1. A configured guard that the app never defined (`auth('api')` throws) — viewOwn must fall back to the default guard, not crash (Task 1 test `test_an_undefined_configured_guard_falls_back_to_the_default_guard`).
2. A tenant header sent to a non-tenant parent — the include must be allowed and scoped to that tenant, not refused (Task 2 test `test_with_a_tenant_header_includes_are_scoped_to_it`).
3. A `beforeRead` hook that adds a tenant-scoped include after the executor's own refusal ran — the tool must still refuse (Task 3 test `test_an_include_a_before_read_hook_adds_is_refused_without_a_tenant`).
4. `queryParams` sent as a query string rather than an object — the pipeline must parse it like `execute*` did (Task 3 test `test_query_params_as_a_string_still_work`).
5. A hook that aborts with an `HttpResponseException` — the tool returns an `isError` result carrying the response's message, and nothing is written (Task 3 test `test_a_hook_that_aborts_refuses_the_call`).

---

### Task 1: viewOwn reads the configured guard

**Files:**
- Modify: `src/Utilities/OwnRecordsScope.php` (`ownerColumn()` L44-82, `apply()` L89-97, `sqlCondition()` L105-113, `cacheToken()` L124-138, `assertNoForeignMatches()` L191-199)
- Test: `tests/Feature/OwnRecordsGuardTest.php` (create)
- Docs: `docs/guide/features/feature-permission-own-records.md` (Requirements and Caveats)

**Interfaces:**
- Consumes: `RecordConfigService::authGuard(): string`.
- Produces: no public API change. Private `OwnRecordsScope::currentUser(): ?Authenticatable`.

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Jobs\ProcessBulkOperationJob;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\OwnRecordsScope;
use Sopheak\Core\Utilities\QueryBuilderFiltersUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Throwable;

/**
 * Permission checks read the user from `sp-laravel-api.auth.guard`; viewOwn
 * read the default guard. When the two differ — a route without auth
 * middleware, or the queued bulk job, which restores the user on the
 * configured guard only — a user passed the permission check but was not
 * restricted to their own rows.
 *
 * @internal
 */
class OwnRecordsGuardTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = 42;

    private const OTHER = 99;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        });
        DB::table('users')->insert([['id' => self::OWNER, 'name' => 'owner'], ['id' => self::OTHER, 'name' => 'other']]);

        Schema::create('widgets', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->unsignedBigInteger('created_by_id')->nullable();
            $t->timestamps();
        });
        DB::table('widgets')->insert([
            ['id' => 1, 'name' => 'MINE', 'created_by_id' => self::OWNER],
            ['id' => 2, 'name' => 'THEIRS', 'created_by_id' => self::OTHER],
        ]);

        Config::set('auth.defaults.guard', 'web');
        Config::set('auth.guards.web', ['driver' => 'session', 'provider' => 'users']);
        Config::set('record.tables', [
            'widgets' => new RecordTableType(
                table: 'widgets',
                pmsName: 'widget',
                columns: [
                    'id' => ['type' => 'integer', 'nullable' => false],
                    'name' => ['type' => 'string', 'nullable' => false],
                    'created_by_id' => ['type' => 'bigInteger', 'nullable' => true],
                    'created_at' => ['type' => 'datetime', 'nullable' => true],
                    'updated_at' => ['type' => 'datetime', 'nullable' => true],
                ],
            ),
        ]);
        SchemaRegistryUtils::refresh();
        QueryBuilderFiltersUtils::clearColumnCache();

        // Everyone may do everything; only OWNER is restricted to own rows.
        Gate::before(fn ($user, string $ability): bool => !str_starts_with($ability, 'viewOwn:') || self::OWNER === (int) $user->id);
    }

    private function user(int $id): GenericUser
    {
        return new GenericUser(['id' => $id, 'name' => 'user ' . $id]);
    }

    /** @return list<string> */
    private function listedNames(): array
    {
        return collect(RecordService::executeGetByFilter('widgets')['data'])->pluck('name')->sort()->values()->all();
    }

    public function test_a_user_on_the_configured_guard_only_is_restricted(): void
    {
        auth('api')->setUser($this->user(self::OWNER));

        $this->assertFalse(auth('web')->check(), 'precondition: the default guard has no user');
        $this->assertSame('created_by_id', OwnRecordsScope::ownerColumn('widgets'));
        $this->assertSame(['MINE'], $this->listedNames());
    }

    public function test_the_configured_guard_user_is_the_one_restricted_when_the_guards_disagree(): void
    {
        auth('api')->setUser($this->user(self::OWNER));
        auth('web')->setUser($this->user(self::OTHER));

        $this->assertSame(['MINE'], $this->listedNames());
    }

    public function test_a_user_on_the_default_guard_only_stays_restricted(): void
    {
        auth('web')->setUser($this->user(self::OWNER));

        $this->assertSame('created_by_id', OwnRecordsScope::ownerColumn('widgets'));
    }

    public function test_an_undefined_configured_guard_falls_back_to_the_default_guard(): void
    {
        Config::set('sp-laravel-api.auth.guard', 'no-such-guard');
        auth('web')->setUser($this->user(self::OWNER));

        $this->assertSame('created_by_id', OwnRecordsScope::ownerColumn('widgets'));
    }

    public function test_the_async_bulk_job_restricts_the_restored_user_to_their_own_rows(): void
    {
        $job = new ProcessBulkOperationJob(
            'update',
            'widgets',
            [['id' => 2, 'name' => 'HACKED']],
            null,
            ['guard' => 'api', 'headers' => [], 'server' => [], 'user_id' => self::OWNER],
        );

        try {
            app()->call($job->handle(...));
        } catch (Throwable) {
            // Refused: the row is outside the restored user's own records.
        }

        $this->assertSame('THEIRS', DB::table('widgets')->where('id', 2)->value('name'));
    }
}
```

- [x] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter OwnRecordsGuardTest`
Expected: FAIL — `test_a_user_on_the_configured_guard_only_is_restricted`, `..._when_the_guards_disagree` and `..._async_bulk_job_...` fail (null owner column / both rows / `HACKED`); the two fallback tests pass (they pin today's narrowing).

- [x] **Step 3: Write minimal implementation**

In `src/Utilities/OwnRecordsScope.php` add `use Illuminate\Contracts\Auth\Authenticatable;` and `use InvalidArgumentException;`, then add:

```php
    /**
     * The user the package authorizes as: the configured guard's user, the one
     * PermissionUtils::actionDecision() reads. Falling back to the default guard
     * keeps every user who was restricted before restricted — viewOwn only
     * narrows, so reading one more guard can never widen access.
     */
    private static function currentUser(): ?Authenticatable
    {
        try {
            $user = auth(RecordConfigService::authGuard())->user();
        } catch (InvalidArgumentException) {
            // The configured guard is not defined in this app.
            $user = null;
        }

        return $user ?? Auth::user();
    }
```

Replace the default-guard reads:

```php
        // ownerColumn()
        if (!$pmsName || '' === RecordConfigService::ownRecordsPermissionPrefix()) {
            return null;
        }

        $user = self::currentUser();
        if (!$user instanceof Authenticatable) {
            return null;
        }
```

and in `apply()`, `sqlCondition()`, `cacheToken()` and `assertNoForeignMatches()` replace `Auth::user()->id` with `self::currentUser()?->id` (each runs only after `ownerColumn()` returned a column, so a user is present).

- [x] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter 'OwnRecords'`
Expected: PASS (all OwnRecords suites).

- [x] **Step 5: Docs**

In `docs/guide/features/feature-permission-own-records.md`, replace the bullet "The check runs only when a user is authenticated (`Auth::check()`); guests fall through to normal table auth." with:

```markdown
- The check runs only when a user is authenticated; guests fall through to
  normal table auth. The user is read from `sp-laravel-api.auth.guard` — the
  guard every permission check reads — and from the default guard when that one
  has none, so a route without auth middleware and the queued bulk job are
  restricted exactly like a normal request.
```

Add to `### Security` in both changelogs: "**viewOwn read the default guard**: permission checks read `sp-laravel-api.auth.guard`, but own-records scoping read the default guard, so a user on a route without auth middleware (or in the queued bulk job, which restores the user on the configured guard) passed the permission check without being restricted to their own rows. It now reads the configured guard, falling back to the default one."

- [x] **Step 6: Commit** — skipped (the user commits); ledger it.

---

### Task 2: HTTP refuses tenant-scoped includes without a tenant

**Files:**
- Create: `src/Utilities/TenantScopedIncludes.php`
- Modify: `src/Mcp/ToolExecutor.php` (`refuseTenantScopedIncludesWithoutATenant()` L308-332; delete `requestedRelationships()` L341-369)
- Modify: `src/Http/Controllers/Concerns/HasControllerHelpers.php` (`resolveTenantContext()` L75-88; new `refuseTenantScopedIncludes()`)
- Modify: `src/Http/Controllers/Concerns/HasFunctionOperations.php` (L27, L63: pass `checkIncludes: false`)
- Test: `tests/Feature/TenantScopedIncludesHttpTest.php` (create); `tests/Feature/McpTenantIncludesTest.php` must stay green
- Docs: `docs/guide/records/record-tenancy.md`

**Interfaces:**
- Produces: `TenantScopedIncludes::requested(string $table, array $queryParams): list<string>` — the tenant-scoped relationship aliases of `$table` the query asks for.
- Consumes (Task 3 uses it): same.

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * Over HTTP a table that is not tenant-scoped (`owners`) embedded rows of a
 * tenant-scoped one (`pets`) for every tenant when no tenant resolved — the
 * hole the MCP tools already refuse. A tenant-scoped table itself answers 422
 * without a tenant; so does an include of one.
 *
 * @internal
 */
class TenantScopedIncludesHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('owners', function (Blueprint $t): void {
            $t->id();
            $t->string('name')->nullable();
            $t->timestamps();
        });
        Schema::create('pets', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('owner_id');
            $t->string('tenant_id')->nullable();
            $t->string('name')->nullable();
            $t->timestamps();
        });
        DB::table('owners')->insert(['id' => 1, 'name' => 'Pat']);
        DB::table('pets')->insert([
            ['id' => 1, 'owner_id' => 1, 'tenant_id' => 't1', 'name' => 'MINE'],
            ['id' => 2, 'owner_id' => 1, 'tenant_id' => 't2', 'name' => 'THEIRS'],
        ]);

        $public = new RecordTablePublic(read: true, write: true);
        Config::set('record.enable_tenant_id', true);
        Config::set('record.tables', [
            'owners' => new RecordTableType(
                table: 'owners',
                pmsName: 'owners',
                public: $public,
                relationships: ['pets' => new RecordHasManyType(table: 'pets', foreignKey: 'owner_id')],
            ),
            'pets' => new RecordTableType(table: 'pets', pmsName: 'pets', hasTenantId: true, public: $public),
        ]);
        SchemaRegistryUtils::refresh();
    }

    /** @return array<string, array{0: string}> */
    public static function includeQueries(): array
    {
        return [
            'select with an include' => ['/api/owners?select=*,pets(*)'],
            'with' => ['/api/owners?with=pets'],
            'a relationship filter' => ['/api/owners?pets.name=eq.THEIRS'],
            'a tenant_id the client picked' => ['/api/owners?select=*,pets(*)&tenant_id=t2'],
            'a single record' => ['/api/owners/1?select=*,pets(*)'],
        ];
    }

    #[DataProvider('includeQueries')]
    public function test_without_a_tenant_an_include_of_tenant_scoped_rows_is_refused(string $uri): void
    {
        $response = $this->getJson($uri);

        $response->assertStatus(422);
        $this->assertStringContainsString('pets', (string) $response->getContent());
        $this->assertStringNotContainsString('THEIRS', (string) $response->getContent());
    }

    public function test_without_a_tenant_the_table_itself_still_reads(): void
    {
        $this->getJson('/api/owners')->assertOk()->assertJsonPath('data.0.name', 'Pat');
    }

    public function test_with_a_tenant_header_includes_are_scoped_to_it(): void
    {
        $response = $this->getJson('/api/owners?select=*,pets(*)', ['X-Tenant-ID' => 't1'])->assertOk();

        $this->assertSame(['MINE'], array_column($response->json('data.0.pets'), 'name'));
    }

    public function test_with_tenancy_off_nothing_changes(): void
    {
        Config::set('record.enable_tenant_id', false);
        SchemaRegistryUtils::refresh();

        $response = $this->getJson('/api/owners?select=*,pets(*)')->assertOk();

        $this->assertCount(2, $response->json('data.0.pets'));
    }
}
```

- [x] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter TenantScopedIncludesHttpTest`
Expected: FAIL — the five data-provider cases answer `200` and include `THEIRS`; the other three pass.

- [x] **Step 3: Write minimal implementation**

`src/Utilities/TenantScopedIncludes.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Utilities;

use Sopheak\Core\Types\RecordTableType;

/**
 * Which tenant-scoped relationships a read asks to include.
 *
 * A table that is not tenant-scoped can still embed rows of one that is
 * (`owners` -> `pets`). With tenancy on and no tenant resolved those rows would
 * come back for every tenant, so the HTTP controllers and the MCP / AI SDK
 * tools refuse such a request, as they refuse a tenant-scoped table itself.
 */
final class TenantScopedIncludes
{
    /**
     * The tenant-scoped relationship aliases of $table that $queryParams asks
     * for: `select=*,rel(*)`, `with=rel`, a relationship filter (`rel.column=…`)
     * or a grouped one (`or=(rel.column.…)`).
     *
     * @param array<string, mixed> $queryParams
     * @return list<string>
     */
    public static function requested(string $table, array $queryParams): array
    {
        $tableSchema = SchemaRegistryUtils::getTable($table);
        if (!$tableSchema instanceof RecordTableType) {
            return [];
        }

        $scoped = [];
        foreach (array_keys((array) $tableSchema->relationships) as $alias) {
            $resolved = RelationshipResolverUtils::resolveRelationship($table, (string) $alias);
            $related = is_array($resolved) ? SchemaRegistryUtils::getTable((string) ($resolved['table'] ?? '')) : null;
            if ($related instanceof RecordTableType && RecordUtils::shouldApplyTenantId($related)) {
                $scoped[] = (string) $alias;
            }
        }

        return array_values(array_intersect($scoped, self::relationshipNames($queryParams)));
    }

    /**
     * @param array<string, mixed> $queryParams
     * @return list<string>
     */
    private static function relationshipNames(array $queryParams): array
    {
        // Body moved verbatim from ToolExecutor::requestedRelationships().
    }
}
```

(Move the body of `ToolExecutor::requestedRelationships()` — L343-368 — into `relationshipNames()` unchanged.)

In `ToolExecutor::refuseTenantScopedIncludesWithoutATenant()` replace the `$scoped` loop and the `array_intersect` with:

```php
        $used = TenantScopedIncludes::requested($table, $queryParams);
```

and delete `requestedRelationships()`.

In `HasControllerHelpers`:

```php
    private function resolveTenantContext(Request $request, object $tableSchema, bool $checkIncludes = true): array
    {
        $tenantId = $this->recordService->resolveTenantFromRequest($request, $tableSchema);
        $this->recordService->attachRequestContext(
            request: $request,
            table: (string) ($request->route('table') ?? ''),
            action: $this->resolveRouteAction($request),
            tableSchema: $tableSchema,
            tenantId: $tenantId
        );
        $error = $this->validateTenantIdRequired($tableSchema, $tenantId)
            ?? ($checkIncludes ? $this->refuseTenantScopedIncludes($request) : null);

        return [$tenantId, $error];
    }

    /**
     * Without a tenant, rows of a tenant-scoped relationship would be embedded
     * for every tenant (see TenantScopedIncludes). The controller's own tenant
     * is null for a table that is not tenant-scoped, so the request's tenant —
     * attribute, record context, then header — decides.
     */
    private function refuseTenantScopedIncludes(Request $request): ?JsonResponse
    {
        $table = (string) ($request->route('table') ?? '');
        if ('' === $table || !RecordConfigService::enableTenantId() || !RecordUtils::isTenantIdMissing(RecordUtils::resolveTenantIdFromRequest($request))) {
            return null;
        }

        $aliases = TenantScopedIncludes::requested($table, $request->query());
        if ([] === $aliases) {
            return null;
        }

        $header = RecordConfigService::tenantHeader();

        return RecordApiResponseService::errorWrapped('Validation failed', RecordApiJsonResponseEnum::VALIDATION_ERROR->value, [
            $header => [sprintf('header %s cannot be empty: it is required to include %s', $header, implode(', ', $aliases))],
        ]);
    }
```

In `HasFunctionOperations` L27 and L63 pass `checkIncludes: false` (a function call embeds no relationships).

- [x] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter 'TenantScopedIncludesHttpTest|McpTenantIncludesTest|RelationshipTenantBindingTest|Tenant'`
Expected: PASS.

- [x] **Step 5: Docs**

In `docs/guide/records/record-tenancy.md` under "Validation Behavior", after the `header X-Tenant-ID cannot be empty` bullet add:

```markdown
A table that is not tenant-scoped answers `422` too when the request embeds or
filters on a tenant-scoped relationship (`?select=*,pets(*)`, `?with=pets`,
`?pets.name=eq.x`) and no tenant resolved — otherwise those rows would come back
for every tenant:

- `header X-Tenant-ID cannot be empty: it is required to include pets`
```

Add to `### Security` in both changelogs: "**Tenant-scoped includes over HTTP**: with tenancy on and no tenant resolved, a read of a table that is not tenant-scoped embedded the rows of a tenant-scoped relationship for every tenant. It now answers `422` (`header X-Tenant-ID cannot be empty: it is required to include …`), as the MCP and AI SDK tools already refused. Send the tenant header (or resolve `resolved_tenant_id`) on such requests."

- [x] **Step 6: Commit** — skipped (the user commits); ledger it.

---

### Task 3: MCP and AI SDK tool calls run record hooks and validators

**Files:**
- Create: `src/Utilities/TableValidatorRunner.php`
- Create: `src/Mcp/RecordHookPipeline.php`
- Modify: `src/Http/Controllers/Concerns/HasControllerHelpers.php` (`runTableValidators()` L220-242 delegates; move L244-409 helpers out)
- Modify: `src/Services/RecordService.php` (`runPostWriteLogic()` L469-503 extracts `runAfterWriteTriggers()`; new public `processAfterWriteHooks()`; `executeCreate/Update/Delete` gain `?array &$outcome = null`)
- Modify: `src/Mcp/ToolExecutor.php` (`dispatch()` L133-162)
- Modify: `config/sp-record.php` (`mcp` section: `run_record_hooks`); `src/Console/SetupPackageCommand.php` if it writes the `mcp` section
- Test: `tests/Feature/McpRecordHooksTest.php` (create), `tests/Feature/Ai/RecordToolHooksTest.php` (create)

**Interfaces:**
- Consumes: `TenantScopedIncludes::requested()` (Task 2).
- Produces:
  - `TableValidatorRunner::firstFailure(mixed $validatorConfig, Request $request, ?string $id): ?\Illuminate\Contracts\Validation\Validator`
  - `RecordService::processAfterWriteHooks(Request $request, string $table, string $operation, array $recordContext): void`
  - `RecordService::executeCreate(string $table, array $payload, array|string $queryParams = [], mixed $tenantId = null, ?array &$outcome = null): array` (same for `executeUpdate`, `executeDelete`; `$outcome` receives the `createRecord` / `updateRecord` / `deleteRecord` result)
  - `RecordHookPipeline::list|read|create|update|delete(...)`: `array` (the `execute*` result)
  - Config `record.mcp.run_record_hooks` (env `SP_MCP_RUN_RECORD_HOOKS`, default `true`)

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Sopheak\Core\Events\RecordMutated;
use Sopheak\Core\Mcp\ToolError;
use Sopheak\Core\Mcp\ToolExecutor;
use Sopheak\Core\Mcp\ToolResult;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableTriggerType;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class McpHookSpy
{
    /** @var list<string> */
    public static array $calls = [];

    /** @var array<string, array<string, mixed>> */
    public static array $contexts = [];

    public static int $audits = 0;

    public static bool $abort = false;

    public static function reset(): void
    {
        self::$calls = [];
        self::$contexts = [];
        self::$audits = 0;
        self::$abort = false;
    }

    /** @param array<string, mixed> $context */
    private static function seen(string $hook, array $context): void
    {
        self::$calls[] = $hook;
        self::$contexts[$hook] = $context;
    }

    public static function globalBeforeCreate(Request $request, string $table, array $context): void
    {
        self::seen('global.beforeCreate', $context);
    }

    public static function beforeCreate(Request $request, string $table, array $context): Request
    {
        self::seen('beforeCreate', $context);
        if (self::$abort) {
            throw new HttpResponseException(response()->json(['success' => false, 'message' => 'Notes are closed today'], 403));
        }

        $request->merge(['title' => strtoupper((string) $request->input('title'))]);

        return $request;
    }

    public static function afterCreate(Request $request, string $table, array $context): void
    {
        self::seen('afterCreate', $context);
    }

    public static function globalAfterCreate(Request $request, string $table, array $context): void
    {
        self::seen('global.afterCreate', $context);
    }

    public static function beforeUpdate(Request $request, string $table, array $context): void
    {
        self::seen('beforeUpdate', $context);
    }

    public static function afterUpdate(Request $request, string $table, array $context): void
    {
        self::seen('afterUpdate', $context);
    }

    public static function beforeDelete(Request $request, string $table, array $context): void
    {
        self::seen('beforeDelete', $context);
    }

    public static function afterDelete(Request $request, string $table, array $context): void
    {
        self::seen('afterDelete', $context);
    }

    /** Team visibility, the way apps scope lists in a beforeRead hook. */
    public static function beforeRead(Request $request, string $table, array $context): Request
    {
        self::seen('beforeRead', $context);
        $request->merge(['team' => 'eq.red']);

        return $request;
    }

    public static function beforeReadAddingPets(Request $request, string $table, array $context): Request
    {
        $request->merge(['select' => '*,pets(*)']);

        return $request;
    }

    public static function afterRead(Request $request, string $table, array $context): void
    {
        self::seen('afterRead', $context);
    }

    public static function requireBody(Request $request): ValidatorContract
    {
        return Validator::make($request->all(), ['body' => 'required']);
    }

    public static function countAudit(mixed ...$args): bool
    {
        ++self::$audits;

        return false;
    }
}

/**
 * MCP data tools (and the AI SDK record tools, which share the executor)
 * called RecordService::execute* directly: no record hooks, no validators, no
 * after-hooks or webhooks, no RecordMutated broadcast. A beforeRead hook that
 * scopes a list to a team did not apply to an agent.
 *
 * @internal
 */
class McpRecordHooksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        McpHookSpy::reset();

        Schema::create('notes', function (Blueprint $t): void {
            $t->id();
            $t->string('title');
            $t->string('body')->nullable();
            $t->string('team')->nullable();
            $t->timestamps();
        });
        Schema::create('tags', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
        DB::table('notes')->insert([
            ['id' => 1, 'title' => 'RED NOTE', 'team' => 'red'],
            ['id' => 2, 'title' => 'BLUE NOTE', 'team' => 'blue'],
        ]);

        Config::set('record.mcp.read_only', false);
        Config::set('record.global_triggers', [
            'beforeCreate' => new RecordTableTriggerType(McpHookSpy::class, 'globalBeforeCreate'),
            'afterCreate' => new RecordTableTriggerType(McpHookSpy::class, 'globalAfterCreate'),
        ]);
        $this->registerTables();
    }

    private function registerTables(?RecordTableTriggerType $beforeRead = null): void
    {
        $public = new RecordTablePublic(read: true, write: true);
        $hook = static fn (string $method): RecordTableTriggerType => new RecordTableTriggerType(McpHookSpy::class, $method);

        Config::set('record.tables', [
            'notes' => new RecordTableType(
                table: 'notes',
                public: $public,
                beforeCreate: $hook('beforeCreate'),
                afterCreate: $hook('afterCreate'),
                beforeUpdate: $hook('beforeUpdate'),
                afterUpdate: $hook('afterUpdate'),
                beforeDelete: $hook('beforeDelete'),
                afterDelete: $hook('afterDelete'),
                beforeRead: $beforeRead ?? $hook('beforeRead'),
                afterRead: $hook('afterRead'),
                createValidator: [McpHookSpy::class, 'requireBody'],
            ),
            'tags' => new RecordTableType(
                table: 'tags',
                public: $public,
                columns: [
                    'id' => ['type' => 'integer', 'nullable' => false, 'key' => 'pri'],
                    'name' => ['type' => 'string', 'nullable' => false],
                ],
            ),
        ]);
        SchemaRegistryUtils::refresh();
    }

    /** @param array<string, mixed> $args */
    private function tool(string $name, array $args = []): ToolResult
    {
        return (new ToolExecutor())->call($name, $args);
    }

    public function test_create_runs_before_hooks_validators_and_after_hooks_in_http_order(): void
    {
        $result = $this->tool('create_notes', ['payload' => ['title' => 'hello', 'body' => 'b']]);

        $this->assertFalse($result->isError, (string) $result->errorMessage);
        $this->assertSame('HELLO', DB::table('notes')->where('body', 'b')->value('title'), 'a before hook can change the payload');
        $this->assertSame(['global.beforeCreate', 'beforeCreate', 'afterCreate', 'global.afterCreate'], McpHookSpy::$calls);
        $this->assertNotNull(McpHookSpy::$contexts['afterCreate']['id'] ?? null);
    }

    public function test_a_failing_table_validator_refuses_the_write(): void
    {
        $result = $this->tool('create_notes', ['payload' => ['title' => 'no body']]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Validation failed', (string) $result->errorMessage);
        $this->assertStringContainsString('body', (string) $result->errorMessage);
        $this->assertSame(2, DB::table('notes')->count());
        $this->assertNotContains('afterCreate', McpHookSpy::$calls);
    }

    public function test_default_validation_applies_to_tool_writes(): void
    {
        Config::set('record.default_validation.enabled', true);

        $result = $this->tool('create_tags', ['payload' => ['name' => null]]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('name', (string) $result->errorMessage);
        $this->assertSame(0, DB::table('tags')->count());
    }

    public function test_update_and_delete_run_their_hooks(): void
    {
        $this->assertFalse($this->tool('update_notes', ['id' => 1, 'payload' => ['title' => 'changed']])->isError);
        $this->assertFalse($this->tool('delete_notes', ['id' => 1])->isError);

        $this->assertSame(['beforeUpdate', 'afterUpdate', 'beforeDelete', 'afterDelete'], McpHookSpy::$calls);
        $this->assertTrue(McpHookSpy::$contexts['afterUpdate']['updated']);
        $this->assertSame(1, McpHookSpy::$contexts['afterDelete']['affected']);
        $this->assertSame('changed', McpHookSpy::$contexts['beforeDelete']['record']->title);
    }

    public function test_a_before_read_filter_narrows_lists_and_reads_and_after_read_runs(): void
    {
        $list = $this->tool('list_notes');
        $blue = $this->tool('read_notes', ['id' => 2]);

        $this->assertSame(['RED NOTE'], array_column($list->structuredContent['response']['data'], 'title'));
        $this->assertEmpty($blue->structuredContent['response']['data']);
        $this->assertSame(['beforeRead', 'afterRead', 'beforeRead'], McpHookSpy::$calls);
    }

    public function test_query_params_as_a_string_still_work(): void
    {
        $result = $this->tool('list_notes', ['queryParams' => 'select=id,title']);

        $this->assertSame(['RED NOTE'], array_column($result->structuredContent['response']['data'], 'title'));
    }

    public function test_tool_writes_broadcast_record_mutated(): void
    {
        Config::set('record.broadcast_events', true);
        Event::fake([RecordMutated::class]);

        $this->tool('create_notes', ['payload' => ['title' => 'hello', 'body' => 'b']]);

        Event::assertDispatched(RecordMutated::class);
    }

    public function test_a_tool_write_is_audited_once(): void
    {
        Config::set('audit.enabled', true);
        Config::set('audit.queue_enabled', false);
        Config::set('audit.filter', [McpHookSpy::class, 'countAudit']);

        $this->tool('create_notes', ['payload' => ['title' => 'hello', 'body' => 'b']]);

        $this->assertSame(1, McpHookSpy::$audits);
    }

    public function test_a_hook_that_aborts_refuses_the_call(): void
    {
        McpHookSpy::$abort = true;

        $result = $this->tool('create_notes', ['payload' => ['title' => 'hello', 'body' => 'b']]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Notes are closed today', (string) $result->errorMessage);
        $this->assertSame(2, DB::table('notes')->count());
    }

    public function test_an_include_a_before_read_hook_adds_is_refused_without_a_tenant(): void
    {
        Schema::create('pets', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('note_id');
            $t->string('tenant_id')->nullable();
        });
        Config::set('record.enable_tenant_id', true);
        $this->registerTables(new RecordTableTriggerType(McpHookSpy::class, 'beforeReadAddingPets'));
        $tables = config('record.tables');
        $tables['notes']->relationships = ['pets' => new RecordHasManyType(table: 'pets', foreignKey: 'note_id')];
        $tables['pets'] = new RecordTableType(table: 'pets', hasTenantId: true, public: new RecordTablePublic(read: true, write: true));
        Config::set('record.tables', $tables);
        SchemaRegistryUtils::refresh();

        $this->expectException(ToolError::class);
        $this->expectExceptionCode(-32001);

        $this->tool('list_notes');
    }

    public function test_switching_record_hooks_off_restores_the_previous_behaviour(): void
    {
        Config::set('record.mcp.run_record_hooks', false);

        $result = $this->tool('create_notes', ['payload' => ['title' => 'no body']]);

        $this->assertFalse($result->isError);
        $this->assertSame([], McpHookSpy::$calls);
        $this->assertSame('no body', DB::table('notes')->where('id', '>', 2)->value('title'));
    }
}
```

`tests/Feature/Ai/RecordToolHooksTest.php` — the AI SDK tools share the executor:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature\Ai;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Tools\Request;
use Sopheak\Core\Ai\RecordTool;
use Sopheak\Core\Ai\RecordTools;
use Sopheak\Core\Tests\Concerns\UsesLaravelAi;
use Sopheak\Core\Tests\Feature\McpHookSpy;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * @internal
 */
class RecordToolHooksTest extends TestCase
{
    use RefreshDatabase;
    use UsesLaravelAi;

    protected function setUp(): void
    {
        $this->requireLaravelAi();
        parent::setUp();
        McpHookSpy::reset();

        Schema::create('notes', function (Blueprint $t): void {
            $t->id();
            $t->string('title');
            $t->string('body')->nullable();
            $t->timestamps();
        });
        Config::set('record.tables', [
            'notes' => new RecordTableType(
                table: 'notes',
                public: new RecordTablePublic(read: true, write: true),
                createValidator: [McpHookSpy::class, 'requireBody'],
            ),
        ]);
        SchemaRegistryUtils::refresh();
    }

    public function test_a_table_validator_refusal_reaches_the_model_and_nothing_is_written(): void
    {
        /** @var RecordTool $tool */
        $tool = RecordTools::for(['notes'])->only(['create'])->withoutApproval()->toArray()[0];

        $answer = json_decode($tool->handle(new Request(['payload' => '{"title":"no body"}'])), true, 512, JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('Validation failed', $answer['error']['message']);
        $this->assertStringContainsString('body', $answer['error']['message']);
        $this->assertSame(0, DB::table('notes')->count());
    }
}
```

(`McpHookSpy` is declared in `McpRecordHooksTest.php`; if autoloading cannot find it from the Ai test, move the class to `tests/Support/McpHookSpy.php` under `Sopheak\Core\Tests\Support` and update both `use` lines.)

- [x] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter 'McpRecordHooksTest|RecordToolHooksTest'`
Expected: FAIL — hooks are not called, validators do not refuse, no `RecordMutated`; `test_a_tool_write_is_audited_once`, `test_query_params_as_a_string_still_work` and `test_switching_record_hooks_off_...` may already pass (they pin invariants).

- [x] **Step 3: Extract the validator runner**

Create `src/Utilities/TableValidatorRunner.php`: a `final class` with `public static function firstFailure(mixed $validatorConfig, Request $request, ?string $id): ?ValidatorContract` holding the body of `HasControllerHelpers::runTableValidators()` (returning the failing validator instead of a response), plus the private helpers moved verbatim from `HasControllerHelpers` L244-409 (`resolveValidatorConfigs`, `flattenValidatorConfigs`, `isValidatorConfigArray`, `invokeValidatorCallable`, `resolveValidationType`, `invokeValidationType`, `normalizeValidatorIdForCallable`, `reflectCallable`) as `private static` methods (`$this->` → `self::`). `HasControllerHelpers::runTableValidators()` becomes:

```php
    private function runTableValidators(mixed $validatorConfig, Request $request, ?string $id): ?JsonResponse
    {
        $validator = TableValidatorRunner::firstFailure($validatorConfig, $request, $id);

        return $validator instanceof ValidatorContract
            ? RecordApiResponseService::errorWrapped('Validation failed', RecordApiJsonResponseEnum::VALIDATION_ERROR->value, $validator->errors()->toArray())
            : null;
    }
```

Before deleting the moved helpers, `graft grep` each name: any other trait that calls one keeps a one-line private wrapper.

- [x] **Step 4: Split the after-write hooks out of the post-write logic**

In `RecordService`, move step 1 of `runPostWriteLogic()` (table and global after-trigger, L471-503) into `private function runAfterWriteTriggers(Request $request, string $table, string $operation, array $recordContext): void` and call it from `runPostWriteLogic()`. Add:

```php
    /**
     * The after-write steps of the HTTP API except the audit row: the table and
     * global after* hooks (and the webhooks delivered through them) and the
     * RecordMutated broadcast. For writes audited elsewhere — the MCP and AI SDK
     * tools audit through RecordCreated / RecordUpdated / RecordDeleted.
     *
     * @param array<string, mixed> $recordContext
     */
    public function processAfterWriteHooks(Request $request, string $table, string $operation, array $recordContext): void
    {
        NestedWriteAuthorizer::trusted(function () use ($request, $table, $operation, $recordContext): void {
            $this->runAfterWriteTriggers($request, $table, $operation, $recordContext);
            $this->fireBroadcastEvent($table, $operation, $recordContext, SchemaRegistryUtils::getTable($table));
        });
    }
```

Give `executeCreate`, `executeUpdate` and `executeDelete` a trailing `?array &$outcome = null` and assign `$outcome = $result;` right after the `createRecord` / `updateRecord` / `deleteRecord` call.

- [x] **Step 5: Write the pipeline**

`src/Mcp/RecordHookPipeline.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Mcp;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Sopheak\Core\Services\RecordApiResponseService;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\DefaultValidationUtils;
use Sopheak\Core\Utilities\NestedWriteAuthorizer;
use Sopheak\Core\Utilities\RecordUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Sopheak\Core\Utilities\TableValidatorRunner;
use Sopheak\Core\Utilities\TenantScopedIncludes;

/**
 * Runs a data tool call through the record hooks and validators of the HTTP
 * API: global and table before* hooks, the table's validators and column
 * default validation, the write, then the after* hooks (and the webhooks
 * delivered through them) and the RecordMutated broadcast — in the order the
 * CRUD controller runs them.
 *
 * The data still comes from RecordService::execute*, so a tool's result, its
 * audit row (RecordCreated / RecordUpdated / RecordDeleted) and its cache
 * invalidation are unchanged. Hooks receive a Request built for the call: the
 * payload as its JSON body, queryParams as its query string, the current
 * request's client details and user, and the resolved tenant.
 */
final readonly class RecordHookPipeline
{
    private const CLIENT_SERVER_KEYS = ['REMOTE_ADDR', 'HTTP_USER_AGENT', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'];

    public function __construct(private RecordService $records) {}

    /**
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>
     */
    public function list(string $table, array $queryParams, mixed $tenantId): array
    {
        $context = ['type' => 'index', RecordConfigService::tenantColumn() => $tenantId];
        $request = $this->before('beforeRead', $this->schema($table)->beforeRead ?? null, $table, $this->request('GET', $queryParams, [], $tenantId), $context);
        $query = $request->query->all();
        $this->refuseTenantScopedIncludes($table, $query, $tenantId);

        $result = RecordService::executeGetByFilter($table, $query, $tenantId, true, 'id');
        $data = RecordService::stripHiddenColumns($result['data'] ?? [], $this->schema($table));
        $meta = (array) ($result['meta'] ?? []);

        $this->afterRead($table, $request, $context + [
            'filters' => $result['filters'] ?? [],
            'data' => $data,
            'meta' => $meta,
            'response' => RecordApiResponseService::successWrapped($data, $meta),
        ]);

        return $result;
    }

    /**
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>
     */
    public function read(string $table, mixed $id, array $queryParams, mixed $tenantId): array
    {
        $context = ['type' => 'show', 'id' => $id, RecordConfigService::tenantColumn() => $tenantId];
        $request = $this->before('beforeRead', $this->schema($table)->beforeRead ?? null, $table, $this->request('GET', $queryParams, [], $tenantId), $context);
        $query = $request->query->all();
        $this->refuseTenantScopedIncludes($table, $query, $tenantId);

        $result = RecordService::executeGetById($table, $id, $query, $tenantId);
        $record = RecordService::stripHiddenColumns($result['data'] ?? [], $this->schema($table));

        if (!empty($record)) {
            $this->afterRead($table, $request, $context + ['record' => $record, 'response' => RecordApiResponseService::successWrapped($record)]);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>
     */
    public function create(string $table, array $payload, array $queryParams, mixed $tenantId): array
    {
        $schema = $this->schema($table);
        $tenantColumn = RecordConfigService::tenantColumn();
        $request = $this->before('beforeCreate', $schema->beforeCreate ?? null, $table, $this->request('POST', $queryParams, $payload, $tenantId), [$tenantColumn => $tenantId]);

        $this->validate($schema->createValidator, $request, null);
        if (RecordConfigService::defaultValidationEnabled() && (!RecordConfigService::defaultValidationOnlyWhenMissing() || null === $schema->createValidator)) {
            $this->validateRules(DefaultValidationUtils::buildCreateRules($schema), $request);
        }

        [$payload, $query] = $this->payloadAndQuery($request);
        $this->refuseTenantScopedIncludes($table, $query, $tenantId);

        return NestedWriteAuthorizer::enforce(fn (): array => DB::transaction(function () use ($table, $payload, $query, $tenantId, $tenantColumn, $request): array {
            $outcome = null;
            $result = RecordService::executeCreate($table, $payload, $query, $tenantId, $outcome);

            $this->records->processAfterWriteHooks($request, $table, 'create', [
                'id' => $outcome['id'] ?? null,
                'payload' => $outcome['payload'] ?? $payload,
                $tenantColumn => $outcome[$tenantColumn] ?? $tenantId,
                'response' => RecordApiResponseService::successWrapped(RecordService::stripHiddenColumns($result['data'] ?? [], $this->schema($table))),
            ]);

            return $result;
        }));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>
     */
    public function update(string $table, mixed $id, array $payload, array $queryParams, mixed $tenantId): array
    {
        $schema = $this->schema($table);
        $tenantColumn = RecordConfigService::tenantColumn();
        $request = $this->before('beforeUpdate', $schema->beforeUpdate ?? null, $table, $this->request('PUT', $queryParams, $payload, $tenantId), ['id' => $id, $tenantColumn => $tenantId]);

        $this->validate($schema->updateValidator, $request, (string) $id);
        if (RecordConfigService::defaultValidationEnabled() && (!RecordConfigService::defaultValidationOnlyWhenMissing() || null === $schema->updateValidator)) {
            $this->validateRules(DefaultValidationUtils::buildUpdateRules($schema, $id), $request);
        }

        [$payload, $query] = $this->payloadAndQuery($request);
        $this->refuseTenantScopedIncludes($table, $query, $tenantId);

        return NestedWriteAuthorizer::enforce(fn (): array => DB::transaction(function () use ($table, $id, $payload, $query, $tenantId, $tenantColumn, $request): array {
            $outcome = null;
            $result = RecordService::executeUpdate($table, $id, $payload, $query, $tenantId, $outcome);

            if (!empty($outcome['exists'])) {
                $this->records->processAfterWriteHooks($request, $table, 'update', [
                    'id' => $id,
                    'payload' => $outcome['payload'] ?? $payload,
                    $tenantColumn => $outcome[$tenantColumn] ?? $tenantId,
                    'updated' => $outcome['updated'] ?? false,
                    'response' => RecordApiResponseService::successWrapped(RecordService::stripHiddenColumns($result['data'] ?? [], $this->schema($table))),
                ]);
            }

            return $result;
        }));
    }

    /**
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>
     */
    public function delete(string $table, mixed $id, array $queryParams, mixed $tenantId): array
    {
        $schema = $this->schema($table);
        $tenantColumn = RecordConfigService::tenantColumn();
        $record = $this->records->fetchRawRecord($table, $id, $tenantId);
        $request = $this->before('beforeDelete', $schema->beforeDelete ?? null, $table, $this->request('DELETE', $queryParams, [], $tenantId), ['id' => $id, $tenantColumn => $tenantId, 'record' => $record]);

        $this->validate($schema->deleteValidator, $request, (string) $id);
        $query = $request->query->all();

        return NestedWriteAuthorizer::enforce(fn (): array => DB::transaction(function () use ($table, $id, $query, $tenantId, $tenantColumn, $request, $record, $schema): array {
            $outcome = null;
            $result = RecordService::executeDelete($table, $id, $query, $tenantId, $outcome);
            $affected = (int) ($outcome['affected'] ?? 0);

            if ($affected > 0) {
                $this->records->processAfterWriteHooks($request, $table, 'delete', [
                    'id' => $id,
                    $tenantColumn => $tenantId,
                    'affected' => $affected,
                    'soft_deleted' => $schema->softDeletes,
                    'record' => $record,
                    'response' => RecordApiResponseService::successWrapped(['deleted' => $affected]),
                ]);
            }

            return $result;
        }));
    }

    private function schema(string $table): RecordTableType
    {
        $schema = SchemaRegistryUtils::getTable($table);
        if (!$schema instanceof RecordTableType) {
            throw new ToolError('Unknown table: ' . $table, -32001);
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $queryParams
     * @param array<string, mixed> $payload
     */
    private function request(string $method, array $queryParams, array $payload, mixed $tenantId): Request
    {
        $current = request();
        $server = array_intersect_key($current->server->all(), array_flip(self::CLIENT_SERVER_KEYS));
        $server['CONTENT_TYPE'] = 'application/json';
        $server['HTTP_ACCEPT'] = 'application/json';

        $isRead = 'GET' === $method;
        $uri = '/' . ($isRead || [] === $queryParams ? '' : '?' . http_build_query($queryParams));
        $request = Request::create($uri, $method, $isRead ? $queryParams : [], [], [], $server, $isRead ? null : (string) json_encode($payload));

        // Hooks call $request->user(): the user this call runs as.
        $request->setUserResolver(static fn (?string $guard = null): mixed => null === $guard
            ? (auth(RecordConfigService::authGuard())->user() ?? auth()->user())
            : auth($guard)->user());

        if ($current->attributes->has('request_id')) {
            $request->attributes->set('request_id', $current->attributes->get('request_id'));
        }

        if (!RecordUtils::isTenantIdMissing($tenantId)) {
            $request->attributes->set('resolved_tenant_id', $tenantId);
        }

        return $request;
    }

    /**
     * Global hook first, then the table's — the controller's order.
     *
     * @param array<string, mixed> $context
     */
    private function before(string $hook, mixed $tableTrigger, string $table, Request $request, array $context): Request
    {
        $params = $this->records->executeGlobalTrigger($hook, [$request, $table, $context]);
        $request = ($params[0] ?? null) instanceof Request ? $params[0] : $request;

        $params = $this->records->executeTableTrigger($tableTrigger, [$request, $table, $context]);

        return ($params[0] ?? null) instanceof Request ? $params[0] : $request;
    }

    /**
     * Table hook first, then the global one — the controller's order.
     *
     * @param array<string, mixed> $context
     */
    private function afterRead(string $table, Request $request, array $context): void
    {
        $this->records->executeTableTrigger($this->schema($table)->afterRead ?? null, [$request, $table, $context]);
        $this->records->executeGlobalTrigger('afterRead', [$request, $table, $context]);
    }

    private function validate(mixed $validatorConfig, Request $request, ?string $id): void
    {
        $failed = TableValidatorRunner::firstFailure($validatorConfig, $request, $id);
        if (null !== $failed) {
            throw new ValidationException($failed);
        }
    }

    /**
     * @param array<string, mixed> $rules
     */
    private function validateRules(array $rules, Request $request): void
    {
        if ([] !== $rules) {
            Validator::make($request->all(), $rules)->validate();
        }
    }

    /**
     * The query string is not part of the payload — the controller's rule.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function payloadAndQuery(Request $request): array
    {
        $query = $request->query->all();

        return [$request->except(array_keys($query)), $query];
    }

    /**
     * A beforeRead hook can add an include after the executor's own check ran.
     *
     * @param array<string, mixed> $query
     */
    private function refuseTenantScopedIncludes(string $table, array $query, mixed $tenantId): void
    {
        if (!RecordConfigService::enableTenantId() || !RecordUtils::isTenantIdMissing($tenantId)) {
            return;
        }

        $used = TenantScopedIncludes::requested($table, $query);
        if ([] !== $used) {
            throw new ToolError(sprintf('Tenant context is required to include %s, but none was resolved from the request.', implode(', ', $used)), -32001);
        }
    }
}
```

- [x] **Step 6: Route the executor through the pipeline**

In `ToolExecutor::dispatch()`, normalise string `queryParams` before the tenant checks:

```php
        $queryParams = $args['queryParams'] ?? [];
        if (is_string($queryParams)) {
            parse_str($queryParams, $parsed);
            $queryParams = $parsed;
        }
```

replace the `match` with:

```php
            $result = (bool) config('record.mcp.run_record_hooks', true)
                ? $this->throughRecordHooks($action, $table, $id, $payload, (array) $queryParams, $tenantId)
                : match ($action) {
                    'list' => RecordService::executeGetByFilter($table, $queryParams, $tenantId, true, 'id'),
                    'read' => RecordService::executeGetById($table, $id, $queryParams, $tenantId),
                    'create' => NestedWriteAuthorizer::enforce(fn (): array => DB::transaction(fn (): array => RecordService::executeCreate($table, $payload, $queryParams, $tenantId))),
                    'update' => NestedWriteAuthorizer::enforce(fn (): array => DB::transaction(fn (): array => RecordService::executeUpdate($table, $id, $payload, $queryParams, $tenantId))),
                    'delete' => RecordService::executeDelete($table, $id, $queryParams, $tenantId),
                };
```

add the catches before `catch (Exception $exception)`:

```php
        } catch (ValidationException $invalid) {
            return ToolResult::error('Validation failed: ' . json_encode($invalid->errors(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (HttpResponseException $refused) {
            return ToolResult::error($this->refusalMessage($refused));
```

and the helpers:

```php
    /**
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>
     */
    private function throughRecordHooks(string $action, string $table, mixed $id, mixed $payload, array $queryParams, mixed $tenantId): array
    {
        $pipeline = new RecordHookPipeline(app(RecordService::class));

        return match ($action) {
            'list' => $pipeline->list($table, $queryParams, $tenantId),
            'read' => $pipeline->read($table, $id, $queryParams, $tenantId),
            'create' => $pipeline->create($table, (array) $payload, $queryParams, $tenantId),
            'update' => $pipeline->update($table, $id, (array) $payload, $queryParams, $tenantId),
            'delete' => $pipeline->delete($table, $id, $queryParams, $tenantId),
        };
    }

    /**
     * A hook or validator that answered with an HTTP response (abort(), an
     * HttpResponseException): its message, as the HTTP client would read it.
     */
    private function refusalMessage(HttpResponseException $refused): string
    {
        $response = $refused->getResponse();
        $body = json_decode((string) $response->getContent(), true);
        $message = is_array($body) && is_string($body['message'] ?? null) ? $body['message'] : 'The request was refused.';

        return sprintf('%s (HTTP %d)', $message, $response->getStatusCode());
    }
```

Add `use Illuminate\Http\Exceptions\HttpResponseException;` and `use Illuminate\Validation\ValidationException;`.

In `config/sp-record.php`'s `mcp` array add (and document it in the block comment above the array):

```php
        // Run record hooks (before*/after*, and the webhooks delivered through
        // them), table validators, default validation and RecordMutated broadcasts
        // for the MCP data tools and the AI SDK record tools, as over HTTP.
        'run_record_hooks' => env('SP_MCP_RUN_RECORD_HOOKS', true),
```

If `SetupPackageCommand` writes an `mcp` config block, add the same key there.

- [x] **Step 7: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter 'McpRecordHooksTest|RecordToolHooksTest|Mcp|Ai|DeleteHooksTest|RestoreHooksTest|Validator|Validation'`
Expected: PASS. Then the laravel-driver matrix: `SP_MCP_DRIVER=laravel vendor/bin/phpunit --filter "Mcp|OwnRecords|NestedChildWrite|HiddenColumnSanitizationMutationChannelsTest|SchemaMcpToken|ReservedRouteSegment|PermissionGateIntegration|ToolContext|Ai"` → PASS (12 legacy-only skips).

- [x] **Step 8: Commit** — skipped (the user commits); ledger it.

---

### Task 4: Docs and changelogs for Task 3

**Files:**
- Modify: `docs/guide/modules/module-mcp.md` (L366 sentence; Security › Data MCP point 4; config section: `run_record_hooks`)
- Modify: `docs/guide/modules/module-ai-sdk.md` ("What an agent inherits" point 6)
- Modify: `docs/guide/records/record-hooks.md` ("Where Hooks Run")
- Modify: `docs/guide/api/api-validation.md` (Notes bullet on tools)
- Modify: `docs/guide/api/api-realtime-openapi-attribute-config.md` (L14 broadcast sentence)
- Modify: `docs/guide/api/api-config-and-middleware.md` (MCP table: `run_record_hooks` row)
- Modify: `docs/guide/api/api-internal-methods-core.md` (L79: execute* still skip hooks; tools now wrap them)
- Modify: `CHANGELOG.md`, `docs/changelog.md` (replace the "Docs correction — agents and service calls skip hooks and validators" note)

- [x] **Step 1: Rewrite the statements Task 3 made untrue**

- `module-mcp.md`: "Record hooks and validators do not — see point 4 …" → "Record hooks, validators and webhooks run too — see point 4 under [Security & Authentication › Data MCP](#data-mcp)." Point 4 → "4. **Hooks and validators run as over HTTP.** Global and table `before*` hooks, `createValidator` / `updateValidator` / `deleteValidator`, column default validation, `after*` hooks (and the webhooks delivered through them) and `RecordMutated` broadcasts run for every data tool call, in the controller's order; `beforeRead` / `afterRead` run for `list_*` and `read_*` (a `beforeRead` filter also narrows `read_*`). A refusal comes back as an `isError` result: `Validation failed: {…}` or the message of the hook's HTTP response. Hooks receive a request built for the call (payload as its JSON body, `queryParams` as its query string, the caller's IP, user agent and user, the resolved tenant). Set `record.mcp.run_record_hooks` (`SP_MCP_RUN_RECORD_HOOKS`) to `false` for the old behaviour." Add `run_record_hooks` to the Data MCP config listing.
- `module-ai-sdk.md` point 6 → "6. Record hooks, table/default validators, after-hooks (webhooks) and `RecordMutated` broadcasts, exactly as over MCP — see [MCP Support › Security](/guide/module-mcp#data-mcp). `record.mcp.run_record_hooks` switches them for both."
- `record-hooks.md` "Where Hooks Run" → "Hooks run for requests to the HTTP API and for the MCP data tools and the AI SDK record tools (unless `record.mcp.run_record_hooks` is `false`). They do not run for your own `RecordService::execute*` calls — hook code calling those methods would otherwise re-enter itself. Those calls still dispatch `RecordCreated` / `RecordUpdated` / `RecordDeleted` (audit rows, cache invalidation)."
- `api-validation.md` Notes bullet → "Table validators and default validation run in the HTTP CRUD controller and for the MCP / AI SDK tools. Your own `RecordService::executeCreate/Update` calls skip them; they still reject payload fields that are neither a column nor a relationship."
- `api-realtime-openapi-attribute-config.md` → "…after every successful mutation made through the HTTP API or the MCP / AI SDK tools (…). Your own `RecordService::execute*` calls do not broadcast."
- `api-config-and-middleware.md` MCP table: add `| \`record.mcp.run_record_hooks\` | \`SP_MCP_RUN_RECORD_HOOKS\` | \`true\` | Run record hooks, validators, after-hooks/webhooks and broadcasts for the MCP and AI SDK tools |`.
- `api-internal-methods-core.md` L79: keep "do **not** run the HTTP pipeline", change its last sentence to "The MCP data tools and the AI SDK record tools call these methods inside the hook pipeline (`record.mcp.run_record_hooks`), so hooks and validators do run for them."

- [x] **Step 2: Changelogs**

Replace the "Docs correction — agents and service calls skip hooks and validators" note in both changelogs with, under `### Fixed`: "**MCP and AI SDK tools run record hooks and validators**: the data tools called `RecordService::execute*` directly, so `before*` / `after*` hooks (and the webhooks delivered through them), `beforeRead` filters, table and default validators and `RecordMutated` broadcasts did not run for agents — although the MCP guide said they did. They now run in the HTTP controller's order (`record.mcp.run_record_hooks`, default `true`). Your own `RecordService::execute*` calls are unchanged." And under `### Notes`: "**Upgrade note — hooks now run for agents**: hooks that assume a routed HTTP request (`$request->route()`) or a specific URL now also run for MCP and AI SDK tool calls; set `SP_MCP_RUN_RECORD_HOOKS=false` to keep the old behaviour while you adapt them."

- [x] **Step 3: Validate**

Run: `php bin/validate-docs.php`
Expected: `Docs validation OK`.

- [x] **Step 4: Commit** — skipped (the user commits); ledger it.

---

## Follow-up: QA findings left open (added 2026-10-03)

The final review's deferred minors, the pre-existing HTTP `beforeRead` bug it surfaced, one docs-vs-code gap found while checking it (grouped `or=(…)` filters drop relationship columns although `api-filter-operators.md` documents them), and the repo-wide formatting debt that makes `composer format-check` fail. Same Global Constraints; same ledger.

### Task 5: Tool pipeline hardening

**Files:** Modify `src/Mcp/RecordHookPipeline.php` (`request()`, `delete()`); Test `tests/Feature/McpRecordHooksTest.php`.

**Interfaces:** none new.

- [ ] **Step 1: Write the failing tests** — add to `McpHookSpy`: `public static array $captured = [];` (reset in `reset()`), and

```php
    public static function beforeUpdateRecordingQuery(Request $request, string $table, array $context): void
    {
        self::$captured['queryKeys'] = array_keys($request->query->all());
    }

    public static function beforeCreateRecordingTenantHeader(Request $request, string $table, array $context): void
    {
        self::$captured['tenantHeader'] = $request->header('X-Tenant-ID');
    }

    public static function beforeDeleteAddingPets(Request $request, string $table, array $context): void
    {
        $request->query->set('select', '*,pets(*)');
    }
```

Let `registerTables()` take a fourth `array $overrides = []` merged into the `notes` constructor arguments (build the arguments as one array and spread it). Tests:

```php
    public function test_a_delete_re_checks_includes_its_before_hooks_add(): void
    {
        Schema::create('pets', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('note_id');
            $t->string('tenant_id')->nullable();
        });
        Config::set('record.enable_tenant_id', true);
        $this->registerTables(
            null,
            ['pets' => new RecordHasManyType(table: 'pets', foreignKey: 'note_id')],
            ['pets' => new RecordTableType(table: 'pets', hasTenantId: true, public: new RecordTablePublic(read: true, write: true))],
            ['beforeDelete' => new RecordTableTriggerType(McpHookSpy::class, 'beforeDeleteAddingPets')],
        );

        try {
            $this->tool('delete_notes', ['id' => 1]);
            $this->fail('a hook-added tenant-scoped include needs a tenant');
        } catch (ToolError $toolError) {
            $this->assertSame(-32001, $toolError->getCode());
        }

        $this->assertSame(2, DB::table('notes')->count(), 'nothing was deleted');
    }

    public function test_write_hooks_see_dotted_query_keys_as_sent(): void
    {
        $this->registerTables(null, [], [], ['beforeUpdate' => new RecordTableTriggerType(McpHookSpy::class, 'beforeUpdateRecordingQuery')]);

        $this->tool('update_notes', ['id' => 1, 'payload' => ['title' => 'x'], 'queryParams' => ['select' => 'id,title', 'pets.name' => 'eq.x']]);

        $this->assertContains('pets.name', McpHookSpy::$captured['queryKeys']);
    }

    public function test_a_payload_that_cannot_be_encoded_is_refused_not_written_empty(): void
    {
        Schema::create('memos', function (Blueprint $t): void {
            $t->id();
            $t->string('body')->nullable();
            $t->timestamps();
        });
        $this->registerTables(null, [], ['memos' => new RecordTableType(table: 'memos', public: new RecordTablePublic(read: true, write: true))]);

        $result = $this->tool('create_memos', ['payload' => ['body' => "\xB1\x31"]]);

        $this->assertTrue($result->isError);
        $this->assertSame(0, DB::table('memos')->count());
    }

    public function test_hooks_see_the_resolved_tenant_in_the_tenant_header(): void
    {
        Config::set('record.enable_tenant_id', true);
        request()->attributes->set('resolved_tenant_id', 't1');
        $this->registerTables(null, [], [], ['beforeCreate' => new RecordTableTriggerType(McpHookSpy::class, 'beforeCreateRecordingTenantHeader')]);

        $this->tool('create_notes', ['payload' => ['title' => 'hello', 'body' => 'b']]);

        $this->assertSame('t1', McpHookSpy::$captured['tenantHeader']);
    }
```

- [ ] **Step 2: Run** `vendor/bin/phpunit --filter McpRecordHooksTest` — Expected: the four new tests FAIL (delete runs; `pets_name`; a memo row with a null body; header null).
- [ ] **Step 3: Implement** — in `request()`: `Request::create('/', $method, [], [], [], $server, $isRead ? null : json_encode($payload, JSON_THROW_ON_ERROR))`, then `$request->query->replace($queryParams)` and `$request->server->set('QUERY_STRING', http_build_query($queryParams))`; when a tenant resolved, also `$request->headers->set(RecordConfigService::tenantHeader(), (string) $tenantId)`. In `delete()`, after `$query = $request->query->all();` call `$this->refuseTenantScopedIncludes($table, $query, $tenantId);`.
- [ ] **Step 4: Run** `vendor/bin/phpunit --filter 'McpRecordHooksTest|RecordToolHooksTest|Mcp'` — Expected: PASS.

### Task 6: The HTTP include check uses the controller's table

**Files:** Modify `src/Http/Controllers/Concerns/HasControllerHelpers.php` (`resolveTenantContext()`, `refuseTenantScopedIncludes()`), `HasCrudOperations.php` and `HasBulkOperations.php` (every `resolveTenantContext(` call passes `table: $table`); Test `tests/Feature/TenantScopedIncludesHttpTest.php`.

- [ ] **Step 1: Write the failing test**

```php
    public function test_a_controller_action_on_a_route_with_another_parameter_name_still_refuses(): void
    {
        Route::get('/custom/owners-feed', fn (Request $request) => app(CoreRecordController::class)->listRecords($request, 'owners'));

        $this->getJson('/custom/owners-feed?select=*,pets(*)')->assertStatus(422);
    }
```

- [ ] **Step 2: Run** — Expected: FAIL (200, the check read `route('table')`, which is null there).
- [ ] **Step 3: Implement** — `resolveTenantContext(Request $request, object $tableSchema, bool $checkIncludes = true, ?string $table = null)`; `refuseTenantScopedIncludes(Request $request, ?string $table)` uses `$table ?? (string) ($request->route('table') ?? '')`; CRUD and bulk callers pass `table: $table`.
- [ ] **Step 4: Run** `vendor/bin/phpunit --filter 'TenantScoped|Tenant|Bulk|Crud'` — Expected: PASS.

### Task 7: beforeRead query changes take effect over HTTP

**Files:** Modify `src/Services/RecordService.php` (`executeTableTrigger()`; new private `syncQueryString()`), `src/Utilities/QueryBuilderFiltersUtils.php` (`apply()` select guard); Test `tests/Feature/BeforeReadQueryChangesTest.php` (create).

QueryBuilderFiltersUtils reads filters from the raw `QUERY_STRING` (to keep dotted keys), so a hook's `$request->query->set()` / `remove()` / `merge()` never reached the filters, and a `select` merged into a JSON request body made `apply()` pass `null` to `parseSelectColumns()` (500).

- [ ] **Step 1: Write the failing tests** — a `notes` table (`title`, `team`) with a `comments` hasMany (`note_id`, `body`); rows RED (team red, comment hello), BLUE (team blue, comment hello), RED2 (team red, no comment); a hook class with `viaQuerySet` (`query->set('team', 'eq.red')`), `viaMerge` (`merge(['team' => 'eq.red'])`), `searchToOr` (the guide's pattern: `query->remove('search')`, `query->set('or', '(title.eq.BLUE,team.eq.green)')`), `selectIntoBody` (`merge(['select' => 'id,title'])`). Tests: list with `viaQuerySet` → only RED and RED2; `viaMerge` on a plain GET (`$this->get(...)`, no JSON content type) → only RED and RED2; `searchToOr` with `?search=x` → only BLUE; `/api/notes?comments.body=eq.hello` with `viaQuerySet` → only RED (the dotted filter survives the rewrite); `selectIntoBody` via `getJson` → 200; the same hook registered as the global `beforeRead` (`record.global_triggers`) → only RED and RED2.
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter BeforeReadQueryChangesTest` — Expected: FAIL (all rows; 500).
- [ ] **Step 3: Implement** — in `executeTableTrigger()`, after `attachRequestContext()`, snapshot `$request->query->all()`; after the triggers, when `$params[0]` is that same Request and its query bag changed, `syncQueryString()`: re-parse the raw `QUERY_STRING` with `QueryBuilderFiltersUtils::parseQueryStringPreservingDots()`, drop keys whose bag form (`.`/space → `_`) the hook removed, overwrite keys it changed, add keys it added, and write `http_build_query()` back to `QUERY_STRING`. In `apply()`, read `$select = $request->query('select')` and select only when it is a string.
- [ ] **Step 4: Run** `vendor/bin/phpunit --filter 'BeforeReadQueryChangesTest|Hook|Trigger|Filter|Select'` — Expected: PASS.

### Task 8: Grouped filters accept relationship columns

**Files:** Modify `src/Utilities/QueryBuilderFiltersUtils.php` (`parseGroupedLogicExpression()`; new public `groupedFilterColumns(array $params): list<string>`), `src/Utilities/TenantScopedIncludes.php`; Test `tests/Feature/TenantScopedIncludesHttpTest.php`, `tests/Feature/McpTenantIncludesTest.php`.

`applyGroupedCondition()` already applies `alias.column` as a tenant-bound relationship filter, but the parser split each condition at its first dot, so `pets.name.eq.x` became column `pets` + an unknown operator `name` and was dropped.

- [ ] **Step 1: Write the failing tests** (tenancy off unless stated): `or=(pets.name.eq.THEIRS,name.eq.Nobody)` → `['Pat']`; `or=(pets.name.eq.NONE,name.eq.Nobody)` → `[]`; tenancy on, no tenant: the first form → 422 naming `pets`; tenancy on, `X-Tenant-ID: t1`: `or=(pets.name.eq.THEIRS,name.eq.Nobody)` → `[]` and `or=(pets.name.eq.MINE,name.eq.Nobody)` → `['Pat']`; MCP `includeForms` gains `'a grouped relationship filter' => [['or' => '(pets.name.eq.THEIRS,name.eq.Pat)']]` (refused) and the "neither embeds nor filters" assertion drops its `or` line.
- [ ] **Step 2: Run** — Expected: FAIL (`[]`; 200).
- [ ] **Step 3: Implement** — when the first-dot split yields no operator, retry with a two-segment column (`/^([A-Za-z_]\w*\.[A-Za-z_]\w*)\.(.+)$/`); `groupedFilterColumns()` = every condition column in the `and`/`or` groups (`extractGroupedFilters()` on a copy, walked recursively); `TenantScopedIncludes::requested()` checks the alias of each dotted grouped column like a relationship-filter key.
- [ ] **Step 4: Run** `vendor/bin/phpunit --filter 'TenantScoped|Tenant|Grouped|Filter|Relationship'` — Expected: PASS.

### Task 9: Docs, changelogs and the stale comment

- `ToolExecutor::refusalMessage()` comment: an HttpResponseException, or a JsonResponse a hook returned (not `abort()`).
- `module-mcp.md` point 4: hooks see the resolved tenant as `resolved_tenant_id` and in the tenant header.
- `record-hooks.md`: `beforeRead` hooks change filters with `$request->query->set()` / `remove()` (now effective over HTTP); a `select` merged into a JSON body is ignored; the Search-OR example works (relationship columns in groups).
- `api-filter-operators.md`: grouped relationship conditions are one level (`alias.column`), tenant-bound, and need a tenant when the relationship is tenant-scoped; `record-tenancy.md`: grouped relationship conditions are among the refused forms.
- Changelogs: Fixed (beforeRead query changes; grouped relationship filters; select-in-body 500; tool pipeline items) and upgrade notes (dormant beforeRead hooks now filter; grouped relationship conditions now match; an app subclass overriding `RecordService::execute*` must add the `?array &$outcome = null` parameter).
- Validate: `php bin/validate-docs.php`.

### Task 10: Clear the formatting debt so `composer format-check` passes

- [ ] `vendor/bin/rector process` and `vendor/bin/php-cs-fixer fix` over the repo; review the diff for anything beyond formatting and type/docblock tidy-ups; `vendor/bin/rector process --dry-run` → `[OK] Rector is done!`; php-cs-fixer dry-run → no files; full suite, PHPStan.
