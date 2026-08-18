---
title: "Custom Function Endpoints"
description: "Custom global and table function configuration via RecordFunctionType (recommended) and the legacy PHP attribute approach for old ORM-based projects."
keywords:
  - custom functions
  - table functions
  - global functions
  - function schemas
  - rpc custom logic
  - legacy attributes
---

### Custom Functions

Custom functions (RPC endpoints) let you expose arbitrary logic under `/{api_prefix}/rpc/{name}` (global) or `/{api_prefix}/{table}/rpc/{name}` (table-scoped). They are configured via `RecordFunctionType` in `record.global_functions` or `RecordTableType::$functions`.

> **Tip — generate OpenAPI schemas for custom functions:**
> The package cannot auto-infer request/response shapes for custom functions. Use **[wk-tools.vercel.app/json-to-openapi](https://wk-tools.vercel.app/json-to-openapi)** to convert a sample JSON payload/response into an OpenAPI `schema` object, then attach it to `RecordFunctionType::$requestSchema` / `$responseSchema`. The exported schema and live `/docs/openapi.json` will include it automatically.

### Global Functions

```http
GET|POST|PUT|PATCH|DELETE /{api_prefix}/rpc/{functionName}
```

Execute global custom functions defined in `config/record.php`.

#### Examples

```http
# Simple global function
GET /api/v1/rpc/system_stats

# Parameterized global function
POST /api/v1/rpc/generate_report
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
GET /api/v1/invoices/rpc/calculate_totals

# Parameterized table function
POST /api/v1/users/rpc/send_notification
Content-Type: application/json
{
  "message": "Welcome to our platform!",
  "type": "welcome"
}
```

---

### Using PHP Attributes for Table and Global Functions

> **Legacy — only for old client projects using Laravel ORM.**
> The recommended way to declare custom functions in this package is record
> config: `RecordFunctionType` in `record.global_functions` (global) or
> `RecordTableType::$functions` (table-scoped), as described at the top of this
> page. The attribute-based approach below is kept for backward compatibility
> with existing client projects that use Laravel ORM (Eloquent models) and is
> **not** the recommended path for new tables.

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
use Sopheak\Core\Enums\RecordFunctionMethodEnum;

// 1. Table-scoped function inside the model
#[RecordTable(table: 'invoices', pmsName: 'invoices')]
class Invoice
{
    #[RecordFunction(
        name: 'sync',
        httpMethod: [RecordFunctionMethodEnum::POST->value],
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
        httpMethod: [RecordFunctionMethodEnum::GET->value],
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

### Full End-to-End Example (Legacy Attributes)

> Legacy path: this complete setup applies to old client projects using Laravel
> ORM. For new tables, prefer declaring functions in record config
> (`RecordFunctionType`) and keeping the attribute discovery off.

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
use Sopheak\Core\Enums\RecordFunctionMethodEnum;

#[RecordTable(table: 'invoices', pmsName: 'invoices', hasTenantId: true, softDeletes: true)]
class Invoice extends Model
{
    #[RecordFunction(
        name: 'sync',
        httpMethod: [RecordFunctionMethodEnum::POST->value],
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
        httpMethod: [RecordFunctionMethodEnum::GET->value],
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
        httpMethod: [\Sopheak\Core\Enums\RecordFunctionMethodEnum::GET->value],
        class: \App\Http\Controllers\HealthController::class,
        functionName: 'fromConfig',
        isPublic: true
    ),
],
```

With this override, `GET /api/v2/rpc/health` uses `HealthController::fromConfig` instead of the attribute method.

## Related Docs

- [Global RPC Functions](/guide/api-rpc-functions)
- [Realtime Events, OpenAPI Export, and Attribute Config](/guide/api-realtime-openapi-attribute-config)

