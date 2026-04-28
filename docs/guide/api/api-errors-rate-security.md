---
title: "Error Responses, Rate Limiting, and Security"
description: "Error response shapes, rate limiting configuration, authentication, authorization, and data protection best practices."
keywords:
  - error responses
  - rate limiting
  - authentication
  - authorization
  - security best practices
---

### Error Responses

#### Standard Success Format

```json
{
  "success": true,
  "error_code": 0,
  "data": {},
  "meta": {
    "request_id": "req_abc123def456"
  }
}
```

#### Standard Error Format

```json
{
  "success": false,
  "error_code": 1000,
  "message": "Validation failed",
  "errors": {
    "email": ["The email field is required."],
    "price": ["The price must be a number."]
  },
  "meta": {
    "request_id": "req_abc123def456"
  }
}
```

`error_code` is a stable, machine-friendly code that complements the HTTP status:

- On success responses it is always the success code.
- On error responses it is derived from the HTTP status (unauthorized, forbidden, not found, validation, server error, etc.), unless a downstream handler explicitly sets `error_code` in its JSON body, in which case that value is preserved.

#### Error Codes

The package uses a fixed set of numeric `error_code` values to make client-side handling and analytics easier. These codes are stable across versions and map to logical error categories:

- `0` – **SUCCESS**: Request completed successfully.
- `10000` – **GENERAL_ERROR**: Generic error when no more specific category applies.
- `10001` – **INVALID_TENANT_ID**: Tenant identifier is missing, malformed, or does not match the current context.
- `10002` – **INVALID_ACCESS**: Unauthorized access (typically HTTP 401) – missing or invalid authentication for the requested resource.
- `10003` – **INVALID_TOKEN**: Authentication token is invalid (bad signature, malformed, or otherwise unusable).
- `10004` – **INVALID_REQUEST**: Request payload or query parameters are invalid (commonly used for validation errors / HTTP 422).
- `10005` – **INVALID_RESOURCE**: Reference to an invalid resource (e.g. invalid foreign key or unsupported table/endpoint).
- `10006` – **INVALID_PERMISSION**: Permission configuration is invalid or inconsistent.
- `10007` – **INVALID_CREDENTIAL**: User credentials are incorrect (login/auth failures).
- `10008` – **PERMISSION_DENIED**: Authenticated user is forbidden from performing this action (typically HTTP 403).
- `10009` – **RESOURCE_NOT_FOUND**: Requested resource cannot be found (table, record, or function – typically HTTP 404).
- `10010` – **INTERNAL_SERVER_ERROR**: Unhandled server-side error (HTTP 500).
- `10011` – **UNKNOWN_ERROR**: Error that cannot be mapped to a known category.
- `10012` – **TENANT_NOT_FOUND**: Tenant does not exist or is not registered.
- `10013` – **TENANT_DISABLED**: Tenant exists but is disabled / suspended.
- `10014` – **NO_TENANT_PMS_ACCESS**: Current user/application has no PMS access for this tenant.
- `10015` – **TOKEN_EXPIRED**: Authentication token is valid but expired.

#### HTTP Status Codes

- `200` - Success
- `201` - Created
- `204` - Deleted (no content)
- `400` - Bad Request
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Not Found
- `422` - Validation Error
- `429` - Rate Limited (throttle middleware)
- `500` - Server Error

### Rate Limiting

Different endpoints have different rate limits:

- **API Reads** (`throttle:api-reads`) - GET operations
- **API Writes** (`throttle:api-writes`) - POST, PUT, PATCH, DELETE operations
- **API Functions** (`throttle:api-functions`) - Custom function calls

Rate limits are configurable in your Laravel application's rate limiting configuration.

#### Per-Table Rate Limits

For dynamic CRUD writes, you can specify custom rate limits on a per-table basis directly in `config/record.php`. This allows you to define stricter or more lenient limits depending on the table.

```php
'rate_limits' => [
    'users' => [
        'create' => ['limit' => 50, 'decay_minutes' => 1],
        'update' => ['limit' => 100, 'decay_minutes' => 1],
    ],
    'invoices' => [
        'create' => ['limit' => 10, 'decay_minutes' => 1],
    ],
],
```

These limits override the global API write limits for the specified table operations.

### Security Considerations

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

