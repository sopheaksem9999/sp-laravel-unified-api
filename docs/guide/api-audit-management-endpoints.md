---
title: "Audit Management Endpoints"
description: "Audit logs, statistics, field timeline, field statistics, create audit log, and cleanup endpoints."
keywords:
  - audit endpoints
  - audit logs
  - audit statistics
  - field timeline
  - cleanup audit logs
---

### Audit Management

#### Audit Formatting

Audit title, subject, and recap labels are configurable via `config/audit.php`:

- `audit.subject_fields`: Ordered list of fields used as the subject (empty list yields blank subject).
- `audit.entity_labels`: Per-entity label overrides (falls back to auto-generated labels).
- `audit.recap_entities`: Entities that use the detailed recap formatter.
- `audit.main_field_labels`: Field label map used by recap output.
- `audit.recap_max_fields`: Limits generic recap length and appends “and N more”.
- `audit.log_relationships`: Includes relationship snapshots in audit data when enabled.

### Get Audit Logs

```http
GET /{api_prefix}/audit_logs
```

Retrieve audit logs using the standard dynamic CRUD API. You can use standard filters.

#### Query Parameters

- `filter[entity_type]` (string, optional) - Filter by entity type (table name, e.g. `invoices`)
- `filter[entity_id]` (integer, optional) - Filter by specific entity ID
- `filter[event]` (string, optional) - Filter by event type
- `filter[user_id]` (integer, optional) - Filter by user ID
- `per_page` (integer) - Max results (default: 15)

#### Example Request

```http
GET /api/v1/audit_logs?filter[entity_type]=invoices&filter[entity_id]=123&per_page=20
Authorization: Bearer {access_token}
```

#### Response Format

```json
{
  "success": true,
  "error_code": 0,
  "data": [
    {
      "id": 1001,
      "entity_type": "invoices",
      "entity_id": 123,
      "event": "updated",
      "old_data": "{\\n  \\\"id\\\": 123,\\n  \\\"status\\\": \\\"draft\\\"\\n}",
      "new_data": "{\\n  \\\"id\\\": 123,\\n  \\\"status\\\": \\\"sent\\\"\\n}",
      "subject": "INV-001",
      "recap": "",
      "user_id": 5,
      "entity_name": "invoices",
      "metadata": "{\\n  \\\"change_summary\\\": { ... },\\n  \\\"field_changes\\\": { ... }\\n}",
      "created_at": "2024-01-15T11:30:00Z"
    }
  ]
}
```

**Note:** `sp_audit_logs` table does **not** expose standard Create, Update, or Delete API endpoints. Audit logs are written strictly by the internal logic events configured on your models. Manual insertion or modification via the API is forbidden to preserve security and data integrity.

### Get Audit Statistics

```http
GET /{api_prefix}/audit_logs/rpc/stats
```

Get audit statistics and metrics.

#### Query Parameters

- `entity_type` (string) - Filter by entity type (table name)
- `entity_id` (integer) - Filter by specific entity ID
- `start_date` (date: Y-m-d) - Filter from date
- `end_date` (date: Y-m-d) - Filter to date (must be >= start_date)
- `event` (string) - Filter by event type (`created`, `updated`, `deleted`, `login`, `logout`, `failed_login`)

#### Response Format

```json
{
  "success": true,
  "error_code": 0,
  "data": {
    "total_logs": 45,
    "actions_breakdown": {
      "created": 10,
      "updated": 30,
      "deleted": 5
    },
    "top_users": [{ "user_name": "John Admin", "count": 20 }],
    "entity_types": {
      "invoices": 45
    }
  }
}
```

### Get Field Timeline

```http
GET /{api_prefix}/audit_logs/rpc/field-timeline/{entityType}/{entityId}/{field}
```

Get timeline of changes for a specific field.

#### Path Parameters (Required)

- `entityType` (string) - Entity type (e.g., `invoices`)
- `entityId` (integer) - Entity ID
- `field` (string) - Field name (e.g., `status`)

#### Optional Parameters

- `limit` (integer) - Max results (default: 50, max: 50)

#### Response Format

```json
{
  "success": true,
  "error_code": 0,
  "data": [
    {
      "id": 1001,
      "changed_at": "2024-01-15T11:30:00Z",
      "old_value": "draft",
      "new_value": "sent",
      "change_type": "updated",
      "data_type": "string",
      "user_name": "John Admin",
      "event": "updated"
    },
    {
      "id": 990,
      "changed_at": "2024-01-10T09:15:00Z",
      "old_value": null,
      "new_value": "draft",
      "change_type": "created",
      "data_type": "string",
      "user_name": "Jane User",
      "event": "created"
    }
  ]
}
```

### Get Field Statistics

```http
GET /{api_prefix}/audit_logs/rpc/field-stats/{entityType}/{entityId}/{field}
```

Get statistics for a specific field across an entity.

#### Path Parameters (Required)

- `entityType` (string) - Entity type
- `entityId` (integer) - Entity ID
- `field` (string) - Field name

#### Response Format

```json
{
  "success": true,
  "error_code": 0,
  "data": {
    "total_changes": 150,
    "first_changed_at": "2024-01-01T08:00:00Z",
    "last_changed_at": "2024-01-15T11:30:00Z",
    "changes_by_user": {
      "John Admin": 80,
      "Jane User": 70
    },
    "field_name": "status"
  }
}
```

### Get Specific Audit Log

```http
GET /{api_prefix}/audit_logs/{id}
```

Retrieve a specific audit log by ID. This uses the standard read endpoint since read access is allowed (`canRead: true`).

