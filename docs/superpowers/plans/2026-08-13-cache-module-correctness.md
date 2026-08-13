# Cache Module Correctness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the six reproduced defects in the Records API cache module so that a write reliably invalidates every cache entry it affects, and so that two requests differing only in a query parameter never share a cache entry.

**Architecture:** Keep the existing namespace-versioning design — it is the right choice for stores without tag support — and fix it in place. Three structural changes carry most of the work: namespace bumps become unconditional and atomic (`Cache::add()` + `Cache::increment()`), `record_show` tokens gain a dependency on their table's namespace so table-level clears cascade, and every cache key gains a fingerprint of the full request query so read-shaping parameters can never collide. Request-scoped state moves off the `Request` object onto a container-scoped context that is actually reset at request and job boundaries.

**Tech Stack:** PHP 8.3+, Laravel/Illuminate (Cache, Events, Log), PHPUnit 11 with Orchestra Testbench, SQLite `:memory:`.

## Global Constraints

- **Backward compatibility must be maintained.** Every public signature in `QueryCacheService` and `RecordCacheService` keeps working with its current argument list. New parameters are appended with defaults; no parameter is removed, renamed, or reordered.
- Use **named arguments** for `RecordTableType` and all relationship type constructors.
- **DB-agnostic:** MySQL, PostgreSQL and SQLite. No DB-specific SQL.
- **Cache-driver-agnostic:** every change must work on `array`, `database`, `file`, `redis` and `memcached`. `Cache::add()` + `Cache::increment()` was verified to behave identically on array, database and file (`add=true, incr=2, add=false, incr=3`).
- Add tests for every core behaviour change (tenant, auth, filters, permission, triggers, response wrapper) — this is a project rule, not a suggestion.
- Run `composer quality` (format-check → analyse → test) before the final commit of each task. PHPStan is strict (larastan v3); use `--memory-limit=1G`.
- Do **not** edit anything in `sp-laravel-api-docs/` — it is auto-generated.
- Rector does the real formatting. Do not hand-format; run `composer format` if `format-check` fails.
- Source report: `docs/superpowers/specs/2026-08-13-cache-module-audit.md` is *not* required reading — every fact needed is inlined below.

## Scope

This plan covers the correctness defects **C1–C6** plus the architecture items **A2, A3, A6, A7, A8, A9** from the cache audit.

**Explicitly deferred to a second plan** (they are a storage-layer redesign, not a correctness fix, and this plan ships working software without them):

- **A1** — orphaned versioned entries are never reclaimed on `database`/`file` stores. Needs a driver-capability layer (native `Cache::tags()` on taggable stores, a per-namespace key index plus a prune command on the rest).
- **A4** — namespace counters are evictable under `allkeys-lru`, which can resurrect stale data.
- **A5** — no stampede protection.

Do not attempt A1/A4/A5 here.

## File Structure

| File | Responsibility | Change |
| --- | --- | --- |
| `src/Services/QueryCacheService.php` | Namespace versioning, token resolution, raw cache access | Heavily modified: Tasks 1, 2, 3, 6, 7, 8 |
| `src/Services/RecordCacheService.php` | Cache key construction, admission rules, invalidation entry points | Modified: Tasks 4, 5, 6 |
| `src/Services/RecordService.php` | Read/write orchestration; passes keys and dependencies | Modified: Tasks 4, 5, 6, 9 |
| `src/Http/Controllers/Concerns/HasCrudOperations.php` | CRUD endpoints | Modified: Task 9 (remove redundant invalidation) |
| `src/Support/CacheRequestContext.php` | **New.** Request/job-scoped memo + stats storage | Created: Task 8 |
| `src/CoreSpLaravelApiProvider.php` | Service registration | Modified: Task 8 (scoped binding + reset listener) |
| `tests/Unit/QueryCacheServiceNamespaceInvalidationTest.php` | Namespace unit tests | Modified: Task 2 (one test encodes the bug and must be replaced) |
| `tests/Feature/CacheInvalidationCorrectnessTest.php` | **New.** Regression suite for C1–C3, C6 | Created: Task 1, extended by 2, 3, 6 |
| `tests/Feature/CacheKeyQueryParamTest.php` | **New.** Regression suite for C4 | Created: Task 4 |
| `tests/Feature/CacheAdmissionRulesTest.php` | **New.** Regression suite for C5 | Created: Task 5 |
| `tests/Feature/CacheRequestContextTest.php` | **New.** Scoped-context lifecycle | Created: Task 8 |

---

### Task 1: Cursor-paginated lists can be invalidated (C3)

`parseScopeFromKey()` does not match the `record_cursor:` prefix, so every cursor key falls through to the hardcoded fallback token `v1` and no bump can ever reach it. A one-line fix already exists uncommitted in the working tree; this task adds the regression test that should have caught it and commits both together.

**Files:**
- Modify: `src/Services/QueryCacheService.php:274` (already changed in the working tree — verify, do not re-edit)
- Test: `tests/Feature/CacheInvalidationCorrectnessTest.php` (new)

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `tests/Feature/CacheInvalidationCorrectnessTest.php` with a `setUp()` that later tasks (2, 3, 6) append tests to. The class registers a `products` table with `hasTenantId: true`, `softDeletes: true`, cache enabled, prefix `sp_laravel_api`, tenant column `tenant_id`.

- [ ] **Step 1: Confirm the working-tree fix is present**

Run:

```bash
git diff src/Services/QueryCacheService.php
```

Expected: exactly one hunk, changing line 274 from `record_(?:index|func)` to `record_(?:index|cursor|func)`. If the working tree is clean instead, apply it manually:

```php
        if (preg_match('/^record_(?:index|cursor|func):table:([^:]+):.*tenant:([^:]*):/', $key, $matches)) {
            return ['scope' => 'table', 'name' => $matches[1], 'tenant' => $matches[2]];
        }
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/CacheInvalidationCorrectnessTest.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Services\QueryCacheService;
use Sopheak\Core\Services\RecordCacheService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class CacheInvalidationCorrectnessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('tenant_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Config::set('record.enable_tenant_id', true);
        Config::set('record.tenant_column', 'tenant_id');
        Config::set('record.tenant_header', 'X-Tenant-ID');
        Config::set('record.cache.enabled', true);
        Config::set('record.cache.prefix', 'sp_laravel_api');
        Config::set('record.cache.ttl', 3600);
        Config::set('record.tables', [
            'products' => new RecordTableType(
                table: 'products',
                pmsName: 'products',
                hasTenantId: true,
                softDeletes: true,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
            ),
        ]);

        SchemaRegistryUtils::refresh();
        Cache::flush();
    }

    public function test_table_clear_invalidates_cursor_paginated_list_cache(): void
    {
        $cursorKey = 'record_cursor:table:products:tenant:acme:hash:abc';

        QueryCacheService::put($cursorKey, ['data' => ['stale']], 3600);
        $this->assertNotNull(QueryCacheService::get($cursorKey));

        QueryCacheService::invalidateTableForTenant('products', 'acme');

        $this->assertNull(
            QueryCacheService::get($cursorKey),
            'A table-level clear must reach record_cursor: keys'
        );
    }
}
```

- [ ] **Step 3: Run the test against unfixed code to verify it fails**

```bash
git stash push src/Services/QueryCacheService.php
vendor/bin/phpunit tests/Feature/CacheInvalidationCorrectnessTest.php --no-coverage
git stash pop
```

Expected: FAIL with `Failed asserting that Array &0 ['data' => ['stale']] is null.`

- [ ] **Step 4: Run the test with the fix applied to verify it passes**

```bash
vendor/bin/phpunit tests/Feature/CacheInvalidationCorrectnessTest.php --no-coverage
```

Expected: PASS (1 test, 3 assertions)

- [ ] **Step 5: Run the full suite**

```bash
vendor/bin/phpunit --no-coverage
```

Expected: 635 tests, 0 failures.

- [ ] **Step 6: Commit**

```bash
git add src/Services/QueryCacheService.php tests/Feature/CacheInvalidationCorrectnessTest.php
git commit -m "fix(cache): invalidate cursor-paginated list caches on table clear

record_cursor: keys were not matched by parseScopeFromKey(), so they fell
through to the hardcoded v1 token and no namespace bump could ever reach
them. Cursor lists were stale until TTL, always."
```

---

### Task 2: Every namespace bump is applied, atomically (C1 + A2)

`bumpNamespace()` keeps a `bumped[]` map in the request attributes and returns early on a repeat. That drops the second write in a *write → read → write* sequence, leaving the entry the read just cached live and stale. Because the map lives on `request()` — a long-lived singleton in queue workers, Octane and Artisan — it never resets there, so after the first invalidation of a namespace every later one in that process is skipped.

The bump is also read-then-write, so two concurrent writers both read v5 and both write v6, losing an update.

**Files:**
- Modify: `src/Services/QueryCacheService.php` — delete `REQUEST_BUMPED_NAMESPACES_KEY`, rewrite `bumpNamespace()`, add `incrementNamespaceVersion()`
- Modify: `tests/Unit/QueryCacheServiceNamespaceInvalidationTest.php:89-99` — one existing test asserts the buggy behaviour and must be replaced
- Test: `tests/Feature/CacheInvalidationCorrectnessTest.php` (extend)

**Interfaces:**
- Consumes: `CacheInvalidationCorrectnessTest` from Task 1.
- Produces: `QueryCacheService::incrementNamespaceVersion(string $key): int` (private). Returns the new version, always ≥ 2. Tasks 7 and 8 modify `bumpNamespace()` further and rely on this method existing with that exact name and signature.
- Note: `invalidation_dedupe_hits` **stays** in `DEFAULT_STATS` (permanently 0) because `RecordService.php:2277` and `:2917` expose `requestStats()` as `meta.debug.cache_stats`, and removing a key would change that public payload shape.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/CacheInvalidationCorrectnessTest.php`:

```php
    public function test_a_second_write_in_one_request_invalidates_a_read_cached_between_them(): void
    {
        $listKey = 'record_index:table:products:tenant:acme:hash:abc';

        // write #1
        QueryCacheService::invalidateTableForTenant('products', 'acme');
        // a read caches the state as of write #1
        QueryCacheService::put($listKey, ['data' => ['A only']], 3600);
        // write #2 must invalidate what that read just cached
        QueryCacheService::invalidateTableForTenant('products', 'acme');

        $this->assertNull(
            QueryCacheService::get($listKey),
            'The second invalidation in a request must not be deduped away'
        );
    }

    public function test_repeated_bumps_each_advance_the_namespace_version(): void
    {
        QueryCacheService::invalidateTableForTenant('products', 'acme');
        $first = QueryCacheService::inspectNamespaceVersion('table', 'products', 'acme');

        QueryCacheService::invalidateTableForTenant('products', 'acme');
        $second = QueryCacheService::inspectNamespaceVersion('table', 'products', 'acme');

        QueryCacheService::invalidateTableForTenant('products', 'acme');
        $third = QueryCacheService::inspectNamespaceVersion('table', 'products', 'acme');

        $this->assertSame(2, $first);
        $this->assertSame(3, $second);
        $this->assertSame(4, $third);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
vendor/bin/phpunit tests/Feature/CacheInvalidationCorrectnessTest.php --no-coverage
```

Expected: 2 failures — `Failed asserting that Array &0 ['data' => ['A only']] is null.` and `Failed asserting that 2 is identical to 3.`

- [ ] **Step 3: Delete the dedupe constant**

In `src/Services/QueryCacheService.php`, delete these two lines (currently lines 15-16):

```php
    private const REQUEST_BUMPED_NAMESPACES_KEY = 'sp_laravel_api.cache.bumped_namespaces';

```

Leave `DEFAULT_STATS` untouched — `invalidation_dedupe_hits` stays as a permanently-zero key for payload compatibility.

- [ ] **Step 4: Rewrite `bumpNamespace()` and add `incrementNamespaceVersion()`**

Replace the whole of `bumpNamespace()` (currently lines 299-321) with:

```php
    private static function bumpNamespace(string $scope, string $name, ?string $tenantKey = null, ?string $recordId = null): void
    {
        $key = self::namespaceKey(scope: $scope, name: $name, tenantKey: $tenantKey, recordId: $recordId);

        try {
            $next = self::incrementNamespaceVersion($key);
            self::memoizeNamespaceVersion($key, $next);
            self::incrementRequestStat('invalidation_bumps');
        } catch (Exception) {
        }
    }

    /**
     * Atomically move a namespace to its next version.
     *
     * add() seeds the counter at 1 so the first increment lands on 2 -- unseeded
     * namespaces already resolve to v1 via getNamespaceVersion(), so a bump has to
     * move off it. add() is a no-op once the counter exists, making this safe to
     * call on every bump. add()+increment() is atomic on database, redis and
     * memcached, which is what stops two concurrent writers from losing an update.
     */
    private static function incrementNamespaceVersion(string $key): int
    {
        Cache::add($key, 1);

        $next = Cache::increment($key);
        if (is_int($next) && $next > 0) {
            return $next;
        }

        // Store has no usable atomic increment -- fall back to read-modify-write.
        $current = (int) Cache::get($key, 1);
        $next = max($current, 1) + 1;
        Cache::forever($key, $next);

        return $next;
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

```bash
vendor/bin/phpunit tests/Feature/CacheInvalidationCorrectnessTest.php --no-coverage
```

Expected: PASS (3 tests)

- [ ] **Step 6: Replace the unit test that encodes the old behaviour**

In `tests/Unit/QueryCacheServiceNamespaceInvalidationTest.php`, replace the whole of `test_it_dedupes_repeated_namespace_bumps_in_the_current_request()` (lines 89-99) with:

```php
    public function test_it_applies_every_namespace_bump_in_the_current_request(): void
    {
        QueryCacheService::invalidateTableForTenant('users', 'tenant-1');
        QueryCacheService::invalidateTableForTenant('users', 'tenant-1');

        $this->assertSame(3, QueryCacheService::inspectNamespaceVersion('table', 'users', 'tenant-1'));

        $stats = QueryCacheService::requestStats();

        $this->assertSame(2, $stats['invalidation_bumps']);
        $this->assertSame(0, $stats['invalidation_dedupe_hits']);
    }
```

- [ ] **Step 7: Run the full suite**

```bash
vendor/bin/phpunit --no-coverage
```

Expected: 0 failures. If any other test fails, it is almost certainly asserting an exact namespace version number that has shifted by one — read the assertion and update the expected number; do not reintroduce the dedupe.

- [ ] **Step 8: Commit**

```bash
git add src/Services/QueryCacheService.php tests/
git commit -m "fix(cache): apply every namespace bump, atomically

bumpNamespace() deduped repeat bumps via a per-request map, which dropped
the second write in a write-read-write sequence and left the entry cached
by the read stale. The map lives on request(), a singleton in queue
workers and Octane, so there every invalidation after the first was
skipped for the life of the process.

Bumps are now unconditional and use add()+increment(), which is atomic on
database, redis and memcached and closes the lost-update race."
```

---

### Task 3: Table clears cascade to single-record caches (C2)

`record_show:*` keys resolve their version from the `record` namespace; `invalidateTableForTenant()` bumps the `table` namespace. The two never meet, so `clearTableCache()` — the built-in that `clearCacheTables` triggers from table and global functions — clears list caches and leaves every `GET /{table}/{id}` response stale until TTL.

Fix by folding the table-scope versions into the `record_show` token. Per-record precision is preserved (a single-record bump still only kills that record) while a table bump now kills all of them.

**Files:**
- Modify: `src/Services/QueryCacheService.php` — `resolveNamespaceToken()`, the `'record' === $parsed['scope']` branch
- Test: `tests/Feature/CacheInvalidationCorrectnessTest.php` (extend)

**Interfaces:**
- Consumes: `CacheInvalidationCorrectnessTest` from Task 1; `incrementNamespaceVersion()` from Task 2.
- Produces: no new public API. The `record_show` token format changes from `v{g}.{t}.{r}` to `v{g}.{t}.{r}.t{tg}.{tt}`. This changes cache keys, so existing entries are orphaned on deploy — acceptable and self-healing.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/CacheInvalidationCorrectnessTest.php`:

```php
    public function test_table_clear_invalidates_single_record_show_cache(): void
    {
        $showKey = 'record_show:table:products:id:5:tenant:acme:select:abc';

        QueryCacheService::put($showKey, ['id' => 5, 'name' => 'stale'], 3600);
        $this->assertNotNull(QueryCacheService::get($showKey));

        app(RecordCacheService::class)->clearTableCache('products', 'acme');

        $this->assertNull(
            QueryCacheService::get($showKey),
            'clearTableCache() must reach record_show: keys for that table'
        );
    }

    public function test_record_clear_still_only_affects_that_record(): void
    {
        $keyFive = 'record_show:table:products:id:5:tenant:acme:select:abc';
        $keySeven = 'record_show:table:products:id:7:tenant:acme:select:abc';

        QueryCacheService::put($keyFive, ['id' => 5], 3600);
        QueryCacheService::put($keySeven, ['id' => 7], 3600);

        QueryCacheService::invalidateRecordForTenant('products', 5, 'acme');

        $this->assertNull(QueryCacheService::get($keyFive));
        $this->assertNotNull(
            QueryCacheService::get($keySeven),
            'Per-record precision must survive the table-version cascade'
        );
    }

    public function test_table_clear_for_one_tenant_leaves_another_tenants_record_cache(): void
    {
        $acme = 'record_show:table:products:id:5:tenant:acme:select:abc';
        $globex = 'record_show:table:products:id:5:tenant:globex:select:abc';

        QueryCacheService::put($acme, ['tenant' => 'acme'], 3600);
        QueryCacheService::put($globex, ['tenant' => 'globex'], 3600);

        app(RecordCacheService::class)->clearTableCache('products', 'acme');

        $this->assertNull(QueryCacheService::get($acme));
        $this->assertNotNull(
            QueryCacheService::get($globex),
            'Tenant isolation must survive the table-version cascade'
        );
    }
```

- [ ] **Step 2: Run the tests to verify the first one fails**

```bash
vendor/bin/phpunit tests/Feature/CacheInvalidationCorrectnessTest.php --no-coverage
```

Expected: `test_table_clear_invalidates_single_record_show_cache` FAILS with `Failed asserting that Array &0 ['id' => 5, 'name' => 'stale'] is null.` The other two pass already (they guard against regressions from the fix).

- [ ] **Step 3: Fold the table versions into the record token**

In `src/Services/QueryCacheService.php`, replace the `'record' === $parsed['scope']` branch inside `resolveNamespaceToken()` (currently lines 254-263) with:

```php
        if ('record' === $parsed['scope'] && isset($parsed['record_id'])) {
            $recordVersion = self::getNamespaceVersion(
                scope: 'record',
                name: $parsed['name'],
                tenantKey: '' !== $parsed['tenant'] ? $parsed['tenant'] : null,
                recordId: $parsed['record_id']
            );

            // A single record's cache must also die when the whole table is cleared.
            // Without these two components, clearTableCache() busts list caches and
            // leaves every record_show entry for the table stale until TTL.
            $tableGlobalVersion = self::getNamespaceVersion(scope: 'table', name: $parsed['name']);
            $tableTenantVersion = '' !== $parsed['tenant']
                ? self::getNamespaceVersion(scope: 'table', name: $parsed['name'], tenantKey: $parsed['tenant'])
                : 1;

            return sprintf(
                'v%s.%s.%s.t%s.%s',
                $globalVersion,
                $tenantVersion,
                $recordVersion,
                $tableGlobalVersion,
                $tableTenantVersion
            );
        }
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
vendor/bin/phpunit tests/Feature/CacheInvalidationCorrectnessTest.php --no-coverage
```

Expected: PASS (6 tests)

- [ ] **Step 5: Run the full suite**

```bash
vendor/bin/phpunit --no-coverage
```

Expected: 0 failures.

- [ ] **Step 6: Commit**

```bash
git add src/Services/QueryCacheService.php tests/Feature/CacheInvalidationCorrectnessTest.php
git commit -m "fix(cache): cascade table-level clears to single-record caches

record_show keys versioned only off the record namespace while
invalidateTableForTenant() bumps the table namespace, so clearTableCache()
-- what clearCacheTables triggers from table and global functions --
left every GET /{table}/{id} response stale until TTL.

The table versions now ride along in the record_show token. Per-record
precision and tenant isolation are preserved and covered by tests."
```

---

### Task 4: Read-shaping query parameters cannot collide (C4)

`generateRecordCacheKey()` hashes only `id`, `select` and `tenant_enabled`. `with_trashed` changes the query — it skips the `whereNull(deleted_at)` guard — but not the key, so `GET /products/1?with_trashed=true` on a soft-deleted row caches it and the next plain `GET /products/1` returns that row with **200 instead of 404**.

The same class of collision exists on the list path for `add_total`/`total`, which are stripped as pagination controls but do change `meta.total` and the `X-Total-Count` header.

Fix by adding a fingerprint of the **entire** request query to every key. This can only split cache entries further, never merge them, so it cannot introduce a new collision.

**Files:**
- Modify: `src/Services/RecordCacheService.php` — add `queryFingerprint()`; append `$queryFingerprint` to `generateOptimizedCacheKey()`, `generateCursorCacheKey()`, `generateRecordCacheKey()`
- Modify: `src/Services/RecordService.php:1327-1335, 1674-1677` (proxy signatures), `:2035, :2046, :2679, :2690, :2971` (call sites)
- Test: `tests/Feature/CacheKeyQueryParamTest.php` (new)

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces:
  - `RecordCacheService::queryFingerprint(Request $request): string` — md5 of the recursively key-sorted full query array. Returns a stable hash for an empty query.
  - `RecordCacheService::generateOptimizedCacheKey(string $table, array $filters, array $includes, int $page, int $limit, bool $tenantEnabled, string $queryFingerprint = ''): string`
  - `RecordCacheService::generateCursorCacheKey(string $table, array $filters, array $includes, string $cursor, string $direction, string $cursorColumn, int $limit, bool $tenantEnabled, string $queryFingerprint = ''): string`
  - `RecordCacheService::generateRecordCacheKey(string $table, mixed $id, mixed $tenantId, mixed $select, bool $tenantEnabled, string $queryFingerprint = ''): string`
  - The three matching `RecordService` proxies gain the same trailing parameter with the same default.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/CacheKeyQueryParamTest.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class CacheKeyQueryParamTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('tenant_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Config::set('record.enable_tenant_id', true);
        Config::set('record.tenant_column', 'tenant_id');
        Config::set('record.tenant_header', 'X-Tenant-ID');
        Config::set('record.cache.enabled', true);
        Config::set('record.cache.prefix', 'sp_laravel_api');
        Config::set('record.cache.ttl', 3600);
        Config::set('record.tables', [
            'products' => new RecordTableType(
                table: 'products',
                pmsName: 'products',
                hasTenantId: true,
                softDeletes: true,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
            ),
        ]);

        SchemaRegistryUtils::refresh();
        Cache::flush();
    }

    public function test_with_trashed_show_request_does_not_poison_the_plain_show_cache(): void
    {
        DB::table('products')->insert([[
            'id' => 1,
            'name' => 'Widget',
            'tenant_id' => 'acme',
            'deleted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]]);

        $this->withHeaders(['X-Tenant-ID' => 'acme'])
            ->getJson('/api/products/1?with_trashed=true')
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Widget');

        $this->withHeaders(['X-Tenant-ID' => 'acme'])
            ->getJson('/api/products/1')
            ->assertStatus(404);
    }

    public function test_plain_show_request_does_not_poison_the_with_trashed_cache(): void
    {
        DB::table('products')->insert([[
            'id' => 1,
            'name' => 'Widget',
            'tenant_id' => 'acme',
            'deleted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]]);

        $this->withHeaders(['X-Tenant-ID' => 'acme'])
            ->getJson('/api/products/1')
            ->assertStatus(404);

        $this->withHeaders(['X-Tenant-ID' => 'acme'])
            ->getJson('/api/products/1?with_trashed=true')
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Widget');
    }

    public function test_add_total_list_request_does_not_share_a_cache_entry_with_the_plain_list(): void
    {
        DB::table('products')->insert([
            ['id' => 1, 'name' => 'A', 'tenant_id' => 'acme', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'B', 'tenant_id' => 'acme', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $withTotal = $this->withHeaders(['X-Tenant-ID' => 'acme'])
            ->getJson('/api/products?limit=2&add_total=true');
        $withTotal->assertStatus(200);
        $this->assertSame(2, $withTotal->json('meta.total'));

        $plain = $this->withHeaders(['X-Tenant-ID' => 'acme'])
            ->getJson('/api/products?limit=2');
        $plain->assertStatus(200);
        $this->assertNull(
            $plain->json('meta.total'),
            'A plain list must not inherit meta.total from an add_total request'
        );
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
vendor/bin/phpunit tests/Feature/CacheKeyQueryParamTest.php --no-coverage
```

Expected: `test_with_trashed_show_request_does_not_poison_the_plain_show_cache` FAILS with `Expected response status code [404] but received 200.` At least one of the other two also fails.

- [ ] **Step 3: Add the fingerprint helper to `RecordCacheService`**

Add `use Illuminate\Http\Request;` if not already imported (it is), then add this method to `src/Services/RecordCacheService.php` immediately after `isCacheableRequest()`:

```php
    /**
     * Stable fingerprint of the full request query.
     *
     * Every cache key folds this in so that any parameter which shapes the read --
     * with_trashed, only_trashed, distinct, add_total, and anything added later --
     * discriminates the key without having to be enumerated. Parameters are
     * opt-out, not opt-in: forgetting to list one can only split a cache entry,
     * never merge two that should differ.
     */
    public function queryFingerprint(Request $request): string
    {
        $query = $request->query();
        $this->recursiveKsort($query);

        return md5(serialize($query));
    }
```

- [ ] **Step 4: Thread the fingerprint through the three key generators**

In `src/Services/RecordCacheService.php`:

`generateOptimizedCacheKey()` — change the signature and `$keyData`:

```php
    public function generateOptimizedCacheKey(string $table, array $filters, array $includes, int $page, int $limit, bool $tenantEnabled, string $queryFingerprint = ''): string
    {
        $tenantColumn = RecordConfigService::tenantColumn();
        $tenantKey = $this->resolveTenantCacheKey(tenantId: $filters[$tenantColumn] ?? null, tenantEnabled: $tenantEnabled);
        $this->recursiveKsort($filters);
        $this->recursiveKsort($includes);
        $keyData = [
            'filters' => $filters,
            'includes' => $includes,
            'page' => $page,
            'limit' => $limit,
            'tenant_enabled' => $tenantEnabled,
            'query' => $queryFingerprint,
        ];

        return sprintf('record_index:table:%s:tenant:%s:hash:%s', $table, $tenantKey, md5(serialize($keyData)));
    }
```

`generateCursorCacheKey()` — same treatment:

```php
    public function generateCursorCacheKey(string $table, array $filters, array $includes, string $cursor, string $direction, string $cursorColumn, int $limit, bool $tenantEnabled, string $queryFingerprint = ''): string
    {
        $tenantColumn = RecordConfigService::tenantColumn();
        $tenantKey = $this->resolveTenantCacheKey(tenantId: $filters[$tenantColumn] ?? null, tenantEnabled: $tenantEnabled);
        $this->recursiveKsort($filters);
        $this->recursiveKsort($includes);
        $keyData = [
            'filters' => $filters,
            'includes' => $includes,
            'cursor' => $cursor,
            'direction' => $direction,
            'cursor_column' => $cursorColumn,
            'limit' => $limit,
            'tenant_enabled' => $tenantEnabled,
            'query' => $queryFingerprint,
        ];

        return sprintf('record_cursor:table:%s:tenant:%s:hash:%s', $table, $tenantKey, md5(serialize($keyData)));
    }
```

`generateRecordCacheKey()` — same treatment:

```php
    public function generateRecordCacheKey(string $table, mixed $id, mixed $tenantId, mixed $select, bool $tenantEnabled, string $queryFingerprint = ''): string
    {
        $tenantKey = $this->resolveTenantCacheKey(tenantId: $tenantId, tenantEnabled: $tenantEnabled);
        if (is_array($select)) {
            $this->recursiveKsort($select);
        }

        $keyData = [
            'id' => $id,
            'select' => $select,
            'tenant_enabled' => $tenantEnabled,
            'query' => $queryFingerprint,
        ];

        return sprintf('record_show:table:%s:id:%s:tenant:%s:select:%s', $table, $id, $tenantKey, md5(serialize($keyData)));
    }
```

- [ ] **Step 5: Update the three `RecordService` proxies**

In `src/Services/RecordService.php`, lines 1327-1335 and 1674-1677:

```php
    public function generateOptimizedCacheKey(string $table, array $filters, array $includes, int $page, int $limit, bool $tenantEnabled, string $queryFingerprint = ''): string
    {
        return $this->cacheService()->generateOptimizedCacheKey($table, $filters, $includes, $page, $limit, $tenantEnabled, $queryFingerprint);
    }

    public function generateRecordCacheKey(string $table, mixed $id, mixed $tenantId, mixed $select, bool $tenantEnabled, string $queryFingerprint = ''): string
    {
        return $this->cacheService()->generateRecordCacheKey($table, $id, $tenantId, $select, $tenantEnabled, $queryFingerprint);
    }
```

```php
    public function generateCursorCacheKey(string $table, array $filters, array $includes, string $cursor, string $direction, string $cursorColumn, int $limit, bool $tenantEnabled, string $queryFingerprint = ''): string
    {
        return $this->cacheService()->generateCursorCacheKey($table, $filters, $includes, $cursor, $direction, $cursorColumn, $limit, $tenantEnabled, $queryFingerprint);
    }
```

- [ ] **Step 6: Pass the fingerprint at all five call sites**

`src/Services/RecordService.php:2035` (inside `listRecords()`), add the argument to the `generateCursorCacheKey` call:

```php
                    tenantEnabled: $tenantEnabled,
                    queryFingerprint: $this->cacheService()->queryFingerprint($request)
                );
```

`:2046` (inside `listRecords()`), add to the `generateOptimizedCacheKey` call:

```php
                    tenantEnabled: $tenantEnabled,
                    queryFingerprint: $this->cacheService()->queryFingerprint($request)
                );
```

`:2679` and `:2690` are inside the static `applyRequestFilters()`. Its local `$service` is a `RecordService`, whose `cacheService()` is private, so resolve the cache service from the container directly at both sites:

```php
                    tenantEnabled: $tenantEnabled,
                    queryFingerprint: app(RecordCacheService::class)->queryFingerprint($request)
                );
```

No import is needed — `RecordCacheService` and `RecordService` both live in `Sopheak\Core\Services`, so the unqualified name already resolves.

`:2971` (inside `getRecord()`), add to the `generateRecordCacheKey` call:

```php
            tenantEnabled: $tenantEnabled,
            queryFingerprint: $this->cacheService()->queryFingerprint($request)
        );
```

- [ ] **Step 7: Run the tests to verify they pass**

```bash
vendor/bin/phpunit tests/Feature/CacheKeyQueryParamTest.php --no-coverage
```

Expected: PASS (3 tests)

- [ ] **Step 8: Run the full suite**

```bash
vendor/bin/phpunit --no-coverage
```

Expected: 0 failures.

- [ ] **Step 9: Commit**

```bash
git add src/Services/RecordCacheService.php src/Services/RecordService.php tests/Feature/CacheKeyQueryParamTest.php
git commit -m "fix(cache): fold the full request query into every cache key

generateRecordCacheKey() hashed only id, select and tenant_enabled, so
?with_trashed=true shared a cache entry with the plain request and could
serve a soft-deleted record as a 200 where a 404 was correct. add_total
collided the same way on the list path.

Keys now include a fingerprint of the whole query, making parameters
opt-out rather than opt-in. This can only split cache entries further, so
it cannot introduce a new collision."
```

---

### Task 5: The dynamic-query admission guard actually fires (C5)

`$request->has(['search','filter','where'])` is true only when **all three** are present, so `!has(...)` admits a request carrying just `search`. The guard only rejects the one request shape nobody sends, so the low-hit dynamic queries it exists to keep out are filling the cache.

`RecordService::executeGlobalFunction()` duplicates this check inline and additionally bypasses the admission rules and `skip_query_params` entirely.

**Files:**
- Modify: `src/Services/RecordCacheService.php:53` — `has` → `hasAny`; add `isCacheableGlobalRequest()`
- Modify: `src/Services/RecordService.php:811` — use the new method
- Test: `tests/Feature/CacheAdmissionRulesTest.php` (new)

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `RecordCacheService::isCacheableGlobalRequest(Request $request): bool` — the table-less counterpart of `isCacheableRequest()`. Applies the global enable flag, the GET check, the `search`/`filter`/`where` guard, and the action + `skip_query_params` admission rules. Table-scoped admission rules (`only_tables`, `except_tables`) do not apply.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/CacheAdmissionRulesTest.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Sopheak\Core\Services\RecordCacheService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class CacheAdmissionRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('record.cache.enabled', true);
        Config::set('record.cache.prefix', 'sp_laravel_api');
        Config::set('record.cache.ttl', 3600);
        Config::set('record.tables', [
            'products' => new RecordTableType(
                table: 'products',
                pmsName: 'products',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
            ),
        ]);

        SchemaRegistryUtils::refresh();
        Cache::flush();
    }

    public static function dynamicQueryParamProvider(): array
    {
        return [
            'search only' => ['search=foo'],
            'filter only' => ['filter=x'],
            'where only' => ['where=y'],
            'search and filter' => ['search=foo&filter=x'],
            'all three' => ['search=foo&filter=x&where=y'],
        ];
    }

    /**
     * @dataProvider dynamicQueryParamProvider
     */
    public function test_dynamic_query_params_make_a_request_non_cacheable(string $query): void
    {
        $service = app(RecordCacheService::class);

        $this->assertFalse(
            $service->isCacheableRequest(Request::create('/api/products?' . $query, 'GET'), 'products')
        );
    }

    public function test_a_plain_get_is_still_cacheable(): void
    {
        $service = app(RecordCacheService::class);

        $this->assertTrue(
            $service->isCacheableRequest(Request::create('/api/products?limit=5', 'GET'), 'products')
        );
    }

    /**
     * @dataProvider dynamicQueryParamProvider
     */
    public function test_global_function_requests_honour_the_same_guard(string $query): void
    {
        $service = app(RecordCacheService::class);

        $this->assertFalse(
            $service->isCacheableGlobalRequest(Request::create('/api/rpc/report?' . $query, 'GET'))
        );
    }

    public function test_global_function_requests_honour_skip_query_params(): void
    {
        Config::set('record.cache.admission.skip_query_params', ['nocache']);
        $service = app(RecordCacheService::class);

        $this->assertFalse(
            $service->isCacheableGlobalRequest(Request::create('/api/rpc/report?nocache=1', 'GET'))
        );
        $this->assertTrue(
            $service->isCacheableGlobalRequest(Request::create('/api/rpc/report', 'GET'))
        );
    }

    public function test_global_function_requests_are_not_cached_for_writes(): void
    {
        $service = app(RecordCacheService::class);

        $this->assertFalse(
            $service->isCacheableGlobalRequest(Request::create('/api/rpc/report', 'POST'))
        );
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
vendor/bin/phpunit tests/Feature/CacheAdmissionRulesTest.php --no-coverage
```

Expected: the `search only`, `filter only`, `where only`, `search and filter` cases FAIL with `Failed asserting that true is false.`, and every `isCacheableGlobalRequest` case ERRORS with `Call to undefined method`.

- [ ] **Step 3: Fix the guard in `isCacheableRequest()`**

In `src/Services/RecordCacheService.php`, replace line 53:

```php
        if ($request->has(['search', 'filter', 'where'])) {
```

with:

```php
        // hasAny(), not has(): has() with an array is ALL-of, so it only ever
        // rejected a request carrying all three params at once.
        if ($request->hasAny(['search', 'filter', 'where'])) {
```

- [ ] **Step 4: Add `isCacheableGlobalRequest()`**

Add to `src/Services/RecordCacheService.php`, immediately after `isCacheableRequest()`:

```php
    /**
     * Table-less counterpart of isCacheableRequest() for global functions.
     *
     * Global functions have no table, so only_tables/except_tables cannot apply,
     * but the enable flag, method check, dynamic-query guard, action rules and
     * skip_query_params all must -- RecordService used to re-implement a partial
     * version of this inline and silently bypassed the last three.
     */
    public function isCacheableGlobalRequest(Request $request): bool
    {
        if (!RecordConfigService::cacheEnabled()) {
            return false;
        }

        if ('GET' !== $request->method()) {
            return false;
        }

        if ($request->hasAny(['search', 'filter', 'where'])) {
            return false;
        }

        if (!RecordConfigService::cacheAdmissionEnabled()) {
            return true;
        }

        $action = $this->resolveCacheAction($request);

        $onlyActions = array_filter(array_map(strval(...), RecordConfigService::cacheAdmissionOnlyActions()));
        if ([] !== $onlyActions && (null === $action || !in_array($action, $onlyActions, true))) {
            return false;
        }

        $exceptActions = array_filter(array_map(strval(...), RecordConfigService::cacheAdmissionExceptActions()));
        if (null !== $action && [] !== $exceptActions && in_array($action, $exceptActions, true)) {
            return false;
        }

        foreach (RecordConfigService::cacheAdmissionSkipQueryParams() as $param) {
            if (!is_string($param) && !is_int($param)) {
                continue;
            }

            if ($request->query->has((string) $param)) {
                return false;
            }
        }

        return true;
    }
```

- [ ] **Step 5: Route the global function path through it**

In `src/Services/RecordService.php`, replace line 811:

```php
        if (!$disableCache && RecordConfigService::cacheEnabled() && 'GET' === $request->method() && !$request->has(['search', 'filter', 'where'])) {
```

with:

```php
        if (!$disableCache && $this->cacheService()->isCacheableGlobalRequest($request)) {
```

- [ ] **Step 6: Run the tests to verify they pass**

```bash
vendor/bin/phpunit tests/Feature/CacheAdmissionRulesTest.php --no-coverage
```

Expected: PASS (13 tests)

- [ ] **Step 7: Run the full suite**

```bash
vendor/bin/phpunit --no-coverage
```

Expected: 0 failures.

- [ ] **Step 8: Commit**

```bash
git add src/Services/RecordCacheService.php src/Services/RecordService.php tests/Feature/CacheAdmissionRulesTest.php
git commit -m "fix(cache): reject any dynamic query param, not only all three at once

Request::has() with an array is ALL-of, so the search/filter/where guard
only fired when a request carried all three and the dynamic queries it
exists to keep out of the cache were being cached.

Global functions re-implemented a partial copy of this check inline,
bypassing the admission rules and skip_query_params entirely; they now go
through the shared isCacheableGlobalRequest()."
```

---

### Task 6: Function caches declare the tables they read (C6)

`record_func_global:*` keys hang off `ns:global_function:{name}:tenant:{t}`, while `clearCacheForTables()` only bumps table namespaces. A global function that aggregates over `products` can therefore only be invalidated by a write to that same global function — ordinary CRUD writes to `products` never reach it.

Fix by letting a cache entry carry an explicit dependency list whose versions join the token. `clearCacheTables` — already declared on `RecordFunctionType` for the write direction — becomes the read-direction dependency set too.

**Files:**
- Modify: `src/Services/QueryCacheService.php` — `get()`, `put()`, `remember()`, `forget()`, `resolveNamespaceToken()`; add `resolveDependencyToken()`
- Modify: `src/Services/RecordCacheService.php` — add `functionCacheDependencies()`
- Modify: `src/Services/RecordService.php:711-723, 750-761` (table function), `:811-822, :844-851` (global function)
- Test: `tests/Feature/CacheInvalidationCorrectnessTest.php` (extend)

**Interfaces:**
- Consumes: `CacheInvalidationCorrectnessTest` from Task 1.
- Produces:
  - `QueryCacheService::get(string $key, array $dependencies = []): mixed`
  - `QueryCacheService::put(string $key, mixed $value, ?int $ttl = null, array $dependencies = []): bool`
  - `QueryCacheService::remember(string $key, callable $callback, ?int $ttl = null, array $dependencies = []): mixed`
  - `QueryCacheService::forget(string $key, array $dependencies = []): bool`
  - A dependency is `['scope' => string, 'name' => string, 'tenant' => ?string]`. Malformed entries are skipped, not fatal. An empty list reproduces the pre-existing token exactly, so every current caller is unaffected.
  - `RecordCacheService::functionCacheDependencies(array|string|null $tables, mixed $tenantId, bool $tenantEnabled): array` — normalises `clearCacheTables` into that dependency shape.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/CacheInvalidationCorrectnessTest.php`:

```php
    public function test_a_dependent_cache_entry_is_invalidated_by_a_table_clear(): void
    {
        $globalKey = 'record_func_global:function:sales_report:tenant:acme:hash:abc';
        $dependencies = [['scope' => 'table', 'name' => 'products', 'tenant' => 'acme']];

        QueryCacheService::put($globalKey, ['data' => ['total' => 1], 'status' => 200], 3600, $dependencies);
        $this->assertNotNull(QueryCacheService::get($globalKey, $dependencies));

        app(RecordCacheService::class)->clearCacheForTables(['products'], 'acme');

        $this->assertNull(
            QueryCacheService::get($globalKey, $dependencies),
            'A cache entry depending on products must die when products is cleared'
        );
    }

    public function test_a_dependent_cache_entry_survives_an_unrelated_table_clear(): void
    {
        $globalKey = 'record_func_global:function:sales_report:tenant:acme:hash:abc';
        $dependencies = [['scope' => 'table', 'name' => 'products', 'tenant' => 'acme']];

        QueryCacheService::put($globalKey, ['data' => ['total' => 1], 'status' => 200], 3600, $dependencies);

        QueryCacheService::invalidateTableForTenant('orders', 'acme');

        $this->assertNotNull(
            QueryCacheService::get($globalKey, $dependencies),
            'An unrelated table clear must not invalidate the entry'
        );
    }

    public function test_dependencies_are_order_independent(): void
    {
        $key = 'record_func_global:function:sales_report:tenant:acme:hash:abc';
        $forward = [
            ['scope' => 'table', 'name' => 'products', 'tenant' => 'acme'],
            ['scope' => 'table', 'name' => 'orders', 'tenant' => 'acme'],
        ];
        $reversed = array_reverse($forward);

        QueryCacheService::put($key, 'cached', 3600, $forward);

        $this->assertSame(
            'cached',
            QueryCacheService::get($key, $reversed),
            'Declaring the same dependencies in a different order must hit the same entry'
        );
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
vendor/bin/phpunit tests/Feature/CacheInvalidationCorrectnessTest.php --no-coverage
```

Expected: `test_a_dependent_cache_entry_is_invalidated_by_a_table_clear` FAILS (`Failed asserting that Array ... is null.`). The other two pass trivially today because the extra argument is currently ignored — they exist to lock in behaviour after the change.

- [ ] **Step 3: Add dependency support to `QueryCacheService`**

In `src/Services/QueryCacheService.php`, change the four public accessors to accept and forward a dependency list:

```php
    public static function remember(string $key, callable $callback, ?int $ttl = null, array $dependencies = []): mixed
    {
        // If caching is disabled, execute callback directly
        if (!self::isCacheEnabled()) {
            return $callback();
        }

        $ttl ??= self::getCacheTtl();
        $cacheKey = self::getCachePrefix() . $key . ':' . self::resolveNamespaceToken($key, $dependencies);

        try {
            return Cache::remember($cacheKey, $ttl, $callback);
        } catch (Exception) {
            return $callback();
        }
    }

    public static function put(string $key, mixed $value, ?int $ttl = null, array $dependencies = []): bool
    {
        // If caching is disabled, return true (no-op)
        if (!self::isCacheEnabled()) {
            return true;
        }

        $ttl ??= self::getCacheTtl();
        $cacheKey = self::getCachePrefix() . $key . ':' . self::resolveNamespaceToken($key, $dependencies);

        try {
            $stored = Cache::put($cacheKey, $value, $ttl);
            if ($stored) {
                self::incrementRequestStat('cache_puts');
            }

            return $stored;
        } catch (Exception) {
            return false;
        }
    }

    public static function get(string $key, array $dependencies = []): mixed
    {
        // If caching is disabled, return null (cache miss)
        if (!self::isCacheEnabled()) {
            return null;
        }

        $cacheKey = self::getCachePrefix() . $key . ':' . self::resolveNamespaceToken($key, $dependencies);

        try {
            $value = Cache::get($cacheKey);
            self::incrementRequestStat(null !== $value ? 'cache_hits' : 'cache_misses');

            return $value;
        } catch (Exception) {
            return null;
        }
    }

    public static function forget(string $key, array $dependencies = []): bool
    {
        // If caching is disabled, return true (no-op)
        if (!self::isCacheEnabled()) {
            return true;
        }

        $cacheKey = self::getCachePrefix() . $key . ':' . self::resolveNamespaceToken($key, $dependencies);

        try {
            return Cache::forget($cacheKey);
        } catch (Exception) {
            return false;
        }
    }
```

- [ ] **Step 4: Append the dependency component to the token**

In `src/Services/QueryCacheService.php`, change the `resolveNamespaceToken()` signature and both `return sprintf(...)` statements, then add the new helper below it:

```php
    private static function resolveNamespaceToken(string $key, array $dependencies = []): string
    {
        $dependencyToken = self::resolveDependencyToken($dependencies);

        $parsed = self::parseScopeFromKey($key);
        if (null === $parsed) {
            return 'v1' . $dependencyToken;
        }

        $globalVersion = self::getNamespaceVersion(scope: $parsed['scope'], name: $parsed['name']);
        $tenantVersion = 1;
        if ('' !== $parsed['tenant']) {
            $tenantVersion = self::getNamespaceVersion(scope: $parsed['scope'], name: $parsed['name'], tenantKey: $parsed['tenant']);
        }

        if ('record' === $parsed['scope'] && isset($parsed['record_id'])) {
            $recordVersion = self::getNamespaceVersion(
                scope: 'record',
                name: $parsed['name'],
                tenantKey: '' !== $parsed['tenant'] ? $parsed['tenant'] : null,
                recordId: $parsed['record_id']
            );

            // A single record's cache must also die when the whole table is cleared.
            // Without these two components, clearTableCache() busts list caches and
            // leaves every record_show entry for the table stale until TTL.
            $tableGlobalVersion = self::getNamespaceVersion(scope: 'table', name: $parsed['name']);
            $tableTenantVersion = '' !== $parsed['tenant']
                ? self::getNamespaceVersion(scope: 'table', name: $parsed['name'], tenantKey: $parsed['tenant'])
                : 1;

            return sprintf(
                'v%s.%s.%s.t%s.%s',
                $globalVersion,
                $tenantVersion,
                $recordVersion,
                $tableGlobalVersion,
                $tableTenantVersion
            ) . $dependencyToken;
        }

        return sprintf('v%s.%s', $globalVersion, $tenantVersion) . $dependencyToken;
    }

    /**
     * Hash the versions of every namespace this entry declares a dependency on.
     *
     * This is what lets a cached function die when a table it reads is written to.
     * Parts are sorted so declaration order does not change the key. An empty list
     * yields an empty string, reproducing the pre-dependency token exactly.
     *
     * @param array<int, array<string, mixed>> $dependencies
     */
    private static function resolveDependencyToken(array $dependencies): string
    {
        if ([] === $dependencies) {
            return '';
        }

        $parts = [];
        foreach ($dependencies as $dependency) {
            if (!is_array($dependency)) {
                continue;
            }

            $scope = isset($dependency['scope']) ? (string) $dependency['scope'] : '';
            $name = isset($dependency['name']) ? (string) $dependency['name'] : '';
            if ('' === $scope || '' === $name) {
                continue;
            }

            $tenant = isset($dependency['tenant']) && '' !== (string) $dependency['tenant']
                ? (string) $dependency['tenant']
                : null;

            $version = self::getNamespaceVersion(scope: $scope, name: $name, tenantKey: $tenant);
            $parts[] = sprintf('%s:%s:%s:%s', $scope, $name, $tenant ?? '-', $version);
        }

        if ([] === $parts) {
            return '';
        }

        sort($parts);

        return '.d' . substr(md5(implode('|', $parts)), 0, 12);
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

```bash
vendor/bin/phpunit tests/Feature/CacheInvalidationCorrectnessTest.php --no-coverage
```

Expected: PASS (9 tests)

- [ ] **Step 6: Add the dependency builder to `RecordCacheService`**

Add to `src/Services/RecordCacheService.php`, immediately after `clearCacheForTables()`:

```php
    /**
     * Turn a function's declared clearCacheTables into a cache dependency list.
     *
     * clearCacheTables already tells us which tables a function touches on write;
     * the same list is what its cached result depends on for reads. Declaring it
     * both ways is what makes a CRUD write to one of those tables invalidate the
     * function's cached output.
     *
     * @return array<int, array<string, string|null>>
     */
    public function functionCacheDependencies(array|string|null $tables, mixed $tenantId, bool $tenantEnabled): array
    {
        if (null === $tables || [] === $tables || '' === $tables) {
            return [];
        }

        $tableList = is_array($tables) ? $tables : array_filter(array_map(trim(...), explode(',', $tables)));
        $tenantKey = $this->resolveTenantCacheKey(tenantId: $tenantId, tenantEnabled: $tenantEnabled);

        $dependencies = [];
        foreach ($tableList as $table) {
            if (!is_string($table) || '' === $table) {
                continue;
            }

            $dependencies[] = ['scope' => 'table', 'name' => $table, 'tenant' => $tenantKey];
        }

        return $dependencies;
    }
```

- [ ] **Step 7: Declare dependencies on both function paths**

In `src/Services/RecordService.php::executeTableFunction()`, the `clearCacheTables` value is currently read at lines 736-745 — *after* the cache lookup. Hoist that read above the lookup so it can be used for both. Replace lines 707-723 with:

```php
        $tenantEnabled = $this->shouldApplyTenantId($tableSchema);
        $tenantId = $tenantEnabled ? $this->resolveTenantFromRequest($request, $tableSchema) : null;
        $cacheKey = null;

        $clearCacheTables = null;
        if ($functionConfig instanceof RecordFunctionType) {
            $clearCacheTables = $functionConfig->clearCacheTables;
        } elseif (is_array($functionConfig)) {
            $clearCacheTables = $functionConfig['clearCacheTables'] ?? null;
        }

        if (null === $clearCacheTables || [] === $clearCacheTables || '' === $clearCacheTables) {
            $clearCacheTables = $table;
        }

        $cacheDependencies = $this->cacheService()->functionCacheDependencies($clearCacheTables, $tenantId, $tenantEnabled);

        if (!$disableCache && $this->isCacheableRequest($request, $table)) {
            $cacheKey = $this->generateTableFunctionCacheKey(
                table: $table,
                functionName: $functionName,
                queryParams: $request->query(),
                tenantId: $tenantId,
                tenantEnabled: $tenantEnabled
            );
            $cached = QueryCacheService::get($cacheKey, $cacheDependencies);
            if (is_array($cached) && isset($cached['data'], $cached['status'])) {
                return new JsonResponse($cached['data'], $cached['status'], $cached['headers'] ?? []);
            }
        }
```

Then replace the now-duplicated block at lines 736-745 (inside the write branch) so it only clears:

```php
            $this->cacheService()->clearCacheForTables($clearCacheTables, $tenantId);
```

And pass the dependencies when storing (line 756):

```php
            QueryCacheService::put($cacheKey, [
                'data' => $response->getData(true),
                'status' => $response->getStatusCode(),
                'headers' => $response->headers->all(),
            ], $ttl, $cacheDependencies);
```

Apply the same three edits to `executeGlobalFunction()`. Hoist its `clearCacheTables` read (lines 834-839) above the cache lookup, but **without** the `?? $table` fallback — a global function has no table, so an undeclared list means no dependencies:

```php
        $tenantEnabled = RecordConfigService::enableTenantId();
        $tenantId = $tenantEnabled ? RecordUtils::resolveTenantIdFromRequest($request) : null;
        $cacheKey = null;

        $clearCacheTables = null;
        if ($functionConfig instanceof RecordFunctionType) {
            $clearCacheTables = $functionConfig->clearCacheTables;
        } elseif (is_array($functionConfig)) {
            $clearCacheTables = $functionConfig['clearCacheTables'] ?? null;
        }

        $cacheDependencies = $this->cacheService()->functionCacheDependencies($clearCacheTables, $tenantId, $tenantEnabled);

        if (!$disableCache && $this->cacheService()->isCacheableGlobalRequest($request)) {
            $cacheKey = $this->generateGlobalFunctionCacheKey(
                functionName: $functionName,
                queryParams: $request->query(),
                tenantId: $tenantId,
                tenantEnabled: $tenantEnabled
            );
            $cached = QueryCacheService::get($cacheKey, $cacheDependencies);
            if (is_array($cached) && isset($cached['data'], $cached['status'])) {
                return new JsonResponse($cached['data'], $cached['status'], $cached['headers'] ?? []);
            }
        }
```

with the write branch reduced to `$this->cacheService()->clearCacheForTables($clearCacheTables, $tenantId);` and the store call passing `$cacheDependencies` as the fourth argument to `QueryCacheService::put()`.

- [ ] **Step 8: Run the full suite**

```bash
vendor/bin/phpunit --no-coverage
```

Expected: 0 failures.

- [ ] **Step 9: Commit**

```bash
git add src/Services/QueryCacheService.php src/Services/RecordCacheService.php src/Services/RecordService.php tests/Feature/CacheInvalidationCorrectnessTest.php
git commit -m "feat(cache): let cached entries declare table dependencies

Global-function caches hung off their own namespace only, so a cached
report aggregating over products could never be invalidated by a CRUD
write to products -- only by a write to the same function.

Cache entries can now declare dependencies whose namespace versions join
the key token. Functions derive theirs from the clearCacheTables they
already declare, so the list now works in both directions."
```

---

### Task 7: Failed invalidations are logged (A3)

Every cache error is swallowed — `catch (Exception)` in the four accessors and a bare empty catch in `bumpNamespace()`. A failed *read* is a cache miss and is genuinely harmless. A failed *bump* is a correctness event: if reads keep succeeding while the counter write fails (read-only replica, Redis OOM under `noeviction`, a missing `cache` table, a full disk), stale data is served indefinitely with no signal anywhere. This shape was hit during the audit — with no `cache` table the whole module degraded to a silent no-op and reported nothing.

**Files:**
- Modify: `src/Services/QueryCacheService.php` — `bumpNamespace()`, add `reportInvalidationFailure()`
- Test: `tests/Feature/CacheInvalidationCorrectnessTest.php` (extend)

**Interfaces:**
- Consumes: `bumpNamespace()` and `incrementNamespaceVersion()` from Task 2.
- Produces: `QueryCacheService::reportInvalidationFailure(string $key, Throwable $e): void` (private).

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/CacheInvalidationCorrectnessTest.php`. Add `use Illuminate\Support\Facades\Log;` and `use Mockery;` to the imports:

```php
    public function test_a_failed_namespace_bump_is_logged(): void
    {
        Cache::shouldReceive('add')->andThrow(new \RuntimeException('cache store unavailable'));

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'cache namespace bump failed')
                    && str_contains((string) $context['namespace_key'], 'ns:table:products')
                    && 'cache store unavailable' === $context['exception'];
            });

        QueryCacheService::invalidateTableForTenant('products', 'acme');
    }
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
vendor/bin/phpunit tests/Feature/CacheInvalidationCorrectnessTest.php --filter test_a_failed_namespace_bump_is_logged --no-coverage
```

Expected: FAIL with a Mockery expectation error — `Log::warning()` was expected once but called 0 times.

- [ ] **Step 3: Log the failure**

In `src/Services/QueryCacheService.php`, add `use Illuminate\Support\Facades\Log;` to the imports, then change the catch in `bumpNamespace()` from:

```php
        } catch (Exception) {
        }
```

to:

```php
        } catch (Throwable $e) {
            self::reportInvalidationFailure($key, $e);
        }
```

and add this method immediately after `incrementNamespaceVersion()`:

```php
    /**
     * A failed bump is a correctness event, not a cache miss.
     *
     * Reads are allowed to degrade silently -- a failed read just means a miss.
     * A failed bump means the old cached value stays live, so the API keeps
     * serving data it has already been told is wrong. That must not be silent.
     */
    private static function reportInvalidationFailure(string $key, Throwable $e): void
    {
        try {
            Log::warning('sp-laravel-api: cache namespace bump failed, stale data may be served', [
                'namespace_key' => $key,
                'exception' => $e->getMessage(),
            ]);
        } catch (Throwable) {
        }
    }
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
vendor/bin/phpunit tests/Feature/CacheInvalidationCorrectnessTest.php --no-coverage
```

Expected: PASS (10 tests)

- [ ] **Step 5: Run the full suite**

```bash
vendor/bin/phpunit --no-coverage
```

Expected: 0 failures.

- [ ] **Step 6: Commit**

```bash
git add src/Services/QueryCacheService.php tests/Feature/CacheInvalidationCorrectnessTest.php
git commit -m "fix(cache): log failed namespace bumps instead of swallowing them

A failed read is a cache miss and is harmless. A failed bump means the
stale value stays live, so the API keeps serving data it was told is
wrong -- indefinitely, and with no signal anywhere."
```

---

### Task 8: Request-scoped state lives on a real scoped context (A6)

The version memo and stats live in `request()->attributes`. In a queue worker, Octane or Artisan, `request()` is a long-lived singleton, so a memoized version never refreshes: the worker memoizes v5, another process bumps to v6, and the worker keeps resolving v5 and serving stale entries for the rest of its life.

`Container::forgetScopedInstances()` is called by `QueueServiceProvider` between jobs, which makes `app()->scoped()` the correct home for this state. HTTP requests need an explicit reset because the container is not rebuilt per request under Octane or in Testbench.

**Files:**
- Create: `src/Support/CacheRequestContext.php`
- Modify: `src/CoreSpLaravelApiProvider.php` — scoped binding + `RouteMatched` reset listener
- Modify: `src/Services/QueryCacheService.php` — replace `getRequestAttribute()`/`setRequestAttribute()` usage
- Test: `tests/Feature/CacheRequestContextTest.php` (new)

**Interfaces:**
- Consumes: `memoizeNamespaceVersion()`, `getNamespaceVersion()`, `incrementRequestStat()`, `requestStats()` from Tasks 2 and 7.
- Produces: `Sopheak\Core\Support\CacheRequestContext` with `namespaceVersion(string $key): ?int`, `rememberNamespaceVersion(string $key, int $version): void`, `stats(): array`, `incrementStat(string $key): void`, `reset(): void`.
- `QueryCacheService::requestStats()` keeps its exact current signature and return shape — `RecordService.php:2277` and `:2917` expose it as `meta.debug.cache_stats`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/CacheRequestContextTest.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Services\QueryCacheService;
use Sopheak\Core\Support\CacheRequestContext;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class CacheRequestContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('tenant_id')->nullable();
            $table->timestamps();
        });

        Config::set('record.enable_tenant_id', true);
        Config::set('record.tenant_column', 'tenant_id');
        Config::set('record.tenant_header', 'X-Tenant-ID');
        Config::set('record.cache.enabled', true);
        Config::set('record.cache.prefix', 'sp_laravel_api');
        Config::set('record.cache.ttl', 3600);
        Config::set('record.tables', [
            'products' => new RecordTableType(
                table: 'products',
                pmsName: 'products',
                hasTenantId: true,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
            ),
        ]);

        SchemaRegistryUtils::refresh();
        Cache::flush();
    }

    public function test_the_context_is_resolvable_and_starts_empty(): void
    {
        $context = app(CacheRequestContext::class);

        $this->assertNull($context->namespaceVersion('anything'));
        $this->assertSame([], $context->stats());
    }

    public function test_reset_clears_memoized_versions_and_stats(): void
    {
        $context = app(CacheRequestContext::class);
        $context->rememberNamespaceVersion('ns:table:products', 7);
        $context->incrementStat('cache_hits');

        $this->assertSame(7, $context->namespaceVersion('ns:table:products'));

        $context->reset();

        $this->assertNull($context->namespaceVersion('ns:table:products'));
        $this->assertSame([], $context->stats());
    }

    public function test_a_memoized_version_does_not_leak_across_http_requests(): void
    {
        DB::table('products')->insert([
            ['id' => 1, 'name' => 'Widget', 'tenant_id' => 'acme', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->withHeaders(['X-Tenant-ID' => 'acme'])->getJson('/api/products')->assertStatus(200);

        // An out-of-band write, exactly as another process would do it.
        DB::table('products')->where('id', 1)->update(['name' => 'Updated']);
        QueryCacheService::invalidateTableForTenant('products', 'acme');

        $this->withHeaders(['X-Tenant-ID' => 'acme'])
            ->getJson('/api/products')
            ->assertStatus(200)
            ->assertJsonPath('data.0.name', 'Updated');
    }

    public function test_request_stats_keep_their_public_shape(): void
    {
        $stats = QueryCacheService::requestStats();

        foreach ([
            'cache_hits',
            'cache_misses',
            'cache_puts',
            'namespace_reads',
            'namespace_memo_hits',
            'invalidation_bumps',
            'invalidation_dedupe_hits',
        ] as $key) {
            $this->assertArrayHasKey($key, $stats);
            $this->assertIsInt($stats[$key]);
        }
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
vendor/bin/phpunit tests/Feature/CacheRequestContextTest.php --no-coverage
```

Expected: the first three ERROR with `Class "Sopheak\Core\Support\CacheRequestContext" does not exist`.

- [ ] **Step 3: Create the context class**

Create `src/Support/CacheRequestContext.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Support;

/**
 * Request- and job-scoped scratch space for the cache layer.
 *
 * This used to live in request()->attributes, which is wrong outside PHP-FPM:
 * request() is a long-lived singleton in queue workers, Octane and Artisan, so a
 * memoized namespace version was never refreshed and the process kept resolving
 * a version another process had already moved past -- serving stale entries for
 * the rest of its life.
 *
 * Registered with the container as a scoped binding. QueueServiceProvider calls
 * forgetScopedInstances() between jobs; HTTP requests reset it on RouteMatched,
 * because the container is not rebuilt per request under Octane or in Testbench.
 */
final class CacheRequestContext
{
    /** @var array<string, int> */
    private array $namespaceVersions = [];

    /** @var array<string, int> */
    private array $stats = [];

    public function namespaceVersion(string $key): ?int
    {
        return $this->namespaceVersions[$key] ?? null;
    }

    public function rememberNamespaceVersion(string $key, int $version): void
    {
        $this->namespaceVersions[$key] = $version;
    }

    /**
     * @return array<string, int>
     */
    public function stats(): array
    {
        return $this->stats;
    }

    public function incrementStat(string $key): void
    {
        $this->stats[$key] = ($this->stats[$key] ?? 0) + 1;
    }

    public function reset(): void
    {
        $this->namespaceVersions = [];
        $this->stats = [];
    }
}
```

- [ ] **Step 4: Register the binding and the reset hook**

In `src/CoreSpLaravelApiProvider.php`, add to the imports:

```php
use Illuminate\Routing\Events\RouteMatched;
use Sopheak\Core\Support\CacheRequestContext;
```

In `register()`, beside the existing `$this->app->singleton(QueryCacheService::class);` (line 65):

```php
        $this->app->scoped(CacheRequestContext::class);
```

In `boot()`, beside the existing `Event::listen(...)` calls (line 132):

```php
        // The container is not rebuilt per request under Octane or in Testbench, so
        // scoped bindings alone are not enough for HTTP. Queue jobs are covered by
        // QueueServiceProvider's forgetScopedInstances().
        Event::listen(RouteMatched::class, static function (): void {
            app(CacheRequestContext::class)->reset();
        });
```

- [ ] **Step 5: Point `QueryCacheService` at the context**

In `src/Services/QueryCacheService.php`, add `use Sopheak\Core\Support\CacheRequestContext;` to the imports and delete the two now-unused constants `REQUEST_NAMESPACE_MEMO_KEY` and `REQUEST_STATS_KEY` (`REQUEST_BUMPED_NAMESPACES_KEY` was already removed in Task 2). Keep `DEFAULT_STATS`.

Replace `requestStats()`, `memoizeNamespaceVersion()`, `incrementRequestStat()`, `getRequestAttribute()` and `setRequestAttribute()` with:

```php
    public static function requestStats(): array
    {
        return array_merge(self::DEFAULT_STATS, self::context()?->stats() ?? []);
    }

    private static function memoizeNamespaceVersion(string $key, int $version): void
    {
        self::context()?->rememberNamespaceVersion($key, $version);
    }

    private static function incrementRequestStat(string $key): void
    {
        self::context()?->incrementStat($key);
    }

    /**
     * The context is unavailable before the container boots (and in a few console
     * paths). Memoization and stats are both optional accelerations, so returning
     * null just makes every namespace read go to the cache store -- correct, only
     * slower.
     */
    private static function context(): ?CacheRequestContext
    {
        try {
            return app(CacheRequestContext::class);
        } catch (Throwable) {
            return null;
        }
    }
```

Then change the memo read inside `getNamespaceVersion()` (currently lines 327-333) to:

```php
        $memoized = self::context()?->namespaceVersion($key);
        if (null !== $memoized) {
            self::incrementRequestStat('namespace_memo_hits');

            return $memoized;
        }
```

- [ ] **Step 6: Run the tests to verify they pass**

```bash
vendor/bin/phpunit tests/Feature/CacheRequestContextTest.php --no-coverage
```

Expected: PASS (4 tests)

- [ ] **Step 7: Run the full suite**

```bash
vendor/bin/phpunit --no-coverage
```

Expected: 0 failures. `QueryCacheServiceNamespaceInvalidationTest::test_it_memoizes_namespace_versions_within_the_current_request` is the one most likely to break — it is a unit test with no routed request, so `RouteMatched` never fires and the scoped context persists for the whole test. That is the correct behaviour; if it fails, verify the assertion still holds rather than reverting.

- [ ] **Step 8: Commit**

```bash
git add src/Support/CacheRequestContext.php src/CoreSpLaravelApiProvider.php src/Services/QueryCacheService.php tests/Feature/CacheRequestContextTest.php
git commit -m "refactor(cache): move request-scoped state off the Request object

The version memo and stats lived in request()->attributes. request() is a
long-lived singleton in queue workers, Octane and Artisan, so a memoized
namespace version never refreshed: the worker kept resolving a version
another process had already moved past and served stale entries for the
rest of its life.

They now live in a container-scoped CacheRequestContext, which
QueueServiceProvider resets between jobs and RouteMatched resets per HTTP
request."
```

---

### Task 9: Remove redundant invalidation and fix two honesty bugs (A7, A8, A9)

Three small cleanups that only became visible once the dedupe was removed in Task 2.

**A7** — a single write invalidates three times. `RecordService::createRecord()` already calls both `invalidateTableCache()` and `invalidateRecordCache()` (lines 87-89) before returning, and `processPostWriteLogic()` fires an event that `InvalidateRecordCacheListener` turns into a third clear. The controller's own `invalidateTableCache()` call is strictly redundant — and weaker than the service's, because it omits the record-scope bump. Removing it costs nothing. The service-level and listener-level calls both stay: a client calling `RecordService::createRecord()` directly never goes through `processPostWriteLogic()`, so the listener alone would not cover them.

**A8** — `invalidateTableFunctionForTenant()` accepts a `$functionName` it never uses.

**A9** — `getRecord()` uses `if ($cachedRecord)`, so a falsy cached payload is treated as a miss and re-queried on every request. The list path already uses the correct `null !==` check.

**Files:**
- Modify: `src/Http/Controllers/Concerns/HasCrudOperations.php:261, 362, 455, 537, 613`
- Modify: `src/Services/QueryCacheService.php:225-228`
- Modify: `src/Services/RecordService.php:2980`
- Test: `tests/Feature/CacheInvalidationCorrectnessTest.php` (extend)

**Interfaces:**
- Consumes: `CacheInvalidationCorrectnessTest` from Task 1.
- Produces: `QueryCacheService::invalidateTableFunctionForTenant(string $table, string $functionName, string $tenantKey): int` keeps its exact signature — it is public API and the argument stays accepted, just documented as unused.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/CacheInvalidationCorrectnessTest.php`. Add `use Illuminate\Support\Facades\DB;` to the imports:

```php
    public function test_an_http_write_still_invalidates_both_list_and_show_caches(): void
    {
        DB::table('products')->insert([
            ['id' => 1, 'name' => 'Widget', 'tenant_id' => 'acme', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->withHeaders(['X-Tenant-ID' => 'acme'])
            ->getJson('/api/products')
            ->assertStatus(200)
            ->assertJsonPath('data.0.name', 'Widget');

        $this->withHeaders(['X-Tenant-ID' => 'acme'])
            ->getJson('/api/products/1')
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Widget');

        $this->putJson('/api/products/1', ['name' => 'Updated'], ['X-Tenant-ID' => 'acme'])
            ->assertStatus(200);

        $this->withHeaders(['X-Tenant-ID' => 'acme'])
            ->getJson('/api/products')
            ->assertStatus(200)
            ->assertJsonPath('data.0.name', 'Updated');

        $this->withHeaders(['X-Tenant-ID' => 'acme'])
            ->getJson('/api/products/1')
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Updated');
    }

    public function test_an_empty_cached_record_is_served_from_cache(): void
    {
        $showKey = 'record_show:table:products:id:9:tenant:acme:select:abc';

        QueryCacheService::put($showKey, [], 3600);

        $this->assertSame(
            [],
            QueryCacheService::get($showKey),
            'An empty cached payload is a hit, not a miss'
        );
    }
```

- [ ] **Step 2: Run the tests to verify they pass before the change**

```bash
vendor/bin/phpunit tests/Feature/CacheInvalidationCorrectnessTest.php --no-coverage
```

Expected: PASS. These are guard tests — they must keep passing *through* the cleanup. Run them first to confirm the baseline, then again after each edit below.

- [ ] **Step 3: Remove the redundant controller-level invalidation**

In `src/Http/Controllers/Concerns/HasCrudOperations.php`, delete this line at each of lines 261, 362, 455, 537 and 613 (five occurrences, identical text):

```php
                $this->recordService->invalidateTableCache($table, $tenantId, $this->recordService->shouldApplyTenantId($tableSchema));
```

`RecordService` already performs a strictly stronger invalidation (table **and** record scope) inside `createRecord()`, `updateRecord()`, `deleteRecord()`, `restoreRecord()` and `forceDeleteRecord()` before returning, so nothing is lost. After deleting, check whether `$tableSchema` is still used inside each closure's `use (...)` list; if a closure no longer references it, remove it from the `use` clause so PHPStan does not flag an unused binding.

- [ ] **Step 4: Document the unused argument on the function invalidator**

In `src/Services/QueryCacheService.php`, replace `invalidateTableFunctionForTenant()` (lines 225-228) with:

```php
    /**
     * Invalidate a table function's cache.
     *
     * $functionName is accepted for call-site clarity and API stability but is
     * deliberately unused: a table function's output can depend on any row in the
     * table, so the whole table namespace is bumped rather than one function's.
     */
    public static function invalidateTableFunctionForTenant(string $table, string $functionName, string $tenantKey): int
    {
        unset($functionName);

        return self::invalidateTableForTenant($table, $tenantKey);
    }
```

- [ ] **Step 5: Fix the falsy cached-record check**

In `src/Services/RecordService.php`, change line 2980 inside `getRecord()` from:

```php
            if ($cachedRecord) {
```

to:

```php
            if (null !== $cachedRecord) {
```

- [ ] **Step 6: Run the tests to verify they still pass**

```bash
vendor/bin/phpunit tests/Feature/CacheInvalidationCorrectnessTest.php --no-coverage
```

Expected: PASS (12 tests)

- [ ] **Step 7: Run the full suite and the quality gate**

```bash
vendor/bin/phpunit --no-coverage
composer quality
```

Expected: 0 test failures; `format-check`, `analyse` and `test` all clean. If `format-check` fails, run `composer format` and re-run.

- [ ] **Step 8: Commit**

```bash
git add src/Http/Controllers/Concerns/HasCrudOperations.php src/Services/QueryCacheService.php src/Services/RecordService.php tests/Feature/CacheInvalidationCorrectnessTest.php
git commit -m "refactor(cache): drop redundant controller invalidation, fix two honesty bugs

The controllers re-invalidated the table after RecordService had already
invalidated both the table and record scopes -- a strictly weaker
duplicate of work just done, previously masked by the bump dedupe.

Also documents the deliberately-unused \$functionName on
invalidateTableFunctionForTenant(), and fixes getRecord() treating an
empty cached payload as a miss."
```

---

## Verification

After all nine tasks, confirm the original symptoms are gone:

- [ ] **Full quality gate**

```bash
composer quality
```

Expected: `format-check` → `analyse` → `test` all pass. 634 baseline tests plus roughly 30 new ones, 0 failures.

- [ ] **Confirm against the `database` driver**

The client runs `CACHE_STORE=database`, and every existing cache test runs on `array`. Add a scratch verification (do not commit it) that runs `CacheInvalidationCorrectnessTest` with a real `cache` table:

```php
Schema::create('cache', function (Blueprint $t): void {
    $t->string('key')->primary();
    $t->mediumText('value');
    $t->integer('expiration');
});
Config::set('cache.default', 'database');
Cache::purge('database');
```

Expected: identical results to the `array` run. `Cache::add()` + `Cache::increment()` was verified to behave the same on array, database and file.

- [ ] **Note for the follow-up plan**

`A1` (orphaned versioned entries are never reclaimed on `database`/`file`) is still open and will get worse in proportion to write volume, because every task above increases how often namespaces are bumped. This is the first item of the deferred storage-layer plan and should be scheduled soon after this one lands.

## Self-Review

**Spec coverage** — C1 → Task 2. C2 → Task 3. C3 → Task 1. C4 → Task 4. C5 → Task 5. C6 → Task 6. A2 → Task 2. A3 → Task 7. A6 → Task 8. A7, A8, A9 → Task 9. A1, A4, A5 explicitly deferred with rationale. No gaps.

**Type consistency** — `incrementNamespaceVersion(string $key): int` introduced in Task 2, reused in Task 7. `resolveNamespaceToken(string $key, array $dependencies = [])` extended in Task 6 with the Task 3 record branch preserved verbatim. `queryFingerprint(Request $request): string` defined in Task 4 and used at five call sites in the same task. `CacheRequestContext` method names (`namespaceVersion`, `rememberNamespaceVersion`, `stats`, `incrementStat`, `reset`) are identical in the class definition (Task 8 Step 3), the provider (Step 4), `QueryCacheService` (Step 5) and the test (Step 1).

**Ordering** — Task 3 rewrites the record branch of `resolveNamespaceToken()`; Task 6 rewrites the whole method and reproduces that branch in full, so Task 6 must not run before Task 3. Task 2 must precede Task 7 (which changes the catch block it introduces). Task 9 must run last, since its guard test depends on Tasks 2 and 3 for show-cache invalidation to work at all.

---

## Outcome

Executed 2026-08-13 across 20 commits, `ef2c787` → `120f38e`. Test suite 634 → 679, all passing.
All six correctness defects (C1–C6) and A2, A3, A6, A7, A8, A9 are fixed and regression-tested.

Nine per-task reviews plus a whole-branch review ran. Five findings originated in this plan's own
text rather than in the implementations, and were resolved by explicit ruling:

1. **Task 2** — the plan's `Cache::add($key, 1)` never reaches the store's atomic `add()`;
   `Repository::add()` only delegates when a TTL is passed. Fixed with an explicit 10-year TTL.
2. **Task 4** — `queryFingerprint()` read `$request->query()`, blind to params arriving in a JSON
   body on a GET, which `input()`/`boolean()` do see. Fixed with `json()->all() + query()`.
3. **Task 6** — `functionCacheDependencies()` resolved tenancy once from the caller while
   invalidation resolves it per table, so a dependency could name a namespace nothing bumps.
   Fixed by resolving per table.
4. **Task 8** — the plan covered queue and Octane but not Artisan. Fixed by resetting the context
   per iteration in `McpServerCommand`'s stdio loop.
5. **Final review** — the Task 4 fix had been applied to only three of five key generators; both
   function key generators were still body-blind. Fixed in the final wave.

Four separate guard tests were found to be inert (passing with the fix removed). The check that
caught every one of them — *remove the fix, watch the test fail, restore it* — is now the required
standard for any test claiming to guard a behaviour change here.

## Carry-forward

Still open, for the deferred storage-layer plan:

- **A1** — orphaned versioned entries are never reclaimed on `database`/`file` stores. Laravel's
  `DatabaseStore` only deletes an expired row when that exact key is read again, and an orphaned
  versioned key never is. This branch increases bump frequency, so it grows faster now.
- **A4** — namespace counters are evictable under `allkeys-lru`; an evicted counter restarts at 1
  and can resurrect surviving entries. Interacts with the 10-year seed TTL added in Task 2.
- **A5** — no cache-stampede protection.
- Generic long-running Artisan loops (consumer-written `while(true)` commands doing CRUD) still
  leak the namespace memo. `app(CacheRequestContext::class)->reset()` per iteration is the fix;
  documented in the CHANGELOG.
- The `reset()` call in `McpServerCommand` is guarded by a test of the leak *mechanism*, not of the
  wiring — deleting that one line fails no test. Its placement is verified by inspection only.
- `record_cache_action` is read by `resolveCacheAction()` but never set by production code; real
  traffic always falls through to `route()->getActionMethod()`. A test-only seam.
- No `phpstan.neon` exists, so `composer analyse` runs at PHPStan level 0 — not the "strict
  larastan v3" CLAUDE.md describes. `composer quality` cannot pass; ~20 rector findings pre-date
  this work. Worth fixing separately.
