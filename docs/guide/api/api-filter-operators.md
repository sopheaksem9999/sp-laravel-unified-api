---
title: "Filter Operators Reference"
description: "Full filter operator reference, grouped-logic (and/or) syntax, and config-driven search for dynamic CRUD list endpoints."
keywords:
  - filter operators
  - grouped logic
  - and or filter
  - search param
  - query syntax
  - fts
  - postgres operators
---

# Filter Operators Reference

Filters are top-level query parameters in the form `{column}={operator}.{value}`
(operators are validated against the table schema). Some operators support a
value-less shorthand form for `null`.

## Operators

- `is.null`, `is_not.null` or simply `is`, `is_not` (equivalent to `IS NULL` / `IS NOT NULL`)
- `eq.{value}`, `neq.{value}`, `in.{a,b,c}`, `not_in.{a,b,c}`
- `like.{value}`, `contains.{value}`, `not_like.{value}`, `starts_with.{value}`, `ends_with.{value}`, `regex.{pattern}`
- `ilike.{value}`, `match.{pattern}`, `imatch.{pattern}`
- `gt.{value}`, `gte.{value}`, `lt.{value}`, `lte.{value}`
- `between.{start,end}`, `not_between.{start,end}`
- `date_eq.{YYYY-MM-DD}`, `date_gt.{YYYY-MM-DD}`, `date_gte.{YYYY-MM-DD}`, `date_lt.{YYYY-MM-DD}`, `date_lte.{YYYY-MM-DD}`
- Full-text operators: `fts.{query}`, `plfts.{query}`, `phfts.{query}`, `wfts.{query}`
- Native Postgres operators: `cs.{value}`, `cd.{value}`, `ov.{value}`, `sl.{value}`, `sr.{value}`, `nxl.{value}`, `nxr.{value}`, `adj.{value}`
- `empty.null`, `not_empty.null` or simply `empty`, `not_empty`:
  - On text columns, `empty` ⇔ `IS NULL OR = ''`, `not_empty` ⇔ `IS NOT NULL AND != ''`.
  - On non-text columns (e.g. integers), `empty` ⇔ `IS NULL`, `not_empty` ⇔ `IS NOT NULL`.
- Negated style is also supported using expression syntax:
  - `not.eq.5`, `not.in.(1,2,3)`, `not.like.ACME`, `not.fts.invoice`
  - Only operators with a negated form accept `not.` (`eq`, `neq`, `in`, `like`, `ilike`, `is`, `gt`/`gte`/`lt`/`lte`, `between`, `empty`, `regex`/`match`/`imatch` and the PostgreSQL families). `contains`, `starts_with`, `ends_with` and `date_*` have none, and a `not.` prefix on them is ignored — use another operator (for example `not_like`) instead.
- `any` / `all` modifiers are supported in expression syntax:
  - `name=like(any).{ACME,SHOP}`
  - `name=ilike(all).{spx,admin}`

::: tip One operator map
The operator names, the databases each one needs (`regex`/`match` family on
MySQL, MariaDB and PostgreSQL; `fts` and the array/range operators on
PostgreSQL only) and the column types each one suits live in a single class,
`FilterOperatorCatalog`. The filter engine and the MCP schema tools both read
it, so the operators an agent is told about are exactly the ones this driver
accepts.
:::

## Grouped Logic

- Top-level query params continue to behave as `AND`.
- You can add grouped logic params:
  - `or=(...)`
  - `and=(...)`
- Inside grouped logic, each condition uses expression syntax:
  - `{column}.{operator}.{value}`
  - Example: `id.eq.5`, `balance_due.gt.0`, `id.in.(5,6,9)`

Examples:

- `vendor_id=eq.27&or=(balance_due.gt.0,id.eq.5)`
  - Interpreted as: `vendor_id = 27 AND (balance_due > 0 OR id = 5)`
- `vendor_id=eq.27&and=(or(balance_due.gt.0,id.eq.5),id.neq.2)`
  - Interpreted as: `vendor_id = 27 AND ((balance_due > 0 OR id = 5) AND id != 2)`
- `id=in.(5,6,9)` and `id=not_in.(5,6,9)` are supported in addition to legacy list style (`id=in.5,6,9`).
- `status=eq.open&and=(or(total_amount.gte.1000,total_amount.is.null),or(currency.eq.USD,currency.eq.KHR),issued_at.date_gte.2026-01-01,issued_at.date_lte.2026-12-31)`
  - Interpreted as: `status = 'open' AND ((total_amount >= 1000 OR total_amount IS NULL) AND (currency = 'USD' OR currency = 'KHR') AND issued_at >= '2026-01-01' AND issued_at <= '2026-12-31')`
- `customer_id=eq.18&or=(and(balance_due.gt.0,due_date.lt.2026-03-31),and(id.in.(5,6,9),ref_number.like.BILL-2026))`
  - Interpreted as: `customer_id = 18 AND ((balance_due > 0 AND due_date < '2026-03-31') OR (id IN (5,6,9) AND ref_number LIKE '%BILL-2026%'))`
- `vendor_id=eq.27&or=(vendor.display_name.like.Acme,items.account_code.in.(4000,4010),items.amount.gt.0)`
  - Example of grouped logic including relationship filters (`vendor.*`, `items.*`) in the same OR expression. A relationship column is one level (`alias.column`) and matches only rows of the request's tenant; with tenancy on and no tenant resolved, a condition on a tenant-scoped relationship answers `422` ([Record Tenancy](/guide/record-tenancy)). A relationship column whose own name is an operator word (`like`, `in`, `is`, …) is not recognised inside a group; filter on it ungrouped (`?pets.like=eq.x`). A value inside a group cannot contain a comma: there is no escape.

Notes:

- For grouped logic, use comma-separated expressions inside the group.
- Do not use `=` or `&` inside `or=(...)` / `and=(...)`.
- For URL safety, grouped logic can also be sent in decoded form:
  - `or=(balance_due.gt.0,id.in.(5,6,9))`
- Complex grouped examples are best URL-encoded when sent from frontend clients.
- If an operator is not supported by the current database driver, the API returns a validation error with an explicit message.
- **Common mistake**: filters are top-level query parameters (`{column}={operator}.{value}`) — do not wrap them in a `filter[...]` key. Bracket-wrapped filters are not supported and are silently ignored or return a `422`, so always send filters as plain top-level parameters.

## Config-Driven `search`

Use `searchable` on the table config when you want a stable `?search=` parameter for clients instead of requiring them to build `or=(...)` expressions manually.

```php
'invoices' => new RecordTableType(
    table: 'invoices',
    searchable: [
        'ref_number',
        'customer.display_name',
        'items.name',
        'items.description',
    ],
),
```

Client request:

```http
GET /api/v1/invoices?select=*,customer(*),items(*)&search=INV-001
```

This behaves like:

```http
GET /api/v1/invoices?select=*,customer(*),items(*)&or=(ref_number.ilike.INV-001,customer.display_name.ilike.INV-001,items.name.ilike.INV-001,items.description.ilike.INV-001)
```

Notes:

- `search` is additive with normal top-level filters, so `status=eq.open&search=INV-001` becomes `status = open AND (...)`.
- Relationship fields in `searchable` use the same one-level dot notation supported by grouped relationship filters.
- `search` uses `ilike` on PostgreSQL and `like` on other drivers.
- `s=<text>` is a separate auto-detected search param (works without `searchable` config) — prefer it for ad-hoc search.

## Related Docs

- [Standard CRUD Operations](/guide/api-crud-operations)
- [Relationships](/core-concepts/relationships) — relationship filtering
- [QueryHelpers Trait](/guide/api-queryhelpers-trait) — a smaller fixed operator set for custom endpoints
