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

```bash
# First page — send empty cursor
GET /api/v1/invoices?cursor=&direction=next&per_page=25

# Subsequent pages — use the cursor value from the previous response
GET /api/v1/invoices?cursor=1025&direction=next&per_page=25

# Override cursor column
GET /api/v1/invoices?cursor=1025&direction=next&cursor_column=id
```

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
    "cursor": "2026-05-15 10:30:00",
    "direction": "next",
    "cursor_column": "created_at",
    "total": 1240,
    "first_cursor": null,
    "last_cursor": "99985"
  }
}
```

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
| `cursor` | — | The cursor value (typically the last record's primary key from the previous page) |
| `direction` | `next` | `next` or `prev` |
| `cursor_column` | `id` | Column to cursor on. Automatically uses composite cursors when column differs from primary key |
| `per_page` | 25 | Page size (capped at `per_page_max`) |

### Composite Cursors

When `cursor_column` differs from the primary key (e.g., sorting by `created_at`), the query builder automatically generates a composite cursor using `WHERE (cursor_col, id) > (?, ?)` to ensure stable ordering across non-unique sort values.

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

- [Pagination (Page/Per Page)](/guide/feature-pagination-page-per-page)
- [Performance Guide](/advanced/performance)
