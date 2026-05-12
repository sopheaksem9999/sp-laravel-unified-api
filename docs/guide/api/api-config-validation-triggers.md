---
title: "Configuration, Validation, and Triggers"
description: "Base config, API prefix, global RPC, table files, middleware map, context sharing, validators, triggers, and default validation."
keywords:
  - configuration
  - middleware map
  - default validation
  - trigger hooks
  - request context
---

### Base Configuration

### API Prefix

All endpoints are served under a configurable prefix defined in `config/record.php`:

```php
'api_prefix' => 'api/v1',
'rpc_prefix' => 'rpc',
```

**Default**:

- CRUD API: `/api/v1`
- Global RPC: `/api/v1/rpc`

**Examples**: `/api`, `/api/v1`, `/api/v2`

### Global RPC

Global functions can be executed via the configured RPC prefix. Nested function names are supported.

`POST /api/v1/rpc/{functionName}`

Example:

- `POST /api/v1/rpc/auth/login`
- `POST /api/v1/rpc/system/status`

### Table Configuration Files

Record behavior is driven by `RecordTableType` configurations defined in `config/record.php` and (optionally) in per-table files under `config/records/tables`.

- `config/record.php` contains global options and can inline smaller schemas.
- `config/records/tables/{name}.php` can return a single `RecordTableType` or an array of `[table_name => RecordTableType]` for large schemas.

You can scaffold a new per-table configuration file via Artisan:

```bash
php artisan sp-laravel-api:record customers
```

This creates `config/records/tables/customers.php` with a basic `RecordTableType` definition for the `customers` table. After creating the file and the corresponding database table, you can populate the `columns` metadata from the database schema:

```bash
# Create configs for all tables in your database (ignores system/package tables)
php artisan sp-laravel-api:generate-record-tables-from-db

# Sync columns from database into existing config files (ignores system/package tables)
php artisan sp-laravel-api:sync-record-columns --force
```

Record endpoints use table-level access rules from `config/record.php`:

- If a table/action is configured as public (`RecordTablePublic`), the endpoint is accessible without authentication.
- Otherwise, the controller requires an authenticated user from the guard configured in `config/sp-laravel-api.php` (`sp-laravel-api.auth.guard`, default: `api`) and checks permissions.
- Permission checks support a custom authorization handler via `record.authorization`.

Custom authorization handler (`record.authorization`) options:

- `null` (default): use `Gate::forUser($user)->allows($permission)`
- class-string: resolved from container and called as `handle($user, $permission, $table, $action): bool`
- closure/callable: called as `fn($user, string $permission, string $table, string $action): bool`

Example:

```php
// config/record.php
'authorization' => \App\Security\RecordAuthorization::class,
```

```php
<?php

namespace App\Security;

final class RecordAuthorization
{
    public function handle(mixed $user, string $permission, string $table, string $action): bool
    {
        return \Illuminate\Support\Facades\Gate::forUser($user)->allows($permission);
    }
}
```

### Middleware Stack

- `api` - API middleware group
- `request.id` - Request ID tracking for audit trails
- Rate limiting with different throttles for different operation types
- `record.route.middleware:{action}` - Dynamic middleware dispatcher resolved from `config/record.php` `middleware_map`

### Middleware Map (Public / Auth / Auth+Subscription)

Use `middleware_map` in `config/record.php` to apply middleware by endpoint group/action and per table.
For a focused version (merge order, groups, function override), see [Record Middleware Map](/guide/record-middleware-map).

```php
'middleware_map' => [
    'default' => [
        '*' => [],
        'read' => [],
        'write' => ['auth:sanctum'],
        'function' => ['auth:sanctum'],
    ],
    'tables' => [
        'customers' => [
            'read' => [],
        ],
        'orders' => [
            'write' => ['auth:sanctum', 'subscribed'],
            'table_function' => ['auth:sanctum', 'subscribed'],
        ],
    ],
],
```

How this matches common client requirements:

- Public query route: keep `read` empty (or only safe middleware like throttling).
- Auth-only route: use `write => ['auth:sanctum']` or per-action `create`, `update`, `delete`.
- Auth + subscription route: add `subscribed` in table/action stack (e.g. `orders.write`).

### Request Context in Hooks and Custom Audit

Request context is available to hooks and custom audit callback via:
For a focused tenancy setup/runtime guide, see [Record Tenancy](/guide/record-tenancy).

- `$context['request_context']` in trigger/audit callback params
- `request()->attributes->get('record_context')`

Built-in `request_context` payload:

- `tenant_id` (`string|int|null`)
- `tenant_column` (`string`, usually `tenant_id`)
- `tenant_source` (`attribute|header|null`)
- `user` (`array|null`) with keys:
  - `id` (`mixed`)
  - `guard` (`string`)
- `request_id` (`string|null`)
- `table` (`string`)
- `action` (`string|null`)

Source priority behavior (built-in):

- `attribute`: recommended for trusted middleware-populated tenant (`resolved_tenant_id`) or context tenant (`record_context.tenant_id`)
- `header`: fallback to tenant header (`X-Tenant-ID` by default)

This same priority is also used by Eloquent trait filtering (`QueryHelpersTrait::scopeApplyRequestFilters`), so model queries remain aligned with dynamic CRUD tenant behavior.

Tenant filtering in `QueryHelpersTrait` is applied when tenant mode is enabled and tenant column exists by any of:

- model `fillable`
- registered `RecordTableType` columns
- database schema column check

Example middleware to set trusted tenant (`resolved_tenant_id`) and enrich `record_context`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $request->user()?->tenant_id;

        if ($tenantId !== null && $tenantId !== '') {
            $request->attributes->set('resolved_tenant_id', $tenantId);
        }

        $context = $request->attributes->get('record_context', []);
        if (!is_array($context)) {
            $context = [];
        }

        $context['tenant_id'] = $tenantId;
        $context['tenant_source'] = 'attribute';
        $context['client_app'] = 'backoffice';
        $request->attributes->set('record_context', $context);

        return $next($request);
    }
}
```

Then register middleware in `record.middleware_map` for table/action route groups so CRUD/trigger flow can consume the context.

Example trigger (`beforeCreate`) using context for auto value:

```php
public static function beforeCreate(\Illuminate\Http\Request $request, string $table, array $context): array
{
    $ctx = $context['request_context'] ?? $request->attributes->get('record_context', []);
    $tenantId = $ctx['tenant_id'] ?? null;
    $userId = $ctx['user']['id'] ?? null;

    $payload = $request->all();
    $payload['tenant_id'] = $tenantId;
    $payload['created_by'] = $userId;
    $request->replace($payload);

    return [$request, $table, $context];
}
```

### Table-Level Validation

Each table configured in `config/record.php` (or in per-table files under `config/record/tables`) can define event-specific validators using the `RecordTableType` configuration. Validators support:

- Callable arrays (e.g. `[ClassName::class, 'method']`)
- `RecordValidationType` objects
- Arrays of validator configs (multiple validators per event)

```php
use App\Record\Validators\RecordValidator;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Types\RecordValidationType;

return [
    'invoices' => new RecordTableType(
        pmsName: 'invoice',
        createValidator: [
            [RecordValidator::class, 'createInvoice'],
            new RecordValidationType(
                class: RecordValidator::class,
                functionName: 'createInvoice',
            ),
        ],
        updateValidator: [
            new RecordValidationType(
                class: RecordValidator::class,
                functionName: 'updateInvoice',
            ),
        ],
        deleteValidator: [
            [RecordValidator::class, 'deleteInvoice'],
        ],
    ),
];
```

If you run `php artisan config:cache`, avoid closures (including `fn (...) => ...`) in config files. Use callable arrays like `[ClassName::class, 'method']` (or a `'ClassName::method'` callable string).

Example validator class:

```php
<?php

declare(strict_types=1);

namespace App\Record\Validators;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class RecordValidator
{
    public static function createInvoice(Request $request, ?int $id = null): ValidatorContract
    {
        return Validator::make($request->all(), [
            'invoice_number' => 'required|string|max:50',
            'customer_id' => 'required|integer',
            'total' => 'required|numeric|min:0',
        ]);
    }

    public static function updateInvoice(Request $request, ?int $id = null): ValidatorContract
    {
        return Validator::make($request->all(), [
            'status' => 'sometimes|required|in:draft,pending,paid,cancelled',
        ]);
    }

    public static function deleteInvoice(Request $request, ?int $id = null): ValidatorContract
    {
        return Validator::make(['id' => $id], [
            'id' => 'required|integer',
        ]);
    }

    public static function deletePackage(Request $request, ?int $id = null): ValidatorContract
    {
        return Validator::make(['id' => $id], [
            'id' => 'required|integer',
        ]);
    }
}
```

- `createValidator` runs before `POST /{api_prefix}/{table}`.
- `updateValidator` runs before `PUT`/`PATCH /{api_prefix}/{table}/{id}`.
- `deleteValidator` runs before `DELETE /{api_prefix}/{table}/{id}`.

If a validator fails, the API returns a `422 Validation Error` with the standard error format described in the **Error Responses** section.

### Using PHP Attributes for Validators

Instead of passing arrays or closures directly in `config/record.php`, you can use the `#[RecordValidator]` attribute inside your validator or handler classes.

**1. Create the Validator Class:**

```php
namespace App\Record\User;

use Sopheak\Core\Attributes\RecordValidator;

class UserValidators
{
    #[RecordValidator('create')]
    public static function createRules(): array
    {
        return [
            'email' => 'required|email|unique:users',
            'password' => 'required|min:8',
        ];
    }

    #[RecordValidator('update')]
    public static function updateRules(): array
    {
        return [
            'email' => 'sometimes|email',
        ];
    }
}
```

**2. Register the Class in `RecordTableType`:**

You can pass the class name as a string to the validator properties, or include it in the `triggers` array (which scans for both triggers and validators).

```php
use Sopheak\Core\Types\RecordTableType;
use App\Record\User\UserValidators;

return [
    'users' => new RecordTableType(
        // Option A: Explicitly assign the class
        createValidator: UserValidators::class,
        updateValidator: UserValidators::class,
        
        // Option B: Let the package auto-discover it via the triggers array
        // triggers: [UserValidators::class],
    ),
];
```

### Table-Level Triggers

In addition to validators, you can configure lifecycle triggers per table using `RecordTableTriggerType`. Triggers allow you to run custom code before and after core CRUD operations.
For a cleaner lifecycle-only guide, see [Record Hooks](/guide/record-hooks).

```php
use Illuminate\Http\Request;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableTriggerType;

return [
    'users' => new RecordTableType(
        pmsName: 'user',
        public: new RecordTablePublic(
            read: false,
            write: false,
        ),
        beforeRead: [
          [
            new RecordTableTriggerType(
            class: \App\Record\Triggers\UserTriggers::class,
            functionName: 'beforeRead',
          )
          ]
        ],
        afterRead: [
          [
            new RecordTableTriggerType(
            class: \App\Record\Triggers\UserTriggers::class,
            functionName: 'afterRead',
          )
          ]
        ],
        beforeCreate: [
          [
            new RecordTableTriggerType(
            class: \App\Record\Triggers\UserTriggers::class,
            functionName: 'beforeCreate',
          )
          ]
        ],
        afterCreate: [
          [
            new RecordTableTriggerType(
            class: \App\Record\Triggers\UserTriggers::class,
            functionName: 'afterCreate',
          )
          ]
        ],
        beforeUpdate: [
          [
            new RecordTableTriggerType(
            class: \App\Record\Triggers\UserTriggers::class,
            functionName: 'beforeUpdate',
          )
          ]
        ],
        afterUpdate: [
          [
            new RecordTableTriggerType(
              class: \App\Record\Triggers\UserTriggers::class,
              functionName: 'afterUpdate',
          )
          ]
        ],
        beforeDelete: [
          [
            new RecordTableTriggerType(
              class: \App\Record\Triggers\UserTriggers::class,
              functionName: 'beforeDelete',
          )
          ]
        ],
        afterDelete: [
          [
            new RecordTableTriggerType(
              class: \App\Record\Triggers\UserTriggers::class,
              functionName: 'afterDelete',
          )
          ]
        ],
    ),
];
```

### Global Triggers

You can register global triggers that apply to all tables and run in addition to per-table triggers.

```php
use Sopheak\Core\Types\RecordTableTriggerType;

return [
    'global_triggers' => [
        'beforeRead' => new RecordTableTriggerType(
            class: \App\Record\Triggers\GlobalTriggers::class,
            functionName: 'beforeRead'
        ),
        'afterRead' => new RecordTableTriggerType(
            class: \App\Record\Triggers\GlobalTriggers::class,
            functionName: 'afterRead'
        ),
        'beforeCreate' => new RecordTableTriggerType(
            class: \App\Record\Triggers\GlobalTriggers::class,
            functionName: 'beforeCreate'
        ),
        'afterCreate' => new RecordTableTriggerType(
            class: \App\Record\Triggers\GlobalTriggers::class,
            functionName: 'afterCreate'
        ),
        'beforeUpdate' => new RecordTableTriggerType(
            class: \App\Record\Triggers\GlobalTriggers::class,
            functionName: 'beforeUpdate'
        ),
        'afterUpdate' => new RecordTableTriggerType(
            class: \App\Record\Triggers\GlobalTriggers::class,
            functionName: 'afterUpdate'
        ),
        'beforeDelete' => new RecordTableTriggerType(
            class: \App\Record\Triggers\GlobalTriggers::class,
            functionName: 'beforeDelete'
        ),
        'afterDelete' => new RecordTableTriggerType(
            class: \App\Record\Triggers\GlobalTriggers::class,
            functionName: 'afterDelete'
        ),
    ],
];
```

Order:

- `before*` global triggers run before table-level `before*` triggers.
- `after*` global triggers run after table-level `after*` triggers.

### Default Validation (Schema-Based)

You can enable automatic validation rules derived from table columns. This is optional and disabled by default.

```php
return [
    'default_validation' => [
        'enabled' => true,
        'only_when_missing' => true,
        'required' => true,
        'types' => true,
        'unique' => true,
        'foreign_keys' => true,
    ],
];
```

Rules generated:

- **required**: non-nullable columns without defaults (excluding system columns and tenant column)
- **types**: basic mapping (`uuid`, `integer`, `numeric`, `boolean`, `date`, `string`, `array`)
- **unique**: single-column unique indexes
- **foreign_keys**: `exists:{table},{column}` based on DB constraints

Notes:

- Only applies on **create** and **update** endpoints (including bulk create/update).
- When `only_when_missing` is `true`, table validators still take priority.

Each trigger method is called with the following signature:

```php
public static function someTrigger(Request $request, string $table, mixed ...$args): Request|array|null
```

Example trigger class:

```php
<?php

declare(strict_types=1);

namespace App\Record\Triggers;

use Illuminate\Http\Request;

final class UserTriggers
{
    /**
     * beforeRead
     * Context: ['type' => 'index'|'show', 'tenant_id' => mixed, 'id' => mixed (if show)]
     * Use for: Forcing filters (e.g., auto-adding company_id), forcing relationships to load.
     */
    public static function beforeRead(Request $request, string $table, array $context): Request
    {
        // Example: Auto-add a filter for specific tables
        if (in_array($table, ['employees', 'invoices'])) {
            $companyId = auth()->user()->company_id ?? 1;
            $request->query->set('company_id', 'eq.' . $companyId);
        }
        return $request;
    }

    /**
     * afterRead
     * Context: ['type' => 'index'|'show', 'tenant_id' => mixed, 'data' => array, 'meta' => array, 'response' => JsonResponse]
     * Use for: Logging views, dispatching analytics events.
     */
    public static function afterRead(Request $request, string $table, array $context): void
    {
        // Example: Log that a user viewed a record
    }

    /**
     * beforeCreate
     * Context: ['tenant_id' => mixed] (or the item payload in bulk operations)
     * Use for: Setting default values, hashing passwords.
     */
    public static function beforeCreate(Request $request, string $table, array $context): array
    {
        // Example: Auto-assign a default role if not provided
        $payload = $request->all();
        if (empty($payload['role'])) {
            $payload['role'] = 'customer';
        }
        
        // Return array to merge into the request input
        return $payload;
    }

    /**
     * afterCreate
     * Context: ['id' => mixed, 'payload' => array, 'tenant_id' => mixed, 'response' => array]
     * Use for: Sending welcome emails, creating default related records.
     */
    public static function afterCreate(Request $request, string $table, array $context): void
    {
        // Example: Send welcome email after user creation
        $user = $context['record'] ?? null;
        if ($user) {
            // \Mail::to($user->email)->send(new WelcomeEmail($user));
        }
    }

    /**
     * beforeUpdate
     * Context: ['id' => mixed, 'tenant_id' => mixed] (or the item payload in bulk operations)
     * Use for: Preventing updates to protected columns.
     */
    public static function beforeUpdate(Request $request, string $table, array $context): array
    {
        $payload = $request->all();
        unset($payload['is_admin']); // Prevent users from making themselves admin
        return $payload;
    }

    /**
     * afterUpdate
     * Context: ['id' => mixed, 'payload' => array, 'tenant_id' => mixed, 'updated' => int, 'response' => array]
     * Use for: Clearing external caches, sending notifications.
     */
    public static function afterUpdate(Request $request, string $table, array $context): void
    {
        // Example: Clear Redis cache
    }

    /**
     * beforeDelete
     * Context: ['id' => mixed, 'tenant_id' => mixed, 'record' => ?object]
     * Use for: Preventing deletion of critical records.
     */
    public static function beforeDelete(Request $request, string $table, array $context): void
    {
        $record = $context['record'] ?? null;
        if ($record && $record->is_system_default) {
            abort(403, 'Cannot delete system default records.');
        }
    }

    /**
     * **afterDelete**
     * Context: ['id' => mixed, 'tenant_id' => mixed, 'record' => ?object, 'soft_deleted' => bool, 'response' => JsonResponse]
     * Use for: Deleting related files (e.g., S3), cleaning up orphaned records.
     */
    public static function afterDelete(Request $request, string $table, array $context): void
    {
        // Example: Delete avatar from S3
    }

    /**
     * beforeRestore
     * Context: ['id' => mixed, 'tenant_id' => mixed, 'record' => ?object]
     * Use for: Checking permissions before restoring a soft-deleted record.
     */
    public static function beforeRestore(Request $request, string $table, array $context): void
    {
        // Example: Check if user can restore
    }

    /**
     * afterRestore
     * Context: ['id' => mixed, 'tenant_id' => mixed, 'record' => ?object, 'restored' => int, 'response' => JsonResponse]
     * Use for: Re-indexing the record in a search engine.
     */
    public static function afterRestore(Request $request, string $table, array $context): void
    {
        // Example: Re-index in Algolia
    }
}
```

Triggers are invoked with `call_user_func_array([$class, $method], $params)` where `$params` always starts with:

- `Request $request`
- `string $table`

Additional arguments depend on the event/endpoint. Common patterns:

- `beforeRead` on list: `[$request, $table, ['type' => 'index', 'tenant_id' => mixed]]`
- `afterRead` on list: `[$request, $table, ['type' => 'index', 'filters' => [...], 'data' => [...], 'meta' => [...], 'tenant_id' => mixed, 'response' => JsonResponse]]`
- `beforeCreate` (single): `[$request, $table, ['tenant_id' => mixed]]`
- `beforeUpdate` (single): `[$request, $table, ['id' => mixed, 'tenant_id' => mixed]]`
- `beforeDelete` (single): `[$request, $table, ['id' => mixed, 'tenant_id' => mixed, 'record' => ?object]]`
- `afterDelete` (single): `[$request, $table, ['id' => mixed, 'tenant_id' => mixed, 'record' => ?object, 'soft_deleted' => bool, 'response' => JsonResponse]]`
- `beforeRestore` (single): `[$request, $table, ['id' => mixed, 'tenant_id' => mixed, 'record' => ?object]]`
- `afterRestore` (single): `[$request, $table, ['id' => mixed, 'tenant_id' => mixed, 'record' => ?object, 'restored' => int, 'response' => JsonResponse]]`
- Bulk endpoints may pass different params per item (e.g. `[$request, $table, $item]`, `[$request, $table, $id, $item]`, `[$request, $table, $id]`)

Return values:

- Return a `Request` to replace the current request (used mainly by `beforeRead`/`beforeCreate`/`beforeUpdate`/`beforeDelete`)
- Return an `array` to merge into the request input (merged into `$request->merge($result)`)
- Return `null` / no return to leave the request unchanged

Trigger handlers are strict: invalid trigger config, missing class/method, or any runtime error will abort the request and surface as an API error response. Use validators when you want user-friendly `422` validation errors.

### Using PHP Attributes in Trigger Classes (Recommended)

Instead of manually mapping every hook in `RecordTableType`, you can use the `#[RecordTrigger]` attribute inside your dedicated trigger classes. This allows you to name your methods based on business logic (e.g., `enforceCompanyFilter`) and automatically register them.

**1. Create the Trigger Class with Attributes:**

```php
namespace App\Record\User;

use Illuminate\Http\Request;
use Sopheak\Core\Attributes\RecordTrigger;

class UserTriggers
{
    #[RecordTrigger('beforeRead', description: 'Auto-filter users by company_id')]
    public static function enforceCompanyFilter(Request $request, string $table, array $context): Request
    {
        // Business logic name, but hooked to 'beforeRead'
        $request->query->set('company_id', 'eq.' . auth()->user()->company_id);
        return $request;
    }

    #[RecordTrigger('afterCreate', description: 'Send welcome email')]
    public static function sendWelcomeEmail(Request $request, string $table, array $context): void
    {
        $user = $context['record'] ?? null;
        if ($user) {
            // \Mail::to($user->email)->send(new WelcomeEmail($user));
        }
    }
}
```

**2. Register the Class in `RecordTableType`:**

Simply pass the class name to the `triggers` array. The package will automatically scan the class and map the methods to the correct lifecycle hooks.

```php
use Sopheak\Core\Types\RecordTableType;
use App\Record\User\UserTriggers;

return [
    'users' => new RecordTableType(
        // ... other config
        triggers: [
            UserTriggers::class,
        ],
    ),
];
```

*Note: You can still use `#[RecordTrigger]` directly on Eloquent Models if you are using Attribute-Based Configuration (`SP_ATTRIBUTE_DISCOVERY=true`).*

### Using PHP Attributes for Table and Global Functions

You can also declare custom functions via attributes when attribute discovery is enabled (`SP_ATTRIBUTE_DISCOVERY=true`).

Available attributes:

- `#[RecordFunction(...)]` for table functions
- `#[RecordGlobalFunction(...)]` for global functions

```php
namespace App\Models;

use Illuminate\Http\Request;
use Sopheak\Core\Attributes\RecordTable;
use Sopheak\Core\Attributes\RecordFunction;
use Sopheak\Core\Attributes\RecordGlobalFunction;

// 1. Table-scoped function inside the model
#[RecordTable(table: 'invoices', pmsName: 'invoices')]
class Invoice
{
    #[RecordFunction(
        name: 'sync',
        httpMethod: ['POST'],
        pmsName: 'invoice.sync',
        disableCache: true,
        description: 'Sync invoice to external system'
    )]
    public static function sync(Request $request): array
    {
        return ['ok' => true];
    }
}

// 2. Table-scoped function in a separate file
// You must specify the `table` parameter so the package knows where it belongs.
class InvoiceFunctions
{
    // The name will automatically default to the method name ('listModulePermissions')
    #[RecordFunction(
        table: 'modules',
        description: 'List module permissions'
    )]
    public static function listModulePermissions(Request $request): JsonResponse
    {
        // ... logic
    }
}

// 3. Global function (not attached to any specific table)
class HealthFunctions 
{
    #[RecordGlobalFunction(
        name: 'health',
        httpMethod: ['GET'],
        isPublic: true,
        description: 'Health check endpoint'
    )]
    public static function health(Request $request): array
    {
        return ['status' => 'ok'];
    }
}
```

Behavior and precedence:

- File config still has priority over attributes on key conflicts.
- For table config conflicts, file `functions` entries override discovered `#[RecordFunction]`.
- For global config conflicts, `record.global_functions` entries override discovered `#[RecordGlobalFunction]`.

### Full End-to-End Example

Use this complete setup when you want table functions and global functions from attributes.

**1) Enable attribute discovery**

```env
SP_ATTRIBUTE_DISCOVERY=true
```

```php
// config/sp-laravel-api.php
'attribute_discovery' => [
    'enabled' => env('SP_ATTRIBUTE_DISCOVERY', false),
    'paths' => ['app/Models', 'app/Record'],
],
```

**2) Declare attribute-based table + functions**

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Sopheak\Core\Attributes\RecordTable;
use Sopheak\Core\Attributes\RecordFunction;
use Sopheak\Core\Attributes\RecordGlobalFunction;

#[RecordTable(table: 'invoices', pmsName: 'invoices', hasTenantId: true, softDeletes: true)]
class Invoice extends Model
{
    #[RecordFunction(
        name: 'sync',
        httpMethod: ['POST'],
        pmsName: 'invoice.sync',
        disableCache: true,
        description: 'Sync invoice to external system'
    )]
    public static function sync(Request $request): array
    {
        $id = (int) $request->route('id');
        return ['ok' => true, 'invoice_id' => $id];
    }

    #[RecordGlobalFunction(
        name: 'health',
        httpMethod: ['GET'],
        isPublic: true,
        description: 'Health check endpoint'
    )]
    public static function health(Request $request): array
    {
        return ['status' => 'ok'];
    }
}
```

**3) Keep record config minimal (optional)**

```php
// config/record.php
return [
    'api_prefix' => 'api/v2',
    'rpc_prefix' => 'rpc',
    'tables' => [
        // can stay empty for fully attribute-driven table config
    ],
    'global_functions' => [
        // can stay empty for attribute-driven global functions
    ],
];
```

**4) Call the endpoints**

- Table function (inside model):
  `POST /api/v2/invoices/123/rpc/sync`
- Table function (standalone file):
  `GET /api/v2/modules/123/rpc/listModulePermissions`
- Global function:
  `GET /api/v2/rpc/health`

**5) Override with file config (file wins)**

```php
// config/record.php
'global_functions' => [
    'health' => new \Sopheak\Core\Types\RecordFunctionType(
        httpMethod: ['GET'],
        class: \App\Http\Controllers\HealthController::class,
        functionName: 'fromConfig',
        isPublic: true
    ),
],
```

With this override, `GET /api/v2/rpc/health` uses `HealthController::fromConfig` instead of the attribute method.
