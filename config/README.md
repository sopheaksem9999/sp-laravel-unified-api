# Configuration Directory Guide

This directory contains the configuration files that define the dynamic API structure for the `sp-laravel-api` package.

## Core Architecture Rules for AI Agents

When modifying or extending the API, **you must strictly follow these rules**:

1. **No Hardcoded Routes:** Never add routes to `routes/api.php` for API endpoints. All endpoints must be driven by the configurations in this directory.
2. **Table Functions:** If you need a custom endpoint related to a specific table (e.g. `POST /api/v1/users/rpc/send-email`), you **must** configure it inside the `functions` array of that table's `RecordTableType` definition.
3. **Global Functions:** If you need a global RPC endpoint not tied to a specific table, configure it inside the `global_functions` array in `record.php`.
4. **Blocking Standard CRUD:** If you need to expose custom endpoints on a table but want to block standard CRUD (Create, Update, Delete), **do not** set `canCreate: false`. Setting `canCreate: false` will also block any `POST` requests to your custom functions. Instead, set `canCreate: true` and assign a validator (e.g. `createValidator`) that throws an exception to block the standard CRUD behavior while allowing custom `POST` RPC endpoints.

### Example: Table Function Configuration
```php
'functions' => [
    'custom-action' => new \Sopheak\Core\Types\RecordFunctionType(
        class: \App\Http\Controllers\MyCustomController::class,
        functionName: 'handleCustomAction',
        httpMethod: ['POST'],
        description: 'Executes a custom action on the table'
    ),
],
```