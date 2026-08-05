---
title: "Validation for Unknown Fields and Relationships in ?select="
description: "Turns three silent-failure modes in the ?select= query parameter into clear 422 validation errors, and fixes a controller catch-block gap the feature depends on."
keywords:
  - select
  - query params
  - validation
  - relationships
  - RelationshipResolverUtils
  - InvalidArgumentException
date: 2026-08-05
status: approved
---

# Validation for Unknown Fields and Relationships in `?select=`

## Problem

The dynamic Record API's `?select=` query parameter lets clients choose which
columns and relationships come back (e.g. `?select=id,name,customer(*)`).
When a client asks for a field or relationship that doesn't exist, the API
today gives no useful signal that anything is wrong — behavior differs by
exactly *where* the bad name appears, and none of the three cases produce a
clean error:

1. **Unknown relationship alias** (e.g. `?select=bogus_rel(*)`, at any
   nesting depth) — silently dropped. `includeRelationshipsRecursive()`
   (`src/Utilities/RelationshipResolverUtils.php:1753-1756`) does
   `if (!$config) { continue; }` when `resolveRelationship()` returns null.
   The rest of the select is processed normally; the client gets a 200 with
   the bad relationship simply missing from the response, no explanation.
2. **Unknown column on a related table** (e.g. `?select=customer(bogus_field)`)
   — silently dropped. `applyColumnSelection()`
   (`RelationshipResolverUtils.php:2414-2426`) filters the requested columns
   down to `array_filter($columns, fn($c) => isset($schemaColumns[$c]))`, so
   an unknown column just disappears from the response with no signal.
3. **Unknown column on the main table** (e.g. `?select=id,bogus_field`) — no
   validation exists at all. `getMainTableColumns()`
   (`RelationshipResolverUtils.php:292-323`) returns the raw column list
   unchecked, which is passed straight into `->select()`/`->addSelect()`.
   This reaches the database driver and throws a raw `QueryException`
   ("unknown column") at execution time — worse than the other two cases,
   since it isn't even a clean validation failure, just an unhandled 500.

This spec adds real validation for all three, converting them into a
consistent 422 response that names the invalid selector and lists the valid
alternatives.

## Decisions

- **Always on, no config gate.** This is a correctness fix, not an optional
  behavior. A client that had a typo'd or stale select field which "worked"
  today (by silently returning less data) will now get a 422 instead of
  silent data loss. This matches how this package already treats
  correctness fixes elsewhere (e.g. the `record.id_type` expansion work).
- **Fail fast on the first invalid selector**, not a batch of all errors.
  Because the three checks run at different points in request processing
  (see "Processing order" below), "first" means first-in-processing-order,
  not necessarily left-to-right in the query string. A request with an
  invalid main-table column *and* an invalid relationship will always report
  the main-table-column error, since that check runs first.
- **Error message states the invalid name and lists valid alternatives** —
  no fuzzy-match "did you mean" suggestion. Simpler, no similarity-matching
  code, and still fully actionable: the client sees the full valid list
  right next to their typo.
- **Scope limited to the three cases above.** A relationship alias that
  *is* declared in the schema but that `resolveRelationship()` still returns
  null for (the `RecordAassociationType` branch with a subtype other than
  `HAS_MANY_THROUGH`, `RelationshipResolverUtils.php:403-408`) is a
  pre-existing, unrelated internal limitation, not a client typo. That case
  keeps its current silent no-op — this spec does not touch it.

## Where each check lives

Rather than a single new centralized validation pass, each gap is fixed at
its existing source. This keeps the diff small, avoids duplicating
resolution logic that's already schema-aware in place, and means each fix
sits directly next to the data it needs.

### 1. Unknown main-table column

`getMainTableColumns(?string $selectParam): array` is a pure string parser
with no schema access, called from 7 places (6 in `RecordService.php`, 1 in
`RecordQueryBuilder.php`). Its signature is left unchanged. Instead, add:

```php
public static function validateMainTableColumns(string $table, array $columns): void
```

in `RelationshipResolverUtils`, and call it once at each of the 7 call
sites, immediately after `getMainTableColumns()` extracts the list — every
call site already has `$table` in scope. Valid = the table's declared
`columns` ∪ declared `attributes` (computed fields — these are legitimately
selectable today and must not become a regression) ∪ literal `'*'`.

This check runs before any database query executes, so an invalid main
column is rejected with zero wasted DB work.

### 2. Unknown relationship alias

In `includeRelationshipsRecursive()`
(`RelationshipResolverUtils.php:1753-1756`), replace:

```php
$config = self::resolveRelationship($table, $alias, $hintTable);
if (!$config) {
    continue;
}
```

with a check for whether `$alias` exists as a *key* in
`$schema[$table]->relationships` at all:

- Key doesn't exist → throw `InvalidArgumentException` (genuinely unknown
  alias — the bug this spec fixes).
- Key exists but `resolveRelationship()` still returned null (the
  unsupported-subtype case from "Decisions" above) → keep today's silent
  `continue`, unchanged.

This runs during post-fetch relationship enrichment, which happens *after*
the main table's query has already executed. An invalid relationship alias
therefore still costs one (otherwise-unnecessary) main-table query before
the 422 comes back. Restructuring the pipeline to validate every selector
before any query runs is out of scope — not requested, and a bigger change
than this fix warrants.

### 3. Unknown column on a related table

In `applyColumnSelection()` (`RelationshipResolverUtils.php:2414-2426`),
replace:

```php
$validColumns = array_values(array_filter($columns, fn($column): bool => '*' === $column || isset($schemaColumns[$column])));
if ([] !== $validColumns) {
    $query->select($validColumns);
}
```

with a loop that throws `InvalidArgumentException` on the first column not
present in `$schemaColumns` and not `'*'`. This needs the related table
name for the error message — add it as a new parameter to
`applyColumnSelection()`. Its only call site
(`RelationshipResolverUtils.php:2218`, inside `loadStandardRelationshipOptimized()`)
has `$relatedTable` (the real table name) in scope, but not the alias the
client typed in `?select=` — that would require threading a new parameter
through `loadStandardRelationshipOptimized()` and its own caller too. Using
the table name instead of the alias is a reasonable simplification: it's
one function signature changed instead of three, for a difference that only
matters when a client aliases a relationship to a different name than its
table (`?select=cust:customers(...)`).

This runs before the related-table query executes, but after the main
table's query has already run (same timing note as case 2).

## Required companion fix: controller catch-block gap

Throwing `InvalidArgumentException` only produces a clean 422 if something
catches it and maps it to `RecordApiJsonResponseEnum::VALIDATION_ERROR`.
Today, **only** `HasCrudOperations::listRecords()`
(`src/Http/Controllers/Concerns/HasCrudOperations.php:103-104`) has that
catch block:

```php
} catch (InvalidArgumentException $e) {
    return RecordApiResponseService::errorWrapped($e->getMessage(), RecordApiJsonResponseEnum::VALIDATION_ERROR->value);
}
```

Every other method in that file (`getRecordById`, `createRecord`,
`updateRecord`, `destroyRecord`, `restoreRecord`, `forceDeleteRecord`,
`upsertRecord`) and every method in `HasBulkOperations.php`
(`bulkRecord`, `bulkRecordUpsert`, `bulkRecordCreate`, `bulkRecordUpdate`,
`bulkRecordDelete`) lacks this catch. Since `InvalidArgumentException`
extends `Exception`, it still gets caught — by the generic
`catch (Exception $e)` — but reported as a 500 "An error occurred" via
`RecordApiResponseService::errorFromException()`, which does not
special-case exception type and just uses whatever message/status the
catch block hardcodes.

`?select=` is honored on the show endpoint (`getRecordById` →
`RecordService::getRecord()`) directly, and reachable from create/update
(`executeCreate`/`executeUpdate` call `executeGetById()` internally to
build their response with embedded relationships) — so without this fix,
exactly the endpoints most likely to exercise the new validation would turn
it into a confusing 500 instead of the clean 422 this spec exists to
produce. This is a required part of the fix, not incidental scope creep:
add the identical catch block, in the identical position (before the
generic `catch (Exception $e)`), to all 12 methods listed above.

**Second required companion fix, found while tracing exact call paths for
the implementation plan:** `HasControllerHelpers::fetchRecordData()`
(`src/Http/Controllers/Concerns/HasControllerHelpers.php:158-167`) — the
shared helper `createRecord`, `updateRecord`, and the per-item logic inside
`bulkRecordCreate`/`bulkRecordUpdate` all use to re-fetch a record (with
`?select=` embedding) after writing it — wraps that fetch in
`catch (Exception) { return null; }`. This swallows the new
`InvalidArgumentException` entirely: a `POST /invoices?select=bogus_rel(*)`
would still create the record, then silently return `{"data": null}`
instead of a 422 — a third silent-failure mode, arguably worse than the
original bug, since the client can't tell whether the create failed,
succeeded with no data, or hit a select error. Fix: let
`InvalidArgumentException` propagate out of `fetchRecordData()` (so the
caller's catch block, from the fix above, handles it), while still
swallowing every other exception type, preserving whatever defensive intent
the original blanket catch had for unrelated fetch failures.

## Error message format

Consistent shape across all three cases. Valid-name lists are always sorted
alphabetically and never include `'*'` (a wildcard, not a real name):

| Case | Example |
|---|---|
| Main column | `Unknown column 'bogus_field' in select for table 'invoices'. Valid columns: created_at, customer_id, id, name, total, updated_at.` |
| Relationship | `Unknown relationship 'bogus_rel' in select for table 'invoices'. Valid relationships: customer, items, payments.` |
| Nested column | `Unknown column 'bogus_field' in select for table 'customers'. Valid columns: email, id, name.` |

The nested-column message names the related table, not the alias the
client typed — see the simplification note above.

All three throw plain `InvalidArgumentException`, matching the existing
filter/operator validation pattern in `QueryBuilderFiltersUtils` — no new
exception class.

## Testing

1. Unknown main-table column on list and on show → 422 with the column
   message, on both endpoints (the show-endpoint case is also the
   regression test for the catch-block fix).
2. Unknown relationship alias, top-level and nested (depth 2) → 422 with the
   relationship message.
3. Unknown column on a related table's nested select → 422 with the nested
   column message.
4. Regression: the existing valid-select test
   (`tests/Feature/DynamicApiTest.php:318-346`,
   `it_supports_field_selection`) still passes. Add a case selecting a
   computed attribute (a `$tableSchema->attributes` key) to confirm it's
   still accepted, not newly rejected as an "unknown column."
5. `'*'` still works at both main-table and nested level.
6. A request with both an invalid main column and an invalid relationship
   in the same `select` string → 422 reports the main-column error (proves
   the processing-order tie-break from "Decisions").
7. A bulk-operation endpoint with an invalid selector → 422, not 500
   (second regression test for the catch-block fix).

## Files affected

- `src/Utilities/RelationshipResolverUtils.php` — new
  `validateMainTableColumns()`; modify `includeRelationshipsRecursive()`
  and `applyColumnSelection()`.
- `src/Services/RecordService.php` — 6 call sites, one line each, calling
  the new validation function after `getMainTableColumns()`.
- `src/Services/Queries/RecordQueryBuilder.php` — 1 call site, same
  addition.
- `src/Http/Controllers/Concerns/HasCrudOperations.php` — add the
  `InvalidArgumentException` catch block to 6 methods.
- `src/Http/Controllers/Concerns/HasBulkOperations.php` — add the same
  catch block to 5 methods.
- `src/Http/Controllers/Concerns/HasControllerHelpers.php` — narrow
  `fetchRecordData()`'s catch to let `InvalidArgumentException` propagate.
- `tests/Feature/SelectParamValidationTest.php` — new file, all test cases
  from "Testing" above. Kept separate from `tests/Feature/DynamicApiTest.php`
  rather than added to it, matching this codebase's existing convention of a
  dedicated file per concern (e.g. `RelationshipFilterMissingTableTest.php`
  for the analogous filter-side silent-drop issue).
