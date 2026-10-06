---
title: "Relationship Scoping Gaps Plan"
description: "Implementation plan closing the relationship-layer gaps: SQL injection through the tenant header in relationship subqueries, viewOwn not applied to included and filtered related rows (and their cache keys), and nested relationship writes ignoring the child table's tenant and owner."
keywords:
  - relationships
  - sql injection
  - tenant isolation
  - viewOwn
  - nested writes
  - plan
---

# Relationship Scoping Gaps Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A row a caller could not read or write directly is never read, filtered on, or written through a relationship — and no request header reaches SQL unbound.

**Architecture:** Every relationship path in `RelationshipResolverUtils` (six correlated-subquery builders, the batched `whereIn` loaders, the three nested-write processors) and `QueryBuilderFiltersUtils::applyRelationshipFilter()` gains the two scopes the direct paths already have: the related table's **tenant** filter, now always bound, and its **own-records** filter from `OwnRecordsScope`. Cache keys account for every table a response embeds.

**Tech Stack:** PHP 8.2+, Laravel 12/13, PHPUnit 11, PHPStan, Rector.

**Spec:** `docs/bug-reports/2026-09-30-relationship-scoping-gaps.md` (Task 0 writes it).

## Global Constraints

- Existing tenant-*condition* logic (when a tenant filter applies at each site) is preserved exactly; only *how the value reaches SQL* changes (bound, never interpolated).
- `selectRaw($sql, $bindings)` replaces `addSelect([DB::raw($sql)])`; it is the same call plus bindings.
- Owner scoping on a related table uses `OwnRecordsScope::ownerColumn($relatedTable)` with `$relatedTable` = the relationship config's `table` (the schema key), qualified by that site's SQL alias/physical name.
- Users without `viewOwn` on any table involved see no change, including cache keys.
- Run `vendor/bin/phpunit`, `vendor/bin/phpstan analyse src tests`, `vendor/bin/rector process --dry-run --no-progress-bar` after each task; no new Rector findings in touched files. Run `graft build` after code changes.

## Review Focus

1. **Tenant header carrying SQL** (`1 OR 1=1`, a quote, `UNION …`) on every include type — must match nothing extra. Pinned in Task 1.
2. **UUID tenant ids** on the subquery path — previously cast `(int)` to `0`; must now return the tenant's rows. Pinned in Task 1.
3. **A viewOwn user embedding a foreign row** through belongsTo, hasMany and belongsToMany, on both the subquery path and the batched path. Pinned in Task 2.
4. **A cached response whose *included* table is own-restricted** while the main table is not — must not be shared across users. Pinned in Task 2.
5. **Nested write under a non-tenant parent** — must not update/delete another tenant's or another owner's child, and a created child must get the request tenant, not a client-supplied one. Pinned in Task 3.

---

### Task 0: Spec

**Files:** Create `docs/bug-reports/2026-09-30-relationship-scoping-gaps.md` (frontmatter; three findings with reproductions: `X-Tenant-ID: 1 OR 1=1` on `GET /notes?select=*,widgets(*)` returns another tenant's child; `GET /comments?select=*,widget(*)` embeds a foreign row for a viewOwn user; a nested `widgets:[{id,…}]` update under a non-tenant `notes` parent rewrites another tenant's child). Mark the two earlier tickets (`2026-09-30-view-own-relationship-includes.md`, `2026-09-27-nested-relationship-write-tenant-scope.md`) as superseded by it.

- [ ] **Step 1:** Write it. **Step 2:** `php bin/validate-docs.php 2>&1 | grep -c "2026-09-30-relationship-scoping"` → `0`.

---

### Task 1: Bind every tenant value in relationship subqueries (SQL injection)

**Files:** Modify `src/Utilities/RelationshipResolverUtils.php` (`addBelongsToSubquery`, `addHasManySubquery`, `addMorphManySubquery`, `addBelongsToManySubquery`, `addMorphToManySubquery`, `addHasManyThroughSubquery`). Test: `tests/Feature/RelationshipTenantBindingTest.php`.

- [ ] **Step 1: Failing test.** Fixture: tenancy on; `notes` (`hasTenantId: false`) hasMany `widgets` (`hasTenantId: true`), and `widgets` belongsTo `notes` via a tenant-scoped `tags` table for the belongsTo case — concretely, write three tests:

```php
    /** @test */
    public function a_tenant_header_carrying_sql_matches_nothing_extra_on_a_has_many_include(): void
    {
        $body = (string) $this->getJson('/api/notes?select=*,widgets(*)', ['X-Tenant-ID' => '1 OR 1=1'])->getContent();

        $this->assertStringNotContainsString('T2-CHILD', $body);
    }

    /** @test */
    public function a_legitimate_tenant_header_still_scopes_the_include(): void
    {
        $body = (string) $this->getJson('/api/notes?select=*,widgets(*)', ['X-Tenant-ID' => '1'])->getContent();

        $this->assertStringContainsString('T1-CHILD', $body);
        $this->assertStringNotContainsString('T2-CHILD', $body);
    }

    /** @test */
    public function a_tenant_header_carrying_sql_matches_nothing_extra_on_a_belongs_to_include(): void
    {
        // widgets belongsTo tags; tags is tenant-scoped.
        $body = (string) $this->getJson('/api/widgets?select=*,tag(*)', ['X-Tenant-ID' => '1 OR 1=1'])->getContent();

        $this->assertStringNotContainsString('T2-TAG', $body);
    }
```

(Seed `widgets` rows under tenant 1 and 2, each pointing at a `tags` row of its own tenant; `notes` row 1 owning both widgets.)

- [ ] **Step 2:** Run → Expected: the two injection tests FAIL; the legitimate one passes.

- [ ] **Step 3: Transform the five `$subqueryRaw` builders.** In each of `addHasManySubquery`, `addMorphManySubquery`, `addBelongsToManySubquery`, `addMorphToManySubquery`, `addHasManyThroughSubquery`:
  1. Add `$bindings = [];` as the first statement.
  2. Replace every occurrence of the form
     `$subqueryRaw .= sprintf(' AND %s.' . $tenantCol . ' = %s', <QUAL>, $tenantId);`
     with
     ```php
     $subqueryRaw .= sprintf(' AND %s.%s = ?', <QUAL>, $tenantCol);
     $bindings[] = $tenantId;
     ```
     keeping `<QUAL>` and the surrounding `if` exactly as they are.
  3. Replace the attach line `$builder->addSelect([DB::raw(sprintf('%s as %s', $subqueryRaw, $alias))]);` with `$builder->selectRaw(sprintf('%s as %s', $subqueryRaw, $alias), $bindings);`.

  Verify: `grep -cF "= %s', \$actual" src/Utilities/RelationshipResolverUtils.php` → `0`.

- [ ] **Step 4: `addBelongsToSubquery`.** It builds `$innerSql` (used only on pgsql) and `$rawSql` (sqlite/mysql), each with its own tenant `if`. Build the scope once, before the driver branch, right after `$innerSql` gets its correlation `WHERE`:

  ```php
        $scopeSql = '';
        $scopeBindings = [];
        $tenantCol = RecordConfigService::tenantColumn();
        if ($enableTenantId && $tenantId && isset($schema[$relatedTable]->columns[$tenantCol])) {
            $scopeSql .= sprintf(' AND %s.%s = ?', $subAlias, $tenantCol);
            $scopeBindings[] = $tenantId;
        }
  ```

  Replace each of the three `if ($enableTenantId && $tenantId && isset(...)) { … . (int) $tenantId; }` blocks with `$innerSql .= $scopeSql;` or `$rawSql .= $scopeSql;` respectively, remove the now-duplicate `$tenantCol = …` line, and change `$builder->selectRaw($rawSql);` to `$builder->selectRaw($rawSql, $scopeBindings);`. Exactly one of `$innerSql`/`$rawSql` reaches the final SQL per driver, so the bindings are passed once.

  Verify: `grep -cF '(int) $tenantId' src/Utilities/RelationshipResolverUtils.php` → `0`.

- [ ] **Step 5:** Run the Task 1 tests → PASS. Add and run:

```php
    /** @test */
    public function a_uuid_tenant_id_scopes_a_belongs_to_include(): void
    {
        // (int) 'a1b2…' used to become 0, so the related row vanished.
        config(['record.id_type' => 'uuid']);
        // fixture variant: tags.tenant_id holds '6f1d…-uuid-t1'; request carries that header
        $body = (string) $this->getJson('/api/widgets?select=*,tag(*)', ['X-Tenant-ID' => self::UUID_T1])->getContent();

        $this->assertStringContainsString('T1-TAG', $body);
    }
```

  (Give the test its own setUp branch with string tenant columns.)

- [ ] **Step 6:** Full gate.

---

### Task 2: Apply viewOwn to included and filtered related rows, and to their cache keys

**Files:** Modify `src/Utilities/OwnRecordsScope.php`, `src/Utilities/RelationshipResolverUtils.php`, `src/Utilities/QueryBuilderFiltersUtils.php` (`applyRelationshipFilter`), `src/Services/RecordCacheService.php` (`queryFingerprint`), `src/Services/RecordService.php` (`getCombinedSelectParam` → `public`). Test: `tests/Feature/OwnRecordsRelationshipIncludesTest.php`; un-skip `OwnRecordsWriteScopingTest::a_related_record_the_caller_does_not_own_is_not_exposed_through_an_include`.

**Interfaces — Produces:**
- `OwnRecordsScope::sqlCondition(string $table, string $qualifier): array{0: string, 1: array<int, mixed>}` — `['', []]` when unrestricted, else `[' AND {qualifier}.{col} = ?', [userId]]`.
- `OwnRecordsScope::cacheToken(string $table, string $select = ''): string` — unchanged output for a main-table-only restriction (`'own:{col}:{uid}'`); appends `{relatedTable}.{col}` parts for every restricted table the select embeds.

- [ ] **Step 1: Failing tests** — viewOwn user 42 (`created_by_id` owner):
  - `comments` belongsTo `widgets`; `GET /comments?select=*,widget(*)` and `/comments/1?select=*,widget(*)` → no `THEIRS`.
  - `notes` hasMany `widgets`; `GET /notes?select=*,widgets(*)` → `MINE` only.
  - belongsToMany (`notes` ↔ `widgets` via `note_widget` pivot) → `MINE` only.
  - Batched path: force it with a nested include `select=*,widgets(*,tag(*))` (children disable the subquery optimisation) → `MINE` only.
  - Relationship filter oracle: `GET /comments?widget.name=eq.THEIRS` → no comment rows.
  - Cache: cached `comments` (not restricted) including `widget(*)` (restricted) — admin warms, then u42 → no `THEIRS`; u42 warms, then admin → `THEIRS` present.
  - Positive control: an admin without viewOwn sees `THEIRS` embedded.

- [ ] **Step 2:** Run → FAIL on every restriction case; controls pass.

- [ ] **Step 3: Add to `OwnRecordsScope`:**

```php
    /**
     * The own-records restriction as a raw-SQL fragment for correlated
     * subqueries that cannot take a query builder.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    public static function sqlCondition(string $table, string $qualifier): array
    {
        $ownerColumn = self::ownerColumn($table);
        if (null === $ownerColumn) {
            return ['', []];
        }

        return [sprintf(' AND %s.%s = ?', $qualifier, $ownerColumn), [Auth::user()->id]];
    }
```

  and extend `cacheToken()` with a `string $select = ''` parameter that walks `RelationshipResolverUtils::parseSelectForIncludes($select)` (recursing into each include's `children`) resolving each alias with `RelationshipResolverUtils::resolveRelationship($parent, $alias)['table']`, collecting every restricted table. Main-table part stays `{col}`; related parts are `{table}.{col}`; token `'own:' . implode(',', $parts) . ':' . $userId`, or `''` when no part.

- [ ] **Step 4: Subquery builders.** In each of the six builders, immediately after the related table's tenant clause, append the owner clause for the **related (target) table** — not a pivot or through table:

```php
        [$ownSql, $ownBindings] = OwnRecordsScope::sqlCondition($relatedTable, <RELATED_QUALIFIER>);
        $subqueryRaw .= $ownSql;               // or $scopeSql .= … in addBelongsToSubquery
        array_push($bindings, ...$ownBindings); // or $scopeBindings
```

  `<RELATED_QUALIFIER>` is the alias that site's SQL uses for the related table (`$subAlias` in belongsTo; `$actualRelatedTableName` elsewhere). The clause must be appended *before* any `GROUP BY`/closing parenthesis already present in that builder.

- [ ] **Step 5: Batched loaders.** Directly after the related-table tenant `where` in `loadStandardRelationshipOptimized` (both the standard and the belongsToMany/morphToMany builder) and in `loadHasManyThroughOptimized` (the **related** builder, not the through builder), add `OwnRecordsScope::apply($builder, $relatedTable, <PHYSICAL_NAME>)`, where `<PHYSICAL_NAME>` is the name that builder was created with (`$actualRelatedTableName` / `$relatedTableName`).

- [ ] **Step 6: Relationship filters.** In `QueryBuilderFiltersUtils::applyRelationshipFilter()`, in each `whereExists` closure, after its tenant `where`, add `OwnRecordsScope::apply($subquery, $relatedTable)` (for the through branch, the target table).

- [ ] **Step 7: Cache.** Make `RecordService::getCombinedSelectParam()` `public`. In `RecordCacheService::queryFingerprint()`, call `OwnRecordsScope::cacheToken($table, RecordService::getCombinedSelectParam($request))`.

- [ ] **Step 8:** Run Task 2 tests + all `OwnRecords*` suites → PASS; un-skip the pinned include test → PASS. Full gate.

---

### Task 3: Scope nested relationship writes by the child table

**Files:** Modify `src/Utilities/RelationshipResolverUtils.php` (`processRelatedData`, `processBelongsToManyOperation`, `processHasManyThroughOperation`). Test: `tests/Feature/NestedRelationshipWriteScopingTest.php`; un-skip `McpTenantIsolationTest::a_nested_write_through_a_non_tenant_parent_cannot_touch_another_tenants_child`.

- [ ] **Step 1: Failing tests** — `notes` (`hasTenantId: false`) hasMany `widgets` (`hasTenantId: true`), both widgets under note 1, widget 2 in tenant 2; caller tenant 1:
  - `PUT /notes/1 {widgets:[{id:2,name:HIJACKED}]}` → widget 2 unchanged.
  - `PUT /notes/1 {widgets:[{id:2,_delete:true}]}` → widget 2 still present.
  - `PUT /notes/1 {widgets:[{name:NEW, tenant_id:2}]}` → created widget has `tenant_id = 1`.
  - viewOwn variant (no tenancy): widget 2 owned by user 99 under the caller's note → nested update refused.
  - belongsToMany attach of a foreign-tenant related id → 422, no pivot row.
  - Positive control: nested update of the caller's own-tenant child works.

- [ ] **Step 2:** Run → FAIL on the refusal/stamping cases.

- [ ] **Step 3: Child scope helper** in `RelationshipResolverUtils`:

```php
    /**
     * The tenant a nested write's child rows belong to: the parent's tenant when
     * it has one, otherwise the request's. A non-tenant parent passes null, and
     * without this its tenant-scoped children were written unscoped.
     */
    private static function childTenant(mixed $tenantId): mixed
    {
        if (!RecordConfigService::enableTenantId()) {
            return null;
        }

        return RecordUtils::isTenantIdMissing($tenantId)
            ? RecordUtils::resolveTenantIdFromRequest(request())
            : $tenantId;
    }

    /**
     * Confine a nested write's child query to rows the caller could write
     * directly: the child table's own tenant, and its own-records scope.
     */
    private static function scopeChildRow(Builder $query, string $relatedTable, string $qualifiedTable, mixed $childTenant, array $schema): Builder
    {
        $tenantCol = RecordConfigService::tenantColumn();
        if (RecordConfigService::enableTenantId() && !RecordUtils::isTenantIdMissing($childTenant) && isset($schema[$relatedTable]->columns[$tenantCol])) {
            $query->where($qualifiedTable . '.' . $tenantCol, $childTenant);
        }

        OwnRecordsScope::apply($query, $relatedTable, $qualifiedTable);

        return $query;
    }
```

- [ ] **Step 4: hasMany/morphMany in `processRelatedData`.** Compute `$childTenant = self::childTenant($tenantId);` and `$childHasTenant = isset($relatedSchema->columns[RecordConfigService::tenantColumn()]);` once per relationship. Wrap both the delete query and the update query: `self::scopeChildRow(self::scopeToParent(…), $relatedTable, $actualRelatedTableName, $childTenant, $schema)`. In the create path replace `if ($hasTenant) { unset(tenant) }` with `if ($childHasTenant) { … }` and `if ($tenantId && $hasTenant) { stamp }` with `if ($childHasTenant && !RecordUtils::isTenantIdMissing($childTenant)) { $item[tenantCol] = $childTenant; }`.

- [ ] **Step 5: Attach-by-id in `processBelongsToManyOperation` and `processHasManyThroughOperation`.** Before linking an **existing** related/target id (the pivot/through insert or update), when the related table is restricted — tenant applies to it, or `OwnRecordsScope::ownerColumn($relatedTable)` is non-null — require it to be visible:

```php
            $visible = self::scopeChildRow(
                DB::table($actualRelatedTableName)->where($relatedPk, $relatedId),
                $relatedTable, $actualRelatedTableName, $childTenant, $schema
            )->exists();
            if (!$visible) {
                throw new InvalidArgumentException(sprintf(
                    "Related record '%s' not found for relationship '%s' on table '%s'.",
                    (string) $relatedId, $alias, $table
                ));
            }
```

  Skip the check when the related table is unrestricted, so apps not using tenancy/viewOwn see no change. When creating a new related/target row, stamp `$childTenant` instead of `$tenantId`.

- [ ] **Step 6:** Run Task 3 tests + un-skipped MCP test → PASS. Full gate.

---

### Task 4: Documentation and release notes

- [ ] Own-records guide: replace the "Known gap — relationship includes" caveat with the rule (a row of table T is never embedded, filtered on, or written through a relationship unless the caller could reach it on T directly), and note cache keys cover embedded tables.
- [ ] Nested-write section of `docs/guide/api/api-nested-and-bulk-operations.md`: children are scoped by the child table's tenant and own-records rules; attaching an existing id the caller cannot see returns 422.
- [ ] Changelog `[Unreleased]` → `### Fixed` / `### Security` in both files.
- [ ] Close the Task 0 report; mark the two superseded reports.
- [ ] Final gate: `graft build && vendor/bin/phpunit && vendor/bin/phpstan analyse src tests && php bin/validate-docs.php`.

## Out of Scope

- The tenant header remains client-supplied; binding stops injection, not tenant choice. Binding a credential to a tenant is the application's job.
- Owner transfer / create-time ownership on nested children.
- `OwnRecordsScope`'s default-guard identity.
