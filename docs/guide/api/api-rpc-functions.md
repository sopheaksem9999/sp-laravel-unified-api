---
title: "Global RPC Functions"
description: "Public and protected global RPC function endpoints and access behavior."
keywords:
  - global rpc
  - public rpc
  - protected rpc
  - function endpoints
  - rpc authorization
---

### Global RPC Functions

Global RPC functions allow you to define custom endpoints that are not tied to a specific table. These are useful for system-wide operations like authentication, reporting, or utility functions.

Global functions are configured in `config/record.php` under the `global_functions` key.

### Public Global Functions

You can create public endpoints by setting `pmsName` to `null`. These functions can be accessed without authentication.

**Configuration Example:**

```php
'global_functions' => [
    'login' => [
        'httpMethod' => ['POST'],
        'class' => \App\Http\Controllers\AuthController::class,
        'functionName' => 'login',
        'description' => 'User login',
        'pmsName' => null, // Public access
        'payloadSchema' => [ ... ],
        'responseSchema' => [ ... ],
    ],
],
```

**Request:**

```bash
curl -X POST http://localhost:8000/api/v1/rpc/login \
  -H "Content-Type: application/json" \
  -d '{"email": "user@example.com", "password": "password"}'
```

### Protected Global Functions

By providing a `pmsName`, the function requires authentication and the user must have the specified permission(s).

**Configuration Example:**

```php
'global_functions' => [
    'system_stats' => [
        'httpMethod' => ['GET'],
        'class' => \App\Services\StatsService::class,
        'functionName' => 'getSystemStats',
        'description' => 'Get system statistics',
        'pmsName' => 'view_system_stats', // Requires auth & permission
    ],
],
```

**Request:**

```bash
curl -X GET http://localhost:8000/api/v1/rpc/system_stats \
  -H "Authorization: Bearer {token}"
```

