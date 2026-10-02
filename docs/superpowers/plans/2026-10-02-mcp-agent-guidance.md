---
title: "MCP Agent Guidance Plan"
description: "Implementation plan for phase 3 of the MCP on Laravel MCP spec: truthful schema-tool guidance, the shared operator map, nested-write bare ids and smaller responses."
keywords:
  - mcp
  - agent guidance
  - filter operators
  - nested writes
  - plan
---

# MCP Agent Guidance Content Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Schema MCP tools tell an agent the truth and the whole truth: fix the six wrong hints (W1–W6), add the missing rules (M1–M5), expose the capabilities it hides (C1–C9) and the performance advice (P), and shrink the response (compact text copy, `actions` argument) — for both MCP drivers.

**Architecture:** `ToolCatalog`/`SchemaTools` already feed both drivers, so all content changes land in `src/Mcp`. New content generators live in small classes under `src/Mcp/Guidance/` (types, payload schemas, per-action context, shared reference, module recipes) so `SchemaTools` does not grow past orchestration. The filter-operator map becomes one shared class, `FilterOperatorCatalog`, that the filter engine itself consults, so advertised operators cannot drift from accepted ones. The nested-write processors stop dropping bare items (W1 backend).

**Tech Stack:** PHP 8.2+, Laravel 11–13, PHPUnit via Testbench, `laravel/mcp` ^1.0.1 (dev, optional).

**Spec:** `docs/superpowers/specs/2026-10-02-mcp-on-laravel-mcp-design.md` §2.1 (findings), §6.6 (content), §9 (verification), §10 (review focus), Q7. Phase 3 of §8.

## Global Constraints

- Backward compatible: existing keys in `sp_api_get_endpoint` / `sp_api_get_api_guidance` stay. New keys are additive. Changed *values* are limited to what §6.6 names (hints, examples, `table` of belongsToMany includes, payload schemas, `queryParameters` first entry, integer types).
- Both drivers get identical content (`ToolCatalog`/`SchemaTools`/`ToolExecutor` are shared). Nothing in this plan touches `Servers/*` or `Tools/*` except the `actions` input property in the catalog.
- Every number (`per_page_max`, `limit_max`, `bulk_max`, throttle limits, tenant header, rpc prefix, route URLs) is read from config/routes at request time. No literal copies.
- A feature the app has not enabled is omitted from the output: `search`, resizing, `realtime`, `async`, module blocks, pgsql-only operators and advice.
- No secrets in output. Tokens appear only as the placeholder `<token>`.
- Add no composer dependency. Commit/push only when the user asks (the user commits their own work) — executors skip commit steps and record that in the ledger. Never `git add -A`.
- Quality gate per task: its own tests; at the end `vendor/bin/phpunit`, the laravel-driver matrix `SP_MCP_DRIVER=laravel vendor/bin/phpunit --filter "Mcp|OwnRecords|NestedChildWrite|HiddenColumnSanitizationMutationChannelsTest|SchemaMcpToken|ReservedRouteSegment|PermissionGateIntegration"`, `vendor/bin/phpstan analyse src tests`, `vendor/bin/rector process --dry-run --no-progress-bar` (apply to files created here only), `php bin/validate-docs.php` (8 pre-existing frontmatter failures expected), `graft build`.
- Test helper methods must not be named `call()` or `put()` (they collide with Testbench).

## Review Focus

1. **Advertised = accepted (C1).** Every operator advertised for a field must work in a real `list` request on the test driver, and no operator the engine rejects on the current driver may be advertised. One map; the engine's driver gate reads it.
2. **Follow-the-hint (W1).** Every `payloadHint` example, parsed and sent to the real API, must change the database as the hint says — for hasMany, belongsToMany, morphMany and hasManyThrough (including the bare-id forms).
3. **Scalars are never silently dropped.** A bare item in a many-to-many array attaches; in a hasMany/morphMany array it is a 422 naming the relationship; an empty/falsy scalar is a 422, never an insert of an empty row.
4. **Config-driven, not hard-coded.** Rename the tenant header, rpc prefix, bulk max; turn off bulk, queue, resizing, broadcasting — the output changes with them and no stale mention survives.
5. **Size budget holds.** The `invoices` fixture stays under 40 KB for the full `sp_api_get_endpoint` response and under 12 KB with `actions: ["list","create"]`; `content[0].text` equals the compact JSON of `structuredContent`.

## File Structure

| File | Responsibility |
|---|---|
| `src/Utilities/FilterOperatorCatalog.php` (new) | The one operator map: names, driver support, type applicability, syntax/example text. |
| `src/Utilities/QueryBuilderFiltersUtils.php` | `FILTER_OPERATORS` and the driver gate read the catalog. Behaviour unchanged. |
| `src/Utilities/RelationshipResolverUtils.php` | Bare items in nested arrays (W1 backend, Q7). |
| `src/Utilities/DefaultValidationUtils.php` | Expose `isRequiredColumn()` so `required` in payload schemas uses the same rule as validation. |
| `src/Services/RecordService.php` | Make the userstamp column constants public for the payload builder. |
| `src/Mcp/Guidance/ColumnTypes.php` (new) | Column type → family, JSON-Schema `type`/`format` (W5). |
| `src/Mcp/Guidance/PayloadSchemaBuilder.php` (new) | Create/update/bulk payload schemas (W4). |
| `src/Mcp/Guidance/EndpointContext.php` (new) | Per-action headers, throttle, pagination, search, async, resizing (M1, C2–C6). |
| `src/Mcp/Guidance/ApiReference.php` (new) | Shared reference blocks for the guidance tool (M2–M4, C1, C2, C5, C7, C8, P). |
| `src/Mcp/Guidance/ModuleRecipes.php` (new) | Per-enabled-module recipes from live config (M5). |
| `src/Mcp/SchemaTools.php` | Orchestration; include hints (W1/W2/W6/M4), RPC guidance (W3), `actions` subset. |
| `src/Mcp/ToolCatalog.php` | `actions` input property, widened guidance output schema. |
| `src/Mcp/ToolResult.php`, `ToolExecutor.php` | Compact text copy. |
| `tests/Concerns/BuildsGuidanceFixture.php` (new) | The `invoices` review fixture shared by the content tests. |

---

### Task 1: Bare items in nested arrays (W1 backend)

**Files:**
- Modify: `src/Utilities/RelationshipResolverUtils.php` (`processRelatedData` ~749; `processBelongsToManyOperation` ~994; `processHasManyThroughOperation` ~1139)
- Test: `tests/Feature/NestedScalarItemsTest.php` (new)

**Interfaces:**
- Consumes: `NestedWriteAuthorizer::authorizeChild` (unchanged), `assertRelatedRowVisible` (unchanged).
- Produces: new private static `RelationshipResolverUtils::normalizeNestedItem(mixed $item, string $table, string $alias, string $primaryKey, bool $attachable): array` — returns the item as an array. An int/string scalar becomes `[$primaryKey => $item]` when `$attachable` (many-to-many, hasManyThrough); otherwise, and for falsy scalars, null, bool, float, throws `InvalidArgumentException` (the existing nested-write 422 path).

Behaviour: many-to-many / hasManyThrough: `[1, 2]` ≡ `[{"id":1},{"id":2}]`. hasMany/morphMany: a scalar → 422 `Relationship 'items' on table 'invoices' expects objects, got scalar 5. Send {"id": ...} to update a child or {...fields} to create one.` Falsy scalar (`0`, `""`, `"0"`) → 422 in every relationship type (message: `...got an empty value...`).

- [ ] **Step 1: Write the failing tests**

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
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Types\RecordMetaBelongsToManyType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class NestedScalarItemsTest extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [CoreSpLaravelApiProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('notes', function (Blueprint $table): void {
            $table->id();
            $table->string('title')->nullable();
        });
        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
        });
        Schema::create('note_tag', function (Blueprint $table): void {
            $table->unsignedBigInteger('note_id');
            $table->unsignedBigInteger('tag_id');
        });
        Schema::create('note_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('note_id');
            $table->string('text')->nullable();
        });

        $public = new RecordTablePublic(read: true, write: true);
        Config::set('record.tables', [
            'notes' => new RecordTableType(
                table: 'notes',
                pmsName: 'notes',
                public: $public,
                relationships: [
                    'tags' => new RecordMetaBelongsToManyType(related: 'tags', table: 'note_tag', foreignPivotKey: 'note_id', relatedPivotKey: 'tag_id'),
                    'lines' => new RecordHasManyType(table: 'note_lines', foreignKey: 'note_id'),
                ],
            ),
            'tags' => new RecordTableType(table: 'tags', pmsName: 'tags', public: $public),
            'note_lines' => new RecordTableType(table: 'note_lines', pmsName: 'note_lines', public: $public),
        ]);
        SchemaRegistryUtils::refresh();
    }

    public function test_bare_ids_attach_in_a_many_to_many_array(): void
    {
        $noteId = DB::table('notes')->insertGetId(['title' => 'n']);
        $a = DB::table('tags')->insertGetId(['name' => 'a']);
        $b = DB::table('tags')->insertGetId(['name' => 'b']);

        $this->putJson('/api/notes/' . $noteId, ['tags' => [$a, (string) $b]])->assertSuccessful();

        $this->assertSame([$a, $b], DB::table('note_tag')->where('note_id', $noteId)->orderBy('tag_id')->pluck('tag_id')->map(fn ($v) => (int) $v)->all());
    }

    public function test_bare_ids_on_create_attach_too(): void
    {
        $a = DB::table('tags')->insertGetId(['name' => 'a']);

        $this->postJson('/api/notes', ['title' => 'x', 'tags' => [$a]])->assertSuccessful();

        $this->assertSame(1, DB::table('note_tag')->where('tag_id', $a)->count());
    }

    public function test_object_and_bare_forms_can_be_mixed(): void
    {
        $noteId = DB::table('notes')->insertGetId(['title' => 'n']);
        $a = DB::table('tags')->insertGetId(['name' => 'a']);
        $b = DB::table('tags')->insertGetId(['name' => 'b']);

        $this->putJson('/api/notes/' . $noteId, ['tags' => [$a, ['id' => $b]]])->assertSuccessful();

        $this->assertSame(2, DB::table('note_tag')->where('note_id', $noteId)->count());
    }

    public function test_re_sending_an_attached_id_is_a_no_op(): void
    {
        $noteId = DB::table('notes')->insertGetId(['title' => 'n']);
        $a = DB::table('tags')->insertGetId(['name' => 'a']);
        DB::table('note_tag')->insert(['note_id' => $noteId, 'tag_id' => $a]);

        $this->putJson('/api/notes/' . $noteId, ['tags' => [$a]])->assertSuccessful();

        $this->assertSame(1, DB::table('note_tag')->where('note_id', $noteId)->count());
    }

    public function test_a_bare_id_in_a_has_many_array_is_refused_naming_the_relationship(): void
    {
        $noteId = DB::table('notes')->insertGetId(['title' => 'n']);

        $this->putJson('/api/notes/' . $noteId, ['lines' => [5]])
            ->assertStatus(422)
            ->assertJsonPath('message', "Relationship 'lines' on table 'notes' expects objects, got scalar 5. Send {\"id\": ...} to update a child or {...fields} to create one.");

        $this->assertSame(0, DB::table('note_lines')->count());
    }

    /** @dataProvider emptyScalars */
    public function test_empty_scalars_are_refused_and_never_create_an_empty_row(mixed $value): void
    {
        $noteId = DB::table('notes')->insertGetId(['title' => 'n']);
        $before = DB::table('tags')->count();

        $this->putJson('/api/notes/' . $noteId, ['tags' => [$value]])->assertStatus(422);

        $this->assertSame($before, DB::table('tags')->count());
        $this->assertSame(0, DB::table('note_tag')->count());
    }

    public static function emptyScalars(): array
    {
        return [[0], [''], ['0'], [null], [false]];
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit --filter NestedScalarItemsTest`
Expected: FAIL — bare ids attach nothing (`0` pivot rows), the hasMany case returns 200, empty scalars return 200.

- [ ] **Step 3: Implement**

Add the helper and call it as the first statement of the three loops (replace the existing `if (!is_array($item)) { continue; }` in each):

```php
    /**
     * A bare id in a many-to-many / hasManyThrough array means "attach this
     * record" and is rewritten to {pk: id}. Everywhere else a non-object item
     * used to be dropped silently, which hid client mistakes; it is a 422 now.
     *
     * @return array<string, mixed>
     */
    private static function normalizeNestedItem(mixed $item, string $table, string $alias, string $primaryKey, bool $attachable): array
    {
        if (is_array($item)) {
            return $item;
        }

        if (is_int($item) || is_string($item)) {
            if ('' === $item || 0 === $item || '0' === $item) {
                throw new InvalidArgumentException(sprintf("Relationship '%s' on table '%s' got an empty value; send a record id or an object.", $alias, $table));
            }

            if ($attachable) {
                return [$primaryKey => $item];
            }

            throw new InvalidArgumentException(sprintf(
                "Relationship '%s' on table '%s' expects objects, got scalar %s. Send {\"id\": ...} to update a child or {...fields} to create one.",
                $alias,
                $table,
                $item,
            ));
        }

        throw new InvalidArgumentException(sprintf("Relationship '%s' on table '%s' got an empty value; send a record id or an object.", $alias, $table));
    }
```

Call sites: `$item = self::normalizeNestedItem($item, $table, $alias, $relatedPk, true);` in `processBelongsToManyOperation` (`$relatedPk` is defined above the loop) and `$targetPk` in `processHasManyThroughOperation`; `$item = self::normalizeNestedItem($item, $table, (string) $alias, $relatedPk, false);` in the `processRelatedData` hasMany/morphMany loop. `foreach ($data as $item)` keeps its variable; the tail of each loop body already reads `$item`.

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit --filter "NestedScalarItemsTest|RelationshipHasManyNestedWriteTest|RelationshipAssociationWriteTest|RelationshipMorphManyTest|RelationshipWritePermissionEnforcementTest|NestedChildWriteAuthorizationTest|NestedRelationshipWriteScopingTest"`
Expected: PASS. If a pre-existing test relied on a scalar being dropped, rule it in the ledger and change that test's input, not the new behaviour.

- [ ] **Step 5: Mutation check, then ledger**

Revert `normalizeNestedItem`'s `$attachable` branch → the bare-id tests fail; restore. Skip the commit (the user commits), recording that.

---

### Task 2: One operator map (C1 core)

**Files:**
- Create: `src/Utilities/FilterOperatorCatalog.php`
- Modify: `src/Utilities/QueryBuilderFiltersUtils.php` (`FILTER_OPERATORS` const line 18; the nine `assertOperatorDriverSupported(…, […])` calls; the method at ~2530)
- Test: `tests/Unit/FilterOperatorCatalogTest.php` (new)

**Interfaces:**
- Produces (`final class FilterOperatorCatalog`, all static):
  - `public const NAMES` — every operator token the engine understands, negated forms included, no duplicates.
  - `names(): array`
  - `drivers(string $operator): ?array` — `null` = every driver.
  - `supports(string $operator, ?string $driver = null): bool` — `$driver` defaults to `currentDriver()`.
  - `currentDriver(): string` — `DB::getDriverName()`, reporting `mariadb` when a `mysql` connection is MariaDB (the check moved out of the engine; same query).
  - `forFamily(string $family, ?string $driver = null): array` — operator names advertised for a column family on a driver, in catalogue order. Families: `text`, `number`, `temporal`, `boolean`, `json`, `uuid`, `range`, `other`.
  - `catalogue(?string $driver = null): array` — list of `['name','syntax','example','summary','families','drivers']` for the operators supported on the driver (the shared reference of Task 5).
  - `normalizeFamily(string $columnType): string` is NOT here — Task 3's `ColumnTypes::family()` owns it.
- Consumes: nothing.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Utilities\FilterOperatorCatalog;
use Sopheak\Core\Utilities\QueryBuilderFiltersUtils;

class FilterOperatorCatalogTest extends TestCase
{
    public function test_the_engine_constant_is_the_catalog(): void
    {
        $engine = (new ReflectionClass(QueryBuilderFiltersUtils::class))->getReflectionConstant('FILTER_OPERATORS')->getValue();

        $this->assertSame(FilterOperatorCatalog::NAMES, $engine);
        $this->assertSame(array_values(array_unique(FilterOperatorCatalog::NAMES)), FilterOperatorCatalog::NAMES);
    }

    public function test_the_engine_has_no_private_driver_lists_left(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Utilities/QueryBuilderFiltersUtils.php');

        $this->assertSame(0, preg_match('/assertOperatorDriverSupported\([^)]*\[/', $source), 'driver lists belong in FilterOperatorCatalog');
    }

    #[DataProvider('driverRules')]
    public function test_driver_support(string $operator, string $driver, bool $expected): void
    {
        $this->assertSame($expected, FilterOperatorCatalog::supports($operator, $driver));
    }

    public static function driverRules(): array
    {
        return [
            ['eq', 'sqlite', true],
            ['like', 'sqlite', true],
            ['regex', 'sqlite', false],
            ['regex', 'mysql', true],
            ['imatch', 'mariadb', true],
            ['not_regex', 'sqlite', false],
            ['fts', 'mysql', false],
            ['fts', 'pgsql', true],
            ['not_fts', 'pgsql', true],
            ['cs', 'mariadb', false],
            ['adj', 'pgsql', true],
        ];
    }

    public function test_every_name_is_advertisable_or_a_negation_of_an_advertisable_one(): void
    {
        $described = array_column(FilterOperatorCatalog::catalogue('pgsql'), 'name');

        foreach (FilterOperatorCatalog::NAMES as $name) {
            $base = str_starts_with($name, 'not_') ? substr($name, 4) : $name;
            $this->assertTrue(
                in_array($name, $described, true) || in_array($base, $described, true),
                sprintf("operator '%s' is accepted by the engine but undocumented", $name),
            );
        }
    }

    public function test_family_sets_follow_the_driver(): void
    {
        $this->assertContains('ilike', FilterOperatorCatalog::forFamily('text', 'sqlite'));
        $this->assertNotContains('regex', FilterOperatorCatalog::forFamily('text', 'sqlite'));
        $this->assertContains('regex', FilterOperatorCatalog::forFamily('text', 'mysql'));
        $this->assertNotContains('fts', FilterOperatorCatalog::forFamily('text', 'mysql'));
        $this->assertContains('fts', FilterOperatorCatalog::forFamily('text', 'pgsql'));
        $this->assertContains('cs', FilterOperatorCatalog::forFamily('json', 'pgsql'));
        $this->assertNotContains('cs', FilterOperatorCatalog::forFamily('json', 'sqlite'));
        $this->assertSame(['eq', 'neq', 'is', 'is_not'], FilterOperatorCatalog::forFamily('boolean', 'sqlite'));
    }

    public function test_numbers_and_dates_get_comparisons_and_text_does_not(): void
    {
        $number = FilterOperatorCatalog::forFamily('number', 'sqlite');
        foreach (['gt', 'gte', 'lt', 'lte', 'between', 'in', 'not_in', 'eq', 'neq'] as $op) {
            $this->assertContains($op, $number);
        }
        $this->assertNotContains('like', $number);
        $this->assertContains('date_gte', FilterOperatorCatalog::forFamily('temporal', 'sqlite'));
        $this->assertNotContains('date_gte', $number);
    }

    public function test_the_catalogue_entries_are_complete(): void
    {
        foreach (FilterOperatorCatalog::catalogue('pgsql') as $entry) {
            foreach (['name', 'syntax', 'example', 'summary', 'families'] as $key) {
                $this->assertArrayHasKey($key, $entry, $entry['name'] ?? '?');
                $this->assertNotSame('', $entry[$key] === [] ? 'x' : (string) (is_array($entry[$key]) ? 'x' : $entry[$key]));
            }
        }
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit --filter FilterOperatorCatalogTest`
Expected: FAIL — `Class "Sopheak\Core\Utilities\FilterOperatorCatalog" not found`.

- [ ] **Step 3: Implement `FilterOperatorCatalog`**

`NAMES` is the current `FILTER_OPERATORS` list verbatim minus the duplicated `ilike`. Driver sets: `REGEX_DRIVERS = ['mysql','mariadb','pgsql']` for `regex, match, imatch, not_regex, not_match, not_imatch`; `['pgsql']` for the `fts`/`plfts`/`phfts`/`wfts` family and the `cs/cd/ov/sl/sr/nxl/nxr/adj` family, each with its `not_` form. Catalogue rows (name | syntax | example | summary | families):

| name | syntax | example | summary | families |
|---|---|---|---|---|
| is | `is.null` | `deleted_at=is.null` | Column is NULL | all |
| is_not | `is_not.null` | `deleted_at=is_not.null` | Column is not NULL | all |
| eq | `eq.{value}` | `status=eq.open` | Equal; a comma list means IN | all |
| neq | `neq.{value}` | `status=neq.void` | Not equal; a comma list means NOT IN | all |
| in | `in.{a,b,c}` or `in.(a,b,c)` | `status=in.open,paid` | Value in a list | text, number, temporal, uuid, json, other |
| not_in | `not_in.{a,b,c}` | `status=not_in.void,draft` | Value not in a list | same as in |
| gt, gte, lt, lte | `gt.{value}` … | `total=gte.100` | Comparison | number, temporal |
| between | `between.{from,to}` | `issued_at=between.2026-01-01,2026-03-31` | Inclusive range | number, temporal |
| not_between | `not_between.{from,to}` | `total=not_between.1,10` | Outside a range | number, temporal |
| like | `like.{text}` | `name=like.acme` | Contains the text (substring match; no `%` needed) | text, json |
| not_like | `not_like.{text}` | `name=not_like.test` | Does not contain the text | text |
| ilike | `ilike.{text}` | `name=ilike.acme` | Case-insensitive contains | text |
| contains | `contains.{text}` | `name=contains.acme` | Same as like | text, json |
| starts_with / ends_with | `starts_with.{text}` | `ref=starts_with.INV-` | Prefix / suffix match | text |
| empty / not_empty | `empty.null` | `notes=not_empty.null` | NULL-or-empty-string / the opposite (non-text columns: NULL / not NULL) | text, number, temporal, uuid |
| date_eq, date_gt, date_gte, date_lt, date_lte | `date_gte.{YYYY-MM-DD}` | `created_at=date_gte.2026-01-01` | Compares the date part only | temporal |
| regex | `regex.{pattern}` | `ref=regex.^INV-[0-9]+$` | Regular expression (MySQL REGEXP / PostgreSQL ~) | text |
| match / imatch | `match.{pattern}` | `ref=imatch.^inv` | Regular expression; imatch ignores case | text |
| fts, plfts, phfts, wfts | `fts.{query}` | `body=fts.late payment` | PostgreSQL full-text search (plain, phrase, web styles) | text |
| cs, cd, ov | `cs.{value}` | `tags=cs.{a,b}` | Array/JSON contains, contained by, overlaps (PostgreSQL) | json |
| sl, sr, nxl, nxr, adj | `sl.{range}` | `period=adj.[2026-01-01,2026-02-01)` | Range strictly left/right, not extending left/right, adjacent (PostgreSQL) | range |

`forFamily()` returns the catalogue names whose `families` include the family (or `all`) and which `supports()` the driver. Also: add `currentDriver()` with the exact mariadb detection from the engine.

- [ ] **Step 4: Rewire the engine**

`private const FILTER_OPERATORS = FilterOperatorCatalog::NAMES;`. Replace the method body with `if (!FilterOperatorCatalog::supports($operator)) { throw new InvalidArgumentException(sprintf("Operator '%s' is not supported on current driver '%s'.", $operator, FilterOperatorCatalog::currentDriver())); }` and its signature with `assertOperatorDriverSupported(string $operator): void`; remove the second argument at the nine call sites with `perl -0pi -e 's/assertOperatorDriverSupported\(([^,()]+), \[[^\]]*\]\)/assertOperatorDriverSupported($1)/g'`.

- [ ] **Step 5: Run to verify it passes**

Run: `vendor/bin/phpunit --filter "FilterOperatorCatalogTest|Filter|Operator|RelationshipNestedFilterTest|RelationshipMixedFilterTest"`
Expected: PASS, including the existing driver-rejection tests (`regex`/`fts` on sqlite still 422 with the same message).

- [ ] **Step 6: Mutation check, then ledger**

Change `REGEX_DRIVERS` to drop `mysql` → the `regex`/`mysql` rule fails; restore. Skip the commit (the user commits).

---

### Task 3: Types, payload schemas, include hints, per-field operators (W1, W2, W4, W5, W6, M4, C1, C9)

**Files:**
- Create: `src/Mcp/Guidance/ColumnTypes.php`, `src/Mcp/Guidance/PayloadSchemaBuilder.php`, `tests/Concerns/BuildsGuidanceFixture.php`
- Modify: `src/Mcp/SchemaTools.php` (`getEndpoint` fields/includes/filters; `recordSchema`; remove `jsonSchemaType` and `filterOperatorsForType`), `src/Services/RecordService.php` (lines 55, 61: `private const` → `public const` for `CREATE_AUDIT_COLUMNS` / `UPDATE_AUDIT_COLUMNS`), `src/Utilities/DefaultValidationUtils.php` (extract `public static function isRequiredColumn(array $info): bool` = `!($info['nullable'] ?? true) && !hasDefault && !isAutoGenerated`, used by the existing method)
- Test: `tests/Unit/ColumnTypesTest.php`, `tests/Feature/McpEndpointContentTest.php`, `tests/Feature/McpFollowTheHintTest.php`, `tests/Feature/McpAdvertisedOperatorsTest.php` (all new)

**Interfaces:**
- Consumes: `FilterOperatorCatalog::forFamily()` (Task 2); `RelationshipResolverUtils::resolveRelationship(string $table, string $alias)` (existing; returns `type`, `table` = related table, `pivot_table`, `related_pivot_key`, `foreign_pivot_key`, `with_pivot`, `morph_type`, `allow_*`).
- Produces:
  - `ColumnTypes::family(string $type): string` (`text|number|temporal|boolean|json|uuid|range|other`), `ColumnTypes::jsonSchema(string $type): array` (`['type'=>…]` plus `format` for `date`, `date-time`, `uuid`), `ColumnTypes::sample(string $type): mixed` (a valid example value).
  - `PayloadSchemaBuilder::forAction(RecordTableType $config, string $action, array $fields, array $includes): array` — the `request.payload` schema for create/update/upsert/bulk* (bulk = `{"type":"array","items":<record schema>}`).
  - Each `fields[]` entry gains `required` (create) and, when the column config defines them, `maxLength`; endpoints gain `validation.defaults`.
  - Each writable `includes[]` entry gains `payloadExample` (the array to send under the alias, built from real columns of the related table, ids 1/2/5) and `childPermissions` (`create|update|delete` → permission names on the related table, via `PermissionUtils::mapPermissions`); `payloadHint` is regenerated from the same example. belongsToMany/morphToMany/spatiePermission includes report the related table as `table` plus `pivotTable`, `relatedPivotKey`, `pivotFields` (`with_pivot` minus `morph_type`).

**Rules for `PayloadSchemaBuilder`:** properties = fields whose `in` contains `write`, minus: `created_at`, `updated_at`, `deleted_at` (unless the table sets `overrideTimestamps`), `RecordService::CREATE_AUDIT_COLUMNS` and `UPDATE_AUDIT_COLUMNS` (unless `overrideUserstamps`), the tenant column when `hasTenantId` and tenancy is on, and the primary key unless `$action` is create/bulkCreate/upsert/bulkUpsert **and** `RecordConfigService::idType()` is `uuid`. Each writable include adds `alias => {"type":"array","description":"Nested write — see includes[].payloadHint","items":{"type":["object","integer","string"]}}` (many-to-many types accept bare ids; hasMany/morphMany items are `{"type":"object"}`). `required` (create and bulkCreate only) = writable properties for which `DefaultValidationUtils::isRequiredColumn($columnConfig)` is true; omitted when empty. `additionalProperties:false` stays. Field `type`/`format` come from `ColumnTypes::jsonSchema`; `enum` and `maxLength` are copied when defined.

- [ ] **Step 1: Build the shared fixture**

`tests/Concerns/BuildsGuidanceFixture.php` — `trait BuildsGuidanceFixture` with `protected function buildGuidanceFixture(bool $tenant = false): void` that creates the tables and registers the config. Tables: `customers(id, name, email, timestamps)`; `invoices(id, ref_number string, customer_id unsignedBigInteger, status string default 'draft', total_amount decimal(12,2) nullable, issued_at date nullable, created_by_id nullable, timestamps, softDeletes)` (+ `tenant_id` nullable when `$tenant`); `invoice_items(id, invoice_id, description string nullable, quantity integer nullable, unit_price decimal nullable)`; `tags(id, name string nullable)`; `invoice_tag(invoice_id, tag_id, note string nullable)`. Config: `invoices` has `softDeletes: true`, `hasTenantId: $tenant`, `searchable: ['ref_number','customer.name']`, `public: new RecordTablePublic(read: true, write: true)` and relationships `customer` (`RecordBelongsToType`), `items` (`RecordHasManyType`), `tags` (`RecordMetaBelongsToManyType`, `withPivot: ['note']`); `customers`, `invoice_items`, `tags` are plain public tables. Ends with `Config::set('record.tables', …)`, `Config::set('record.mcp.enabled', true)`, `SchemaRegistryUtils::refresh()`. Tenant mode also sets `record.enable_tenant_id` true.

- [ ] **Step 2: Write the failing tests**

`ColumnTypesTest` (data provider): `bigInteger, unsignedBigInteger, mediumInteger, smallInteger, tinyInteger, unsignedInteger, increments, bigIncrements, id, foreignId, int, bigint, integer, unsigned` → `integer`/family `number`; `decimal, float, double, numeric, real` → `number`; `boolean, bool` → `boolean`; `date` → `string`+`date`; `datetime, timestamp, dateTime, timestampTz` → `string`+`date-time`; `uuid` → `string`+`uuid`; `json, jsonb` → `object`/family `json`; `text, string, varchar, longText, char, enum` → `string`/`text`; `int4range` → family `range`; unknown → `string`/`other`.

`McpEndpointContentTest` (uses `buildGuidanceFixture()`, calls `app(SchemaTools::class)->getEndpoint(['endpoint' => 'invoices'])`; `SchemaTools` is `final` but container-resolvable). Assertions, one test each:
1. `test_integer_columns_are_integers`: `actions.create.request.payload.properties.customer_id.type === 'integer'`; `actions.read.response.dataSchema.properties.id.type === 'integer'`.
2. `test_date_columns_carry_a_format`: `issued_at.format === 'date'`, `created_at` (response) `format === 'date-time'`.
3. `test_create_payload_lists_aliases_and_excludes_system_columns`: properties contain `items`, `tags`, `ref_number`, `customer_id`, `status`; do not contain `id`, `created_at`, `updated_at`, `deleted_at`, `created_by_id`; `items.type === 'array'`.
4. `test_create_payload_marks_required_columns`: `required` equals `['ref_number','customer_id']` (status has a default, the rest are nullable); `actions.update.request.payload` has no `required`.
5. `test_tenant_column_is_never_writable` (fixture with `buildGuidanceFixture(tenant: true)`): no `tenant_id` in the create properties.
6. `test_uuid_ids_are_writable_on_create_only`: set `record.id_type` to `uuid`, the create payload keeps `id` and the update payload does not (use `Config::set('record.id_type','uuid')` — confirm the key in `RecordConfigService::idType()` first).
7. `test_belongs_to_many_include_reports_the_related_table`: `includes` entry `tags`: `table==='tags'`, `pivotTable==='invoice_tag'`, `relatedPivotKey==='tag_id'`, `pivotFields===['note']`; and `getEndpoint(['endpoint'=>'tags'])` does not throw.
8. `test_hints_use_shapes_that_write_and_never_say_sync`: for `items` the `payloadExample` is a list of three objects (create without id, update with id 2, `['id'=>5,'_delete'=>true]`); for `tags` the first item is `['id'=>1]`; no hint or `guidance` string in `getEndpoint('invoices')` or `apiGuidance()` contains the word `sync` (case-insensitive); `payloadHint` contains the JSON of `payloadExample`.
9. `test_writable_includes_name_the_child_permissions`: `items.childPermissions.create` is a non-empty list.
10. `test_fields_carry_required_and_defaults_note`: `fields` entry `ref_number.required === true`, `status.required === false`; `validation.defaults.enabled` is a bool and `validation.defaults.note` is a non-empty string.
11. `test_each_field_lists_only_family_operators`: `filters` entry for `total_amount` contains `gte` and `between`, not `like`; for `ref_number` contains `starts_with`, not `gte`.

`McpFollowTheHintTest` (HTTP; for each writable include send the advertised `payloadExample` to the real API with `PUT /api/invoices/{id}` after seeding ids the example refers to, then assert the database):
- `items`: seed invoice 1; items id 2 (`description` 'old') and id 5 on invoice 1 → after PUT: id 2 updated to the example's description, id 5 gone, one new row created.
- `tags`: seed tags 1, 2, 5, attach tag 5 → after PUT: pivot rows for tags 1 and 2 exist (2 has the pivot `note` from the example), tag 5 detached, plus one new tag row linked.
- The guidance's `nestedWriteExamples.childCollections.example` and `.manyToManyPivot.example` (read from `apiGuidance()`) are sent too: `items` as the example says (ids 105/88 seeded) and `tags: [1,3,5]` attaches three rows.
- Bare-id form of a many-to-many hint attaches (`['tags' => [1]]`).

`McpAdvertisedOperatorsTest`: its own table `ks_rows(id, s string, t text, i integer, d decimal, b boolean, day date, at timestamp, u uuid, j json)` registered public; `getEndpoint('ks_rows')['filters']` gives each field's operators; for every field/operator send `GET /api/ks_rows?{field}={op}.{sample}` (`is`/`is_not`/`empty`/`not_empty` → `null`; `between`/`not_between` → `{sample},{sample}`; `in`/`not_in` → the sample) and assert 200 (seed two rows first). Also: no field advertises `regex`, `match`, `fts`, `cs` on sqlite, and `GET ?s=regex.x` returns 422 with "not supported on current driver"; boolean fields advertise exactly `eq, neq, is, is_not`.

- [ ] **Step 3: Run to verify they fail**

Run: `vendor/bin/phpunit --filter "ColumnTypesTest|McpEndpointContentTest|McpFollowTheHintTest|McpAdvertisedOperatorsTest"`
Expected: FAIL — `ColumnTypes` missing; content assertions fail (`customer_id` is `string`, no aliases, `tags.table === 'invoice_tag'`).

- [ ] **Step 4: Implement**

`ColumnTypes::normalize` lowercases and strips `_`, `-`, spaces; a leading `unsigned` is removed (`unsigned` alone → `integer`). Integer set: `integer int bigint biginteger smallint smallinteger tinyint tinyinteger mediumint mediuminteger increments bigincrements smallincrements mediumincrements tinyincrements id foreignid int2 int4 int8 serial bigserial`. Number: `decimal float double numeric real money`. Boolean: `boolean bool`. Date: `date`; date-time: `datetime datetimetz timestamp timestamptz`; time: `time timetz year` (string). `uuid` → uuid family + `format: uuid`; `ulid` → uuid family. JSON: `json jsonb array object`. Text: `string text varchar char longtext mediumtext tinytext enum`. A type containing `range` → family `range`. `sample()` returns `'example'`, `1`, `9.99`, `true`, `'2026-01-01'`, `'2026-01-01T00:00:00Z'`, a fixed uuid, `[]` per family.

`SchemaTools::getEndpoint` changes, in this order: fields get `required` and `maxLength`; the include loop calls `RelationshipResolverUtils::resolveRelationship($config->table, $relName)` for `belongsToMany|morphToMany|morphByMany|spatiePermission|hasManyThrough` and fills `table`/`pivotTable`/`relatedPivotKey`/`pivotFields`; writable includes get `payloadExample`, `payloadHint` and `childPermissions`; `withActionContexts` passes `$config` and `$includes` through to `PayloadSchemaBuilder::forAction`; `recordSchema` (read side) uses `ColumnTypes::jsonSchema`; `filters` use `FilterOperatorCatalog::forFamily(ColumnTypes::family($type))`. The hint wording: hasMany/morphMany — "Omit `id` to create a child, include `id` to update it, add `\"_delete\": true` with `id` to delete it. Children you leave out are kept."; many-to-many/hasManyThrough — "Attach with `{\"id\": N}` (a bare id works), update pivot fields with `{\"id\": N, <pivotFields>}`, create a related row by omitting `id`, detach with `{\"id\": N, \"_delete\": true}`. Links you leave out are kept."

`apiGuidance()` examples: `childCollections.example` keeps its shape; `manyToManyPivot.explanation` becomes "Attach existing records by id (bare ids or `{id}` objects); links you leave out are kept; detach with `{id, _delete: true}`."; the `like.%acme%` example becomes `like.acme`; the explanation of nested writes lists `hasManyThrough` and `morphToMany`.

- [ ] **Step 5: Run to verify they pass**

Run: `vendor/bin/phpunit --filter "ColumnTypesTest|McpEndpointContentTest|McpFollowTheHintTest|McpAdvertisedOperatorsTest|McpSchemaEndpointCoverageTest|McpEndpointRelationshipSchemaTest|McpStructuredOutputTest|DefaultValidation"`
Expected: PASS. Existing schema tests that pinned the old `string` type of an id or the old hint wording are updated to the new values with a ledger note.

- [ ] **Step 6: Mutation checks, then ledger**

(a) Make `family()` return `text` for numbers → the operators test fails. (b) Drop the alias loop in `PayloadSchemaBuilder` → test 3 fails. (c) Revert Task 1's `normalizeNestedItem` → the follow-the-hint `tags: [1,3,5]` case fails. Restore all three. Skip the commit.

---

### Task 4: Per-action context (M1, C2–C6, W3)

**Files:**
- Create: `src/Mcp/Guidance/EndpointContext.php`
- Modify: `src/Mcp/SchemaTools.php` (`withActionContexts`, `queryParametersForAction`, `functionCallContext`, RPC lists in `listEndpoints`/`getEndpoint`)
- Test: `tests/Feature/McpEndpointContextTest.php` (new)

**Interfaces:**
- Consumes: `buildGuidanceFixture()` (Task 3), `RecordConfigService::{tenantHeader, enableTenantId, perPageMax, limitMax, bulkMax, bulkOperationsEnabled}`.
- Produces (`EndpointContext`, instance, no state):
  - `headers(RecordTableType $config, string $action): array` — `['Authorization' => 'Bearer <token>']` when the action needs auth (`list|read` → `$config->isAuthRead`; every other table action → `$config->isAuthWrite`), plus `[RecordConfigService::tenantHeader() => '<tenant id>']` when `enableTenantId() && $config->hasTenantId`. Always present; `[]` when neither applies.
  - `rpcHeaders(array $fnConfig, ?RecordTableType $table): array` — same, with auth from `!($fnConfig['isPublic'] ?? false)`.
  - `throttle(string $action): ?array` — `['group' => 'api-reads|api-writes|api-functions', 'limit' => int, 'perSeconds' => int]`; `limit`/`perSeconds` come from invoking `RateLimiter::limiter($group)` with the current request and reading the returned `Limit`; the key is dropped when the limiter is undefined or returns anything else. `null` for unknown actions.
  - `listExtras(RecordTableType $config): array` — `pagination` (`limit` max = `limitMax()`, `per_page` max = `perPageMax()`, parameter names `skip_total`, `total`, `add_total`, `cursor`, `cursor_column`, `direction`, plus `boundary_cursors` only when `RecordConfigService::cursorBoundaryEnabled()`), `search` (`['enabled'=>true,'columns'=>$config->searchable,'parameter'=>'search']`, key omitted when the table has no `searchable`), `softDeletes` (`['with_trashed','only_trashed']`, only when `$config->softDeletes`).
  - `bulkExtras(string $action): array` — `['maxItems' => bulkMax()]` and, only when `config('queue.default') !== 'sync'`, `async` = `['query'=>'async=true','header'=>'X-Async-Process: 1','response'=>'202 with status "queued"']`.
  - `resizingQuerySchema(): ?array` — `w`, `h` (integer, bounded by `attachments.read_resizing_min/max`), `fit`, `format` (enum `attachments.read_resizing_formats`), `size_name`; `null` unless `attachments.read_resizing` is true.

- [ ] **Step 1: Write the failing tests**

`McpEndpointContextTest` (fixture + direct `getEndpoint` calls; HTTP only for the one RPC check):
1. `test_every_action_has_headers`: with `isAuthRead/isAuthWrite` true every action's `headers` has `Authorization === 'Bearer <token>'`; with the fixture's table switched to `isAuthRead: false` (public read) `list.headers` has no `Authorization` while `create.headers` still does.
2. `test_tenant_header_appears_exactly_for_tenant_scoped_tables`: tenant fixture → every action lists `X-Tenant-ID`; after `Config::set('record.tenant_header', 'X-Org')` the key is `X-Org` and `X-Tenant-ID` is gone; non-tenant fixture → no tenant header on any action.
3. `test_list_query_parameters_describe_filters_once`: `list.request.queryParameters[0]` is exactly `'{column}={operator}.{value}'`; the array contains `select`, `with`, `search`, `sortby`, `limit`; does not contain the literal `filters`.
4. `test_list_carries_search_pagination_and_trash_flags`: `list.search.columns === ['ref_number','customer.name']`; `list.pagination.limit.max === limitMax()`, changes when `record.limit_max` is set to 77; `list.pagination.per_page.max` follows `record.per_page_max`; `list.softDeletes` names `with_trashed`/`only_trashed`; a table with no `searchable` has no `search` key; `read` has the soft-delete flags but not `search`/`pagination`.
5. `test_boundary_cursor_param_is_config_gated`: `Config::set('record.pagination.cursor.boundary_cursors', false)` removes `boundary_cursors`.
6. `test_bulk_actions_carry_max_items_and_async_only_with_a_queue`: `bulkCreate.request.payload.maxItems === bulkMax()` (change `record.bulk_max` to 25 → 25); with `queue.default = 'database'` `bulkCreate.async.header === 'X-Async-Process: 1'`; with `sync` the `async` key is absent; bulk actions are absent when `record.bulk_operations` is false (existing behaviour, pinned).
7. `test_actions_name_their_throttle_group`: `list.rateLimit.group === 'api-reads'`, `create.rateLimit.group === 'api-writes'`; with `RateLimiter::for('api-reads', fn () => Limit::perMinute(123))` registered, `list.rateLimit.limit === 123` and `perSeconds === 60`; with no limiter registered the `limit` key is absent but `group` stays.
8. `test_upload_rpc_says_multipart` (registers a table function whose `payloadSchema` has a `format: binary` property; also asserts the real `sp_api_get_endpoint sp_attachments` upload RPC when `attachments.enabled`): `guidance` contains `multipart/form-data` and `request.payload`; a JSON-only RPC says `Send a JSON request body that conforms to request.payload`; no RPC guidance still contains the literal `payloadSchema`.
9. `test_view_rpc_exposes_resizing_params_only_when_enabled`: `attachments.read_resizing` false → no `w` in the view RPC's `request.querySchema`; true → `w`, `h`, `fit`, `format`, `size_name` present and `format.enum` equals the configured list.
10. `test_rpc_headers_follow_the_function_visibility`: a public function has no `Authorization`; a private one does; both gain the tenant header on a tenant table.

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit --filter McpEndpointContextTest`
Expected: FAIL — no `headers`, `rateLimit`, `search`, `async` keys.

- [ ] **Step 3: Implement**

`EndpointContext` per the interface above (`RateLimiter::limiter()` returns `?Closure`; call it with `request()` inside `try/catch (Throwable)`; accept a `Limit` or the first `Limit` of an array). `SchemaTools::withActionContexts(array $actions, array $fields, RecordTableType $config, array $includes)` merges, per action: `headers`, `rateLimit` (when `throttle()` is non-null), `search`/`pagination`/`softDeletes` (list; `softDeletes` also on read), and `maxItems`/`async` (bulk). `queryParametersForAction('list'|'read')` returns `['{column}={operator}.{value}', 'select', 'with', 'sortby', 'order', 'limit', 'per_page', 'page', 'cursor']` plus `'search'` when the table has `searchable`, plus `'with_trashed'`/`'only_trashed'` for soft-delete tables (the config is passed in). `functionCallContext` gains `$table` and `$fnName`; it adds `headers`, `rateLimit` (group `api-functions`), the resizing `querySchema` merge for the attachments `{id}/view` function (`$table === 'sp_attachments'` — the module's canonical key), and the W3 wording.

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit --filter "McpEndpointContextTest|McpEndpointContentTest|McpSchemaEndpointCoverageTest|McpEndpointRelationshipSchemaTest"`
Expected: PASS.

- [ ] **Step 5: Mutation checks, then ledger**

(a) Hard-code `X-Tenant-ID` in `headers()` → test 2's rename case fails. (b) Remove the `queue.default` check → test 6 fails. (c) Drop the `format: binary` branch → test 8 fails. Restore. Skip the commit. Note in the ledger that per-table `record.rate_limits` is documented but enforced nowhere in `src` (grep), so it is deliberately not advertised.

---

### Task 5: Shared reference in `sp_api_get_api_guidance` (M2, M3, M4, C1, C2, C5, C7, C8, P)

**Files:**
- Create: `src/Mcp/Guidance/ApiReference.php`
- Modify: `src/Mcp/SchemaTools.php` (`apiGuidance()` merges `ApiReference::build()`), `src/Mcp/ToolCatalog.php` (`guidanceOutputSchema`)
- Test: `tests/Feature/McpGuidanceReferenceTest.php` (new)

**Interfaces:**
- Consumes: `FilterOperatorCatalog::catalogue()`, `EndpointContext::throttle()` (Task 4), `HttpErrorCodeConstant`, `RecordConfigService::*`.
- Produces: `ApiReference::build(): array` with keys — always: `headers`, `querySyntax`, `operators`, `pagination`, `errors`, `rateLimits`, `nestedWrites`, `docs` (omitted when the docs routes are not registered), `recommendations`; conditionally: `realtime` (only when `broadcastEventsEnabled()`). Existing keys are unchanged. `guidanceOutputSchema` lists the new keys as optional properties of the matching type; the existing six stay `required`.

Content (every value computed, none copied from the docs):
- `headers`: `Authorization` (`Bearer <token>` — required unless the endpoint's `headers` omits it), the tenant header named by `tenantHeader()` only when `enableTenantId()`, `Accept: application/json`.
- `querySyntax`: `filters` ("one query key per column: `{column}={operator}.{value}`; never `filter[...]`"), `relationshipFilters` (`items.qty=gt.1`, `customer.name=ilike.acme`), `negation` (`not.eq.5`, `not.in.(1,2)`), `modifiers` (`name=like(any).{ACME,SHOP}`, `ilike(all)`), `grouped` (`or=(...)`, `and=(...)`), `substring` ("`like` and `ilike` already match substrings; do not add `%`"), `lists` (`in.(a,b)`), `ranges` (`between.1,10`), `nulls` (`is.null`, `is_not.null`), `search` ("`search=<text>` on tables whose `list` action has `search`"), `trashed` (`with_trashed=true`, `only_trashed=true`; soft-delete tables only), `selectVsWith` (`select` chooses columns and includes; `with` adds includes to the default columns). Confirm each parameter name by grep in `src` before emitting it; drop any that does not exist.
- `operators`: `['negation' => 'Prefix any operator with not. (not.fts.invoice) or use the not_ form', 'catalogue' => FilterOperatorCatalog::catalogue()]` — already driver-filtered, so pgsql-only operators appear only on pgsql.
- `pagination`: the three modes with their parameters and `skip_total`/`total`/`add_total`, `cursor_column`, `direction` (and `boundary_cursors` only when enabled) and the live `limit_max`/`per_page_max`.
- `errors`: rows `{status, error_code, name, when}` built from `HttpErrorCodeConstant` for 401 (`INVALID_ACCESS`), 403 (`PERMISSION_DENIED`), 404 (`RESOURCE_NOT_FOUND`, "including a by-id row outside your tenant, own-records or soft-delete scope"), 422 validation (`INVALID_REQUEST`, with `errors: {field: [messages]}`), 422 tenant header missing (`INVALID_TENANT_ID`, only when tenancy is on), 429 (framework throttle, `Retry-After`), plus `mcp: {-32001: unauthenticated or unknown table, -32002: forbidden, -32601: tool not found}`. The test asserts each row against a real response, so a wrong constant fails the build.
- `rateLimits`: the three groups with `EndpointContext::throttle()` limits and the rule "on 429 wait `Retry-After` seconds, then retry".
- `nestedWrites`: `_delete` needs the related `id`; attaching a record the caller cannot read → 422; one transaction for the parent and every child; "each child item needs the child table's create/update/delete permission; attaching an existing row needs only the parent's"; `canCreate/canUpdate/canDelete: false` on the child → 422; a bare id attaches (many-to-many, hasManyThrough) and is a 422 in hasMany/morphMany; nested child changes are not audit-logged.
- `docs`: the real URLs (`/{apiPrefix}/docs/openapi.json`, `/{apiPrefix}/docs/llms.txt`) found by scanning the registered routes, plus `private` from `record.api_docs.is_private`.
- `realtime`: channel `private-tenant.{tenantId}` (`Echo.private('tenant.42')`), `tenant.global` when there is no tenant, event name `{table}.{action}` (read `RecordMutated::broadcastAs()` for the exact format first), `tables` = `broadcastTables()` or `"all"`, and "the client needs the app's broadcasting auth".
- `recommendations`: ordered list of `{rule, reason}` for the ten items of spec §6.6 (P), with numbers from config. Omit: rule 8's `fts` text unless the driver is pgsql (otherwise say "`search` or `starts_with`/`contains` on indexed columns"), rule 6's `async` sentence when `queue.default` is `sync`, rule 6 entirely when `bulk_operations` is off, rule 10 when `cacheEnabled()` is false.

- [ ] **Step 1: Write the failing tests**

`McpGuidanceReferenceTest` (fixture + `app(SchemaTools::class)->apiGuidance()`):
1. `test_headers_name_the_configured_tenant_header_only_with_tenancy`: tenancy on, `record.tenant_header=X-Org` → `headers` has `X-Org`, not `X-Tenant-ID`; tenancy off → neither.
2. `test_query_syntax_covers_the_hidden_features`: keys `relationshipFilters`, `negation`, `modifiers`, `substring`, `selectVsWith`, `trashed` exist; the `substring` text says `%` is unnecessary; no value anywhere in `querySyntaxExamples` contains `%acme%`.
3. `test_operator_catalogue_follows_the_driver`: on sqlite `operators.catalogue` has no `regex`/`fts`/`cs`; stubbing the driver is not possible, so assert via `FilterOperatorCatalog::catalogue('pgsql')` equality for a second `ApiReference::build(driver: 'pgsql')` call — `ApiReference::build(?string $driver = null)` takes the driver for exactly this test; the pgsql build contains `fts`, `cs`, `regex`.
4. `test_pagination_reports_live_limits_and_options`: change `record.limit_max`/`per_page_max` → the values change; `skip_total`, `add_total`, `cursor_column`, `direction` are listed.
5. `test_errors_match_real_responses` (the oracle test): produce a 401 (`secure` table with `isAuthRead: true`, no auth), a 404 (`GET /api/invoices/999999`), a 422 validation (`POST /api/invoices` with `{}`), a 422 tenant missing (tenant fixture without the header), a 403 (authenticated user without permission — reuse the pattern from `RelationshipPermissionsTest`) and a 429 (`RateLimiter::for('api-reads', fn () => Limit::perMinute(1))` + `throttle:api-reads` is on the real route; two requests) and assert the table's `status` and `error_code` equal the observed ones, and that the 422 body has `errors`; the MCP block names `-32001`, `-32002`, `-32601`.
6. `test_rate_limits_follow_the_registered_limiters`: `RateLimiter::for('api-writes', fn () => Limit::perMinute(7))` → `rateLimits.api-writes.limit === 7`.
7. `test_docs_urls_are_the_registered_routes`: `docs.openapi` equals `/api/docs/openapi.json`; with `Config::set('record.api_prefix','x1')`-style prefix change (use the helper the other URL tests use) the URL follows.
8. `test_realtime_only_when_enabled`: absent by default; with `record.broadcast_events=true` and `broadcast_tables=['invoices']` present with the channel, event format and `tables === ['invoices']`.
9. `test_recommendations_are_true_for_this_app`: on sqlite no recommendation text contains `fts`; with `queue.default=sync` no entry mentions `async`; with `record.bulk_operations=false` no bulk entry; every number mentioned (`bulk_max`, `limit_max`) equals config; changing `record.bulk_max` changes the text.
10. `test_nested_write_rules_are_stated`: `nestedWrites` mentions `_delete`, `transaction`, `audit`, `permission`.
11. `test_output_schema_accepts_the_new_keys`: validate the guidance array against `ToolCatalog::schema()[3]->outputSchema` with a minimal checker (every key present in the output is declared in `properties`, every `required` key is present) — and the existing `McpStructuredOutputTest` still passes.

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit --filter McpGuidanceReferenceTest`
Expected: FAIL — keys missing.

- [ ] **Step 3: Implement**

`ApiReference` (final, instance). `build(?string $driver = null)` passes `$driver` to `FilterOperatorCatalog::catalogue()`. `SchemaTools::apiGuidance()` becomes `return [...existing blocks with corrected examples (Task 3)..., ...(new ApiReference())->build()];` with `array_merge` so existing keys keep their position first.

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit --filter "McpGuidanceReferenceTest|McpStructuredOutputTest|McpSchemaEndpointCoverageTest"`
Expected: PASS.

- [ ] **Step 5: Mutation checks, then ledger**

(a) Change the 404 row's constant → the oracle test fails. (b) Remove the `queue.default` check → test 9 fails. (c) Make `docs` emit unconditionally → test 7's prefix case fails. Restore. Skip the commit.

---

### Task 6: Module recipes (M5)

**Files:**
- Create: `src/Mcp/Guidance/ModuleRecipes.php`
- Modify: `src/Mcp/Guidance/ApiReference.php` (`modules` key), `src/Mcp/ToolCatalog.php` (`modules` property)
- Test: `tests/Feature/McpModuleRecipesTest.php` (new)

**Interfaces:**
- Consumes: `SchemaRegistryUtils::getTable()`, `RecordConfigService::{auditEnabled, apiPrefix, rpcPrefix, permissionSeparator, ownRecordsPermissionPrefix}`, `config('attachments.*')`, `config('permissions.enabled')`.
- Produces: `ModuleRecipes::build(): array<string, array>` — only entries for **enabled** modules whose tables are registered: `audit`, `permissions`, `attachments`. Each recipe is `{summary, endpoints[], steps[] | recipes[], notes[]}`; every URL is built from the registered table key and the function keys of its config (`/{apiPrefix}/{table}/{rpcPrefix?}/{fnKey}`), never typed out.

Recipes:
- **audit** (`audit.enabled` and the `audit.audit_log_model` table registered): `history` = `GET /{api}/{auditTable}?entity_type=eq.<table>&entity_id=eq.<id>&order=desc` (confirm the column names against the registered table's `columns`; drop the recipe line if they are absent); `fieldTimeline` and `fieldStats` = the real RPC URIs with their path parameters named (`entityType` takes the table name); `notes`: the JSON columns (`old_values`/`new_values` — confirm names) can only be filtered as text with `contains`, there is no JSON-path filter, use `fieldTimeline` for one field's history; nested child changes are not logged.
- **permissions** (`permissions.enabled` and the roles table registered): `nameFormat` = `{action}{separator}{pmsName}` with the live separator and the list of action verbs from `RecordConstants::ACTION_*`; `viewOwn` = the live `ownRecordsPermissionPrefix()` plus "narrows reads, writes and relationships to records the caller created"; `attachToRole` = `PUT /{api}/{rolesTable}/{id}` with `{"permissions":[{"id":"<permission id>"}]}` (only when the roles table has a `permissions` include); `assignRoles` = the endpoint and payload of any registered table whose include resolves to the roles table, else the sentence "This package exposes no endpoint for assigning roles to users unless your users table declares a roles relationship; none is declared." Note that `sp_api_list_permissions` returns bare names only.
- **attachments** (`attachments.enabled` and the attachments table registered): `upload` = the real RPC URI, `multipart/form-data`, the field names from its `payloadSchema` (`file` binary), `visibility` values from the schema enum; `linkAfterUpload` = `POST` the `record/{table}/{record_id}` RPC with `{"attachment_ids":[…]}`; `uploadAndLink` = upload with `record_type` (the table name) and `record_id`; `view`/`download` = the real RPC URIs, with the resizing parameters listed only when `attachments.read_resizing` is on. Functions removed by `RecordConfigService`'s gating (direct upload, preview) are not mentioned because the recipe reads the registered function keys.

- [ ] **Step 1: Write the failing tests**

`McpModuleRecipesTest`:
1. `test_no_module_blocks_when_modules_are_off`: `audit.enabled=false`, `permissions.enabled=false`, `attachments.enabled=false` → `apiGuidance()['modules']` is `[]` (the key is always present, empty when nothing is enabled).
2. `test_audit_recipe_uses_the_live_routes`: `audit.enabled=true` → `modules.audit` exists; its `fieldTimeline.uri` equals the `uri` that `listEndpoints` reports for the `field-timeline/...` function of the audit table; changing `record.rpc_prefix` changes both (the rename test required by Review Focus 4); `history` contains `entity_type=eq.` and `entity_id=eq.`.
3. `test_permissions_recipe_reads_the_separator_and_prefix`: `permissions.enabled=true` → `nameFormat` uses `RecordConfigService::permissionSeparator()`; changing the separator via config changes it; `viewOwn.prefix` equals `ownRecordsPermissionPrefix()`; with no `roles` include in the registry `assignRoles` is the "exposes no endpoint" sentence; after registering a `users` table with a `roles` include it names `users` and the payload.
4. `test_attachments_recipe_lists_multipart_upload_and_link_flow`: `modules.attachments.upload.contentType === 'multipart/form-data'`, fields include `file`, visibility includes `temp_private`; `linkAfterUpload.payload` has `attachment_ids`; `uploadAndLink` mentions `record_type` and `record_id`; resizing params appear only when `attachments.read_resizing` is true; the `preview` URI is absent while `attachments.preview_url_enabled` is false and present when true.
5. `test_modules_block_is_omitted_per_module_not_globally`: only `attachments.enabled=true` → only `attachments` under `modules`.

- [ ] **Step 2: Run to verify it fails** — `vendor/bin/phpunit --filter McpModuleRecipesTest` → FAIL (`modules` key missing).

- [ ] **Step 3: Implement** as specified. Register the module tables in the test through `Config::set('audit.enabled', true)` etc.; the package config already merges `audit`, `permissions`, `attachments` namespaces, so `RecordConfigService::getTableConfig()` yields their tables once enabled.

- [ ] **Step 4: Run to verify it passes** — `vendor/bin/phpunit --filter "McpModuleRecipesTest|McpGuidanceReferenceTest"` → PASS.

- [ ] **Step 5: Mutation check, then ledger** — hard-code `field-timeline` URI prefix → test 2's rpc-prefix case fails; restore. Skip the commit.

---

### Task 7: Smaller responses (compact text copy, `actions` argument, budget)

**Files:**
- Modify: `src/Mcp/ToolResult.php` (line 45), `src/Mcp/ToolExecutor.php` (line ~172, the data-tool text), `src/Mcp/ToolCatalog.php` (`sp_api_get_endpoint` input schema), `src/Mcp/SchemaTools.php` (`getEndpoint`)
- Modify/Delete: `tests/Feature/McpExtractionParityTest.php` and `tests/Fixtures/mcp/*` for the content this phase intentionally changes
- Test: `tests/Feature/McpResponseSizeTest.php` (new)

**Interfaces:**
- Consumes: everything above.
- Produces: `sp_api_get_endpoint` accepts `actions` (array of strings, optional): the response keeps only those entries of `actions`; an unknown name throws `Unknown action(s): x. Available: list, read, …` (a tool error, as for an unknown endpoint). `content[0].text` is `json_encode($value, JSON_UNESCAPED_SLASHES)` — compact, same value as `structuredContent` for the tools whose two copies are equal.

- [ ] **Step 1: Write the failing tests**

`McpResponseSizeTest` (fixture; `app(ToolExecutor::class)` — constructible without arguments):
1. `test_text_copy_is_the_compact_json_of_the_structured_copy`: for `sp_api_get_endpoint invoices` and `sp_api_get_api_guidance`: `$wire = $result->toWireArray()`; `assertStringNotContainsString("\n", $wire['content'][0]['text'])`; `assertSame($wire['structuredContent'], json_decode($wire['content'][0]['text'], true))`.
2. `test_full_endpoint_response_stays_under_the_budget`: `strlen(json_encode($wire))` < 40_000.
3. `test_action_subset_stays_under_the_small_budget`: `call('sp_api_get_endpoint', ['endpoint'=>'invoices','actions'=>['list','create']])` → `array_keys(structuredContent['actions']) === ['list','create']` and the whole wire < 12_000.
4. `test_unknown_action_names_are_an_error`: `actions=['nope']` → `isError` with a message naming `nope` and listing the available actions.
5. `test_input_schema_declares_actions`: `ToolCatalog::schema()[1]->inputSchema['properties']['actions']['type'] === 'array'`.
6. `test_data_tool_text_is_compact_too`: a `list_invoices` call's text has no newline and decodes to the same array as `structuredContent['response']`… (match the shape the existing `McpStructuredOutputTest` asserts).

- [ ] **Step 2: Run to verify it fails** — `vendor/bin/phpunit --filter McpResponseSizeTest` → FAIL (pretty-printed text, no `actions`).

- [ ] **Step 3: Implement.** If test 2 or 3 exceed the budget after the compaction, apply these levers in order, re-measuring after each, and ledger which were needed: (1) drop `description`/`guidance` strings that repeat the shared reference (the per-action `guidance` sentence stays once per body/bodyless class); (2) emit each action's `response.envelope` once at the top level of the endpoint instead of per action; (3) for bulk actions, emit `request.payload` as `{"type":"array","items":{"$ref":"#/payloads/create"}}`-style short references only if (1)–(2) are not enough — rule it explicitly because it changes a value shape.

- [ ] **Step 4: Retire the old snapshots.** Run `vendor/bin/phpunit --filter McpExtractionParityTest`; every failing fixture pins content this phase changed on purpose (schema tools, `tools/list` for `sp_api_get_endpoint`). Delete exactly those fixtures and their test methods; keep the fixtures for data tools, resources, errors and the other tools. Record each deleted fixture in the ledger. Everything the deleted fixtures guarded is covered by Tasks 3–6; add no replacement snapshots.

- [ ] **Step 5: Run to verify it passes**

Run: `vendor/bin/phpunit --filter "Mcp"` and `SP_MCP_DRIVER=laravel vendor/bin/phpunit --filter "Mcp"`
Expected: PASS on both drivers (the 12 legacy-only skips stay skipped).

- [ ] **Step 6: Mutation check, then ledger** — re-add `JSON_PRETTY_PRINT` → test 1 fails; restore. Skip the commit.

---

### Task 8: Phase gate and the behaviour doc

**Files:**
- Modify: `docs/guide/api/api-nested-and-bulk-operations.md` (bare-id rules, W1/Q7), `docs/guide/api/api-filter-operators.md` (one line: the operator list is the same one the MCP schema tools advertise, per driver)
- No new tests.

- [ ] **Step 1:** In `api-nested-and-bulk-operations.md`, under the many-to-many and hasMany sections, add: bare ids attach in `belongsToMany`/`morphToMany`/`hasManyThrough` arrays and are a 422 in `hasMany`/`morphMany` arrays; empty values are a 422. Add the Changed note to both changelogs in phase 5 (not here).
- [ ] **Step 2: Full gate.** `vendor/bin/phpunit` (all green), the laravel-driver matrix command from Global Constraints (0 failures), `vendor/bin/phpstan analyse src tests` (`[OK]`), `vendor/bin/rector process --dry-run --no-progress-bar` (apply to the files created in this plan only), `php bin/validate-docs.php` (8 pre-existing failures only), `graft build`.
- [ ] **Step 3: Ledger** the gate results; mark the plan complete.

## Self-Review

- **Spec coverage:** W1 → T1 (backend) + T3 (hints, examples, follow-the-hint); W2 → T3; W3 → T4; W4 → T3; W5 → T3; W6 → T3; M1 → T4 (+T5 `headers`); M2 → T4 (query params) + T5 (`querySyntax`); M3 → T5 (oracle test); M4 → T3 (child permissions) + T5 (`nestedWrites`); M5 → T6; C1 → T2 + T3 (+T5 catalogue); C2 → T4 + T5; C3 → T4; C4 → T4; C5 → T4 + T5; C6 → T4; C7 → T5; C8 → T5; C9 → T3; P → T5; Size → T7; "retire parity fixtures" → T7; docs for behaviour → T8 (module-mcp rewrite and changelog are phase 5).
- **Placeholders:** none; where a literal must be confirmed (parameter names, `RecordMutated::broadcastAs()`, audit column names, `record.id_type` key) the step says what to grep and what to do with the answer.
- **Type consistency:** `FilterOperatorCatalog::forFamily/catalogue/supports/currentDriver` (T2) are the names used in T3 and T5; `ColumnTypes::family/jsonSchema/sample` (T3) are used by `PayloadSchemaBuilder` and `SchemaTools`; `EndpointContext::{headers,rpcHeaders,throttle,listExtras,bulkExtras,resizingQuerySchema}` (T4) are used by T5; `ApiReference::build(?string $driver)` (T5) is extended by T6's `modules`.
- **Review Focus:** 1 → T2 `driverRules`, T3 `McpAdvertisedOperatorsTest`; 2 → T3 `McpFollowTheHintTest`; 3 → T1; 4 → T4 tests 2/6/7, T5 tests 1/4/6/7/8/9, T6 test 2; 5 → T7 tests 2/3.
