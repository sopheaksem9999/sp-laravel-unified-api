---
title: "Standard CRUD Operations"
description: "List, show, create, upsert, update, delete, restore, and force-delete endpoint behavior and examples."
keywords:
  - CRUD endpoints
  - create update delete
  - list and show
  - upsert endpoint
  - restore and force delete
---

### Standard CRUD Operations

#### List Records

```http
GET /{api_prefix}/{table}
```

Retrieve a paginated list of records with filtering, sorting, and relationship loading.

#### Query Parameters

**Pagination**

- `page` (integer) - Page number for offset pagination (starts at 1).
- `per_page` (integer, max: `per_page_max` = 10000) - Items per page for offset and cursor pagination.

**Cursor Pagination**

Pass `cursor=` to switch to cursor (keyset) pagination — O(1) per page regardless of dataset size. See [Pagination Module](/guide/module-pagination) for full details.

- `cursor` (string) - Opaque cursor token from the previous response's `meta.cursor`. Send empty (`cursor=`) for the first page.
- `direction` (string: `next`|`prev`, default: `next`) - Paging direction.
- `cursor_column` (string) - Column to page on. Defaults to the column the results are sorted by (`created_at` when available, otherwise the primary key).

```bash
GET /{api_prefix}/{table}?cursor=&direction=next&per_page=25
```

Cursor responses replace `meta.page`/`meta.per_page` with `meta.cursor`, `meta.direction`, `meta.cursor_column`, `meta.first_cursor`, `meta.last_cursor`, and `meta.total`.

**Total Count Control**

- `total` (boolean) - Force include (`total=true`) or omit (`total=false`) the `meta.total`/`X-Total-Count` count, avoiding the `COUNT(*)` query on large datasets.
- `skip_total` (boolean, default: `pagination.skip_total_default`) - Legacy alias for `total=false`.
- `add_total` (boolean) - Legacy alias for `total=true` in limit-only mode.

**Search**

- `s` (string) - Search across searchable columns (uses full-text index when available, otherwise LIKE)
- `search` (string) - Search only the fields declared in `RecordTableType(searchable: [...])`. Supports main-table columns and one-level relationship fields like `customer.display_name` or `items.name`.

**Selection & Relationships**

- `select` (string) - Select main columns and include relationships using parentheses syntax.
  - Example: `?select=*,customer(*),items(*,product(*))`
- `with` (string) - Alias/extension of `select`; combined with `select` when both are provided. Uses the same parentheses syntax.

**Sorting**

- `sortby` (string) - Field to sort by
- `order` (string: `asc`|`desc`) - Sort direction

::: warning `sortby` only accepts columns declared in the table config
Sorting, filtering, `select` and `group_by` all validate column names against
the `columns` array of that table's `RecordTableType` — not against the database.
A column that exists in the table but is missing from `columns` is **silently
ignored**: the request still returns `200`, and the sort falls back to
`created_at` (if declared) or the primary key. No error identifies the dropped
parameter.

So declare every column you want queryable, timestamps included:

```php
columns: [
    'id' => ['type' => 'uuid', 'nullable' => false],
    'title' => ['type' => 'string', 'nullable' => false],
    'created_at' => ['type' => 'datetime', 'nullable' => true],
    'updated_at' => ['type' => 'datetime', 'nullable' => true],
],
```

Declaring the timestamps affects reads only — they stay server-managed and are
still ignored in write payloads unless the table sets `overrideTimestamps: true`.
As a safety net, `created_at`/`updated_at` are recovered automatically when the
physical table has them but the config does not; **no other column is**, because
omitting a column from `columns` is also how you deliberately keep it off the
query surface. Run `php artisan sp-laravel-api:validate` to list any table whose
config has drifted from its migration.
:::

**Limiting (Recommended for AI Agents & Fixed Previews)**

- `limit` (integer, max: `limit_max` = 10000) - Limit results (only applied when `per_page` is not provided). In limit-only mode `total` is not included by default; pass `total=true` (or legacy `add_total=true`) to include it.
  > **Performance Tip**: For retrieving a specific number of records (e.g. top 5 invoices, latest 10 orders, or AI assistant queries), **always prefer `limit` over `per_page`**. `limit` executes a direct SQL `LIMIT N` query and skips the expensive `SELECT COUNT(*)` count query, yielding significantly faster response times.

**Result Shape & Aggregation**

- `distinct` (boolean) - Apply SQL `DISTINCT` to the main query.
- `only_trashed` (boolean) - For soft-deleted tables, return only rows where `deleted_at` is not null.
- `with_trashed` (boolean) - For soft-deleted tables, include soft-deleted rows alongside active rows. Takes precedence over the default exclusion of deleted records.
- `aggregate` (string) - One or more aggregate expressions (comma-separated):
  - Supported functions: `count`, `sum`, `avg`, `min`, `max`.
  - Syntax:
    - `count` (no column) ⇒ `COUNT(*)`
    - `count:column` ⇒ `COUNT(column)`
    - `sum:column`, `avg:column`, `min:column`, `max:column`
  - Column names are validated against the table schema.
- `group_by` (string) - Comma-separated list of columns to group by. Column names are validated against the table schema.

**Debugging**

- `X-Debug` (HTTP header, boolean) - When sent as `true`, `1`, `yes`, or `on`, responses include lazy-loading diagnostics and error debug details:
  - `meta.debug.lazy_stats` with the output of `QueryBuilderFilters::getLazyStats()`:
    - `total_operations`
    - `executed_operations`
    - `pending_operations`
    - `cache_hits`
    - `cache_efficiency`
  - `meta.debug.cache_stats` with `QueryCacheService::requestStats()`.
  - on error responses, `meta.debug` may include exception context (`exception`, `exception_message`, `file`, `line`).
- `explain` (query param, boolean) - When `record.profiling.enabled` is true, adds the database query plan under `meta.debug.explain`.
- `record.debug` (config, boolean, default: `false`) also enables error debug details globally without needing `X-Debug`.
- When debug mode is enabled (via config or header), error responses are also written to Laravel log (`Log::error`) with request context and error metadata.

**Filter Operators & Grouped Logic**

See [Filter Operators Reference](/guide/api-filter-operators) for the full operator list, grouped-logic (`or=`/`and=`) syntax, and the config-driven `?search=` param.

When `aggregate` is present and valid, the list endpoint returns aggregated rows instead of paginated records. The response still follows the standard shape, with:

- `data`: Aggregated rows (including `group_by` columns and aggregate aliases like `count_id`).
- `meta.total`: Number of aggregated rows.
- `meta.group_by`: Grouped columns (when provided).
- `meta.aggregate`: List of aggregate operations with function, column, and alias metadata.

#### Example Request

```http
GET /api/v1/invoices?per_page=25&sortby=created_at&order=desc&select=*,customer(*),items(*,product(*))&status=eq.pending&total=gte.100&search=invoice
Authorization: Bearer {access_token}
```

#### Response Format

```json
{
  "success": true,
  "error_code": 0,
  "data": [
    {
      "id": 1,
      "invoice_number": "INV-001",
      "total": 150.0,
      "status": "pending",
      "created_at": "2024-01-15T10:30:00Z",
      "customer": {
        "id": 5,
        "name": "John Doe",
        "email": "john@example.com"
      },
      "items": [
        {
          "id": 10,
          "description": "Product A",
          "quantity": 2,
          "price": 75.0
        }
      ]
    }
  ],
  "meta": {
    "request_id": "req_abc123def456",
    "page": 1,
    "per_page": 25,
    "total": 150
  }
}
```

#### Status Codes

- `200` - Success
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Not found (table not configured/disabled or record not found)
- `500` - Server error

---

#### Related Docs

- [Nested Relationship Writes and Bulk Operations](/guide/api-nested-and-bulk-operations) — nested create/update/delete in payloads and bulk create/update/delete/upsert.
- [Pagination Module](/guide/module-pagination) — offset, cursor, and limit-only pagination details.
- [Relationships](/core-concepts/relationships) — relationship selection, filtering, and the write payload guide.
- [Validation](/guide/api-validation) — table validators used by write endpoints.
- [Record Hooks](/guide/record-hooks) — global and table trigger hooks.
- [Error Responses, Rate Limiting, and Security](/guide/api-errors-rate-security) — error envelope and per-table rate limits.
- [Internal API Methods](/guide/api-internal-methods-core) — run the same CRUD logic from business code.
- [QueryHelpers Trait](/guide/api-queryhelpers-trait) — Eloquent-based filtering for custom endpoints.

#### Get Single Record

```http
GET /{api_prefix}/{table}/{id}
```

Retrieve a single record by its primary key.

#### Query Parameters

- `select` (string) - Select main columns and include relationships using parentheses syntax
  - Example: `?select=*,customer(*),items(*,product(*))`
- `with` (string) - Alias/extension of `select`; combined with `select` when both are provided.
- `with_trashed` (boolean) - For soft-deleted tables, fetch the record even if it has been soft-deleted.

#### Example Request

```http
GET /api/v1/invoices/123?select=*,customer(*),items(*),payments(*)
Authorization: Bearer {access_token}
```

#### Response Format

```json
{
  "success": true,
  "error_code": 0,
  "data": {
    "id": 123,
    "invoice_number": "INV-123",
    "total": 250.00,
    "status": "paid",
    "customer": {
      "id": 5,
      "name": "John Doe"
    },
    "items": [...],
    "payments": [...]
  },
  "meta": {
    "request_id": "req_abc123def456"
  }
}
```

#### Status Codes

- `200` - Success
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Not found (table not configured/disabled or record not found)
- `500` - Server error

> **Rows outside the caller's scope.** Under tenant scoping and [`viewOwn`](/guide/feature-permission-own-records), a by-id read, update, delete, restore or force-delete of a row outside the caller's tenant or own records returns `404`, exactly like a nonexistent id.

#### Create Record

```http
POST /{api_prefix}/{table}
```

Create a new record.

If a `createValidator` is defined for the target table in `config/record.php`, the request body is validated using that validator before any database changes. On validation failure, the endpoint returns `422` with detailed error messages.

**Unknown field/relationship names are rejected**: every top-level key in the request body must be either a real column or a declared relationship alias for the table — a typo'd or invented field name (e.g. `{"custommer_id": 5}` instead of `customer_id`, or a relationship alias that was never declared) returns `422` with the field name and the full list of valid columns and relationships, instead of the field being silently dropped and the record saved without it. This does **not** apply to a column listed in that table's `columnWriteDisabled` — sending one of those is still a silent no-op (useful for round-tripping a GET response back as a write without stripping server-managed fields first), not an error. See the [Relationship Write Payload Guide](/core-concepts/relationships) for the shape each relationship type expects.

#### Create With Relationships In Response

To return related records in the response of the create call, pass a nested `select` query parameter. Relationship selection uses parentheses:
`relationship(columns,childRelationship(...))`.

Example (return `customer` and `items.product` after creating an `orders` record):

```bash
curl --location 'http://127.0.0.1:8000/api/v1/orders?select=*,customer(*),items(*,product(*))' \
  --header 'Content-Type: application/json' \
  --header 'Authorization: Bearer {access_token}' \
  --data '{
    "customer_id": 5,
    "status": "draft",
    "total": 300.00
  }'
```

#### Request Body

JSON object with field values:

```json
{
  "invoice_number": "INV-124",
  "customer_id": 5,
  "total": 300.0,
  "status": "draft",
  "items": [
    {
      "description": "Product B",
      "quantity": 3,
      "price": 100.0
    }
  ]
}
```

#### Response Format

```json
{
  "success": true,
  "error_code": 0,
  "data": {
    "id": 124,
    "invoice_number": "INV-124",
    "customer_id": 5,
    "total": 300.0,
    "status": "draft",
    "created_at": "2024-01-15T11:00:00Z",
    "updated_at": "2024-01-15T11:00:00Z"
  },
  "meta": {
    "request_id": "req_abc123def456"
  }
}
```

#### Status Codes

- `200` - Success
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Resource not available (table not configured or disabled)
- `422` - Validation error (table validator or request validation)
- `500` - Server error

#### Upsert Record

```http
POST /{api_prefix}/{table}/upsert
```

Create a new record or update an existing one based on matching columns.

#### Query Parameters

- `match_on` (string, required) - Comma-separated list of columns to use for matching records.
  - Example: `?match_on=sku` or `?match_on=email,tenant_id`

#### Request Body

JSON object with field values:

```json
{
  "sku": "PROD-001",
  "name": "Wireless Mouse",
  "price": 29.99
}
```

#### Response Format

```json
{
  "success": true,
  "error_code": 0,
  "data": {
    "id": 125,
    "payload": {
      "sku": "PROD-001",
      "name": "Wireless Mouse",
      "price": 29.99
    }
  },
  "meta": {
    "request_id": "req_abc123def456"
  }
}
```

#### Status Codes

- `200` - Success
- `401` - Unauthorized
- `403` - Forbidden (needs both the `create` and `update` permission; under [`viewOwn`](/guide/feature-permission-own-records), also when the item would overwrite another user's row through `match_on`, the primary key or any other unique key)
- `404` - Resource not available (table not configured, or `canUpsert` not enabled)
- `422` - Validation error (missing `match_on` or invalid payload)

#### Update Record

```http
PUT /{api_prefix}/{table}/{id}
PATCH /{api_prefix}/{table}/{id}
```

Update an existing record. `PUT` expects complete data, `PATCH` allows partial updates.

If an `updateValidator` is defined for the target table, the request is validated with access to both the incoming payload and the current record ID. Validation failures return `422` with error details.

Same unknown-field/relationship validation as the "Create Record" section above applies here.

#### Request Body

```json
{
  "status": "sent",
  "total": 275.0
}
```

#### Response Format

```json
{
  "success": true,
  "error_code": 0,
  "data": {
    "id": 124,
    "invoice_number": "INV-124",
    "status": "sent",
    "total": 275.0,
    "updated_at": "2024-01-15T11:30:00Z"
  },
  "meta": {
    "request_id": "req_abc123def456"
  }
}
```

#### Status Codes

- `200` - Success
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Not found (table not configured/disabled or record not found)
- `422` - Validation error (table validator or request validation)
- `500` - Server error

#### Delete Record

```http
DELETE /{api_prefix}/{table}/{id}
DELETE /{api_prefix}/{table}/{id}?force=true
```

Delete a record (soft delete if enabled, otherwise hard delete).

Adding `?force=true` is a shortcut that permanently deletes the record regardless of soft-delete configuration — identical in behavior to the dedicated `DELETE /{api_prefix}/{table}/{id}/force` endpoint. Useful when you want to conditionally force-delete without changing the URL path.

If a `deleteValidator` is defined for the target table, the request is validated (typically against the ID and context) before the record is deleted. Validation failures return `422` with error details.

#### Response Format

```json
{
  "success": true,
  "error_code": 0,
  "data": {
    "deleted": 1
  },
  "meta": {
    "request_id": "req_abc123def456"
  }
}
```

#### Status Codes

- `200` - Success
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Not found (table not configured/disabled or record not found)
- `422` - Validation error (table validator or request validation)
- `500` - Server error

#### Restore Record

```http
POST /{api_prefix}/{table}/{id}/restore
```

Restore a soft-deleted record (only available for tables with soft deletes enabled).

#### Response Format

```json
{
  "success": true,
  "error_code": 0,
  "data": {
    "restored": 1
  },
  "meta": {
    "request_id": "req_abc123def456"
  }
}
```

#### Status Codes

- `200` - Success
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Not found (table not configured/disabled or record not found)
- `500` - Server error

#### Force Delete Record

```http
DELETE /{api_prefix}/{table}/{id}/force
```

Permanently delete a record (bypasses soft delete).

#### Response Format

```json
{
  "success": true,
  "error_code": 0,
  "data": {
    "deleted": 1
  },
  "meta": {
    "request_id": "req_abc123def456"
  }
}
```

#### Status Codes

- `200` - Success
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Not found (table not configured/disabled or record not found)
- `500` - Server error
