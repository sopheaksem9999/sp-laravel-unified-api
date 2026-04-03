---
name: sp-laravel-api-front
title: "AI Skill: sp-laravel-api Front"
description: "Use when building frontend integrations against sopheak/sp-laravel-api, including OpenAPI discovery, endpoint selection, payload building, filters, embeds, upsert, and response/error handling."
keywords:
  - sp laravel api frontend
  - openapi api consumer
  - dynamic crud endpoint usage
  - query filters
  - relationship embedding
  - attachment upload frontend
  - clone temp attachment
  - attachment visibility
  - protected download url
  - upsert
  - bulk upsert
  - response envelope
  - error_code handling
trigger:
  - "Use when requests ask to build frontend calls (fetch/axios) to sopheak/sp-laravel-api endpoints."
  - "Use when reading OpenAPI to create query params, payloads, response handling, or typed API clients."
  - "Do not use for backend package internals like migrations, RecordTableType config, or Laravel hook implementation."
---

# AI Skill: sp-laravel-api-front

## Trigger Details (AI Routing)

Use this skill when the task is about consuming an API powered by `sopheak/sp-laravel-api` from web/mobile frontend code.

### Strong trigger intents

- Build `fetch`/Axios calls for CRUD, upsert, bulk upsert, auth, report, or custom function endpoints
- Build file upload and attachment linking flows (`upload`, `clone-temp`, `record/{table}/{record_id}`)
- Read OpenAPI schema and map it into typed frontend request/response models
- Construct query params (`filter`, `search`, `embed`, `columns`, `sort`, pagination)
- Handle `error_code` and standard response envelope in frontend UX
- Generate integration examples for React, Vue, Next.js, Nuxt, mobile, or SDK wrappers

### Trigger keywords

- `openapi`, `frontend`, `api consumer`, `fetch`, `axios`, `query params`
- `attachments`, `upload`, `multipart`, `clone-temp`, `download`, `collection_name`
- `embed`, `filter`, `search`, `columns`, `sort`, `group_by`, `agg`
- `upsert`, `bulk upsert`, `auth login`, `report endpoint`
- `error_code`, `success`, `meta.page`, `meta.per_page`, `meta.total`

### Do not use this skill when

- The task requires changing backend package internals, Laravel configs, migrations, or trigger hooks
- The task is package architecture refactor with no frontend integration layer

### Minimum context to collect first

1. Base API URL and version prefix
2. Live OpenAPI spec endpoint
3. Auth/tenant headers required by the target endpoint
4. Operation type (list/read/create/update/delete/upsert/custom function)

# Agent Context: sp-laravel-api Frontend (API Consumer)

## What This Is

A config-driven REST API backend. Every table, every function, and every schema is
**fully described in the live OpenAPI spec** — that is the single source of truth.
The frontend has no access to backend code. Always read the OpenAPI spec first.

## The Only Source of Truth

```
GET {baseUrl}/api/v2/docs/openapi.json
```

From this response you learn:
- Every available table name (exact slug for URL construction)
- Every column, its type, and whether it is required
- Every relationship and its embed alias syntax
- Every custom function path, its HTTP method, and its payload schema
- Which headers are required per endpoint
- Response schemas

**Never hardcode table names, column names, or function paths. Always derive them from the OpenAPI spec.**

---

## How to Generate a Correct API Call

When asked to write any API call, follow these steps in order.

### Step 1 — Fetch the OpenAPI spec

```
GET {baseUrl}/api/v2/docs/openapi.json
```

Find in the response:
- `paths` — every endpoint with its method, parameters, and requestBody schema
- `components.schemas` — every resource's fields, types, and required list
- `info.description` — query operators, pagination options, and relationship syntax

### Step 2 — Identify the operation type

| What the user wants | Use |
|---------------------|-----|
| List / search / filter records | `GET /api/v2/{table}` with query params |
| Get one record | `GET /api/v2/{table}/{id}` |
| Create a record | `POST /api/v2/{table}` |
| Update a record | `PUT /api/v2/{table}/{id}` |
| Delete a record | `DELETE /api/v2/{table}/{id}` |
| Restore soft-deleted | `POST /api/v2/{table}/{id}/restore` |
| Permanently delete | `DELETE /api/v2/{table}/{id}/force` |
| Upsert single | `POST /api/v2/{table}/upsert?match_on={col}` |
| Upsert many | `POST /api/v2/{table}/bulk/upsert` |
| Custom action / function | path and method from OpenAPI `paths` |
| Auth (login/logout/refresh) | `/api/v2/auth/{action}` |
| Report | `/api/v2/report/{name}` |

### Step 3 — Build headers

```
Authorization: Bearer {token}      required for protected endpoints
x-company-id: {companyId}          required for company-scoped endpoints
Content-Type: application/json     required for POST / PUT / PATCH
```

> Whether an endpoint requires `x-company-id` is visible in the OpenAPI `parameters`
> section for that path. When in doubt, include it.

### Step 4 — Build the payload

For write operations, look up `components.schemas.{Resource}Write` in the OpenAPI spec.
That schema lists every writable field and which are `required`.

```
required fields   → must be present in POST / PUT
optional fields   → include only when needed
system fields     → never send: id, created_at, updated_at, deleted_at, company_id
```

### Step 5 — Handle the response

Every response uses the same envelope:

```
success: true  → read data for the record(s), read meta for pagination
success: false → read error_code to classify the error, read message and errors
```

---

## Authentication Endpoints

These do **not** follow the CRUD pattern. They are fixed custom paths.

```
POST /api/v2/auth/login              body: { email, password }
POST /api/v2/auth/logout
POST /api/v2/auth/refresh
POST /api/v2/auth/register
POST /api/v2/auth/forgot-password
POST /api/v2/auth/reset-password
GET  /api/v2/auth/whoami
POST /api/v2/auth/impersonate_in
POST /api/v2/auth/impersonate_out
```

Login response shape:
```json
{
  "success": true,
  "error_code": 0,
  "data": { "token": "eyJ...", "type": "bearer" },
  "meta": {}
}
```

Store the token and send it as `Authorization: Bearer {token}` on all subsequent requests.

---

## Standard Endpoint Pattern

Every resource table follows this pattern (exact paths are in the OpenAPI `paths`):

```
GET    /api/v2/{table}                      list with filters, sorting, pagination
GET    /api/v2/{table}/{id}                 get one record
POST   /api/v2/{table}                      create
PUT    /api/v2/{table}/{id}                 full update
DELETE /api/v2/{table}/{id}                 soft delete (if supported)
POST   /api/v2/{table}/{id}/restore         restore soft-deleted record
DELETE /api/v2/{table}/{id}/force           permanent delete
POST   /api/v2/{table}/upsert?match_on=col  upsert single record
POST   /api/v2/{table}/bulk/upsert          upsert many records
```

> Not every table exposes all endpoints. The OpenAPI `paths` object is the
> authoritative list — if a path does not appear there, the endpoint does not exist.

---

## Attachment Module (Frontend Usage)

Attachment endpoints are function endpoints on `sp_attachments`. The final path depends on API prefix and attachment route prefix.

Default shape in most projects:

```
/{apiPrefix}/attachments
```

Use OpenAPI to confirm exact path before coding.

### Core attachment endpoints

```
POST   /{apiPrefix}/{attachmentPrefix}/upload
POST   /{apiPrefix}/{attachmentPrefix}/clone-temp
GET    /{apiPrefix}/{attachmentPrefix}/{id}/download
GET    /{apiPrefix}/{attachmentPrefix}/record/{table}/{record_id}
POST   /{apiPrefix}/{attachmentPrefix}/record/{table}/{record_id}
DELETE /{apiPrefix}/{attachmentPrefix}/record/{table}/{record_id}/{attachment_id}
GET    /{apiPrefix}/{attachmentPrefix}/folders
POST   /{apiPrefix}/{attachmentPrefix}/folders
PUT    /{apiPrefix}/{attachmentPrefix}/folders/{id}
PATCH  /{apiPrefix}/{attachmentPrefix}/folders/{id}
DELETE /{apiPrefix}/{attachmentPrefix}/folders/{id}
```

### Upload file (multipart/form-data)

Required input:

- `file`

Common optional inputs:

- `visibility`: `private | public | temp_private | temp_public`
- `as_temp`: boolean
- `temp_timeout_minutes` or `temp_timeout_at`
- `record_id`, `record_type`, `collection_name`, `replace_old`
- `folder_id`, `title`, `caption`
- Image options: `size_name`, `w`, `h`, `fit`

Example:

```ts
const form = new FormData();
form.append("file", fileInput.files[0]);
form.append("visibility", "temp_private");
form.append("record_type", "products");
form.append("record_id", "123");
form.append("collection_name", "gallery");
form.append("replace_old", "false");

const res = await fetch(`${baseUrl}/${apiPrefix}/${attachmentPrefix}/upload`, {
  method: "POST",
  headers: {
    Authorization: `Bearer ${token}`,
    "x-company-id": companyId
  },
  body: form
});
```

Note: do not set `Content-Type` manually for `FormData`; browser sets the boundary.

### Clone existing attachment as temp/final

Use this when user already uploaded a temp file and you want a separate final asset record.

```json
POST /{apiPrefix}/{attachmentPrefix}/clone-temp
{
  "attachment_id": "uuid-source-id",
  "visibility": "private",
  "record_type": "invoices",
  "record_id": "inv_001",
  "collection_name": "documents"
}
```

### Link and unlink attachments to records

Link:

```json
POST /{apiPrefix}/{attachmentPrefix}/record/{table}/{record_id}
{
  "attachment_id": "uuid-id",
  "collection_name": "default"
}
```

List by record:

```http
GET /{apiPrefix}/{attachmentPrefix}/record/{table}/{record_id}?collection_name=default
```

Unlink:

```http
DELETE /{apiPrefix}/{attachmentPrefix}/record/{table}/{record_id}/{attachment_id}?collection_name=default
```

### Visibility and access behavior

- `private` and `temp_private`: frontend should use API download URL.
- `public`: attachment usually includes direct public asset URL.
- `temp_public`: direct URL by default, but can be forced to protected download URL when `attachments.protect_temp_public_via_download=true`.
- Expired temp attachments return HTTP `410 Gone` on download.

### Recommended frontend flow

1. Upload as temp during draft forms (`as_temp=true` or `visibility=temp_private`).
2. On final save, call `clone-temp` with final visibility and link to target record.
3. Keep collection names consistent (`avatar`, `gallery`, `documents`) for predictable UI rendering.
4. If policy requires access checks, use download endpoint URLs and avoid direct public URLs.

---

## Custom Function Endpoints

Custom actions (non-CRUD) have their own paths. They do **not** follow a single
naming convention — always look them up in the OpenAPI spec.

Examples from this API:

```
POST /api/v2/attendances/scan                   table-scoped action
POST /api/v2/attendances/manual_check_in        table-scoped action
POST /api/v2/attendances/manual_check_out       table-scoped action
POST /api/v2/employee_rosters/close             table-scoped action
GET  /api/v2/employee_rosters/rosters           table-scoped query
POST /api/v2/companies/add_user                 table-scoped action
POST /api/v2/companies/remove_user              table-scoped action
GET  /api/v2/generate/no                        global function
GET  /api/v2/report/{reportName}                report function
GET  /api/v2/roles/role_permission              table-scoped query
```

For each custom function, the OpenAPI spec provides:
- The exact HTTP method
- Required query parameters or body schema
- Response shape

---

## Report Endpoints

```
GET /api/v2/report/bill
GET /api/v2/report/customer_invoice
GET /api/v2/report/delivery_note
GET /api/v2/report/estimate
GET /api/v2/report/inventory_summary
GET /api/v2/report/inventory_valuation
GET /api/v2/report/invoice
GET /api/v2/report/product
GET /api/v2/report/purchase_order
GET /api/v2/report/receive_note
GET /api/v2/report/so_customer
```

All accept standard query params (filters, date range, pagination).

---

## Query Parameters — Full Reference

All parameters go in the URL query string on GET requests.

### Pagination

Two modes:

**Traditional** (default):

| Param | Default | Max | Notes |
|-------|---------|-----|-------|
| `page` | 1 | — | Page number |
| `per_page` | 25 | 100 | Records per page |

**Cursor-based** (auto-enabled when result set > 10,000 rows, or request explicitly):

| Param | Values | Notes |
|-------|--------|-------|
| `cursor` | record id | Start cursor position |
| `direction` | `next` \| `prev` | Pagination direction |

When cursor mode is active, `meta` returns `next_cursor` and `prev_cursor` instead of `page`/`total`.

### Full-text Search

```
?s=search term
?search=search term       (alias)
```

Only searches columns that the backend has indexed. Check the schema to see what is indexed.

### Column Selection

```
?select=col1,col2,col3          select specific columns
?select=*                        all columns
```

### Relationship Embedding

Embed related records directly in the response using the alias syntax:

```
?select=*,alias:foreignTable(col1,col2)
?select=*,alias:foreignTable(*)
```

Real examples:
```
?select=*,customer:customers(id,display_name,primary_email_addr)
?select=*,items:invoice_items(*),customer:customers(id,display_name)
?select=*,vendor:vendors(id,display_name),class:classes(id,name)
?select=*,product:products(id,name,price),warehouse:warehouses(id,name)
```

> The `alias` is the relationship name you want in the response.
> The `foreignTable` is the actual table name from the OpenAPI spec.
> Always use snake_case table names exactly as they appear in the OpenAPI paths.

Legacy eager load (no column control):
```
?with=customers,invoice_items
```

### Sorting

```
?sortby=created_at&order=desc
?sortby=date&order=asc
?sortby=total_amount&order=desc
```

Default order is ascending on `id` when not specified.

### Aggregation

```
?aggregate=count
?aggregate=sum:total_amount
?aggregate=count,sum:total_amount,avg:total_amount
?aggregate=sum:total_amount&group_by=status
?aggregate=count,sum:total_amount&group_by=status,class_id
```

Functions: `count` `count:{col}` `sum:{col}` `avg:{col}` `min:{col}` `max:{col}`

---

## Filter Operators

Format: `?{column}={operator}.{value}`
All filters on one request are ANDed together.

### Equality

| Operator | Meaning | Example |
|----------|---------|---------|
| `eq` | Equals | `?status=eq.active` |
| `neq` | Not equals | `?status=neq.archived` |

### Comparison

| Operator | Meaning | Example |
|----------|---------|---------|
| `gt` | Greater than | `?total_amount=gt.1000` |
| `gte` | Greater than or equal | `?total_amount=gte.1000` |
| `lt` | Less than | `?total_amount=lt.5000` |
| `lte` | Less than or equal | `?total_amount=lte.5000` |

### String Pattern

| Operator | Meaning | Example |
|----------|---------|---------|
| `like` | SQL LIKE (use `%` wildcard) | `?display_name=like.%John%` |
| `ilike` | Case-insensitive LIKE | `?display_name=ilike.john` |
| `not_like` | SQL NOT LIKE | `?display_name=not_like.%test%` |
| `contains` | Substring match | `?display_name=contains.John` |
| `starts_with` | Prefix | `?ref_number=starts_with.INV` |
| `ends_with` | Suffix | `?email=ends_with.gmail.com` |
| `regex` | Regular expression | `?ref_number=regex.^INV-[0-9]+` |
| `match` / `imatch` | Regex helpers (case-sensitive / insensitive) | `?ref_number=imatch.^inv-[0-9]+` |

### List

| Operator | Meaning | Example |
|----------|---------|---------|
| `in` | Value in list | `?status=in.draft,pending,approved` |
| `not_in` | Value not in list | `?status=not_in.cancelled,void` |
| `in` (parenthesized) | Postgres-style list | `?id=in.(5,6,9)` |

### Range

| Operator | Meaning | Example |
|----------|---------|---------|
| `between` | Inclusive range | `?total_amount=between.100,10000` |
| `not_between` | Outside range | `?total_amount=not_between.0,100` |

### Null and Empty

| Operator | Meaning | Example |
|----------|---------|---------|
| `is.null` | Is NULL | `?deleted_at=is.null` |
| `is_not.null` | Is NOT NULL | `?deleted_at=is_not.null` |
| `is` | Is NULL (short form) | `?approved_at=is.null` |
| `is_not` | Is NOT NULL (short form) | `?approved_at=is_not.null` |
| `empty` | NULL or empty string | `?notes=empty` |
| `not_empty` | Not null and not empty | `?notes=not_empty` |

### Date (date part only, ignores time)

| Operator | Example |
|----------|---------|
| `date_eq` | `?date=date_eq.2024-01-15` |
| `date_gt` | `?date=date_gt.2024-01-01` |
| `date_gte` | `?date=date_gte.2024-01-01` |
| `date_lt` | `?date=date_lt.2024-12-31` |
| `date_lte` | `?date=date_lte.2024-12-31` |

### Advanced

| Operator / Syntax | Example |
|----------|---------|
| `not.<operator>` | `?status=not.eq.archived` |
| `any` modifier | `?name=like(any).{ACME,SHOP}` |
| `all` modifier | `?name=ilike(all).{spx,admin}` |
| grouped `or` | `?vendor_id=eq.27&or=(balance_due.gt.0,id.eq.5)` |
| grouped nested | `?vendor_id=eq.27&and=(or(balance_due.gt.0,id.eq.5),id.neq.2)` |

### PostgreSQL-only operators

| Operator | Example |
|----------|---------|
| `fts`, `plfts`, `phfts`, `wfts` | `?description=fts.invoice` |
| `cs`, `cd`, `ov`, `sl`, `sr`, `nxl`, `nxr`, `adj` | `?tags=cs.{a,b}` |

If an operator is not supported by the current database driver, API responds with validation error `422`.

### Column Comparison

| Operator | Meaning | Example |
|----------|---------|---------|
| `compare` | Column equals another column | `?updated_at=compare.created_at` |

---

## Create and Update Payload

```
POST /api/v2/{table}       body: writable columns from the schema
PUT  /api/v2/{table}/{id}  body: full update with writable columns
```

Rules:
- Only send fields listed in `components.schemas.{Resource}Write` in the OpenAPI spec
- Required fields (from `required: [...]` in the schema) must always be present in POST
- Never send: `id`, `created_at`, `updated_at`, `deleted_at`, `company_id` — these are system-managed
- For PATCH-style partial updates, some tables accept `PUT` with only changed fields

### Real Example — Create an Invoice

From OpenAPI schema `InvoicesWrite`, required fields: `[]` (none required), writable fields include:

```json
POST /api/v2/invoices
Authorization: Bearer {token}
x-company-id: {companyId}
Content-Type: application/json

{
  "customer_id": 42,
  "class_id": 1,
  "term_id": 3,
  "ref_number": "INV-2024-001",
  "date": "2024-01-15",
  "due_date": "2024-02-15",
  "email": "customer@example.com",
  "total_amount": 1500.00,
  "status": "draft",
  "private_note": "Internal note",
  "items": [
    { "product_id": 10, "name": "Service A", "quantity": 2, "price": 500.00, "total_amount": 1000.00 },
    { "product_id": 11, "name": "Service B", "quantity": 1, "price": 500.00, "total_amount": 500.00 }
  ]
}
```

### Real Example — List Products with Filters

```
GET /api/v2/products
  ?select=*,category:product_categories(id,name)
  &status=eq.active
  &total_amount=gte.100
  &sortby=name
  &order=asc
  &per_page=25
  &page=1
Authorization: Bearer {token}
x-company-id: {companyId}
```

### Real Example — List Invoices with Date Range

```
GET /api/v2/invoices
  ?select=*,customer:customers(id,display_name),items:invoice_items(*)
  &date=date_gte.2024-01-01
  &date=date_lte.2024-12-31
  &status=neq.void
  &sortby=date
  &order=desc
  &per_page=50
Authorization: Bearer {token}
x-company-id: {companyId}
```

---

## Relationship Payload on Write

When creating or updating a record, include related records in the same body.
The key is the relationship alias. Look up the relationship names in the OpenAPI spec.

### Items / Line Items (HasMany pattern)

Array of child operations:

| Element | Operation |
|---------|-----------|
| `{ ...fields }` (no id) | Create new child |
| `{ "id": 5, ...fields }` | Update existing child |
| `{ "id": 5, "_delete": true }` | Remove child |
| `5` (integer only) | Attach by id |

```json
{
  "customer_id": 42,
  "date": "2024-01-15",
  "items": [
    { "product_id": 10, "quantity": 2, "price": 500.00, "total_amount": 1000.00 },
    { "id": 3, "quantity": 5 },
    { "id": 7, "_delete": true }
  ]
}
```

### Pivot / Many-to-Many (BelongsToMany pattern)

Array with optional `pivot` key for extra junction columns:

```json
{
  "name": "Admin Role",
  "permissions": [
    1,
    { "id": 2 },
    { "id": 3, "pivot": { "is_primary": true } },
    { "id": 4, "_delete": true }
  ]
}
```

### BelongsTo (FK in root)

Just set the foreign key column directly — no nested object:

```json
{ "customer_id": 42, "class_id": 1, "term_id": 3 }
```

### Relationship Write Summary

| Pattern | Payload | Remove |
|---------|---------|--------|
| FK / BelongsTo | `"fk_col": id` in root body | — |
| HasMany / line items | `"rel": [id, {id,...}, {...}]` | `{id, "_delete": true}` |
| HasOne | `"rel": {fields}` | `{"_delete": true}` |
| BelongsToMany | `"rel": [id, {id, pivot:{}}]` | `{id, "_delete": true}` |
| MorphMany | `"rel": [{data}]` | `{id, "_delete": true}` |

---

## Upsert

`match_on` is a **query parameter**, not a body field.

```
POST /api/v2/{table}/upsert?match_on={column}
POST /api/v2/{table}/upsert?match_on={col1},{col2}
Body: same as create payload
```

Match by existing column value → update. No match → create.

Example:
```
POST /api/v2/products/upsert?match_on=ref_number
Body: { "ref_number": "PROD-001", "name": "Widget", "price": 9.99 }
```

---

## Bulk Upsert

All bulk operations go through a single `/bulk/upsert` endpoint.
Wrap records in a `data` array.

```
POST /api/v2/{table}/bulk/upsert?match_on={col}
Content-Type: application/json

Body:
{
  "data": [
    { "ref_number": "INV-001", "customer_id": 1, "total_amount": 500 },
    { "ref_number": "INV-002", "customer_id": 2, "total_amount": 750 }
  ]
}
```

Without `match_on`, all records are created. With `match_on`, existing records are updated.

> Bulk operations run lifecycle hooks per record but skip row-level validators.

---

## Response Envelope

Every response — success or error — uses the same wrapper.

### Success (list)

```json
{
  "success": true,
  "error_code": 0,
  "data": [ {...}, {...} ],
  "meta": {
    "request_id": "uuid",
    "page": 1,
    "per_page": 25,
    "total": 142
  }
}
```

### Success (cursor-based pagination)

```json
{
  "success": true,
  "error_code": 0,
  "data": [ {...}, {...} ],
  "meta": {
    "request_id": "uuid",
    "next_cursor": 10050,
    "prev_cursor": 9950,
    "per_page": 25
  }
}
```

### Success (single record)

```json
{
  "success": true,
  "error_code": 0,
  "data": { "id": 5, "name": "...", ... },
  "meta": { "request_id": "uuid" }
}
```

### Error

```json
{
  "success": false,
  "error_code": 10004,
  "message": "Validation failed",
  "errors": {
    "email": ["The email field is required."],
    "total_amount": ["Must be a number."]
  },
  "meta": { "request_id": "uuid" }
}
```

Important: key is `error_code` (snake_case). Pagination meta key is `page`, not `current_page`.

### Error Code Reference

| error_code | Meaning | HTTP |
|------------|---------|------|
| 0 | Success | 200 |
| 10000 | General error | 500 |
| 10001 | Invalid company/tenant ID | 400 |
| 10002 | Invalid access | 401 |
| 10003 | Invalid token | 401 |
| 10004 | Validation failed | 422 |
| 10005 | Invalid resource | 404 |
| 10006 | Invalid permission | 403 |
| 10007 | Invalid credential | 401 |
| 10008 | Permission denied | 403 |
| 10009 | Resource not found | 404 |
| 10010 | Internal server error | 500 |
| 10011 | Unknown error | 500 |
| 10012 | Company/tenant not found | 404 |
| 10013 | Company/tenant disabled | 403 |
| 10014 | No company/tenant permission | 403 |
| 10015 | Token expired | 401 |

---

## Real Schema Reference (from OpenAPI)

Use these as guides when building payloads. Always verify the latest schema from the OpenAPI spec.

### Products (writable fields)

```
Required: []
Fields: name, description, price(decimal), cost(decimal), unit, sku, barcode,
        is_active, product_category_id(int64), warehouse_id(int64), company_id(int64)
```

### Invoices (writable fields)

```
Required: []
Fields: customer_id(int64), class_id(int64), term_id(int64), project_id(int64),
        ref_number, date, due_date, email, ship_addr, bill_addr,
        total_amount(decimal), discount(decimal), tax_rate(decimal), tax_amount(decimal),
        exchange_rate(decimal), private_note, management_note, status
```

### Customers (writable fields)

```
Required: []
Fields: display_name, given_name, family_name, company_name, primary_phone,
        primary_email_addr, mobile, active, notes, vat_in, currency_ref,
        bill_addr, ship_addr
```

### Employees (writable fields)

```
Required: []
Fields: first_name, last_name, gender, date_of_birth(date), join_date(date),
        phone, email, address, designation_id(int64), department_id(int64),
        employee_group_id(int64), employee_shift_id(int64), status, note
```

### Banks (writable fields)

```
Required: [name, account_number]
Fields: name, account_number, bank_name, bank_address, description, is_default
```

---

## Reading the OpenAPI Spec — Quick Guide

When working on a new feature, extract what you need from the spec:

```
1. Table name       paths key → strip /api/v2/ and /{id} → the table slug
2. Schema fields    components.schemas.{Table}Write → properties list + required list
3. Relationships    info.description → embedded relationship examples for this table
4. Custom paths     paths → look for non-standard endpoints on the table (not /{id}, /upsert, /bulk/upsert)
5. Auth required    paths.{path}.{method}.security → if empty [], no auth needed
6. x-company-id     paths.{path}.{method}.parameters → look for x-company-id header param
```

Always trust the spec over any cached knowledge. The spec reflects the live server.
