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

- `per_page` (integer, max: 100) - Items per page
- `page` (integer) - Page number (offset pagination)

**Search**

- `s` (string) - Search across searchable columns (uses full-text index when available, otherwise LIKE)
- `search` (string) - Search only the fields declared in `RecordTableType(searchable: [...])`. Supports main-table columns and one-level relationship fields like `customer.display_name` or `items.name`.

**Selection & Relationships**

- `select` (string) - Select main columns and include relationships using parentheses syntax.
  - Example: `?select=*,customer(*),items(*,product(*))`

**Sorting**

- `sortby` (string) - Field to sort by
- `order` (string: `asc`|`desc`) - Sort direction

**Limiting**

- `limit` (integer, max: 1000) - Limit results (only applied when `per_page` is not provided)

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
  - on error responses, `meta.debug` may include exception context (`exception`, `exception_message`, `file`, `line`).
- `record.debug` (config, boolean, default: `false`) also enables error debug details globally without needing `X-Debug`.
- When debug mode is enabled (via config or header), error responses are also written to Laravel log (`Log::error`) with request context and error metadata.

**Filter Operators**
Filters are usually passed as `{column}={operator}.{value}` (operators validated against the table schema). Some operators support a value-less shorthand form for `null`:

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
- `any` / `all` modifiers are supported in expression syntax:
  - `name=like(any).{ACME,SHOP}`
  - `name=ilike(all).{spx,admin}`

**Grouped Logic**

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
  - Example of grouped logic including relationship filters (`vendor.*`, `items.*`) in the same OR expression.

Notes:

- For grouped logic, use comma-separated expressions inside the group.
- Do not use `=` or `&` inside `or=(...)` / `and=(...)`.
- For URL safety, grouped logic can also be sent in decoded form:
  - `or=(balance_due.gt.0,id.in.(5,6,9))`
- Complex grouped examples are best URL-encoded when sent from frontend clients.
- If an operator is not supported by the current database driver, API returns validation error with an explicit message.

#### Config-Driven `search`

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
- `404` - Resource not available (table not configured or disabled)
- `500` - Server error

#### Get Single Record

```http
GET /{api_prefix}/{table}/{id}
```

Retrieve a single record by its primary key.

#### Query Parameters

- `select` (string) - Select main columns and include relationships using parentheses syntax
  - Example: `?select=*,customer(*),items(*,product(*))`
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

#### Create Record

```http
POST /{api_prefix}/{table}
```

Create a new record.

If a `createValidator` is defined for the target table in `config/record.php`, the request body is validated using that validator before any database changes. On validation failure, the endpoint returns `422` with detailed error messages.

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
- `422` - Validation error (missing `match_on` or invalid payload)

#### Update Record

```http
PUT /{api_prefix}/{table}/{id}
PATCH /{api_prefix}/{table}/{id}
```

Update an existing record. `PUT` expects complete data, `PATCH` allows partial updates.

If an `updateValidator` is defined for the target table, the request is validated with access to both the incoming payload and the current record ID. Validation failures return `422` with error details.

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
