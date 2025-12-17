# API Documentation

This document provides comprehensive documentation for the SP Laravel API package endpoints, request/response formats, and usage examples.

## Base Configuration

### API Prefix
All endpoints are served under a configurable prefix defined in `config/record.php`:

```php
'api_prefix' => env('API_PREFIX', 'api'),
```

**Default**: `/api`  
**Examples**: `/api`, `/api/v1`, `/api/v2`

### Authentication
All endpoints require JWT authentication via the `auth:api` middleware unless explicitly configured as public.

### Middleware Stack
- `api` - API middleware group
- `auth:api` - JWT authentication
- `request.id` - Request ID tracking for audit trails
- Rate limiting with different throttles for different operation types

### Table-Level Validation
Each table configured in `config/record.php` (or in per-table files under `config/record/tables`) can define event-specific validators using the `RecordTableType` configuration:

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Sopheak\Core\Types\RecordTableType;

return [
    'invoices' => new RecordTableType(
        pms_name: 'invoice',
        createValidator: function (Request $request, ?int $id = null): ValidatorContract {
            return Validator::make($request->all(), [
                'invoice_number' => 'required|string|max:50',
                'customer_id' => 'required|integer',
                'total' => 'required|numeric|min:0',
            ]);
        },
        updateValidator: function (Request $request, ?int $id = null): ValidatorContract {
            return Validator::make($request->all(), [
                'status' => 'sometimes|required|in:draft,pending,paid,cancelled',
            ]);
        },
        deleteValidator: function (Request $request, ?int $id = null): ValidatorContract {
            return Validator::make(['id' => $id], [
                'id' => 'required|integer',
            ]);
        },
    ),
];
```

- `createValidator` runs before `POST /{api_prefix}/{table}`.
- `updateValidator` runs before `PUT`/`PATCH /{api_prefix}/{table}/{id}`.
- `deleteValidator` runs before `DELETE /{api_prefix}/{table}/{id}`.

If a validator fails, the API returns a `422 Validation Error` with the standard error format described in the **Error Responses** section.

### Table-Level Triggers
In addition to validators, you can configure lifecycle triggers per table using `RecordTableTriggerType`. Triggers allow you to run custom code before and after core CRUD operations.

```php
use Illuminate\Http\Request;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableTriggerType;

return [
    'users' => new RecordTableType(
        pms_name: 'user',
        public: new RecordTablePublic(
            read: false,
            write: false,
        ),
        beforeRead: new RecordTableTriggerType(
            class: \App\Record\Triggers\UserTriggers::class,
            function_method: 'beforeRead',
        ),
        afterRead: new RecordTableTriggerType(
            class: \App\Record\Triggers\UserTriggers::class,
            function_method: 'afterRead',
        ),
        beforeCreate: new RecordTableTriggerType(
            class: \App\Record\Triggers\UserTriggers::class,
            function_method: 'beforeCreate',
        ),
        afterCreate: new RecordTableTriggerType(
            class: \App\Record\Triggers\UserTriggers::class,
            function_method: 'afterCreate',
        ),
        beforeUpdate: new RecordTableTriggerType(
            class: \App\Record\Triggers\UserTriggers::class,
            function_method: 'beforeUpdate',
        ),
        afterUpdate: new RecordTableTriggerType(
            class: \App\Record\Triggers\UserTriggers::class,
            function_method: 'afterUpdate',
        ),
        beforeDelete: new RecordTableTriggerType(
            class: \App\Record\Triggers\UserTriggers::class,
            function_method: 'beforeDelete',
        ),
        afterDelete: new RecordTableTriggerType(
            class: \App\Record\Triggers\UserTriggers::class,
            function_method: 'afterDelete',
        ),
    ),
];
```

Each trigger method is called with the following signature:

```php
public static function someTrigger(Request $request, string $table, array $context): void
```

The `$context` payload depends on the event:

- `beforeRead` on list (`GET /{prefix}/{table}`): `['type' => 'index', 'filters' => [...], 'includes' => [...], 'page' => int, 'per_page' => ?int, 'limit' => int, 'tenant_id' => mixed]`
- `afterRead` on list: `['type' => 'index', 'filters' => [...], 'data' => [...], 'meta' => [...], 'tenant_id' => mixed]`
- `beforeRead` on show (`GET /{prefix}/{table}/{id}`): `['type' => 'show', 'id' => mixed]`
- `afterRead` on show: `['type' => 'show', 'id' => mixed, 'record' => object|array]`
- `beforeCreate` (`POST /{prefix}/{table}`): `['payload' => [...], 'tenant_id' => mixed]`
- `afterCreate`: `['id' => mixed, 'payload' => [...], 'tenant_id' => mixed]`
- `beforeUpdate` (`PUT|PATCH /{prefix}/{table}/{id}`): `['id' => mixed, 'payload' => [...], 'tenant_id' => mixed]`
- `afterUpdate`: `['id' => mixed, 'payload' => [...], 'tenant_id' => mixed, 'updated' => int]`
- `beforeDelete` (`DELETE /{prefix}/{table}/{id}`): `['id' => mixed, 'tenant_id' => mixed]`
- `afterDelete`: `['id' => mixed, 'tenant_id' => mixed, 'affected' => int, 'soft_deleted' => bool]`

Trigger handlers are best-effort: if the configured class or method does not exist, or if the handler throws an exception, the error is logged and the main API operation still completes. Use validators when you need to block operations.

## Standard CRUD Operations

### List Records
```http
GET /{api_prefix}/{table}
```

Retrieve a paginated list of records with filtering, sorting, and relationship loading.

#### Query Parameters

**Pagination**
- `per_page` (integer, max: 100) - Items per page
- `page` (integer) - Page number for offset pagination
- `cursor` (string) - Cursor value for cursor pagination
- `direction` (string: `next`|`prev`) - Cursor direction

**Filtering**
- `search` (string) - Full-text search across searchable fields
- `{field}` (mixed) - Exact match filter
- `{field}_like` (string) - LIKE search with wildcards
- `{field}_in` (string) - Comma-separated values for IN clause
- `{field}_between` (string) - Comma-separated min,max for BETWEEN
- `{field}_null` (boolean) - Filter for NULL/NOT NULL values
- `{field}_gt` (mixed) - Greater than filter
- `{field}_gte` (mixed) - Greater than or equal filter
- `{field}_lt` (mixed) - Less than filter
- `{field}_lte` (mixed) - Less than or equal filter

**Selection & Relationships**
- `select` (string) - Comma-separated field list
- `with` (string) - Comma-separated relationship list
- `withCount` (string) - Comma-separated relationship count list

**Sorting**
- `sortby` (string) - Field to sort by
- `order` (string: `asc`|`desc`) - Sort direction

**Advanced Options**
- `trashed` (string: `with`|`only`) - Include/only soft-deleted records
- `distinct` (boolean) - Remove duplicate results
- `limit` (integer, max: 1000) - Limit results (alternative to pagination)

#### Example Request
```http
GET /api/invoices?per_page=25&sortby=created_at&order=desc&with=customer,items&status=pending&total_gte=100
Authorization: Bearer {jwt_token}
```

#### Response Format
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "invoice_number": "INV-001",
      "total": 150.00,
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
          "price": 75.00
        }
      ]
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 25,
    "total": 150,
    "last_page": 6,
    "from": 1,
    "to": 25
  },
  "links": {
    "first": "/api/invoices?page=1",
    "last": "/api/invoices?page=6",
    "prev": null,
    "next": "/api/invoices?page=2"
  },
  "request_id": "req_abc123def456"
}
```

### Get Single Record
```http
GET /{api_prefix}/{table}/{id}
```

Retrieve a single record by its primary key.

#### Query Parameters
- `with` (string) - Comma-separated relationship list
- `withCount` (string) - Comma-separated relationship count list
- `select` (string) - Comma-separated field list
- `trashed` (string: `with`) - Include if soft-deleted

#### Example Request
```http
GET /api/invoices/123?with=customer,items,payments
Authorization: Bearer {jwt_token}
```

#### Response Format
```json
{
  "success": true,
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
  "request_id": "req_abc123def456"
}
```

### Create Record
```http
POST /{api_prefix}/{table}
```

Create a new record.

If a `createValidator` is defined for the target table in `config/record.php`, the request body is validated using that validator before any database changes. On validation failure, the endpoint returns `422` with detailed error messages.

#### Request Body
JSON object with field values:

```json
{
  "invoice_number": "INV-124",
  "customer_id": 5,
  "total": 300.00,
  "status": "draft",
  "items": [
    {
      "description": "Product B",
      "quantity": 3,
      "price": 100.00
    }
  ]
}
```

#### Response Format
```json
{
  "success": true,
  "data": {
    "id": 124,
    "invoice_number": "INV-124",
    "customer_id": 5,
    "total": 300.00,
    "status": "draft",
    "created_at": "2024-01-15T11:00:00Z",
    "updated_at": "2024-01-15T11:00:00Z"
  },
  "message": "Record created successfully",
  "request_id": "req_abc123def456"
}
```

### Update Record
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
  "total": 275.00
}
```

#### Response Format
```json
{
  "success": true,
  "data": {
    "id": 124,
    "invoice_number": "INV-124",
    "status": "sent",
    "total": 275.00,
    "updated_at": "2024-01-15T11:30:00Z"
  },
  "message": "Record updated successfully",
  "request_id": "req_abc123def456"
}
```

### Delete Record
```http
DELETE /{api_prefix}/{table}/{id}
```

Delete a record (soft delete if enabled, otherwise hard delete).

If a `deleteValidator` is defined for the target table, the request is validated (typically against the ID and context) before the record is deleted. Validation failures return `422` with error details.

#### Response Format
```json
{
  "success": true,
  "message": "Record deleted successfully",
  "request_id": "req_abc123def456"
}
```

### Restore Record
```http
POST /{api_prefix}/{table}/{id}/restore
```

Restore a soft-deleted record (only available for tables with soft deletes enabled).

#### Response Format
```json
{
  "success": true,
  "data": {
    "id": 124,
    "deleted_at": null,
    "updated_at": "2024-01-15T12:00:00Z"
  },
  "message": "Record restored successfully",
  "request_id": "req_abc123def456"
}
```

### Force Delete Record
```http
DELETE /{api_prefix}/{table}/{id}/force
```

Permanently delete a record (bypasses soft delete).

#### Response Format
```json
{
  "success": true,
  "message": "Record permanently deleted",
  "request_id": "req_abc123def456"
}
```

## Bulk Operations

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
      "price": 100.00
    },
    {
      "name": "Product B", 
      "price": 150.00
    }
  ]
}
```

#### Response Format
```json
{
  "success": true,
  "data": {
    "created": 2,
    "failed": 0,
    "records": [
      {
        "id": 10,
        "name": "Product A",
        "price": 100.00
      },
      {
        "id": 11,
        "name": "Product B",
        "price": 150.00
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
      "price": 110.00
    },
    {
      "id": 11,
      "price": 160.00
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

## Audit Management

### Get Audit Logs
```http
GET /{api_prefix}/audit/logs
```

Retrieve audit logs with filtering options.

#### Query Parameters
- `entity_type` (string) - Filter by entity type (table name, e.g. `invoices`)
- `entity_id` (integer) - Filter by specific entity ID
- `event` (string) - Filter by event type (created, updated, deleted, etc.)
- `user_id` (integer) - Filter by user who performed the action
- `date_from` (date: Y-m-d) - Filter from date
- `date_to` (date: Y-m-d) - Filter to date
- `per_page` (integer) - Items per page
- `page` (integer) - Page number

#### Example Request
```http
GET /api/audit/logs?entity_type=invoices&entity_id=123&event=updated&per_page=20
Authorization: Bearer {jwt_token}
```

#### Response Format
```json
{
  "success": true,
  "data": [
    {
      "id": 1001,
      "entity_type": "invoices",
      "entity_id": 123,
      "event": "updated",
      "old_data": {
        "status": "draft",
        "total": 250.00
      },
      "new_data": {
        "status": "sent",
        "total": 275.00
      },
      "user_id": 5,
      "user_name": "John Admin",
      "ip_address": "192.168.1.100",
      "user_agent": "Mozilla/5.0...",
      "created_at": "2024-01-15T11:30:00Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 45
  }
}
```

### Get Audit Statistics
```http
GET /{api_prefix}/audit/stats
```

Get audit statistics and metrics.

#### Query Parameters
- `entity_type` (string) - Filter by entity type
- `entity_id` (integer) - Filter by specific entity ID
- `date_from` (date) - Filter from date
- `date_to` (date) - Filter to date
- `group_by` (string: `event`|`user`|`date`|`entity_type`) - Group results by

#### Response Format
```json
{
  "success": true,
  "data": {
    "total_events": 1250,
    "events_by_type": {
      "created": 450,
      "updated": 650,
      "deleted": 150
    },
    "events_by_user": {
      "5": 800,
      "10": 300,
      "15": 150
    },
    "date_range": {
      "from": "2024-01-01",
      "to": "2024-01-15"
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
- `date_from` (date) - Filter from date
- `date_to` (date) - Filter to date

#### Response Format
```json
{
  "success": true,
  "data": [
    {
      "date": "2024-01-15T11:30:00Z",
      "old_value": "draft",
      "new_value": "sent",
      "user_id": 5,
      "user_name": "John Admin"
    },
    {
      "date": "2024-01-10T09:15:00Z",
      "old_value": null,
      "new_value": "draft",
      "user_id": 3,
      "user_name": "Jane User"
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
- `field` (string) - Field name

#### Response Format
```json
{
  "success": true,
  "data": {
    "field": "status",
    "entity_type": "invoices",
    "total_changes": 150,
    "value_distribution": {
      "draft": 45,
      "sent": 60,
      "paid": 30,
      "cancelled": 15
    },
    "most_active_users": [
      {
        "user_id": 5,
        "user_name": "John Admin",
        "changes": 80
      }
    ]
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
  "entity_type": "invoices",
  "entity_id": 123,
  "event": "status_changed",
  "old_data": {
    "status": "draft"
  },
  "new_data": {
    "status": "sent"
  },
  "description": "Status changed via API"
}
```

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

## Custom Functions

### Global Functions
```http
GET|POST|PUT|PATCH|DELETE /{api_prefix}/{functionName}
```

Execute global custom functions defined in `config/record.php`.

#### Examples
```http
# Simple global function
GET /api/system_stats

# Parameterized global function  
POST /api/generate_report
Content-Type: application/json
{
  "report_type": "monthly",
  "date_range": "2024-01"
}
```

### Table Functions
```http
GET|POST|PUT|PATCH|DELETE /{api_prefix}/{table}/rpc/{functionName}
```

Execute table-specific custom functions.

#### Examples
```http
# Simple table function
GET /api/invoices/rpc/calculate_totals

# Parameterized table function
POST /api/users/rpc/send_notification
Content-Type: application/json
{
  "message": "Welcome to our platform!",
  "type": "welcome"
}
```

## Error Responses

### Standard Error Format
```json
{
  "success": false,
  "error": {
    "message": "Validation failed",
    "code": "VALIDATION_ERROR",
    "details": {
      "email": ["The email field is required."],
      "price": ["The price must be a number."]
    }
  },
  "request_id": "req_abc123def456"
}
```

### Common Error Codes
- `VALIDATION_ERROR` - Request validation failed
- `NOT_FOUND` - Resource not found
- `UNAUTHORIZED` - Authentication required
- `FORBIDDEN` - Insufficient permissions
- `RATE_LIMITED` - Too many requests
- `SERVER_ERROR` - Internal server error

### HTTP Status Codes
- `200` - Success
- `201` - Created
- `400` - Bad Request
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Not Found
- `422` - Validation Error
- `429` - Rate Limited
- `500` - Server Error

## Rate Limiting

Different endpoints have different rate limits:

- **API Reads** (`throttle:api-reads`) - GET operations
- **API Writes** (`throttle:api-writes`) - POST, PUT, PATCH, DELETE operations  
- **API Functions** (`throttle:api-functions`) - Custom function calls

Rate limits are configurable in your Laravel application's rate limiting configuration.

## Security Considerations

### Authentication
- All endpoints require valid JWT tokens unless configured as public
- Tokens should be included in the `Authorization: Bearer {token}` header

### Authorization
- Permission-based access control using Spatie Laravel Permission
- Automatic tenant isolation when `tenant_id` column is present
- Special permissions for restricted access patterns

### Data Protection
- Automatic SQL injection prevention
- Input validation and sanitization
- Audit trail for all operations
- Configurable field exclusion for sensitive data

### Best Practices
- Use HTTPS in production
- Implement proper CORS policies
- Monitor rate limits and adjust as needed
- Regularly review audit logs
- Keep JWT secrets secure and rotate them periodically
