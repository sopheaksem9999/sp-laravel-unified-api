---
title: "Nested Relationship Writes and Bulk Operations"
description: "Nested create/update patterns and bulk create/update/delete/upsert endpoint workflows."
keywords:
  - nested create
  - nested update
  - bulk operations
  - bulk upsert
  - bulk validation notes
---

### Routing

Global functions are registered with high priority, so a global function named `login` will take precedence over a table named `login`. However, they are constrained to the configured keys to avoid shadowing valid table routes unnecessarily.

---

### Nested Relationships

You can perform Create and Update operations on a record and its related records in a single request. This is supported for `hasMany` relationships configured in `config/record.php`.

### Nested Create

Create a parent record along with its related child records.

#### Request Body

```json
{
  "customer_name": "Tech Corp",
  "date": "2023-12-23",
  "total": 1500.0,
  "status": "draft",
  "items": [
    {
      "product_name": "Laptop",
      "quantity": 1,
      "price": 1200.0,
      "total": 1200.0
    },
    {
      "product_name": "Mouse",
      "quantity": 2,
      "price": 150.0,
      "total": 300.0
    }
  ]
}
```

#### Example Request

```bash
curl --location 'http://127.0.0.1:8000/api/v1/invoices' \
--header 'Content-Type: application/json' \
--header 'Authorization: Bearer {token}' \
--data '{
    "customer_name": "Tech Corp",
    "date": "2023-12-23",
    "total": 1500.00,
    "status": "draft",
    "items": [
        {
            "product_name": "Laptop",
            "quantity": 1,
            "price": 1200.00,
            "total": 1200.00
        }
    ]
}'
```

### Nested Update

Update a parent record and manage its relationships simultaneously. You can:

- **Update** existing children (provide `id`).
- **Create** new children (omit `id`).
- **Delete** existing children (provide `id` and `_delete: true` or `_destroy: true`).

Children are scoped to what the caller could write on the child table directly:

- Every child create, update or delete needs the permission a direct request on
  the child table needs (by default `create:` / `update:` / `delete:` + its
  `pmsName`, or that action's entry in its `permissions` map; a public child
  table, one without a `pmsName`, or a `super_admin_callback` super admin needs
  none) and its `canCreate` /
  `canUpdate` / `canDelete` flag, exactly as a direct request would. A missing
  permission fails the whole request with `403`, and a disabled flag with
  `422`; nothing is written. Attaching or detaching an existing related record
  needs only the parent's update permission.
  Your own triggers, hooks and record event listeners are trusted app code:
  nested writes they make themselves are not checked against the requesting
  user. The same rule applies to every item of a bulk create or update and to
  the legacy `/bulk` dispatcher. With `?async=true` (or `X-Async-Process`), the
  queued job checks children as the requesting user; a refusal fails the job
  (logged) and rolls back the batch.
- An update or delete only touches a child that belongs to this parent, to the
  caller's tenant (the child table's own tenant column, even when the parent is
  not tenant-scoped), and — under [`viewOwn`](/guide/feature-permission-own-records)
  — to the caller. Any other `id` is silently skipped, exactly as if it did not exist.
- A created child is stamped with the caller's tenant; a `tenant_id` in the payload
  is ignored.
- A write to a tenant-scoped child (`hasTenantId: true`) with no resolvable tenant
  returns `422`, as the child's own endpoint does — even when the parent is not
  tenant-scoped.
- Attaching an existing record by `id` to a many-to-many or has-many-through
  relationship returns `422` (`Related record '…' not found for relationship …`)
  when the caller could not read that record directly.

#### Bare ids in a relationship array

A relationship array normally holds objects, but a many-to-many or
has-many-through array also accepts a bare id, which attaches that record
exactly like `{"id": <id>}`:

```json
{ "tags": [1, 2, {"id": 3, "note": "primary"}, {"id": 5, "_delete": true}] }
```

Links you leave out of the array are kept. Everywhere else a non-object item is
refused instead of being dropped silently:

| Item | Relationship | Result |
|---|---|---|
| `5`, `"5"` | many-to-many, has-many-through | attaches record `5` |
| `5`, `"5"` | has-many, morph-many | `422` — `Relationship 'items' on table 'invoices' expects objects, got scalar 5. Send {"id": ...} to update a child or {...fields} to create one.` |
| `0`, `""`, `"0"`, `null`, `false` | has-many, morph-many, many-to-many, has-many-through | `422` — empty value; nothing is created |

#### Request Body

```json
{
  "total": 1750.0,
  "items": [
    {
      "id": 1,
      "quantity": 2,
      "total": 2400.0
    },
    {
      "id": 2,
      "_delete": true
    },
    {
      "product_name": "Keyboard",
      "quantity": 5,
      "price": 50.0,
      "total": 250.0
    }
  ]
}
```

#### Example Request

```bash
curl --location --request PUT 'http://127.0.0.1:8000/api/v1/invoices/123' \
--header 'Content-Type: application/json' \
--header 'Authorization: Bearer {token}' \
--data '{
    "total": 1750.00,
    "items": [
        {
            "id": 1,
            "quantity": 2,
            "total": 2400.00
        },
        {
            "id": 2,
            "_delete": true
        },
        {
            "product_name": "Keyboard",
            "quantity": 5,
            "price": 50.00,
            "total": 250.00
        }
    ]
}'
```

### Bulk Operations

Bulk operations allow you to perform Create, Update, or Delete actions on multiple records in a single HTTP request. This is significantly more efficient than sending individual requests for large datasets.

> **Opt-in flag:** Bulk routes are registered only when `record.bulk_operations` is `true` (the default). Set `SP_BULK_OPERATIONS=false` in your `.env` to disable all bulk endpoints entirely.

For performance considerations and best practices when using bulk operations, see [Performance](/advanced/performance).

### Triggers & Validation in Bulk Operations

- **Validation**: Table-level validators (`createValidator`, `updateValidator`, `deleteValidator`) are currently **not** automatically applied to bulk operations. You should validate your payload before sending.
- **Triggers**: Table-level triggers (`beforeCreate`, `afterCreate`, `beforeUpdate`, `afterUpdate`, `beforeDelete`, `afterDelete`) **are executed** for each individual item in the bulk batch.
  - This allows you to maintain consistent business logic (e.g., setting default values, syncing with external systems) regardless of whether a record is created individually or in bulk.

### Request Body Shapes

Every bulk endpoint accepts the item list in either shape, so the wrapped body the
examples below use and a bare array are equivalent:

```json
{ "data": [ { "name": "Product A" }, { "name": "Product B" } ] }
```

```json
[ { "name": "Product A" }, { "name": "Product B" } ]
```

Additionally:

- **A single object is one row.** `POST /{table}/bulk/create` with `{"name": "A"}`
  creates one record — you do not have to wrap a single item in an array.
- **`/bulk/delete`** also accepts `{"ids": [10, 11, 12]}`.
- **`/bulk`** (the legacy dispatcher) also accepts `{"items": [...]}`.

> **Envelope keys vs. real columns:** `data`, `items`, and `ids` are only treated as
> envelopes when the key is the body's *only* top-level key, its value is a JSON
> array, and the table declares no column of that name. A table with a real `data`
> column keeps ownership of it, so `{"data": [...]}` there is one row whose `data`
> field is a list — not a wrapper around several rows.

### Legacy Bulk Operation

```http
POST /{api_prefix}/{table}/bulk
```

Auto-detects operation type based on request data structure.

### Bulk Create

```http
POST /{api_prefix}/{table}/bulk/create
```

Create multiple records in a single request.

#### Request Body

```json
{
  "data": [
    {
      "name": "Product A",
      "price": 100.0
    },
    {
      "name": "Product B",
      "price": 150.0
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
    "created": 2,
    "failed": 0,
    "records": [
      {
        "id": 10,
        "name": "Product A",
        "price": 100.0
      },
      {
        "id": 11,
        "name": "Product B",
        "price": 150.0
      }
    ]
  },
  "message": "Bulk create completed: 2 created, 0 failed",
  "request_id": "req_abc123def456"
}
```

### Bulk Update

```http
POST /{api_prefix}/{table}/bulk/update
```

Update multiple records by ID.

#### Request Body

```json
{
  "data": [
    {
      "id": 10,
      "price": 110.0
    },
    {
      "id": 11,
      "price": 160.0
    }
  ]
}
```

An `id` outside the caller's tenant or [`viewOwn`](/guide/feature-permission-own-records) scope is treated as missing: the request fails with `422` (`Record with id '…' not found or no changes detected`) and no item is written.

### Bulk Delete

```http
POST /{api_prefix}/{table}/bulk/delete
```

Delete multiple records by ID.

#### Request Body

```json
{
  "ids": [10, 11, 12]
}
```

An `id` outside the caller's tenant or `viewOwn` scope is skipped like a missing id — it is left out of `data` and `meta.affected`.

### Bulk Upsert

```http
POST /{api_prefix}/{table}/bulk/upsert
```

Bulk create or update records based on matching columns.

Under [`viewOwn`](/guide/feature-permission-own-records), a batch in which any item would overwrite another user's row — through `match_on`, the primary key or any other unique key — is refused with `403` and nothing is written.

#### Query Parameters

- `match_on` (string, required) - Comma-separated list of columns to use for matching records.
  - Example: `?match_on=sku`

#### Request Body

JSON array of objects, or the same list wrapped in `{"data": [...]}` — see
[Request Body Shapes](#request-body-shapes).

```json
[
  {
    "sku": "PROD-001",
    "name": "Wireless Mouse",
    "price": 29.99
  },
  {
    "sku": "PROD-002",
    "name": "Mechanical Keyboard",
    "price": 89.99
  }
]
```

#### Response Format

```json
{
  "success": true,
  "error_code": 0,
  "data": {
    "count": 2
  },
  "meta": {
    "request_id": "req_abc123def456",
    "total": 2
  }
}
```
