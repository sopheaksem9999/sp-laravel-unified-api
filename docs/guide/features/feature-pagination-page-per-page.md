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

For large datasets, cursor pagination avoids the performance penalty of offset-based pagination.

### Query Style

```
GET /api/v1/customers?cursor=5000&direction=next&per_page=25
```

### Response Meta

```json
{
  "meta": {
    "cursor": "5025",
    "direction": "next",
    "cursor_column": "id"
  }
}
```

### Headers

```
X-Cursor: 5025
```

## Skip Total

Avoid the `COUNT(*)` overhead by adding `skip_total=true`:

```
GET /api/v1/customers?page=1&per_page=25&skip_total=true
```

## Config

```php
// config/record.php
'pagination' => [
    'default_mode' => 'offset',        // or 'cursor'
    'cursor' => [
        'default_column' => 'id',
        'composite_enabled' => true,
    ],
    'skip_total_default' => false,
],
```
