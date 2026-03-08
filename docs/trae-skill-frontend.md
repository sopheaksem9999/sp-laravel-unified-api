---
name: sp-laravel-api-frontend
description: Use when building a frontend that calls a backend powered by sopheak/sp-laravel-api — discovering available endpoints from the OpenAPI spec, constructing correct CRUD requests, custom function calls, filters, relationship embedding, bulk upsert, and handling API responses.
---

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

### Step 4 — Build the payload

Look up `components.schemas.{Resource}Write` in the OpenAPI spec.

```
required fields → must be present in POST / PUT
optional fields → include only when needed
system fields   → never send: id, created_at, updated_at, deleted_at, company_id
```

### Step 5 — Handle the response

```
success: true  → read data, read meta for pagination
success: false → read error_code, read message and errors
```

---

## Authentication Endpoints

```
POST /api/v2/auth/login              body: { email, password }
POST /api/v2/auth/logout
POST /api/v2/auth/refresh
POST /api/v2/auth/register
POST /api/v2/auth/forgot-password
POST /api/v2/auth/reset-password
GET  /api/v2/auth/whoami
```

---

## Standard Endpoint Pattern

```
GET    /api/v2/{table}                      list with filters, sorting, pagination
GET    /api/v2/{table}/{id}                 get one record
POST   /api/v2/{table}                      create
PUT    /api/v2/{table}/{id}                 full update
DELETE /api/v2/{table}/{id}                 soft delete
POST   /api/v2/{table}/{id}/restore         restore soft-deleted
DELETE /api/v2/{table}/{id}/force           permanent delete
POST   /api/v2/{table}/upsert?match_on=col  upsert single
POST   /api/v2/{table}/bulk/upsert          upsert many
```

> Not every table exposes all endpoints. The OpenAPI `paths` is authoritative.

---

## Custom Function Endpoints

Custom actions have their own paths — look them up in the OpenAPI spec.

```
POST /api/v2/attendances/scan
POST /api/v2/attendances/manual_check_in
POST /api/v2/attendances/manual_check_out
POST /api/v2/employee_rosters/close
GET  /api/v2/employee_rosters/rosters
POST /api/v2/companies/add_user
POST /api/v2/companies/remove_user
GET  /api/v2/generate/no
GET  /api/v2/report/{reportName}
GET  /api/v2/roles/role_permission
```

---

## Query Parameters

### Pagination

**Traditional** (default):
```
?page=1&per_page=25
```

**Cursor-based** (auto for >10,000 rows):
```
?cursor={id}&direction=next
?cursor={id}&direction=prev
```

### Search
```
?s=search term
?search=search term
```

### Column Selection
```
?select=id,name,price
?select=*
```

### Relationship Embedding

Syntax: `?select=*,alias:foreignTable(columns)`

```
?select=*,customer:customers(id,display_name)
?select=*,customer:customers(*),items:invoice_items(*)
?select=*,product:products(id,name,price),warehouse:warehouses(id,name)
?select=*,vendor:vendors(id,display_name),class:classes(id,name)
```

Legacy: `?with=customers,invoice_items`

### Sorting
```
?sortby=date&order=desc
?sortby=total_amount&order=asc
```

### Aggregation
```
?aggregate=count,sum:total_amount,avg:total_amount
?aggregate=sum:total_amount&group_by=status
```

---

## Filter Operators

Format: `?{column}={operator}.{value}` — multiple filters are ANDed.

### Equality
| `eq` | `?status=eq.active` |
| `neq` | `?status=neq.archived` |

### Comparison
| `gt` `gte` `lt` `lte` | `?total_amount=gte.1000` |

### String Pattern
| `like` | `?display_name=like.%John%` |
| `ilike` | `?display_name=ilike.john` |
| `not_like` | `?display_name=not_like.%test%` |
| `contains` | `?display_name=contains.John` |
| `starts_with` | `?ref_number=starts_with.INV` |
| `ends_with` | `?email=ends_with.gmail.com` |
| `regex` | `?ref_number=regex.^INV-[0-9]+` |
| `match` / `imatch` | `?ref_number=imatch.^inv-[0-9]+` |

### List
| `in` | `?status=in.draft,pending,approved` |
| `not_in` | `?status=not_in.cancelled,void` |
| `in` (parenthesized) | `?id=in.(5,6,9)` |

### Range
| `between` | `?total_amount=between.100,10000` |
| `not_between` | `?total_amount=not_between.0,100` |

### Null / Empty
| `is.null` / `is` | `?deleted_at=is.null` |
| `is_not.null` / `is_not` | `?approved_at=is_not.null` |
| `empty` | `?notes=empty` |
| `not_empty` | `?notes=not_empty` |

### Date (date only, ignores time)
| `date_eq` `date_gt` `date_gte` `date_lt` `date_lte` | `?date=date_gte.2024-01-01` |

### Advanced
| Syntax | Example |
|--------|---------|
| `not.<operator>` | `?status=not.eq.archived` |
| `any` / `all` | `?name=like(any).{ACME,SHOP}` |
| grouped `or` | `?vendor_id=eq.27&or=(balance_due.gt.0,id.eq.5)` |
| grouped nested | `?vendor_id=eq.27&and=(or(balance_due.gt.0,id.eq.5),id.neq.2)` |

### PostgreSQL-only operators
| Operator | Example |
|----------|---------|
| `fts` `plfts` `phfts` `wfts` | `?description=fts.invoice` |
| `cs` `cd` `ov` `sl` `sr` `nxl` `nxr` `adj` | `?tags=cs.{a,b}` |

If an operator is not supported by current DB driver, API returns `422`.

### Column Compare
| `compare` | `?updated_at=compare.created_at` |

Full details and complete examples: `docs/api-documentation.md` (`Filter Operators` and `Grouped Logic`).

---

## Create / Update Payload

```
POST /api/v2/{table}
PUT  /api/v2/{table}/{id}
```

- Send only fields from `{Resource}Write` schema in OpenAPI
- Required fields must be present on POST
- Never send: `id`, `created_at`, `updated_at`, `deleted_at`, `company_id`

### Example — Create Invoice with Line Items

```json
POST /api/v2/invoices
Authorization: Bearer {token}
x-company-id: {companyId}

{
  "customer_id": 42,
  "ref_number": "INV-2024-001",
  "date": "2024-01-15",
  "due_date": "2024-02-15",
  "status": "draft",
  "total_amount": 1500.00,
  "items": [
    { "product_id": 10, "name": "Service A", "quantity": 2, "price": 500.00, "total_amount": 1000.00 },
    { "product_id": 11, "name": "Service B", "quantity": 1, "price": 500.00, "total_amount": 500.00 }
  ]
}
```

---

## Relationship Write Payload

| Pattern | Payload | Remove |
|---------|---------|--------|
| FK / BelongsTo | `"fk_col": id` in root | — |
| HasMany / line items | `"rel": [id, {id,...}, {...}]` | `{id, "_delete": true}` |
| HasOne | `"rel": {fields}` | `{"_delete": true}` |
| BelongsToMany | `"rel": [id, {id, pivot:{}}]` | `{id, "_delete": true}` |
| MorphMany | `"rel": [{data}]` | `{id, "_delete": true}` |

---

## Upsert

```
POST /api/v2/{table}/upsert?match_on={column}
Body: same as create payload
```

## Bulk Upsert

```
POST /api/v2/{table}/bulk/upsert?match_on={col}
Body: { "data": [ {record}, {record} ] }
```

---

## Response Envelope

### Success

```json
{
  "success": true,
  "error_code": 0,
  "data": [...] or {...},
  "meta": { "request_id": "uuid", "page": 1, "per_page": 25, "total": 142 }
}
```

### Cursor Pagination Meta

```json
"meta": { "request_id": "uuid", "next_cursor": 10050, "prev_cursor": 9950, "per_page": 25 }
```

### Error

```json
{
  "success": false,
  "error_code": 10004,
  "message": "Validation failed",
  "errors": { "email": ["Required."] },
  "meta": { "request_id": "uuid" }
}
```

### Error Codes

| error_code | Meaning | HTTP |
|------------|---------|------|
| 0 | Success | 200 |
| 10001 | Invalid company ID | 400 |
| 10002 | Invalid access | 401 |
| 10003 | Invalid token | 401 |
| 10004 | Validation failed | 422 |
| 10008 | Permission denied | 403 |
| 10009 | Not found | 404 |
| 10010 | Server error | 500 |
| 10015 | Token expired | 401 |
