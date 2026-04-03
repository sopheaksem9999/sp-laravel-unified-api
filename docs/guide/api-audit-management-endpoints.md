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
GET /{api_prefix}/audit/logs
```

Retrieve audit logs with filtering options.

#### Query Parameters

- `entity_type` (string, required) - Filter by entity type (table name, e.g. `invoices`)
- `entity_id` (integer, required) - Filter by specific entity ID
- `limit` (integer) - Max results (default: 50, max: 100)

#### Example Request

```http
GET /api/v1/audit/logs?entity_type=invoices&entity_id=123&limit=20
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

**Note:** `old_data`, `new_data`, and `metadata` are stored as JSON strings in the database. Clients can `JSON.parse` / `json_decode` them when needed.

### Get Audit Statistics

```http
GET /{api_prefix}/audit/stats
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
GET /{api_prefix}/audit/field-timeline
```

Get timeline of changes for a specific field.

#### Query Parameters (Required)

- `entity_type` (string) - Entity type
- `entity_id` (integer) - Entity ID
- `field` (string) - Field name

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
GET /{api_prefix}/audit/field-stats
```

Get statistics for a specific field across entities.

#### Query Parameters (Required)

- `entity_type` (string) - Entity type
- `entity_id` (integer) - Entity ID
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

### Create Audit Log

```http
POST /{api_prefix}/audit/logs
```

Manually create an audit log entry.

#### Request Body

```json
{
  "event": "updated",
  "entity_type": "invoices",
  "entity_name": "invoices",
  "entity_id": 123,
  "subject": "INV-001",
  "recap": "Status changed via API",
  "metadata": {
    "id": 123,
    "old_data": { "status": "draft" },
    "new_data": { "status": "sent" }
  }
}
```

**Note:** If `metadata.old_data` and `metadata.new_data` are provided, they are used as the explicit old/new snapshots for the audit record. If omitted for updates, the system may infer `old_data` from the most recent `new_data` stored for the same entity.

### Get Specific Audit Log

```http
GET /{api_prefix}/audit/logs/{id}
```

Retrieve a specific audit log by ID.

### Cleanup Audit Logs

```http
DELETE /{api_prefix}/audit/cleanup
```

Clean up old audit logs (admin only - requires `can:manage-audit-logs` permission).

#### Query Parameters

- `days` (integer) - Retention period in days
- `dry_run` (boolean) - Preview what would be deleted

