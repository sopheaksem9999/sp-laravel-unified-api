---
title: "Record Middleware Map (Action Routing and Overrides)"
description: "Focused guide for record.middleware_map with merge order, action groups, table overrides, and function-level middleware behavior."
keywords:
  - record middleware map
  - middleware_map
  - record.route.middleware
  - table middleware override
  - global function middleware
  - table function middleware
  - action group read write function
---

# Record Middleware Map

This guide explains how `record.middleware_map` resolves middleware stacks for CRUD and function endpoints.

## Where to Configure

Configure in `config/record.php`:

```php
'middleware_map' => [
    'default' => [
        '*' => [],
        'read' => [],
        'write' => ['auth:sanctum'],
        'function' => ['auth:sanctum'],
    ],
    'tables' => [
        // table-specific overrides
    ],
],
```

This map is used by `record.route.middleware:{action}`.

## Resolution Order (Priority)

For each request, middleware is merged in this exact order:

1. `default['*']`
2. `default[{group}]`
3. `default[{action}]`
4. `tables[{table}]['*']`
5. `tables[{table}][{group}]`
6. `tables[{table}][{action}]`

Result is normalized, duplicates are removed, and invalid/self-recursive route middleware entries are skipped.

## Action Groups

Group mapping used by runtime:

- `read`: `list`, `show`
- `write`: `create`, `update`, `delete`, `restore`, `force_delete`, `upsert`, `bulk`, `bulk_create`, `bulk_update`, `bulk_delete`, `bulk_upsert`
- `function`: `table_function`, `global_function`

## Example: Public Read, Auth Write, Subscribed Orders

```php
'middleware_map' => [
    'default' => [
        '*' => [],
        'read' => [],
        'write' => ['auth:sanctum'],
        'function' => ['auth:sanctum'],
    ],
    'tables' => [
        'orders' => [
            'write' => ['auth:sanctum', 'subscribed'],
            'table_function' => ['auth:sanctum', 'subscribed'],
        ],
    ],
],
```

## Function-Level Middleware Override

For `table_function` and `global_function`, `RecordFunctionType::middleware` can override the map:

- If `middleware` is **non-null**, it replaces `middleware_map` entirely for that function.
- If `middleware` is `null`, normal `middleware_map` resolution is used.
- If `middleware` is `[]`, no middleware is applied for that function.
- Plain-array function configs do not support this override path.

Example:

```php
'global_functions' => [
    'health/check' => new RecordFunctionType(
        httpMethod: ['GET'],
        class: \App\Api\Functions\HealthCheckFunction::class,
        functionName: 'handle',
        middleware: ['auth:sanctum', 'subscribed'],
    ),
],
```

## Quick Troubleshooting

- Middleware not applied: verify action key (`write` vs `create` vs `table_function`).
- Table override not applied: verify route `{table}` matches `tables` key exactly.
- Function override not applied: ensure function config resolves to `RecordFunctionType` (or class-based function), not plain array.
