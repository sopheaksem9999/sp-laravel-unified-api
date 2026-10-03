---
title: "Select Param Validation Plan"
description: "Implementation plan for turning silent ?select= failures into 422 validation errors."
keywords:
  - select
  - validation
  - 422
  - implementation plan
---

# Select Param Validation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn three silent-failure modes in `?select=` (unknown main column, unknown relationship alias, unknown column on a related table) into clear 422 validation errors, and fix the controller/helper gaps that would otherwise swallow those errors as a 500 or a silent `null`.

**Architecture:** Fix each of the three gaps at its existing source in `RelationshipResolverUtils` (no new centralized validation pass), reusing the existing `InvalidArgumentException` → 422 pattern already used for filter/operator validation. Then close the two places that would otherwise prevent that exception from reaching the client cleanly: missing `catch (InvalidArgumentException $e)` blocks in most CRUD/bulk controller methods, and a blanket `catch (Exception)` in `HasControllerHelpers::fetchRecordData()` that swallows it entirely.

**Tech Stack:** PHP 8.3, Laravel/Illuminate, PHPUnit 10/11.

## Global Constraints

- Always-on validation, no config gate.
- Fail fast on the first invalid selector, in processing order (main-table column checks run before any query; relationship/nested-column checks run during post-fetch enrichment) — not necessarily left-to-right in the query string.
- Error message states the invalid name and lists valid alternatives, sorted alphabetically, never including `'*'`. No fuzzy-match suggestions.
- Plain `InvalidArgumentException` throughout — no new exception class.
- A relationship alias that *is* declared in the schema but that `resolveRelationship()` still returns null for (the `RecordAassociationType` non-`hasManyThrough` branch, `RelationshipResolverUtils.php:403-408`) keeps its current silent no-op — out of scope.
- Design doc: `docs/superpowers/specs/2026-08-05-select-param-validation-design.md`.

---

### Task 1: Main-table column validation

**Files:**
- Modify: `src/Utilities/RelationshipResolverUtils.php` — add `use InvalidArgumentException;`, add `validateMainTableColumns()`.
- Modify: `src/Services/RecordService.php:2143, 2191, 2781, 2829, 2926, 3017` — one call each.
- Modify: `src/Services/Queries/RecordQueryBuilder.php:108` — one call.
- Test: `tests/Feature/SelectParamValidationTest.php` (new).

**Interfaces:**
- Produces: `RelationshipResolverUtils::validateMainTableColumns(string $table, array $columns): void` — throws `InvalidArgumentException` on the first `$columns` entry that is not `'*'`, not a key in the table's declared `columns`, and not a key in its declared `attributes`. No-op on an empty `$columns` array or an unregistered `$table`. Used by Tasks 2-4's tests as the "known-working baseline" — do not rename.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/SelectParamValidationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Services\Queries\RecordQueryBuilder;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class SelectParamValidationTest extends TestCase
{
    use RefreshDatabase;

    protected int $authorId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('authors', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('author_id');
            $table->string('title');
            $table->timestamps();
        });

        $this->authorId = DB::table('authors')->insertGetId([
            'name' => 'Ada Lovelace',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('posts')->insert([
            'author_id' => $this->authorId,
            'title' => 'First Post',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Config::set('record.tables', [
            'authors' => new RecordTableType(
                table: 'authors',
                pmsName: 'authors',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                columns: [
                    'id' => ['type' => 'integer', 'nullable' => false],
                    'name' => ['type' => 'string', 'nullable' => false],
                    'created_at' => ['type' => 'datetime', 'nullable' => true],
                    'updated_at' => ['type' => 'datetime', 'nullable' => true],
                ],
                relationships: [
                    'posts' => new RecordHasManyType(table: 'posts', foreignKey: 'author_id'),
                ],
                attributes: [
                    'display_name' => fn($record): string => ($record->name ?? '') . ' (author)',
                ],
            ),
            'posts' => new RecordTableType(
                table: 'posts',
                pmsName: 'posts',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                columns: [
                    'id' => ['type' => 'integer', 'nullable' => false],
                    'author_id' => ['type' => 'integer', 'nullable' => false],
                    'title' => ['type' => 'string', 'nullable' => false],
                    'created_at' => ['type' => 'datetime', 'nullable' => true],
                    'updated_at' => ['type' => 'datetime', 'nullable' => true],
                ],
                relationships: [],
            ),
        ]);

        SchemaRegistryUtils::refresh();
    }

    /** @test */
    public function unknown_main_table_column_returns_422(): void
    {
        $response = $this->getJson('/api/authors?select=bogus_field');

        $response->assertStatus(422);
        $response->assertJsonPath('message', "Unknown column 'bogus_field' in select for table 'authors'. Valid columns: created_at, display_name, id, name, updated_at.");
    }

    /** @test */
    public function computed_attribute_is_a_valid_main_column(): void
    {
        $response = $this->getJson('/api/authors?select=id,name,display_name');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.display_name', 'Ada Lovelace (author)');
    }

    /** @test */
    public function wildcard_select_still_works_on_main_table(): void
    {
        $response = $this->getJson('/api/authors?select=*');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.name', 'Ada Lovelace');
    }

    /** @test */
    public function record_query_builder_validates_main_columns(): void
    {
        $config = new RecordTableType(table: 'authors', pmsName: 'authors');
        $builder = new RecordQueryBuilder('authors', $config);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown column 'bogus_field' in select for table 'authors'.");

        $builder->applySelectFromParam('bogus_field');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter=SelectParamValidationTest`
Expected: `unknown_main_table_column_returns_422` FAILS (today this either 500s with a raw SQL error or otherwise does not return 422 with this message). `computed_attribute_is_a_valid_main_column` and `wildcard_select_still_works_on_main_table` PASS already (nothing broken yet). `record_query_builder_validates_main_columns` FAILS (no exception thrown today).

- [ ] **Step 3: Add the validation function**

In `src/Utilities/RelationshipResolverUtils.php`, add the import right after the namespace declaration:

```php
use InvalidArgumentException;
```

Add this new public method (a good location is directly after `getMainTableColumns()`, i.e. after line 323):

```php
    /**
     * Throws if any requested main-table column is not a real column or a
     * declared computed attribute on the table's schema. '*' is always valid.
     * No-op for an empty column list or an unregistered table.
     *
     * @param string[] $columns
     */
    public static function validateMainTableColumns(string $table, array $columns): void
    {
        if ([] === $columns) {
            return;
        }

        $tableSchema = self::getSchema()[$table] ?? null;
        if (null === $tableSchema) {
            return;
        }

        $validNames = array_merge(
            array_keys($tableSchema->columns ?? []),
            array_keys($tableSchema->attributes ?? [])
        );

        foreach ($columns as $column) {
            if ('*' === $column || in_array($column, $validNames, true)) {
                continue;
            }

            sort($validNames);
            throw new InvalidArgumentException(sprintf(
                "Unknown column '%s' in select for table '%s'. Valid columns: %s.",
                $column,
                $table,
                implode(', ', $validNames)
            ));
        }
    }
```

- [ ] **Step 4: Wire the validation into all 7 call sites**

In `src/Services/Queries/RecordQueryBuilder.php`, in `applySelectFromParam()`, change:

```php
        $mainCols = RelationshipResolverUtils::getMainTableColumns($selectParam);
        if (!empty($mainCols)) {
```

to:

```php
        $mainCols = RelationshipResolverUtils::getMainTableColumns($selectParam);
        RelationshipResolverUtils::validateMainTableColumns($this->table, $mainCols);
        if (!empty($mainCols)) {
```

In `src/Services/RecordService.php`, make these six changes:

At line 2143 (inside `listRecords()`), change:

```php
                    $mainCols = RelationshipResolverUtils::getMainTableColumns(is_string($selectParam) ? $selectParam : '');
                    // Strip computed attribute keys — they are not real DB columns
```

to:

```php
                    $mainCols = RelationshipResolverUtils::getMainTableColumns(is_string($selectParam) ? $selectParam : '');
                    RelationshipResolverUtils::validateMainTableColumns($table, $mainCols);
                    // Strip computed attribute keys — they are not real DB columns
```

At line 2191 (inside `listRecords()`), change:

```php
            $requestedCols = $effectiveSelectParam !== ''
                ? RelationshipResolverUtils::getMainTableColumns(is_string($selectParam) ? $selectParam : '')
                : [];
            $data = RecordApiResponseService::applyAttributes($data, $table, $tableSchema->attributes, $requestedCols);
```

to:

```php
            $requestedCols = $effectiveSelectParam !== ''
                ? RelationshipResolverUtils::getMainTableColumns(is_string($selectParam) ? $selectParam : '')
                : [];
            RelationshipResolverUtils::validateMainTableColumns($table, $requestedCols);
            $data = RecordApiResponseService::applyAttributes($data, $table, $tableSchema->attributes, $requestedCols);
```

At line 2781 (inside `applyRequestFilters()`), change:

```php
                    $mainCols = RelationshipResolverUtils::getMainTableColumns(is_string($selectParam) ? $selectParam : '');
                    // Strip computed attribute keys — they are not real DB columns
```

to:

```php
                    $mainCols = RelationshipResolverUtils::getMainTableColumns(is_string($selectParam) ? $selectParam : '');
                    RelationshipResolverUtils::validateMainTableColumns($table, $mainCols);
                    // Strip computed attribute keys — they are not real DB columns
```

At line 2829 (inside `applyRequestFilters()`), change:

```php
            $requestedCols = $effectiveSelectParam !== ''
                ? RelationshipResolverUtils::getMainTableColumns(is_string($selectParam) ? $selectParam : '')
                : [];
            $data = RecordApiResponseService::applyAttributes($data, $table, $tableSchema->attributes, $requestedCols);
```

to:

```php
            $requestedCols = $effectiveSelectParam !== ''
                ? RelationshipResolverUtils::getMainTableColumns(is_string($selectParam) ? $selectParam : '')
                : [];
            RelationshipResolverUtils::validateMainTableColumns($table, $requestedCols);
            $data = RecordApiResponseService::applyAttributes($data, $table, $tableSchema->attributes, $requestedCols);
```

At line 2926 (inside `getRecord()`), change:

```php
            $mainCols = RelationshipResolverUtils::getMainTableColumns(is_string($selectParam) ? $selectParam : '');
            // Build DB-safe column list: strip computed attribute keys (not real DB columns)
```

to:

```php
            $mainCols = RelationshipResolverUtils::getMainTableColumns(is_string($selectParam) ? $selectParam : '');
            RelationshipResolverUtils::validateMainTableColumns($table, $mainCols);
            // Build DB-safe column list: strip computed attribute keys (not real DB columns)
```

At line 3017 (inside `getRecord()`), change:

```php
            $requestedCols = $effectiveSelectParam !== ''
                ? RelationshipResolverUtils::getMainTableColumns(is_string($selectParam) ? $selectParam : '')
                : [];
            $record = RecordApiResponseService::applyAttributes($record, $table, $tableSchema->attributes, $requestedCols);
```

to:

```php
            $requestedCols = $effectiveSelectParam !== ''
                ? RelationshipResolverUtils::getMainTableColumns(is_string($selectParam) ? $selectParam : '')
                : [];
            RelationshipResolverUtils::validateMainTableColumns($table, $requestedCols);
            $record = RecordApiResponseService::applyAttributes($record, $table, $tableSchema->attributes, $requestedCols);
```

Note: several of these run *after* an earlier call site in the same request has already validated the same string and would have thrown first. That's intentional redundancy, not a bug — see the design doc's "Where each check lives" section. Every site still needs the call, since which one executes first depends on the request path taken.

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter=SelectParamValidationTest`
Expected: all 4 tests PASS.

- [ ] **Step 6: Run the full suite for regressions**

Run: `vendor/bin/phpunit`
Expected: PASS, same count as baseline plus 4.

This was checked exhaustively before writing this plan: only two other places in the suite issue a non-wildcard main-table `?select=` — `tests/Feature/DynamicApiTest.php`'s `it_supports_field_selection` (`users` table) and `tests/Feature/ApplyRequestFiltersConfigTest.php`'s two `select` tests (`qht_categories` table) — and neither declares `columns:` on its `RecordTableType`. Both are still expected to pass unmodified: `validateMainTableColumns()` reads through `self::getSchema()`, which is backed by `SchemaRegistryUtils::get()`'s existing DB-introspection fallback (`SchemaRegistryUtils.php:88-92`) — when `columns` is empty, it's populated from the real table's schema (SQLite `PRAGMA table_info`, Postgres `information_schema`, or MySQL `DESCRIBE`) before anything reads it, and both fixtures create their table with `Schema::create()` before registering the (columns-less) config. If either test fails here anyway, don't weaken the check to route around it — investigate why the fallback didn't fire (e.g. a table created after config registration, or on a driver where introspection behaves differently) and fix the fixture's ordering or add an explicit `columns:` declaration instead.

- [ ] **Step 7: Commit**

```bash
git add src/Utilities/RelationshipResolverUtils.php src/Services/RecordService.php \
        src/Services/Queries/RecordQueryBuilder.php tests/Feature/SelectParamValidationTest.php
git commit -m "feat: validate unknown main-table columns in ?select="
```

---

### Task 2: Unknown relationship alias validation

**Files:**
- Modify: `src/Utilities/RelationshipResolverUtils.php:1753-1756` (`includeRelationshipsRecursive()`) **and** `RelationshipResolverUtils.php:74-77` (`applySubqueryRelationships()`) — discovered during implementation. `listRecords()` takes an "optimized subquery" fast path (`applySubqueryRelationships()`) whenever none of the requested relationships have nested children; that path has its own independent `if (!$config) { continue; }` silently dropping unknown aliases, identical to the one in `includeRelationshipsRecursive()` but never found by the original design-phase research (which only traced one of the two parallel implementations). A top-level `?select=bogus_rel(*)` with no nested children hits this path exclusively; a nested unknown alias still reaches `includeRelationshipsRecursive()` for the child level. Both need the identical fix.
- Test: `tests/Feature/SelectParamValidationTest.php` (extend).

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces: no new public function — behavior change inside `includeRelationshipsRecursive()` and `applySubqueryRelationships()` only.

- [ ] **Step 1: Write the failing tests**

Add to `SelectParamValidationTest`:

```php
    /** @test */
    public function unknown_top_level_relationship_returns_422(): void
    {
        $response = $this->getJson('/api/authors?select=bogus_rel(*)');

        $response->assertStatus(422);
        $response->assertJsonPath('message', "Unknown relationship 'bogus_rel' in select for table 'authors'. Valid relationships: posts.");
    }

    /** @test */
    public function unknown_nested_relationship_returns_422(): void
    {
        $response = $this->getJson('/api/authors?select=posts(bogus_child(*))');

        $response->assertStatus(422);
        $response->assertJsonPath('message', "Unknown relationship 'bogus_child' in select for table 'posts'. Valid relationships: none.");
    }

    /** @test */
    public function valid_relationship_select_still_works(): void
    {
        $response = $this->getJson('/api/authors?select=id,posts(title)');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.posts.0.title', 'First Post');
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter=SelectParamValidationTest`
Expected: `unknown_top_level_relationship_returns_422` and `unknown_nested_relationship_returns_422` FAIL — today both silently return 200 with the bad relationship simply absent from the response. `valid_relationship_select_still_works` already PASSES.

- [ ] **Step 3: Implement the fix**

In `src/Utilities/RelationshipResolverUtils.php`, inside `includeRelationshipsRecursive()`, change:

```php
            $config = self::resolveRelationship($table, $alias, $hintTable);
            if (!$config) {
                continue;
            }
```

to:

```php
            $config = self::resolveRelationship($table, $alias, $hintTable);
            if (!$config) {
                $schema = self::getSchema();
                $declaredRelationships = isset($schema[$table]) ? ($schema[$table]->relationships ?? []) : [];
                if (!array_key_exists($alias, $declaredRelationships)) {
                    $validNames = array_keys($declaredRelationships);
                    sort($validNames);
                    throw new InvalidArgumentException(sprintf(
                        "Unknown relationship '%s' in select for table '%s'. Valid relationships: %s.",
                        $alias,
                        $table,
                        [] === $validNames ? 'none' : implode(', ', $validNames)
                    ));
                }

                continue;
            }
```

Then, in the same file, inside `applySubqueryRelationships()`, change:

```php
            $config = self::resolveRelationship($table, $alias, $hintTable);
            if (!$config) {
                continue;
            }

            $relatedTable = $config['table'];
            $type = $config['type'];
```

to:

```php
            $config = self::resolveRelationship($table, $alias, $hintTable);
            if (!$config) {
                $declaredRelationships = isset($schema[$table]) ? ($schema[$table]->relationships ?? []) : [];
                if (!array_key_exists($alias, $declaredRelationships)) {
                    $validNames = array_keys($declaredRelationships);
                    sort($validNames);
                    throw new InvalidArgumentException(sprintf(
                        "Unknown relationship '%s' in select for table '%s'. Valid relationships: %s.",
                        $alias,
                        $table,
                        [] === $validNames ? 'none' : implode(', ', $validNames)
                    ));
                }

                continue;
            }

            $relatedTable = $config['table'];
            $type = $config['type'];
```

Note `applySubqueryRelationships()` already has `$schema = self::getSchema();` at the top of the function (used later for `$schema[$relatedTable]`) — reuse that variable rather than re-fetching it, unlike the `includeRelationshipsRecursive()` change above, which needed a local `$schema` fetch since none was already in scope there.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter=SelectParamValidationTest`
Expected: all 7 tests PASS.

- [ ] **Step 5: Run the full suite for regressions**

Run: `vendor/bin/phpunit`
Expected: PASS. Pay particular attention to `tests/Feature/RelationshipFilterMissingTableTest.php` and any other test that exercises a relationship intentionally absent from the schema registry (as opposed to absent from a table's own `relationships` array) — this fix only throws for aliases that are not declared at all; if any existing test relies on an alias being declared but still unresolvable (the `RecordAassociationType` non-`hasManyThrough` case), it must keep passing unchanged.

- [ ] **Step 6: Commit**

```bash
git add src/Utilities/RelationshipResolverUtils.php tests/Feature/SelectParamValidationTest.php
git commit -m "feat: validate unknown relationship aliases in ?select="
```

---

### Task 3: Unknown nested-column validation, and the interaction test

**Files:**
- Modify: `src/Utilities/RelationshipResolverUtils.php:2218, 2414-2426` (`applyColumnSelection()` and its call site) — covers the "standard" relationship-loading path (`loadRelatedRecords()` → `loadStandardRelationshipOptimized()`).
- Modify: `src/Utilities/RelationshipResolverUtils.php` — `resolveJsonObjectColumns()`, `buildJsonObjectExpression()`, `buildJsonArrayAggExpression()`, and their 6 call sites — discovered during implementation. Same pattern as Task 2's second location: `listRecords()`'s "optimized subquery" fast path builds related-record JSON via a completely separate column-filtering function (`resolveJsonObjectColumns()`, used inside the JSON-object/JSON-array-agg SQL builders), independent of `applyColumnSelection()`. A relationship with no nested children (e.g. `?select=posts(bogus_field)` where `posts` has no sub-relationships) goes through this path exclusively, never touching `applyColumnSelection()` at all. Both need the identical fix.
- Test: `tests/Feature/SelectParamValidationTest.php` (extend).
- Modify: `tests/Unit/RelationshipResolverUtilsJsonExpressionTest.php:30` — this existing test invokes `buildJsonObjectExpression()` directly via `ReflectionMethod` with 3 args; it needs a 4th (`'vendors'` again is fine — its 51 columns are all declared valid, so the new parameter never triggers the throw, it's just a required signature change).

**Interfaces:**
- Consumes: Task 1's `validateMainTableColumns()` and Task 2's relationship-alias check, for the interaction test.
- Produces: no new public function. `resolveJsonObjectColumns()`, `buildJsonObjectExpression()`, and `buildJsonArrayAggExpression()` each gain a new trailing `string $relatedTable` parameter — update every call site, not just the one this task's test happens to exercise.

- [ ] **Step 1: Write the failing tests**

Add to `SelectParamValidationTest`:

```php
    /** @test */
    public function unknown_nested_column_returns_422(): void
    {
        $response = $this->getJson('/api/authors?select=posts(bogus_field)');

        $response->assertStatus(422);
        $response->assertJsonPath('message', "Unknown column 'bogus_field' in select for table 'posts'. Valid columns: author_id, created_at, id, title, updated_at.");
    }

    /** @test */
    public function nested_wildcard_select_still_works(): void
    {
        $response = $this->getJson('/api/authors?select=id,posts(*)');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.posts.0.title', 'First Post');
        $response->assertJsonPath('data.0.posts.0.author_id', $this->authorId);
    }

    /** @test */
    public function invalid_main_column_error_wins_over_invalid_relationship(): void
    {
        $response = $this->getJson('/api/authors?select=bogus_field,bogus_rel(*)');

        $response->assertStatus(422);
        $response->assertJsonPath('message', "Unknown column 'bogus_field' in select for table 'authors'. Valid columns: created_at, display_name, id, name, updated_at.");
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter=SelectParamValidationTest`
Expected: `unknown_nested_column_returns_422` FAILS — today the bad column is silently dropped and the request returns 200. `nested_wildcard_select_still_works` and `invalid_main_column_error_wins_over_invalid_relationship` already PASS (the former because `applyColumnSelection()` already early-returns on `['*']` before any change; the latter because Task 1's check already runs first and throws the main-column error before the relationship check is ever reached) — both exist to lock in behavior this task must not break, not to drive new code.

- [ ] **Step 3: Implement the fix**

In `src/Utilities/RelationshipResolverUtils.php`, change `applyColumnSelection()`'s signature and body from:

```php
    private static function applyColumnSelection($query, array $columns, array $schemaColumns): void
    {
        if ($columns === ['*'] || [] === $columns) {
            return; // No filtering needed
        }

        // Validate and filter columns against schema
        $validColumns = array_values(array_filter($columns, fn($column): bool => '*' === $column || isset($schemaColumns[$column])));

        if ([] !== $validColumns) {
            $query->select($validColumns);
        }
    }
```

to:

```php
    private static function applyColumnSelection($query, array $columns, array $schemaColumns, string $relatedTable): void
    {
        if ($columns === ['*'] || [] === $columns) {
            return; // No filtering needed
        }

        foreach ($columns as $column) {
            if ('*' === $column || isset($schemaColumns[$column])) {
                continue;
            }

            $validNames = array_keys($schemaColumns);
            sort($validNames);
            throw new InvalidArgumentException(sprintf(
                "Unknown column '%s' in select for table '%s'. Valid columns: %s.",
                $column,
                $relatedTable,
                [] === $validNames ? 'none' : implode(', ', $validNames)
            ));
        }

        $query->select($columns);
    }
```

Update its one call site at line 2218 from:

```php
        self::applyColumnSelection($builder, $columns, $schema[$relatedTable]->columns ?? []);
```

to:

```php
        self::applyColumnSelection($builder, $columns, $schema[$relatedTable]->columns ?? [], $relatedTable);
```

Then fix the second, independent location. Change `resolveJsonObjectColumns()` from:

```php
    private static function resolveJsonObjectColumns(array $columns, array $schemaColumns): array
    {
        if ($columns === ['*'] || [] === $columns) {
            $columns = array_keys($schemaColumns);
        }

        // Validate columns against schema
        $validColumns = array_filter($columns, fn($column): bool => isset($schemaColumns[$column]));

        // Remove tenant_id if it's not enabled in configuration
```

to:

```php
    private static function resolveJsonObjectColumns(array $columns, array $schemaColumns, string $relatedTable): array
    {
        if ($columns === ['*'] || [] === $columns) {
            $columns = array_keys($schemaColumns);
        }

        // Validate columns against schema
        foreach ($columns as $column) {
            if ('*' === $column || isset($schemaColumns[$column])) {
                continue;
            }

            $validNames = array_keys($schemaColumns);
            sort($validNames);
            throw new InvalidArgumentException(sprintf(
                "Unknown column '%s' in select for table '%s'. Valid columns: %s.",
                $column,
                $relatedTable,
                [] === $validNames ? 'none' : implode(', ', $validNames)
            ));
        }

        $validColumns = array_filter($columns, fn($column): bool => isset($schemaColumns[$column]));

        // Remove tenant_id if it's not enabled in configuration
```

Change `buildJsonObjectExpression()`'s signature (and its call into `resolveJsonObjectColumns()`) from:

```php
    private static function buildJsonObjectExpression(array $columns, array $schemaColumns, string $tableName = ''): string
    {
        $driver = DB::getDriverName();
        $validColumns = self::resolveJsonObjectColumns($columns, $schemaColumns);
```

to:

```php
    private static function buildJsonObjectExpression(array $columns, array $schemaColumns, string $tableName, string $relatedTable): string
    {
        $driver = DB::getDriverName();
        $validColumns = self::resolveJsonObjectColumns($columns, $schemaColumns, $relatedTable);
```

Change `buildJsonArrayAggExpression()`'s signature (and its call into `buildJsonObjectExpression()`) from:

```php
    private static function buildJsonArrayAggExpression(array $columns, array $schemaColumns, string $tableName = ''): string
    {
        $jsonObjectExpr = self::buildJsonObjectExpression($columns, $schemaColumns, $tableName);
```

to:

```php
    private static function buildJsonArrayAggExpression(array $columns, array $schemaColumns, string $tableName, string $relatedTable): string
    {
        $jsonObjectExpr = self::buildJsonObjectExpression($columns, $schemaColumns, $tableName, $relatedTable);
```

Both lost their `= ''` default when gaining the required `$relatedTable` parameter — this is safe since each is `private` with call sites entirely inside this same file, all updated below.

Update the one direct `resolveJsonObjectColumns()` call site (inside `addBelongsToSubquery()`) from:

```php
        $validColumns = self::resolveJsonObjectColumns($columns, $schema[$relatedTable]->columns ?? []);
```

to:

```php
        $validColumns = self::resolveJsonObjectColumns($columns, $schema[$relatedTable]->columns ?? [], $relatedTable);
```

Update all 5 `buildJsonArrayAggExpression()` call sites (inside `addHasManySubquery()`, `addMorphManySubquery()`, and three more `add*Subquery()` functions — they're textually identical, so a single find-and-replace-all covers every occurrence) from:

```php
        $jsonArrayAggExpr = self::buildJsonArrayAggExpression($columns, $schema[$relatedTable]->columns ?? [], $actualRelatedTableName);
```

to:

```php
        $jsonArrayAggExpr = self::buildJsonArrayAggExpression($columns, $schema[$relatedTable]->columns ?? [], $actualRelatedTableName, $relatedTable);
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter=SelectParamValidationTest`
Expected: all 10 tests PASS.

- [ ] **Step 5: Run the full suite for regressions**

Run: `vendor/bin/phpunit`
Expected: PASS. The `$schemaColumns` passed into `applyColumnSelection()` comes from the same `self::getSchema()`-backed source as Task 1's main-table check (`$schema[$relatedTable]->columns ?? []`, where `$schema` traces back to `SchemaRegistryUtils::get()`), so the same DB-introspection fallback from Task 1's Step 6 note applies here too — an existing test whose related table doesn't declare `columns:` should still work as long as that table was actually created via `Schema::create()` before its config was registered. If something fails here, apply the same investigate-and-fix approach as Task 1's Step 6, not a weakened check.

- [ ] **Step 6: Commit**

```bash
git add src/Utilities/RelationshipResolverUtils.php tests/Feature/SelectParamValidationTest.php
git commit -m "feat: validate unknown related-table columns in nested ?select="
```

---

### Task 4: Controller catch-block completeness and the fetchRecordData leak

**Files:**
- Modify: `src/Http/Controllers/Concerns/HasCrudOperations.php` — add a catch block to 7 methods (corrected during implementation: `upsertRecord` also lacked it, not just the 6 named in the original design-phase pass).
- Modify: `src/Http/Controllers/Concerns/HasBulkOperations.php` — add the same catch block to 5 methods.
- Modify: `src/Http/Controllers/Concerns/HasControllerHelpers.php:158-167` (`fetchRecordData()`).
- Test: `tests/Feature/SelectParamValidationTest.php` (extend).

**Interfaces:**
- Consumes: Task 2's relationship-alias validation, as the throwing mechanism for every test in this task.
- Produces: nothing new — this task's job is making the exception already thrown by Tasks 1-3 actually reach the client as a 422 from every endpoint, instead of a 500 or a swallowed `null`.

- [ ] **Step 1: Write the failing tests**

Add to `SelectParamValidationTest`:

```php
    /** @test */
    public function show_endpoint_reports_422_not_500(): void
    {
        $response = $this->getJson('/api/authors/' . $this->authorId . '?select=bogus_rel(*)');

        $response->assertStatus(422);
        $response->assertJsonPath('message', "Unknown relationship 'bogus_rel' in select for table 'authors'. Valid relationships: posts.");
    }

    /** @test */
    public function create_endpoint_reports_422_instead_of_swallowing_the_error(): void
    {
        $response = $this->postJson('/api/authors?select=bogus_rel(*)', ['name' => 'Grace Hopper']);

        $response->assertStatus(422);
        $response->assertJsonPath('message', "Unknown relationship 'bogus_rel' in select for table 'authors'. Valid relationships: posts.");
        $this->assertDatabaseMissing('authors', ['name' => 'Grace Hopper']);
    }

    /** @test */
    public function update_endpoint_reports_422_instead_of_swallowing_the_error(): void
    {
        $response = $this->putJson('/api/authors/' . $this->authorId . '?select=bogus_rel(*)', ['name' => 'Ada, Countess of Lovelace']);

        $response->assertStatus(422);
        $response->assertJsonPath('message', "Unknown relationship 'bogus_rel' in select for table 'authors'. Valid relationships: posts.");
    }

    /** @test */
    public function bulk_create_endpoint_reports_422_instead_of_swallowing_the_error(): void
    {
        // A single JSON object (not wrapped in an array) is required here:
        // bulkRecordCreate() builds its items list from $request->all(), which
        // merges the select query param into the body. Sending an array body
        // would put 'select' at the same level as the items, failing an
        // unrelated pre-existing "each item must be an object" check before
        // this test's target code ever runs.
        $response = $this->postJson('/api/authors/bulk/create?select=bogus_rel(*)', ['name' => 'Grace Hopper']);

        $response->assertStatus(422);
        $response->assertJsonPath('message', "Unknown relationship 'bogus_rel' in select for table 'authors'. Valid relationships: posts.");
    }
```

Note: `create_endpoint_reports_422_instead_of_swallowing_the_error` asserts the record was *not* left behind. This is correct because `HasControllerHelpers::withinTransaction()` (`HasControllerHelpers.php:43-54`) catches `Throwable`, calls `DB::rollBack()`, and re-throws — verified while writing this plan — so once `fetchRecordData()`'s fix in Step 3 lets the `InvalidArgumentException` escape, the whole create rolls back.

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter=SelectParamValidationTest`
Expected: all 4 new tests FAIL.
- `show_endpoint_reports_422_not_500`: today gets some non-422 status (the `InvalidArgumentException` falls into `getRecordById`'s generic `catch (Exception $e)` → 500 "An error occurred").
- The other three: today get a 200 (or whatever `createRecord`/`updateRecord`/`bulkRecordCreate` return on their happy path) with `data` silently `null`, because `fetchRecordData()` swallows the exception — the record write itself still succeeds.

- [ ] **Step 3: Fix `fetchRecordData()`**

In `src/Http/Controllers/Concerns/HasControllerHelpers.php`, change:

```php
    private function fetchRecordData(Request $request, string $table, mixed $id, mixed $tenantId): mixed
    {
        try {
            $result = $this->recordService->getRecord($request, $table, $id, $tenantId);

            return $result['data'] ?? null;
        } catch (Exception) {
            return null;
        }
    }
```

to:

```php
    private function fetchRecordData(Request $request, string $table, mixed $id, mixed $tenantId): mixed
    {
        try {
            $result = $this->recordService->getRecord($request, $table, $id, $tenantId);

            return $result['data'] ?? null;
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (Exception) {
            return null;
        }
    }
```

Add `use InvalidArgumentException;` to this file's imports if not already present (check the top of `HasControllerHelpers.php` first).

- [ ] **Step 4: Add the catch block to the remaining `HasCrudOperations.php` methods**

`listRecords()` already has this block; add the identical block, in the identical position (immediately before the existing `catch (Exception $e)`), to `getRecordById`, `createRecord`, `updateRecord`, `destroyRecord`, `restoreRecord`, `forceDeleteRecord`, and `upsertRecord`. Every one of these methods already ends with a sequence of catch blocks; each gets one line inserted. For example, `getRecordById`'s block currently reads:

```php
        } catch (RecordNotFoundException $e) {
            return RecordApiResponseService::errorWrapped($e->getMessage(), RecordApiJsonResponseEnum::NOT_FOUND->value);
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        } catch (Exception $e) {
            return RecordApiResponseService::errorFromException($e, 'An error occurred', RecordApiJsonResponseEnum::SERVER_ERROR->value);
        }
```

Change it to:

```php
        } catch (RecordNotFoundException $e) {
            return RecordApiResponseService::errorWrapped($e->getMessage(), RecordApiJsonResponseEnum::NOT_FOUND->value);
        } catch (InvalidArgumentException $e) {
            return RecordApiResponseService::errorWrapped($e->getMessage(), RecordApiJsonResponseEnum::VALIDATION_ERROR->value);
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        } catch (Exception $e) {
            return RecordApiResponseService::errorFromException($e, 'An error occurred', RecordApiJsonResponseEnum::SERVER_ERROR->value);
        }
```

`restoreRecord` and `forceDeleteRecord` have the identical shape to `getRecordById` above (no `ValidationException`). `createRecord`, `updateRecord`, `destroyRecord`, and `upsertRecord` have `catch (ValidationException $e) { ... }` also present — insert the new block immediately after the `RecordNotFoundException` catch and before `ValidationException`/`HttpResponseException` in each (exact ordering among the others doesn't matter; consistency with `getRecordById`'s position above does, for readability). `InvalidArgumentException` is already imported in this file (`HasCrudOperations.php:8`).

- [ ] **Step 5: Add the catch block to all `HasBulkOperations.php` methods**

Add `use InvalidArgumentException;` to this file's imports if not already present. Add the same catch block (`catch (InvalidArgumentException $e) { return RecordApiResponseService::errorWrapped($e->getMessage(), RecordApiJsonResponseEnum::VALIDATION_ERROR->value); }`), in the same relative position (after `RecordNotFoundException`, before `HttpResponseException`/`ValidationException`), to all five methods: `bulkRecord`, `bulkRecordUpsert`, `bulkRecordCreate`, `bulkRecordUpdate`, `bulkRecordDelete`.

Note: `bulkRecord`, `bulkRecordUpsert`, and `bulkRecordDelete` don't call `fetchRecordData()` and aren't reachable by the new `?select=` validation at all (confirmed while researching this plan — they don't process `select` for embedding). This addition to those three methods is a defensive consistency fix with no independent regression test in this feature's scope: it protects against any *other* `InvalidArgumentException` (e.g. from filter validation) being mishandled the same way. Don't invent a fake select-based test for them.

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter=SelectParamValidationTest`
Expected: all 14 tests PASS.

- [ ] **Step 7: Run the full suite for regressions**

Run: `vendor/bin/phpunit`
Expected: PASS, same count as Task 3's checkpoint plus 4.

- [ ] **Step 8: Commit**

```bash
git add src/Http/Controllers/Concerns/HasCrudOperations.php \
        src/Http/Controllers/Concerns/HasBulkOperations.php \
        src/Http/Controllers/Concerns/HasControllerHelpers.php \
        tests/Feature/SelectParamValidationTest.php
git commit -m "fix: surface select-validation errors instead of a 500 or swallowed null"
```
