---
title: "Page and Per Page Pagination Contract"
description: "Feature guide for standard list pagination parameters, cursor pagination, and skip_total for large datasets."
keywords:
  - page param
  - per_page param
  - cursor pagination
  - keyset pagination
  - skip_total
  - pagination response meta
  - meta.page
  - meta.per_page
  - meta.total
  - meta.cursor
---

# Pagination (Page and Per Page)

This package supports **offset pagination** (page/per_page) and **cursor pagination** (cursor/direction) for list endpoints.

## Offset Pagination

### Query Style

```
GET /api/v1/customers?page=1&per_page=25
```

- `page`: Page number (starts at 1)
- `per_page`: Items per page (default 25, max `per_page_max`)

### Response Meta

```json
{
  "meta": {
    "page": 1,
    "per_page": 25,
    "total": 1240
  }
}
```

## Cursor Pagination

For large datasets, cursor pagination avoids the performance penalty of offset-based pagination. Defaults to `ORDER BY created_at DESC` — same sort order as offset pagination.

### First Page

Send an empty cursor (`cursor=` or `cursor=0`) to load the first N rows:

```
GET /api/v1/customers?cursor=&direction=next&per_page=25
```

### Subsequent Pages

Use the `cursor` value from the previous response's `meta.cursor`:

```
GET /api/v1/customers?cursor=5025&direction=next&per_page=25
```

### Cursor Direction vs Sort Order

The default sort is `created_at DESC`. The cursor operator adapts:

| Sort | Direction | Operator | Meaning |
|---|---|---|---|
| ASC | next | `>` | higher values = next page |
| ASC | prev | `<` | lower values = prev page |
| DESC | next | `<` | lower values = next page (older) |
| DESC | prev | `>` | higher values = prev page (newer) |

Override the cursor column:

```
GET /api/v1/customers?cursor=5025&direction=next&cursor_column=id
```

### Response Meta

```json
{
  "meta": {
    "cursor": "2026-05-15 10:30:00",
    "direction": "next",
    "cursor_column": "created_at",
    "total": 1240,
    "first_cursor": null,
    "last_cursor": "2025-01-01 00:00:00"
  }
}
```

- `cursor` — next page cursor (the last record's cursor column value)
- `first_cursor` — `null`; send `cursor=` for the first page
- `last_cursor` — cursor to jump to the final page. Omitted when `skip_total=true` or `boundary_cursors=false`

### Navigation

| User Action | API Call |
|---|---|
| Load page 1 | `?cursor=&direction=next` |
| Click Next | `?cursor={meta.cursor}&direction=next` |
| Click Prev | `?cursor={meta.cursor}&direction=prev` |
| Click First | `?cursor=&direction=next` |
| Click Last | `?cursor={meta.last_cursor}&direction=next` |

### Skip Total / Boundary Cursors

`total` is included by default via a `COUNT(*)` query. The `last_cursor` computation adds a O(per_page) query. Use `skip_total=true` to omit both:

```
GET /api/v1/customers?cursor=5025&direction=next&per_page=25&skip_total=true
```

## Non-Paginated List (`limit`)

When using `limit` without `per_page` or `page`, `total` is NOT included by default. Use `add_total=true` to include it:

```
GET /api/v1/customers?limit=50                      # no total
GET /api/v1/customers?limit=50&add_total=true       # includes total
```

## Skip Total

Avoid the `COUNT(*)` overhead on paginated endpoints by adding `skip_total=true`:

```
GET /api/v1/customers?page=1&per_page=25&skip_total=true
GET /api/v1/customers?cursor=5025&direction=next&per_page=25&skip_total=true
```

## Config

```php
// config/record.php
'pagination' => [
    'default_mode' => 'offset',        // or 'cursor'
    'cursor' => [
        'default_column' => 'created_at',
        'composite_enabled' => true,
        'boundary_cursors' => true,     // false to skip last_cursor computation
    ],
    'skip_total_default' => false,
],
```
