---
title: "Performance"
description: "Practical performance guidance for dynamic CRUD, relationship loading, caching, pagination, and query optimization."
keywords:
  - performance
  - scalability
  - bulk operations
  - relationships
  - caching
  - pagination
  - indexing
  - cursor pagination
  - total=false
  - read replica
  - query profiling
  - index hints
---

# Performance

## Pagination Optimizations

### Cursor Pagination (`?cursor=`)

For large datasets, cursor-based (keyset) pagination is **O(1) per page** regardless of total row count, unlike offset pagination which gets progressively slower. Default sort: `created_at DESC` (matching offset pagination).

```bash
# First page — send empty cursor:
GET /api/v1/invoices?cursor=&direction=next&per_page=25

# Subsequent pages — use cursor from previous response:
GET /api/v1/invoices?cursor=250000&direction=next&per_page=25
```

Response meta:

```json
{
  "meta": {
    "cursor": "250025",
    "direction": "next",
    "cursor_column": "created_at",
    "total": 500000,
    "first_cursor": null,
    "last_cursor": "99985"
  }
}
```

`total` and `last_cursor` are computed via separate queries (COUNT + O(per_page) DESC LIMIT). Skip both with `total=false`:

**Supported parameters:**

| Param | Default | Description |
|---|---|---|
| `cursor` | — | The cursor value (usually the last record's ID from previous page) |
| `direction` | `next` | `next` or `prev` |
| `cursor_column` | `id` | Column to cursor on. Composite cursors auto-enable when this differs from the primary key |
| `per_page` | 25 | Page size (capped at `per_page_max`) |

Composite cursors (`cursor_column` != primary key) generate stable ordering via `WHERE (cursor_col, id) > (?, ?)` to handle non-unique sort values.

### Total COUNT Control (`?total=true|false`)

The `COUNT(*)` query on paginated endpoints can be expensive on large filtered datasets. Use `total=false` when you don't need exact totals. Works for both offset and cursor pagination:

```bash
# Offset
GET /api/v1/invoices?page=1&per_page=25&total=false

# Cursor
GET /api/v1/invoices?cursor=250000&per_page=25&total=false
```

When skipped, offset pagination omits `total` metadata and cursor pagination returns `"total": 0`. Legacy `skip_total=true` remains supported for older clients.

When `pagination.skip_total_default=true` is configured, clients can force totals back on with `total=true`.

### Config

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

## Dynamic CRUD Query Cost

- Prefer `select=` to limit columns, especially on wide tables.
- Use pagination (`page`, `per_page`) or cursor pagination (`cursor`) instead of unbounded lists.
- Add DB indexes for common filters and sorts (`status`, `created_at`, foreign keys, tenant column).
- Use `?total=false` to avoid expensive `COUNT(*)`.

## Relationship Loading

- Prefer loading only needed relationships (`select=*,customer(id,name)`).
- Keep relationship depth small and predictable (`max_depth` is your safety valve, default 10).
- Avoid joining multiple high-cardinality relations on list endpoints unless required.
- Subquery JSON optimization is used for <= `subquery_optimization_max_records` (default 100) records to batch-load relationships in a single query.

## Caching

- Enable query caching when tables are read-heavy and change infrequently.
- Keep TTL short for frequently-updated tables; invalidate on write paths.
- Cursor pagination responses are cached with their cursor value + direction to avoid stale page data.

## Bulk Operations

- Bulk operations still execute validation + trigger logic; do not assume "free" writes.
- Use smaller batch sizes when payloads are large to avoid timeouts.
- Ensure the DB has proper indexes on the primary key and tenant column.

## Database Connection Splitting

Route read queries (list, show) to a read replica by configuring a connection in `config/database.php` and setting:

```php
// config/record.php
'database' => [
    'read_connection' => env('DB_READ_CONNECTION'),
    'write_connection' => env('DB_WRITE_CONNECTION'),
],
```

```env
DB_READ_CONNECTION=mysql_read
```

All write operations (create, update, delete, bulk) stay on the write connection.

## Index Hints

For tables where the query optimizer chooses a suboptimal index, you can force specific indexes per context:

```php
// config/record.php
'index_hints' => [
    'invoices' => [
        'list' => 'idx_invoices_tenant_status_created',
        'filter_status' => 'idx_invoices_status',
    ],
],
```

The `list` context is used for list/show queries. Apply via `applyIndexHint($builder, 'invoices', 'list')`.

## Query Profiling (`?explain=true`)

Debug slow queries by inspecting the execution plan. Returns `EXPLAIN FORMAT=JSON` (MySQL) or `EXPLAIN (FORMAT JSON)` (PostgreSQL) in the response meta:

```bash
GET /api/v1/invoices?status=eq.active&explain=true
```

Requires `profiling.enabled` to be `true` in config (disabled by default for security):

```php
// config/record.php
'profiling' => [
    'enabled' => env('SP_QUERY_PROFILING_ENABLED', false),
],
```

Response includes in `meta.debug`:

```json
{
  "meta": {
    "debug": {
      "explain": {
        "plan": [
          {
            "id": 1,
            "select_type": "SIMPLE",
            "table": "invoices",
            "type": "ref",
            "rows": 50000,
            "Extra": "Using where; Using index"
          }
        ],
        "query_time_ms": 12.53,
        "sql": "select * from `invoices` where `status` = ?"
      }
    }
  }
}
```

## RecordQueryBuilder (Programmatic Usage)

The expanded `RecordQueryBuilder` class provides a fluent API for building optimized queries in application code:

```php
use Sopheak\Core\Services\Queries\RecordQueryBuilder;

$results = (new RecordQueryBuilder('invoices', $tableSchema, $tenantId))
    ->applyFilters($request)
    ->applyIndexHint('list')
    ->cursorPaginate(
        cursor: $request->input('cursor'),
        direction: 'next',
        perPage: 50
    );

// Or with offset:
$results = (new RecordQueryBuilder('invoices', $tableSchema, $tenantId))
    ->applyFilters($request)
    ->offsetPaginate(page: 1, perPage: 25, skipTotal: true);

// The underlying builder is accessible for custom clauses:
$builder = (new RecordQueryBuilder('invoices', $tableSchema))
    ->getBuilder()
    ->where('amount', '>', 1000);
```
