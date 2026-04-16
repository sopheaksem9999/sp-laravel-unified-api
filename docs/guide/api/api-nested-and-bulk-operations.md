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

### Bulk Upsert

```http
POST /{api_prefix}/{table}/bulk/upsert
```

Bulk create or update records based on matching columns.

#### Query Parameters

- `match_on` (string, required) - Comma-separated list of columns to use for matching records.
  - Example: `?match_on=sku`

#### Request Body

JSON array of objects.

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
