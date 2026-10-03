---
title: "Pagination Module"
description: "Pagination behavior covering page/per_page, cursor-based pagination, and total count control for large datasets."
keywords:
  - pagination architecture
  - page per_page standard
  - cursor pagination
  - keyset pagination
  - total=false
  - pagination metadata
  - list endpoint paging
---

# Pagination Module

Record listing endpoints support three modes: **offset pagination** (default), **cursor pagination**, and **limit-only**.

## Offset Pagination (Default)

Standard page-based pagination using `page` and `per_page` parameters:

```bash
GET /api/v1/invoices?page=2&per_page=25
```

### Response Meta

```json
{
  "meta": {
    "page": 2,
    "per_page": 25,
    "total": 1240
  }
}
```

### Headers

```
X-Total-Count: 1240
X-Page: 2
X-Per-Page: 25
X-Total-Pages: 50
```

## Cursor Pagination

Cursor (keyset) pagination provides **O(1) performance** per page regardless of how many total rows exist. Default sort: `created_at DESC` (same as offset pagination).

**The cursor always pages on the column the list is sorted by.** `sortby` therefore
drives the cursor, and `meta.cursor_column` reports the column in use:

```bash
# First page — send empty cursor
GET /api/v1/invoices?cursor=&direction=next&per_page=25

# Subsequent pages — pass back the cursor from the previous response
GET /api/v1/invoices?cursor=<meta.cursor>&direction=next&per_page=25

# sortby drives the cursor: this pages on total, not on the primary key
GET /api/v1/invoices?sortby=total&order=desc&per_page=25

# Override the paging column explicitly
GET /api/v1/invoices?cursor=<meta.cursor>&direction=next&cursor_column=id
```

::: warning Treat `meta.cursor` as an opaque token
When the paging column is not the primary key, the cursor encodes **both** the
sort value and a tie-breaking key, so it is a token (`c1.…`) rather than a
readable value. Pass it back verbatim; do not parse it, and do not construct one
by hand. Paging on the primary key still returns the plain key value, and a bare
scalar cursor from an older release is still accepted.
:::

### Sort vs Operator

| Sort | Direction | Operator |
|---|---|---|
| ASC | next | `>` |
| ASC | prev | `<` |
| DESC | next | `<` |
| DESC | prev | `>` |

### Response Meta

```json
{
  "meta": {
    "cursor": "c1.eyJ2IjoiMjAyNi0wNS0xNSAxMDozMDowMCIsImsiOjEwMjV9",
    "direction": "next",
    "cursor_column": "created_at",
    "total": 1240,
    "first_cursor": null,
    "last_cursor": "99985"
  }
}
```

- `cursor` — pass back verbatim. A token when paging on a non-key column, the plain key value when paging on the primary key
- `first_cursor` — `null`; send `cursor=` for the first page
- `last_cursor` — computed via O(per_page) query; sends you to the final page. Omitted when `total=false`, `skip_total=true`, or `boundary_cursors=false`
- `total` — full matching count. Omitted with `total=false` or `skip_total=true`

`total` reflects the full matching record count before cursor filtering. Add `total=false` to omit it and avoid the `COUNT(*)` query:

```bash
GET /api/v1/invoices?cursor=1025&direction=next&per_page=25&total=false
```

### Headers

```
X-Cursor: 1025
```

### Available Parameters

| Parameter | Default | Description |
|---|---|---|
| `cursor` | — | The opaque cursor token returned as `meta.cursor` by the previous page |
| `direction` | `next` | `next` or `prev` |
| `cursor_column` | the sorted column | Column to cursor on. Defaults to whatever `sortby` resolved to — `created_at` when the table has one, otherwise the primary key. Automatically uses composite cursors when the column differs from the primary key |
| `per_page` | 25 | Page size (capped at `per_page_max`) |

### Composite Cursors

When the paging column differs from the primary key (e.g. sorting by
`created_at`), rows can share a cursor value, so the key is added as a
tie-break and the comparison becomes:

```sql
WHERE created_at < :cursor_created_at
   OR (created_at = :cursor_created_at AND id < :cursor_id)
```

Both operands come from the cursor token, and both comparisons are strict — an
inclusive tie-break would re-serve the row that ended the previous page. The
ordering is `ORDER BY created_at, id` on every page, including the first, since
keyset paging needs a total order for rows tied on the sort column.

This is why the cursor is a token: it has to carry `created_at` *and* `id`. Turn
it off with `pagination.cursor.composite_enabled => false` if you need plain
scalar cursors, at the cost of rows tied on the sort column being unstable
across page boundaries.

::: tip Declare the column you page on
`cursor_column` and `sortby` are both validated against the table config's
`columns`. A column missing there cannot be paged on — see
[CRUD Operations](/guide/api-crud-operations) for the sorting rules.
:::

### Navigation

| User action | API call |
|---|---|
| Load page 1 | `?cursor=&direction=next` |
| Click Next | `?cursor={meta.cursor}&direction=next` |
| Click Prev | `?cursor={meta.cursor}&direction=prev` |
| Click First | `?cursor=&direction=next` |
| Click Last | `?cursor={meta.last_cursor}&direction=next` |

## Total Count Control (`?total=true|false`)

Avoid the expensive `COUNT(*)` query on large filtered datasets:

```bash
GET /api/v1/invoices?page=1&per_page=25&total=false
```

When disabled, offset pagination omits `total` metadata and cursor pagination returns `"total": 0`. Legacy `skip_total=true` remains supported for older clients. Configure skipped totals globally with:

```env
SP_PAGINATION_SKIP_TOTAL=true
```

Clients can force totals back on with `total=true`.

## Limit-Only Mode (`limit`)

`limit` is a bounded, non-paginated subset — it is not pagination. It only
applies when `per_page` (and `page`) are absent:

```bash
GET /api/v1/invoices?limit=50                      # no total
GET /api/v1/invoices?limit=50&total=true           # includes total
```

By default `limit` mode omits the count; pass `total=true` (legacy
`add_total=true`) to include it. Never mix `limit` and `per_page` on one request.

### Performance Guidance: When to Use `limit` vs `per_page`

| Use Case | Recommended Parameter | Why? |
|---|---|---|
| **AI Agent / Assistant queries** | `limit=N` | Fast and lightweight. Skips `SELECT COUNT(*)` overhead and returns only the necessary context rows. |
| **Top-N / Latest Record Previews** | `limit=N` | Bypasses pagination state calculation, delivering results with minimal DB latency. |
| **Interactive UI Page Navigation** | `page=N&per_page=M` | Necessary only when building multi-page navigation controls that display total page counts. |
| **Deep / Infinite Scroll Traversal** | `cursor=TOKEN&limit=N` | O(1) keyset traversal without offset query degradation. |

## Config Reference

```php
// config/record.php
'pagination' => [
    'default_mode' => env('SP_PAGINATION_DEFAULT_MODE', 'offset'),
    'cursor' => [
        'default_column' => 'id',
        'composite_enabled' => true,
    ],
    'skip_total_default' => env('SP_PAGINATION_SKIP_TOTAL', false),
],
```

## Related Feature Docs

- [Performance Guide](/advanced/performance)
- [Standard CRUD Operations](/guide/api-crud-operations)
