---
title: "Realtime Events, OpenAPI Export, and Attribute Config"
description: "Broadcast events, openapi export command, and PHP attribute-based configuration guidance."
keywords:
  - broadcast events
  - realtime mutation events
  - openapi export
  - php attributes
  - attribute discovery
---

### Broadcast Events (Real-Time Mutations)

When `record.broadcast_events` is enabled, the package fires a `RecordMutated` event over Laravel's broadcasting system after every successful mutation made through the HTTP API (create, update, upsert, delete, restore, force-delete, bulk) or the MCP / AI SDK tools (`record.mcp.run_record_hooks`). Your own `RecordService::execute*` calls do not broadcast.

#### Enabling Broadcasting

```php
// config/record.php
'broadcast_events' => env('SP_BROADCAST_EVENTS', false),

// Optional: restrict to specific tables (empty = all tables)
'broadcast_tables' => ['invoices', 'payments'],
```

Set `SP_BROADCAST_EVENTS=true` in your `.env`. Configure your broadcast driver (`BROADCAST_DRIVER`) as usual — Pusher, Soketi, Reverb, etc.

#### Event Details

| Property | Value |
|---|---|
| Class | `Sopheak\Core\Events\RecordMutated` |
| Interface | `Illuminate\Contracts\Broadcasting\ShouldBroadcast` |
| Channel | `private-tenant.{tenantId}` (falls back to `private-tenant.global`) |
| Event name | `{table}.{action}` — e.g. `invoices.created`, `payments.deleted` |

#### Broadcast Payload

```json
{
  "table": "invoices",
  "action": "created",
  "record": { "id": 42, "status": "draft", ... },
  "tenant_id": "tenant_abc",
  "timestamp": "2026-03-29T10:00:00.000000Z"
}
```

`action` is one of: `created`, `updated`, `upserted`, `deleted`, `restored`, `force_deleted`.

#### Per-Table Opt-Out

```php
new RecordTableType(
    table: 'audit_snapshots',
    disableBroadcast: true,  // never broadcast this table
);
```

#### Authorization

Private channels use standard Laravel channel authorization. Register the channel in `routes/channels.php`:

```php
Broadcast::channel('tenant.{tenantId}', function ($user, $tenantId) {
    return (int) $user->tenant_id === (int) $tenantId;
});
```

> **Note:** Broadcasting failures are silently swallowed so they never break the HTTP response.

---

### Realtime Metadata in OpenAPI

Set both record broadcasting and OpenAPI realtime documentation to publish the
package-managed `RecordMutated` subscription contract in the runtime and
exported OpenAPI 3.0.3 document:

```php
// config/sp-record.php
'broadcast_events' => true,

// config/sp-laravel-api.php
'openapi' => [
    'realtime' => [
        'enabled' => true,
    ],
],
```

The result contains an opt-in `x-sp-realtime` extension and a
`#/components/schemas/RecordMutated` schema. The extension states the private
`tenant.{tenantId}` channel pattern, the `{table}.{action}` event pattern, and
the effective broadcast table list. The schema contains the exact event wire
fields: `table`, `action`, `record`, `tenant_id`, and `timestamp`.

`record` is intentionally an open object because its fields depend on the
table and mutation response. `action` is also an open string so clients do not
break when the package adds a mutation type. A missing tenant uses the existing
`tenant.global` channel at runtime.

Private-channel authorization remains the host application's responsibility.
The OpenAPI document never includes broadcaster credentials, app keys, private
keys, or authorization closures.

#### Documenting application-owned realtime channels

Applications can explicitly append their own documented channels. The package
does not inspect `routes/channels.php` or infer authorization rules.

```php
// config/sp-laravel-api.php
'openapi' => [
    'realtime' => [
        'enabled' => true,
        'channels' => [
            [
                'name' => 'booking-status',
                'pattern' => 'booking.{bookingId}',
                'private' => true,
                'parameters' => [
                    'bookingId' => [
                        'description' => 'Booking UUID.',
                        'schema' => ['type' => 'string', 'format' => 'uuid'],
                    ],
                ],
                'events' => [[
                    'name' => 'booking.status.updated',
                    'payload' => ['$ref' => '#/components/schemas/BookingStatusUpdated'],
                ]],
                'authorization' => 'The user must be allowed to view the booking.',
            ],
        ],
    ],
],
```

Channel `name` values are unique stable documentation identifiers. A channel
requires a non-empty pattern, boolean `private`, parameters matching every
`{placeholder}`, and at least one event. Each event has exactly one of `name`
or `pattern`, plus a payload schema or local component reference. Invalid
declarations fail OpenAPI generation rather than returning a partial document.

#### Documenting application-owned HTTP routes

Use `openapi.contributions` for routes the client application implements
outside package CRUD/RPC configuration:

```php
'openapi' => [
    'contributions' => [
        'tags' => [
            ['name' => 'Reports', 'description' => 'Application reporting routes.'],
        ],
        'paths' => [
            '/api/v1/reports/monthly' => [
                'get' => [
                    'tags' => ['Reports'],
                    'summary' => 'Get monthly report',
                    'operationId' => 'getMonthlyReport',
                    'responses' => [
                        '200' => [
                            'description' => 'Monthly report.',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/MonthlyReport'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'components' => [
            'schemas' => [
                'MonthlyReport' => ['type' => 'object'],
                'BookingStatusUpdated' => ['type' => 'object'],
            ],
        ],
        'extensions' => [
            'x-client-documentation' => ['owner' => 'reporting-team'],
        ],
    ],
],
```

Contribution paths must begin with `/`, use a non-empty `summary`, a globally
unique `operationId`, and at least one response. A `{pathParameter}` needs a
matching required `in: path` parameter. Contributions may not replace a
package path, component, tag, or extension. The `x-sp-*` namespace is reserved
for package metadata; use an application namespace such as `x-client-*`.

For reusable modules, configure a class string that implements
`Sopheak\Core\Contracts\OpenApiDocumentContributorInterface`. The class is
resolved by Laravel's container and receives an append-only
`OpenApiDocumentBuilder`; config closures and mutable root-document access are
not supported, so `config:cache` remains safe.

---

### OpenAPI Export Command

Export the package-generated OpenAPI 3.0 schema to a local file.

```bash
php artisan sp-laravel-api:export-openapi
```

#### Options

| Option | Default | Description |
|---|---|---|
| `--output` | `openapi-schema.json` (from `sp-laravel-api.openapi.output`) | Output file path (relative to project root) |
| `--format` | `json` | Output format: `json` or `yaml` |
| `--pretty` | `false` | Pretty-print JSON output |

#### Examples

```bash
# Export as JSON
php artisan sp-laravel-api:export-openapi

# Export as pretty-printed JSON
php artisan sp-laravel-api:export-openapi --pretty

# Export as YAML to a custom path
php artisan sp-laravel-api:export-openapi --format=yaml --output=docs/openapi.yaml

# JSON to a specific path
php artisan sp-laravel-api:export-openapi --output=public/api-schema.json --pretty
```

The default output path is configurable via `config/sp-laravel-api.php`:

```php
'openapi' => [
    'output' => 'openapi-schema.json',
],
```

The command uses the same `OpenApiService::generateInternal()` that powers the runtime `/docs/openapi.json` endpoint, so the exported file is always consistent with the live API schema.

#### Filter operator documentation link

Dynamic CRUD list operations expose one top-level query parameter per configured
column. To avoid repeating the full filter-operator catalogue in every field
description, configure the absolute URL of your canonical filter guide:

```php
// config/sp-laravel-api.php
'openapi' => [
    'filter_documentation_url' => 'https://docs.example.com/guide/api-filter-operators',
],
```

Each list operation then includes the standard OpenAPI `externalDocs` object
with that URL. Field descriptions remain short (`Filter value for \`status\`; use
\`{operator}.{value}\` syntax.`), while the complete operator list, grouped
logic, and top-level parameter rule remain in one authoritative guide. Leave
the setting empty to omit `externalDocs`.

#### Documenting Custom Function Endpoints

The auto-generated schema covers all dynamic CRUD and bulk routes, but **custom RPC functions** (`RecordFunctionType`) require you to provide the request/response schema manually. Use the **JSON to OpenAPI** tool to generate the schema from a sample JSON payload or response:

👉 **[wk-tools.vercel.app/json-to-openapi](https://wk-tools.vercel.app/json-to-openapi)**

Workflow:

1. Run your custom function and capture the JSON request body and response.
2. Paste each into the tool — it generates an OpenAPI-compatible `schema` object.
3. Use the output in `RecordFunctionType::$requestSchema` and `$responseSchema` to annotate your function config.

```php
use Sopheak\Core\Types\RecordFunctionType;

'functions' => [
    'calculate_totals' => new RecordFunctionType(
        handler: CalculateTotalsFunction::class,
        // Paste the schema generated by wk-tools here:
        requestSchema: [
            'type' => 'object',
            'properties' => [
                'invoice_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'currency'    => ['type' => 'string', 'example' => 'USD'],
            ],
            'required' => ['invoice_ids'],
        ],
        responseSchema: [
            'type' => 'object',
            'properties' => [
                'total'    => ['type' => 'number'],
                'currency' => ['type' => 'string'],
            ],
        ],
    ),
],
```

Once set, the schema appears in the exported OpenAPI file and in the live `/docs/openapi.json` endpoint automatically.

#### Authorization, Permission, and Pagination Documentation

Every operation in the generated schema documents its auth requirement, derived live from the table or function config:

- **Description block** — each operation's `description` gains an `**Authorization:**` line (and, when configured, `**Permission scope(s):**` and `**Route middleware:**` lines):

  ```markdown
  **Authorization:** Bearer token required — write auth (`isAuthWrite=true` in config/records/tables/invoices.php)
  **Permission scope(s):** `invoice:create`
  **Route middleware:** `auth:sanctum`, `subscribed`
  ```

  Public endpoints read as `**Authorization:** Public — no authentication required (...)`. Sources: table CRUD uses `isAuthRead` (read ops) / `isAuthWrite` (write ops) from `config/records/tables/{table}.php`; global and table RPC functions use `isPublic` from their function config (global functions default to public, table RPC functions to authenticated).

- **`x-sp-auth` extension** — machine-readable counterpart on every operation, so tooling and AI agents can query the contract without parsing prose:

  ```json
  {
    "x-sp-auth": {
      "auth": "bearer",
      "mode": "write",
      "flag": "isAuthWrite",
      "flag_value": true,
      "public": false,
      "permissions": ["invoice:create"],
      "middleware": ["auth:sanctum", "subscribed"],
      "tenant": true,
      "source": "config/records/tables/invoices.php"
    }
  }
  ```

  - `permissions` — from the table's `permissions[action]` map (same source the runtime authorization flow checks); omitted when not configured.
  - `middleware` — resolved with the same precedence as route execution: a function's own `middleware` setting replaces the map; otherwise `middleware_map` default + per-table entries merge across `*`, the action group, and the exact action.
  - `tenant` — `hasTenantId` on the table config.

- **Pagination defaults** — the main document's Pagination section states the configured values (`record.pagination.default_mode`, cursor default column, composite cursors, `skip_total_default`), and every list operation's description notes the effective default mode and cursor column.

The `security` arrays (the OpenAPI auth contract) are unchanged — this is documentation only.

#### Schema Alignment with Table Config

The component schemas mirror the runtime behavior of the table config:

- **`columnHiddens`** — hidden columns are omitted from the response and `Read` schemas (they never appear in responses), but stay in the `Write` schema (they remain writable).
- **`columnWriteDisabled`** — these fields appear in the `Write` schema with `readOnly: true` and a note that sending them is a silent no-op (matching `RecordPayloadExtractor` behavior).
- **`id` and `record.id_type`** — with `id_type=integer` the `id` is omitted from the `Write` schema (auto-increment, must not be sent); with `id_type=uuid` it is an optional `string`/`uuid` field (server-generated via `Str::uuid()` when omitted). The `{id}` path parameter's schema type follows the same `id_type` (`integer`/`int64` vs `string`/`uuid`).
- **`lazy` parameter** — list operations declare the `lazy` query parameter (deferred query execution for large/complex filters).
- **`max_depth`** — the main document's Column Selection section states the relationship nesting depth limit from `record.max_depth`.
- **Rate limits** — the main document's Rate Limits section documents the `throttle:api-reads` / `api-writes` / `api-functions` buckets and any per-table overrides from `record.rate_limits`.
- **Primary keys** — the main document's Getting Started section states whether keys are auto-increment integers or UUIDs.

---

### PHP 8.3 Attribute-Based Config

> **Legacy — only for old client projects using Laravel ORM.**
> The recommended way to configure tables in this package is file-based record
> config (`config/records/tables/*.php` with `RecordTableType`), as described
> throughout this guide. The `#[RecordTable]` attribute approach below is kept
> for backward compatibility with existing client projects that use Laravel ORM
> (Eloquent models) and is **not** the recommended path for new tables.

As an alternative to file-based `RecordTableType` configuration, you can annotate Eloquent models directly with PHP 8 attributes. This keeps table configuration co-located with the model class.

#### Enabling Discovery

```php
// config/sp-laravel-api.php
'attribute_discovery' => [
    'enabled' => env('SP_ATTRIBUTE_DISCOVERY', false),
    'paths'   => ['app/Models'],
],
```

Set `SP_ATTRIBUTE_DISCOVERY=true` in your `.env`.

> File-based config (config/record.php and config/records/tables/) **always takes precedence** over attribute-discovered tables. Attribute discovery only fills in tables that have no file-based entry.

#### `#[RecordTable]` Attribute

```php
use Sopheak\Core\Attributes\RecordTable;
use Sopheak\Core\Attributes\RecordRelationship;
use Sopheak\Core\Attributes\Trigger;
use Illuminate\Http\Request;

#[RecordTable(
    pmsName: 'invoice',
    table: 'invoices',
    hasTenantId: true,
    softDeletes: true,
    isAuthRead: true,
    isAuthWrite: true,
    canRead: true,
    canCreate: true,
    canUpdate: true,
    canDelete: true,
    canUpsert: true,
    disableAuditLog: false,
    disableCache: false,
    disableBroadcast: false,
)]
#[RecordRelationship(
    name: 'customer',
    type: 'belongs_to',
    foreignKey: 'customer_id',
    relatedTable: 'customers',
)]
#[RecordRelationship(
    name: 'items',
    type: 'has_many',
    foreignKey: 'invoice_id',
    relatedTable: 'invoice_items',
)]
class Invoice extends Model
{
    #[Trigger('beforeRead')]
    public static function enforceCompanyFilter(Request $request, string $table, array $context): Request
    {
        $request->query->set('company_id', 'eq.' . auth()->user()->company_id);
        return $request;
    }

    #[Trigger('afterCreate')]
    public static function sendInvoiceEmailToCustomer(Request $request, string $table, array $context): void
    {
        // Send email logic...
    }
}
```

All parameters from `RecordTableType` are available as named arguments on `#[RecordTable]`. `#[RecordRelationship]` is repeatable and maps to the relationship types supported by the package.

#### Listing Discovered Tables

```bash
# All tables (file-based + attribute-discovered)
php artisan sp-laravel-api:list-tables

# Only file-based tables
php artisan sp-laravel-api:list-tables --source=file

# Only attribute-discovered tables
php artisan sp-laravel-api:list-tables --source=attributes
```

Output columns: `Key`, `Table`, `PMS Name`, `Auth R/W`, `Soft Del`, `Tenant`, `Source`, `Note` (where `Note` shows `⚠ overridden by file` for attribute tables that are shadowed by a file-based entry).

---
