<?php

declare(strict_types=1);

namespace Sopheak\Core\Services;

use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Interfaces\RecordFunctionInterface;
use Sopheak\Core\Types\RecordFunctionType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Sopheak\Core\Utilities\RelationshipResolverUtils;
use Sopheak\Core\Constants\HttpErrorCodeConstant;
use Sopheak\Core\Exceptions\OpenApiContributionException;
use Sopheak\Core\Http\Middleware\RecordRouteMiddleware;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;

class OpenApiService
{
    /**
     * Load OpenAPI 3.0 specification dynamically from current configuration.
     *
     * @return array The OpenAPI specification
     */
    public static function load(): array
    {
        return self::generateInternal();
    }

    public static function generateLlmMdx(): string
    {
        $apiPrefix = trim(RecordConfigService::apiPrefix(), '/');
        $rpcPrefix = trim(RecordConfigService::rpcPrefix(), '/');
        $tenantHeader = RecordConfigService::tenantHeader();
        $tenantColumn = RecordConfigService::tenantColumn();
        $baseUrl = rtrim((string) config('app.url'), '/');

        $openApiPath = '/' . $apiPrefix . '/docs/openapi.json';
        $openApiUrl = $baseUrl !== '' ? $baseUrl . $openApiPath : $openApiPath;

        $globalRpcPattern = $rpcPrefix === ''
            ? '/' . $apiPrefix . '/{functionName}'
            : '/' . $apiPrefix . '/' . $rpcPrefix . '/{functionName}';

        $tableRpcPattern = $rpcPrefix === ''
            ? '/' . $apiPrefix . '/{table}/{functionName}'
            : '/' . $apiPrefix . '/{table}/' . $rpcPrefix . '/{functionName}';

        $tableCount = count(RecordConfigService::getTableConfig());
        $globalFunctionCount = count(RecordConfigService::globalFunctions());

        return <<<MDX
            # SP Laravel API Agent Contract

            This API is private/internal. Prefer OpenAPI as the source of truth.

            ## OpenAPI

            - Schema URL: {$openApiUrl}
            - Content-Type: application/vnd.oai.openapi+json
            - Auth: Bearer token
            - Tenant header: {$tenantHeader} (maps to {$tenantColumn})

            ## Key Notes

            - OpenAPI schema is the only source of truth for modules, fields, and relationships.
            - Do not duplicate or hardcode relationship details from this MDX document.
            - Read relationship metadata from `paths` + `components.schemas` in OpenAPI.
            - If OpenAPI and any prose differ, always follow OpenAPI.

            ## Endpoint Patterns

            - List: /{$apiPrefix}/{table}
            - Detail: /{$apiPrefix}/{table}/{id}
            - Create: POST /{$apiPrefix}/{table}
            - Update: PUT|PATCH /{$apiPrefix}/{table}/{id}
            - Delete: DELETE /{$apiPrefix}/{table}/{id}
            - Global RPC: {$globalRpcPattern}
            - Table RPC: {$tableRpcPattern}

            ## Runtime Snapshot

            - Configured tables: {$tableCount}
            - Configured global functions: {$globalFunctionCount}

            ## Agent Rules

            - Do not invent fields or endpoints.
            - Generate frontend types and API clients from OpenAPI schema URL.
            - Use error_code and message from API responses for UI handling.
            - Respect tenant header and auth on every request.
            MDX;
    }

    /**
     * Generate OpenAPI 3.0 specification dynamically from runtime configuration.
     *
     * @return array<string, mixed[]|string> The generated OpenAPI specification
     */
    public static function generateInternal(): array
    {
        $tables = SchemaRegistryUtils::get();
        $apiPrefix = RecordConfigService::apiPrefix();
        $tenantHeader = RecordConfigService::tenantHeader();
        $tenantColumn = RecordConfigService::tenantColumn();

        $maxPerPage = RecordConfigService::perPageMax();

        $defaultPaginationMode = RecordConfigService::paginationDefaultMode();
        $defaultCursorColumn = RecordConfigService::cursorDefaultColumn();
        $compositeCursorsEnabled = RecordConfigService::cursorCompositeEnabled();
        $skipTotalDefault = RecordConfigService::skipTotalDefault();

        $schemas = [];
        foreach ($tables as $recordName => $config) {
            $columns = $config->columns ?? [];
            $actualTableName = $config->table ?? $recordName; // Use actual table name from config

            // Full schema (for responses)
            $schemas[self::schemaName($recordName)] = self::tableSchema($actualTableName, $columns, $config);

            // Read schema (for GET operations - exclude created_at, updated_at)
            $schemas[self::schemaName($recordName) . 'Read'] = self::tableSchemaRead($actualTableName, $columns, $config);

            // Write schema (for POST/PUT/PATCH operations - exclude created_at, updated_at, deleted_at)
            $schemas[self::schemaName($recordName) . 'Write'] = self::tableSchemaWrite($actualTableName, $columns, $config);
        }

        $paths = self::paths($tables);

        $servers = [
            [
                'url' => rtrim((string) config('app.url'), '/'),
                'description' => 'Primary API server (Internal)',
            ],
        ];

        $securitySchemes = [
            'bearerAuth' => [
                'type' => 'http',
                'scheme' => 'bearer',
                'bearerFormat' => 'JWT',
                'description' => 'Use `Authorization: Bearer <token>` - Internal team access only',
            ],
        ];

        $globalFunctions = RecordConfigService::globalFunctions();

        $spec = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => config('app.name') . ' - Internal Documentation',
                'version' => '2.0.0',
                'description' => '# API Documentation

A powerful, flexible API for accessing system data with advanced filtering, relationships, and performance optimizations.

## 📋 What This API Does

This API provides **unified access** to all tables through a single endpoint pattern:
- **CRUD Operations**: Create, read, update, delete records
- **Dynamic Filtering**: Extended operator set with grouped logical expressions  
- **Relationship Embedding**: Load related data in a single request
- **Bulk Operations**: Process multiple records efficiently
- **Custom Functions**: Execute business logic via RPC endpoints

## 🔑 Authentication

Endpoints document their auth requirement per operation (see each operation\'s `**Authorization:**` block and the `x-sp-auth` extension):

- **Public** operations (e.g. `isAuthRead=false`, `isAuthWrite=false`, or a function with `isPublic=true`) need no token.
- **All other operations** require a valid API token in the `Authorization` header:
```
Authorization: Bearer <your-api-token>
```

Required permission scopes (when configured) and route middleware are listed in the same per-operation documentation.

## Error Code

Every API response includes an integer `error_code` that provides a stable, machine-readable error identifier.

Common values:
- `0` (**SUCCESS**) – Request processed successfully.
- `10000` (**GENERAL_ERROR**) – Unclassified error when no more specific code applies.
- `10001` (**INVALID_TENANT_ID**) – Tenant header is missing or invalid.
- `10002` (**INVALID_ACCESS**) – Authentication failed or access token is invalid.
- `10003` (**INVALID_TOKEN**) – Token is malformed, expired, or not accepted.
- `10004` (**INVALID_REQUEST**) – Request payload or parameters are invalid (validation errors).
- `10005` (**INVALID_RESOURCE**) – The requested resource identifier is invalid.
- `10006` (**INVALID_PERMISSION**) – User lacks the required permission scope.
- `10007` (**INVALID_CREDENTIAL**) – Provided credentials are incorrect.
- `10008` (**PERMISSION_DENIED**) – Authenticated but not allowed to perform this operation.
- `10009` (**RESOURCE_NOT_FOUND**) – Entity or endpoint not found.
- `10010` (**INTERNAL_SERVER_ERROR**) – Unexpected server-side error.
- `10011` (**UNKNOWN_ERROR**) – Error cause cannot be determined.
- `10012` (**TENANT_NOT_FOUND**) – Tenant does not exist.
- `10013` (**TENANT_DISABLED**) – Tenant is disabled.
- `10014` (**NO_TENANT_PMS_ACCESS**) – Tenant has no PMS access for this operation.

Clients should always branch on `error_code` instead of parsing the human-readable `message`.

## 🔒 Error Handling

All API responses follow this structure:
```json
{
    "success": true,
    "error_code": 0,
    "data": {},
    "meta": {
        "request_id": "f9c4d1e2-9b0c-4f8a-9b8a-123456789abc"
    }
}
```

On error, the structure is:
```json
{
    "success": false,
    "error_code": 10009,
    "message": "Resource not found",
    "errors": [],
    "meta": {
        "request_id": "f9c4d1e2-9b0c-4f8a-9b8a-123456789abc"
    }
}
```

## 🚀 Getting Started

### Basic Usage
```bash
# Get all paid invoices
GET /' . $apiPrefix . '/invoices?status=eq.PAID

# Search customers by name
GET /' . $apiPrefix . '/customers?name=like.John

# Get products in price range
GET /' . $apiPrefix . '/products?price=between.100,1000
```

Primary keys are governed by `record.id_type`: `integer` (auto-increment) by default, or `uuid` — UUIDs are generated server-side when the `id` is omitted on create, and may be supplied by the client.

### Documentation References
- Interactive package docs: https://sp-laravel-api-docs.vercel.app/#/
- Runtime OpenAPI JSON: /' . $apiPrefix . '/docs/openapi.json
- Runtime API docs UI: /' . $apiPrefix . '/docs
- Full Markdown guide: docs/api-documentation.md

### Load Related Data
```bash
# Get invoices with customer and items
GET /' . $apiPrefix . '/invoices?select=id,ref_number,customer:customers(id,name),items(*)

# Get users with their roles
GET /' . $apiPrefix . '/users?select=id,name,roles(id,name)
```

## 🏢 Multi-Tenant Header

If multi-tenant mode is enabled (`record.enable_tenant_id=true`) and the table is configured with `hasTenantId=true`, include the tenant header on requests:
```bash
' . $tenantHeader . ': <tenant-id>
```
Records are filtered by the `' . $tenantColumn . '` column.

## 🧩 Middleware Map (Public / Auth / Subscription)

You can configure route middleware stacks per action and per table in `config/sp-record.php` using `middleware_map`.

```php
"middleware_map" => [
  "default" => [
    "read" => [],
    "write" => ["auth:sanctum"],
  ],
  "tables" => [
    "customers" => ["read" => []],
    "bills" => ["write" => ["auth:sanctum", "subscribed"]],
  ],
]
```

## 🚦 Rate Limits

Routes are throttled via the `throttle:api-reads`, `throttle:api-writes`, and `throttle:api-functions` buckets (defined in the host application\'s `AppServiceProvider`). Per-table overrides can be configured in `config/sp-record.php` under `record.rate_limits`:

```php
"rate_limits" => [
  "users" => [
    "create" => ["limit" => 50, "decay_minutes" => 1],
  ],
],
```

Configured overrides for this API:' . self::rateLimitsSummary() . '

## 🔧 Key Features

### Filtering & Search
- **Equality**: `eq` (equal), `neq` (not equal)
- **Text Search**: `like`/`contains` (contains), `ilike`, `starts_with`, `ends_with`, `not_like`, `regex`, `match`, `imatch`
- **Comparisons**: `gt` (greater than), `lt` (less than), `gte` (greater/equal), `lte` (less/equal)
- **Lists**: `in` (value in list), `not_in` (value not in list), `between`, `not_between`
- **Date Filters**: `date_eq`, `date_gt`, `date_lt`, `date_gte`, `date_lte`
- **Null Checks**: `is` (is null), `is_not` (is not null), `empty` (null or empty), `not_empty`
- **Advanced**: `not.<operator>` syntax, `any/all` modifiers (example: `name=like(any).{ACME,SHOP}`), grouped logic `and=(...)` and `or=(...)`
- **Postgres Native**: `fts`, `plfts`, `phfts`, `wfts`, `cs`, `cd`, `ov`, `sl`, `sr`, `nxl`, `nxr`, `adj`
- **Compatibility**: If an operator is not supported by the current database driver, API returns a validation error
- **Common mistake**: filters are top-level query parameters in the form `{column}={operator}.{value}` (e.g. `created_at=gte.2026-08-01`). Bracket-style filters such as `filter[column]=value` or `filter[column][operator]=value` are **not** a supported syntax — they either silently filter nothing or return a `422` explaining the correct format.

### Grouped Logic Examples
- `vendor_id=eq.27&or=(balance_due.gt.0,id.eq.5)`
- `vendor_id=eq.27&and=(or(balance_due.gt.0,id.eq.5),id.neq.2)`
- `id=in.(5,6,9)` and legacy `id=in.5,6,9` are both supported

### Column Selection
- **Basic**: `select=id,name,email` (specific columns)
- **All Columns**: `select=*` (all table columns)
- **Relationships**: `select=id,name,customer:customers(id,name)` (include related data)
- **Nested**: `select=id,items(id,name,product:products(*))` (deep relationships)
- **Mixed**: `select=*,customer:customers(id,name),items(*)` (combine table and relationship columns)
- **Depth**: relationship nesting is limited to `' . RecordConfigService::maxDepth() . '` levels (config `record.max_depth`)

<h3 id="relationship-write-payload-guide">Relationship Write Payload Guide</h3>

This section describes payload format for write endpoints (`POST`, `PUT`, `PATCH`) based on `RecordRelationshipsEnum`.

| Enum Type | Payload Support | Payload Shape |
|---|---|---|
| `BELONGS_TO` | ✅ FK scalar only | `customer_id: 10` |
| `HAS_MANY` | ✅ alias array | `items: [1, {"id": 2}, {"name": "Line A"}]` |
| `BELONGS_TO_MANY` | ✅ alias array | `roles: [1, {"id": 2}]` |
| `HAS_MANY_THROUGH` | ✅ alias array | `tasks: [3, {"id": 4}]` |
| `MORPH_MANY` | ✅ alias array | `comments: [1, {"id": 2}]` |
| `MORPH_TO_MANY` | ✅ alias array | `roles: [1, {"id": 2}]` |
| `MORPH_BY_MANY` | ✅ alias array | `tags: [1, {"id": 2}]` |
| `SPATIE_PERMISSION` | ✅ alias array | `roles: [1, {"id": 2}]` |
| `HAS_ONE` | ⚠️ schema-dependent | Prefer scalar FK-style field |
| `HAS_ONE_THROUGH` | ⚠️ not direct alias write | Use main table fields or custom function |
| `MORPH_TO` | ⚠️ morph columns | `commentable_type`, `commentable_id` |
| `MORPH_ONE` | ⚠️ schema-dependent | Prefer scalar FK-style field |

**Write payload examples**
```json
{
  "customer_id": 10,
  "items": [
    1,
    {"id": 2},
    {"name": "Line A", "qty": 1},
    {"id": 5, "_delete": true}
  ],
  "roles": [1, {"id": 2}]
}
```

Full relationship examples and payload guides: https://sp-laravel-api-docs.vercel.app/#/

### Ordering & Sorting
- **Basic**: `sortby=name&order=asc` (sort by column)
- **Direction**: `order=desc` (default) or `order=asc`
- **Table Qualified**: `sortby=table.column` (explicit table reference)
- **Default**: Falls back to `id` or first available column if invalid
- **Validation**: Only allows columns that exist in table schema

### Pagination
- **Traditional**: `page=1&per_page=25` (offset-based for small datasets)
- **Cursor-Based**: `cursor=12345&direction=next` (high-performance for large datasets — use when cursor parameter present, or when `pagination.default_mode=cursor`)
- **Cursor follows the sort**: the cursor pages on whatever column the list is sorted by, so `sortby=created_at` pages on `created_at`. `meta.cursor_column` reports it. Pass `meta.cursor` back **verbatim** — when paging on a non-key column it is an opaque token (`c1.…`) carrying both the sort value and a tie-breaking key, not a readable value.
- **Custom Cursor**: `cursor_column=created_at` (override the paging column; configurable default via `pagination.cursor.default_column`)
- **Composite**: multi-column cursors are applied automatically when the paging column is not the primary key, so rows sharing a sort value are neither repeated nor skipped (toggle via `pagination.cursor.composite_enabled`)
- **Total Control**: `total=false` omits the total count query for performance; `total=true` includes totals even when `pagination.skip_total_default=true`. Legacy `skip_total=true` remains supported.
- **Limits**: `per_page` max ' . $maxPerPage . ', default 25
- **Configured defaults**: `record.pagination.default_mode` = `' . $defaultPaginationMode . '` (cursor default column: `' . $defaultCursorColumn . '`, composite cursors: ' . ($compositeCursorsEnabled ? 'enabled' : 'disabled') . ', `record.pagination.skip_total_default` = ' . ($skipTotalDefault ? 'true' : 'false') . ')

### Bulk Operations (Available for All Tables)

#### Mixed Operations
`POST /' . $apiPrefix . '/{table}/bulk` - Create, update, delete in one request. Operations are auto-detected based on payload structure.

**Request Payload:**
Accepts a JSON array of objects directly or wrapped in `{"items": [...]}`.

- **Create**: Object without primary key (e.g., `id`).
- **Update**: Object with primary key and other fields.
- **Delete**: Object with only primary key.

**Option 1: Direct Array**
```json
[
  {"name": "New Customer", "email": "new@example.com"},           // Create (No ID)
  {"id": "123", "name": "Updated Name", "status": "active"},      // Update (ID + fields)
  {"id": "789"}                                                   // Delete (Only ID)
]
```

**Option 2: Wrapped in Items**
```json
{
  "items": [
    {"name": "New Customer"},
    {"id": "123", "status": "active"}
  ]
}
```

**Response:**
Returns consolidated list of created and updated records in `data`. Deleted records are not returned.
`meta.affected` contains the total count of processed records.

#### Bulk Create
`POST /' . $apiPrefix . '/{table}/bulk/create` - Create multiple records
Primary keys (e.g., `id`) must NOT be provided.

```json
[
  {"name": "Product A", "price": 99.99, "category": "electronics"},
  {"name": "Product B", "price": 149.99, "category": "electronics"}
]
```

#### Bulk Update
`POST /' . $apiPrefix . '/{table}/bulk/update` - Update multiple records
Primary key is **REQUIRED** for each item. At least one field to update must be provided.

```json
[
  {"id": "123", "price": 89.99},
  {"id": "456", "status": "discontinued"}
]
```

#### Bulk Delete
`POST /' . $apiPrefix . '/{table}/bulk/delete` - Delete multiple records
Accepts an array of IDs or an array of objects with the primary key.

**Option 1: Array of IDs**
```json
["record-id-1", "record-id-2", "record-id-3"]
```

**Option 2: Array of Objects**
```json
[
  {"id": "record-id-1"},
  {"id": "record-id-2"}
]
```

### Performance Optimization
- **Lazy Loading**: `lazy=true` for complex queries
- **Smart Caching**: Automatic request caching
- **Cursor Pagination**: Efficient for large datasets
- **Query Optimization**: Operations prioritized by speed

## 🎯 Quick Tips

- Use `select` to limit returned columns for better performance
- Add `lazy=true` for complex queries with multiple filters
- Embed relationships instead of making separate API calls
- Use cursor pagination (`cursor`) for large datasets',
                'contact' => [
                    'name' => config('app.name') . ' Development Team',
                    'email' => config('app.email'),
                ],
                'license' => [
                    'name' => 'Proprietary - Internal Use Only',
                ],
            ],
            'servers' => $servers,
            'tags' => self::tags($tables, $globalFunctions),
            'paths' => $paths + self::rpcPaths($tables, $globalFunctions),
            'components' => [
                'schemas' => $schemas,
                'securitySchemes' => $securitySchemes,
            ],
            'security' => [['bearerAuth' => []]],
        ];

        $contributionService = app(OpenApiContributionService::class);
        $spec = $contributionService->apply($spec);

        if (self::realtimeDocumentationEnabled()) {
            if (isset($spec['components']['schemas']['RecordMutated'])) {
                throw new OpenApiContributionException('components.schemas.RecordMutated conflicts with the package realtime schema.');
            }

            $spec['components']['schemas']['RecordMutated'] = self::recordMutatedSchema($tables);
            $realtime = self::realtimeMetadata();
            $realtime['channels'] = $contributionService->realtimeChannels($spec, $realtime['channels']);
            $spec['x-sp-realtime'] = $realtime;
        }

        return $spec;
    }

    /**
     * @param array<string, RecordTableType> $tables
     *
     * @return array<string, mixed>
     */
    private static function recordMutatedSchema(array $tables): array
    {
        $allowedTables = RecordConfigService::broadcastTables();
        $broadcastTables = [];

        foreach ($tables as $table => $config) {
            if ($config->disableBroadcast) {
                continue;
            }

            if ([] !== $allowedTables && !in_array($table, $allowedTables, true)) {
                continue;
            }

            $broadcastTables[] = $table;
        }

        return [
            'type' => 'object',
            'required' => ['table', 'action', 'record', 'tenant_id', 'timestamp'],
            'properties' => [
                'table' => ['type' => 'string', 'enum' => $broadcastTables],
                'action' => [
                    'type' => 'string',
                    'description' => 'The emitted mutation action. Clients must not assume a closed enum.',
                ],
                'record' => [
                    'type' => 'object',
                    'additionalProperties' => true,
                    'description' => 'The affected record. Fields depend on the table and mutation response shape.',
                ],
                'tenant_id' => [
                    'description' => 'Tenant identifier, or null when the event uses tenant.global.',
                ],
                'timestamp' => ['type' => 'string', 'format' => 'date-time'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function realtimeMetadata(): array
    {
        return [
            'version' => '1.0',
            'transport' => 'laravel-broadcasting',
            'channels' => [
                [
                    'name' => 'record-mutations',
                    'pattern' => 'tenant.{tenantId}',
                    'private' => true,
                    'parameters' => [
                        'tenantId' => [
                            'description' => 'Tenant identifier. tenant.global is used when no tenant context exists.',
                            'schema' => ['type' => 'string'],
                        ],
                    ],
                    'events' => [
                        [
                            'pattern' => '{table}.{action}',
                            'payload' => ['$ref' => '#/components/schemas/RecordMutated'],
                        ],
                    ],
                    'authorization' => 'Private-channel authorization is implemented by the host application.',
                ],
            ],
        ];
    }

    private static function realtimeDocumentationEnabled(): bool
    {
        return RecordConfigService::broadcastEventsEnabled()
            && (bool) config('sp-laravel-api.openapi.realtime.enabled', false);
    }

    private static function schemaName(string $table): string
    {
        return str_replace(['-', ' '], '_', ucwords(strtolower($table)));
    }

    /**
     * Resolve the display text used to build an RPC operation's summary/description:
     * $functionConfig->name when set, else ->description, else a humanized function key.
     */
    private static function rpcMethodName(mixed $functionConfig, string $functionName): string
    {
        $name = $functionConfig->name ?? null;
        if (!empty($name)) {
            return $name;
        }

        return empty($functionConfig->description) ? self::schemaName($functionName) : $functionConfig->description;
    }

    /**
     * @return array<string, string|mixed[][]|int[]|string[]>
     */
    private static function tableSchema(string $table, array $columns, ?RecordTableType $config = null): array
    {
        $properties = [];
        $required = [];

        // Exclude deleted_at from all response schemas
        $excludeFields = ['deleted_at'];

        // Columns hidden from responses via columnHiddens are omitted too.
        $hidden = is_array($config?->columnHiddens ?? null) ? $config->columnHiddens : [];
        $hiddenSet = array_flip(array_filter($hidden, is_string(...)));

        foreach ($columns as $name => $info) {
            if (in_array($name, $excludeFields, true)) {
                continue;
            }

            if (isset($hiddenSet[$name])) {
                continue;
            }

            $mapped = self::mapColumnToOpenApi($info['type'] ?? 'string', $info);
            $properties[$name] = $mapped;
            // Avoid forcing typical system fields as required
            if (!(bool) ($info['nullable'] ?? true) && !in_array($name, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
                $required[] = $name;
            }
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'description' => sprintf('Schema for table `%s` (excludes soft delete field%s)', $table, [] !== $hidden ? ' and columns hidden via `columnHiddens`' : ''),
        ];
    }

    /**
     * @param array<string, mixed> $info
     * @return array<string, mixed>
     */
    private static function mapColumnToOpenApi(string $dbType, array $info = []): array
    {
        if (isset($info['enum']) && is_array($info['enum']) && !empty($info['enum'])) {
            return ['type' => 'string', 'enum' => array_values(array_map('strval', $info['enum']))];
        }

        $type = strtolower($dbType);

        // Parse enum('a','b')
        if (str_starts_with($type, 'enum(')) {
            $values = [];
            $inside = substr($type, 5, -1); // remove enum( and )
            foreach (explode(',', $inside) as $raw) {
                $values[] = trim($raw, "'\"");
            }

            return ['type' => 'string', 'enum' => $values];
        }

        // Boolean tinyint(1)
        if (str_starts_with($type, 'tinyint(1)')) {
            return ['type' => 'boolean'];
        }

        // Numeric
        if (str_starts_with($type, 'int') || str_starts_with($type, 'smallint')) {
            return ['type' => 'integer', 'format' => 'int32'];
        }

        if (str_starts_with($type, 'bigint')) {
            return ['type' => 'integer', 'format' => 'int64'];
        }

        if (str_starts_with($type, 'decimal') || str_starts_with($type, 'numeric')) {
            return ['type' => 'number', 'format' => 'decimal'];
        }

        if (str_starts_with($type, 'double') || str_starts_with($type, 'float')) {
            return ['type' => 'number', 'format' => 'double'];
        }

        // Date/time
        if ('date' === $type) {
            return ['type' => 'string', 'format' => 'date'];
        }

        if ('datetime' === $type || 'timestamp' === $type) {
            return ['type' => 'string', 'format' => 'date-time'];
        }

        // JSON
        if ('json' === $type) {
            return ['type' => 'object'];
        }

        // Default string
        return ['type' => 'string'];
    }

    /**
     * @return array<string, string|mixed[][]|int[]|string[]>
     */
    private static function tableSchemaRead(string $table, array $columns, ?RecordTableType $config = null): array
    {
        $properties = [];
        $required = [];

        // Exclude created_at, updated_at, and deleted_at for read operations
        $excludeFields = ['created_at', 'updated_at', 'deleted_at'];

        // Columns hidden from responses via columnHiddens are omitted too.
        $hidden = is_array($config?->columnHiddens ?? null) ? $config->columnHiddens : [];
        $hiddenSet = array_flip(array_filter($hidden, is_string(...)));

        foreach ($columns as $name => $info) {
            if (in_array($name, $excludeFields, true)) {
                continue;
            }

            if (isset($hiddenSet[$name])) {
                continue;
            }

            $mapped = self::mapColumnToOpenApi($info['type'] ?? 'string', $info);
            $properties[$name] = $mapped;
            // Avoid forcing typical system fields as required
            if (!(bool) ($info['nullable'] ?? true) && !in_array($name, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
                $required[] = $name;
            }
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'description' => sprintf('Read schema for table `%s` (excludes system timestamps, soft delete%s)', $table, [] !== $hidden ? ', and columns hidden via `columnHiddens`' : ''),
        ];
    }

    /**
     * @return array<string, string|mixed[][]|int[]|string[]>
     */
    private static function tableSchemaWrite(string $table, array $columns, ?RecordTableType $config = null): array
    {
        $properties = [];
        $required = [];

        // Exclude created_at, updated_at, and deleted_at for write operations
        $excludeFields = ['created_at', 'updated_at', 'deleted_at'];

        // Auto-increment primary keys are server-managed; uuid keys are optional
        // (generated server-side via Str::uuid() when omitted) — see RecordService.
        $idType = RecordConfigService::idType();
        if ('integer' === $idType) {
            $excludeFields[] = 'id';
        }

        // columnWriteDisabled fields are server-computed: sending them is a
        // deliberate silent no-op, so they are documented as read-only.
        $writeDisabled = is_array($config?->columnWriteDisabled ?? null) ? $config->columnWriteDisabled : [];
        $writeDisabledSet = array_flip(array_filter($writeDisabled, is_string(...)));

        foreach ($columns as $name => $info) {
            if (in_array($name, $excludeFields, true)) {
                continue;
            }

            $mapped = self::mapColumnToOpenApi($info['type'] ?? 'string', $info);
            if (isset($writeDisabledSet[$name])) {
                $mapped['readOnly'] = true;
                $mapped['description'] = 'Write-disabled (columnWriteDisabled): sending this field is ignored (silent no-op).';
            }

            if ('uuid' === $idType && 'id' === $name) {
                // Primary keys hold server-generated UUID strings regardless of
                // the underlying column type mapping.
                $mapped = ['type' => 'string', 'format' => 'uuid'];
            }

            $properties[$name] = $mapped;
            // Avoid forcing typical system fields as required
            if (!(bool) ($info['nullable'] ?? true) && !in_array($name, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
                $required[] = $name;
            }
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'description' => sprintf(
                'Write schema for table `%s` (excludes system-managed fields%s).%s',
                $table,
                [] !== $writeDisabled ? '; marks `columnWriteDisabled` fields as read-only' : '',
                'integer' === $idType ? ' `id` is auto-increment and must not be sent.' : ' `id` is a UUID; omit it to have the server generate one.'
            ),
        ];
    }

    /**
     * @param array<string, RecordTableType> $tables
     */
    private static function tags(array $tables, array $globalFunctions): array
    {
        $tags = [];
        foreach (array_keys($tables) as $table) {
            $formattedTableName = ucwords(str_replace('_', ' ', $table));
            $tags[] = ['name' => $formattedTableName, 'description' => sprintf('Operations for `%s` records', $formattedTableName)];
        }

        $tags[] = ['name' => 'RPC', 'description' => 'Global RPC functions'];

        $rpcGroups = [];
        foreach (array_keys($globalFunctions) as $functionName) {
            if (!is_string($functionName)) {
                continue;
            }

            if ($functionName === '') {
                continue;
            }

            $segments = explode('/', trim($functionName, '/'));
            $group = $segments[0] ?? '';
            if ($group !== '' && $group !== $functionName) {
                $rpcGroups[$group] = true;
            }
        }

        foreach (array_keys($rpcGroups) as $group) {
            $formatted = ucwords(str_replace('_', ' ', $group));
            $tags[] = ['name' => 'RPC - ' . $formatted, 'description' => sprintf('Global RPC functions under `%s/*`', $group)];
        }

        return $tags;
    }

    /**
     * @param array<string, RecordTableType> $tables
     */
    /**
     * @return array{}|array<int, array<string, array{}>>
     */
    /**
     * Describe one bulk endpoint.
     *
     * The bulk routes accept the item list either as a bare JSON array or wrapped
     * in the envelope the guide documents (`{"data": [...]}`, or `{"ids": [...]}`
     * for delete), so both shapes are advertised. The success payload is the list
     * of affected records with the count in `meta.affected` — matching what
     * HasBulkOperations actually returns.
     *
     * @param array<string, mixed> $itemSchema
     * @return array<string, mixed>
     */
    private static function bulkOperationDocs(string $formattedRecordName, string $label, string $envelopeKey, array $itemSchema, bool $isAuthWrite): array
    {
        $bareList = ['type' => 'array', 'items' => $itemSchema];
        $envelope = [
            'type' => 'object',
            'properties' => [$envelopeKey => $bareList],
            'required' => [$envelopeKey],
        ];

        return [
            'tags' => [$formattedRecordName],
            'summary' => 'Bulk ' . $label . ' ' . $formattedRecordName,
            'description' => sprintf(
                'Bulk %s %s records in one request. Send a bare JSON array or the wrapped form {"%s": [...]}; both are accepted.',
                strtolower($label),
                $formattedRecordName,
                $envelopeKey,
            ),
            'requestBody' => [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => ['oneOf' => [$envelope, $bareList]],
                    ],
                ],
            ],
            'responses' => [
                '200' => [
                    'description' => 'Bulk ' . $label . 'd',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'success' => ['type' => 'boolean', 'example' => true],
                                    'error_code' => ['type' => 'integer', 'example' => HttpErrorCodeConstant::SUCCESS],
                                    'data' => ['type' => 'array', 'items' => ['type' => 'object']],
                                    'meta' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'request_id' => ['type' => 'string'],
                                            'affected' => ['type' => 'integer'],
                                        ],
                                    ],
                                ],
                                'required' => ['success', 'error_code', 'data', 'meta'],
                            ],
                        ],
                    ],
                ],
                '422' => [
                    'description' => 'Validation error',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'success' => ['type' => 'boolean', 'example' => false],
                                    'error_code' => ['type' => 'integer', 'example' => HttpErrorCodeConstant::INVALID_REQUEST],
                                    'message' => ['type' => 'string', 'example' => 'Validation failed'],
                                    'errors' => ['type' => 'object'],
                                    'meta' => [
                                        'type' => 'object',
                                        'properties' => ['request_id' => ['type' => 'string']],
                                    ],
                                ],
                                'required' => ['success', 'error_code', 'message', 'meta'],
                            ],
                        ],
                    ],
                ],
            ],
            'security' => self::security($isAuthWrite),
        ];
    }

    private static function security(bool $requiresAuth): array
    {
        return $requiresAuth ? [['bearerAuth' => []]] : [];
    }

    /**
     * Append Authorization documentation to an OpenAPI operation: a
     * human-readable description block plus the machine-readable `x-sp-auth`
     * extension, both derived from the table/function config.
     *
     * @param array<string, mixed> $operation
     * @param string[]             $permissions
     * @param string[]             $middleware
     *
     * @return array<string, mixed>
     */
    private static function appendAuthDocs(
        array $operation,
        string $mode,
        string $flag,
        bool $flagValue,
        bool $public,
        string $source,
        array $permissions = [],
        array $middleware = [],
        bool $tenant = false
    ): array {
        $modeLabel = 'function' === $mode ? 'function call' : $mode . ' auth';

        $lines = $public
            ? [sprintf('**Authorization:** Public — no authentication required (`%s=%s` in %s)', $flag, $flagValue ? 'true' : 'false', $source)]
            : [sprintf('**Authorization:** Bearer token required — %s (`%s=%s` in %s)', $modeLabel, $flag, $flagValue ? 'true' : 'false', $source)];

        if ([] !== $permissions) {
            $lines[] = '**Permission scope(s):** ' . implode(', ', array_map(
                static fn(string $scope): string => '`' . $scope . '`',
                $permissions
            ));
        }

        if ([] !== $middleware) {
            $lines[] = '**Route middleware:** ' . implode(', ', array_map(
                static fn(string $middleware): string => '`' . $middleware . '`',
                $middleware
            ));
        }

        $operation = self::appendNote($operation, implode("\n", $lines));

        $operation['x-sp-auth'] = [
            'auth' => $public ? 'public' : 'bearer',
            'mode' => $mode,
            'flag' => $flag,
            'flag_value' => $flagValue,
            'public' => $public,
            'permissions' => $permissions,
            'middleware' => $middleware,
            'tenant' => $tenant,
            'source' => $source,
        ];

        return $operation;
    }

    /**
     * Append a documentation block to an operation's description.
     *
     * @param array<string, mixed> $operation
     *
     * @return array<string, mixed>
     */
    private static function appendNote(array $operation, string $note): array
    {
        $description = (string) ($operation['description'] ?? '');
        $operation['description'] = '' === $description ? $note : $description . "\n\n" . $note;

        return $operation;
    }

    /**
     * Build the Authorization docs for a table CRUD operation.
     *
     * @param array<string, mixed> $operation
     *
     * @return array<string, mixed>
     */
    private static function tableOperationDocs(
        array $operation,
        RecordTableType $config,
        string $recordName,
        string $action,
        bool $isRead,
        string $source,
        bool $tenant
    ): array {
        $flag = $isRead ? 'isAuthRead' : 'isAuthWrite';
        $flagValue = $isRead ? (bool) $config->isAuthRead : (bool) $config->isAuthWrite;
        $permissionAction = in_array($action, ['list', 'show'], true) ? 'read' : $action;

        return self::appendAuthDocs(
            $operation,
            mode: $isRead ? 'read' : 'write',
            flag: $flag,
            flagValue: $flagValue,
            public: !$flagValue,
            source: $source,
            permissions: self::permissionScopesForAction($config, $permissionAction),
            middleware: self::middlewareForAction($recordName, $action),
            tenant: $tenant,
        );
    }

    /**
     * Build the Authorization docs for a function (global or table RPC) operation.
     *
     * @param array<string, mixed> $operation
     *
     * @return array<string, mixed>
     */
    private static function functionOperationDocs(
        array $operation,
        object $functionConfig,
        bool $isPublic,
        string $source,
        string $table,
        string $action
    ): array {
        return self::appendAuthDocs(
            $operation,
            mode: 'function',
            flag: 'isPublic',
            flagValue: $isPublic,
            public: $isPublic,
            source: $source,
            permissions: [],
            middleware: self::middlewareForAction($table, $action, $functionConfig instanceof RecordFunctionType ? $functionConfig : null),
            tenant: false,
        );
    }

    /**
     * Resolve the permission scopes declared for an action on a table config.
     *
     * @return string[]
     */
    private static function permissionScopesForAction(RecordTableType $config, string $action): array
    {
        $permissions = $config->permissions ?? null;
        if (!is_array($permissions) || !isset($permissions[$action])) {
            return [];
        }

        $scopes = $permissions[$action];

        return array_values(array_filter(
            array_map(
                static fn(mixed $scope): string => is_string($scope) ? trim($scope) : '',
                is_array($scopes) ? $scopes : [$scopes]
            ),
            static fn(string $scope): bool => '' !== $scope
        ));
    }

    /**
     * Resolve the route middleware stack for an action with the same
     * precedence as RecordRouteMiddleware: a function-level `middleware`
     * setting replaces the map; otherwise default + per-table maps merge
     * across `*`, the action group, and the exact action.
     *
     * @return string[]
     */
    private static function middlewareForAction(string $table, string $action, ?RecordFunctionType $function = null): array
    {
        $map = RecordConfigService::middlewareMap();
        $globalMap = is_array($map['default'] ?? null) ? $map['default'] : [];
        $tablesMap = is_array($map['tables'] ?? null) ? $map['tables'] : [];
        $tableMap = is_array($tablesMap[$table] ?? null) ? $tablesMap[$table] : [];

        if (('table_function' === $action || 'global_function' === $action) && ($function instanceof RecordFunctionType && null !== $function->middleware)) {
            return self::resolveMiddlewareAliases(self::normalizeMiddleware($function->middleware));
        }

        $group = self::middlewareActionGroup($action);

        return self::resolveMiddlewareAliases(array_merge(
            self::resolveMiddlewareMapForAction($globalMap, $group, $action),
            self::resolveMiddlewareMapForAction($tableMap, $group, $action)
        ));
    }

    /**
     * @param array<string, mixed> $map
     *
     * @return string[]
     */
    private static function resolveMiddlewareMapForAction(array $map, string $group, string $action): array
    {
        return array_merge(
            self::normalizeMiddleware($map['*'] ?? []),
            self::normalizeMiddleware($map[$group] ?? []),
            self::normalizeMiddleware($map[$action] ?? [])
        );
    }

    private static function middlewareActionGroup(string $action): string
    {
        return match ($action) {
            'list', 'show' => 'read',
            'create', 'update', 'delete', 'restore', 'force_delete', 'upsert', 'bulk', 'bulk_create', 'bulk_update', 'bulk_delete', 'bulk_upsert' => 'write',
            'table_function', 'global_function' => 'function',
            default => 'misc',
        };
    }

    /**
     * @return string[]
     */
    private static function normalizeMiddleware(mixed $middlewares): array
    {
        if (is_string($middlewares)) {
            $middlewares = [$middlewares];
        }

        if (!is_array($middlewares)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn(mixed $middleware): string => is_string($middleware) ? trim($middleware) : '', $middlewares),
            static fn(string $middleware): bool => '' !== $middleware
        ));
    }

    /**
     * Resolve middleware aliases to class names, mirroring
     * RecordRouteMiddleware::sanitizeMiddlewares().
     *
     * @param string[] $middlewares
     *
     * @return string[]
     */
    private static function resolveMiddlewareAliases(array $middlewares): array
    {
        /** @var Router $router */
        $router = app('router');
        $aliases = $router->getMiddleware();

        $result = [];
        foreach ($middlewares as $middleware) {
            [$name, $params] = array_pad(explode(':', $middleware, 2), 2, null);
            $resolved = $aliases[$name] ?? $name;
            if (is_string($params) && '' !== $params) {
                $resolved .= ':' . $params;
            }

            if (str_starts_with((string) $resolved, RecordRouteMiddleware::class)) {
                continue;
            }

            if (str_starts_with($middleware, 'record.route.middleware')) {
                continue;
            }

            $result[] = $resolved;
        }

        return array_values(array_unique($result));
    }

    /**
     * One-line pagination note reflecting the configured defaults, appended
     * to list operations.
     */
    private static function paginationDefaultNote(): string
    {
        return sprintf(
            '**Pagination:** Default mode: `%s` (config `record.pagination.default_mode`); cursor pagination via `cursor` parameter (default cursor column `%s`).',
            RecordConfigService::paginationDefaultMode(),
            RecordConfigService::cursorDefaultColumn()
        );
    }

    /**
     * Markdown summary of configured per-table rate limit overrides for the
     * main document's Rate Limits section.
     */
    private static function rateLimitsSummary(): string
    {
        $overrides = (array) config('record.rate_limits', []);
        $lines = [];

        foreach ($overrides as $table => $rules) {
            if (!is_array($rules)) {
                continue;
            }

            $parts = [];
            foreach ($rules as $action => $rule) {
                if (!is_array($rule)) {
                    continue;
                }

                $limit = (int) ($rule['limit'] ?? 0);
                $decay = (int) ($rule['decay_minutes'] ?? 1);
                $parts[] = sprintf('%s: %d/%dmin', $action, $limit, $decay);
            }

            if ([] !== $parts) {
                $lines[] = sprintf(' - `%s` — %s', $table, implode(', ', $parts));
            }
        }

        if ([] === $lines) {
            return ' none (global `api-reads` / `api-writes` / `api-functions` limits apply)';
        }

        return "\n" . implode("\n", $lines);
    }

    private static function paths(array $tables): array
    {
        $apiPrefix = RecordConfigService::apiPrefix();
        $paths = [];

        foreach ($tables as $recordName => $config) {
            $actualTableName = $config->table ?? $recordName; // Use actual table name from config
            $formattedRecordName = ucwords(str_replace('_', ' ', $recordName));
            $formattedTableName = ucwords(str_replace('_', ' ', $actualTableName));
            $schemaRef = '#/components/schemas/' . self::schemaName($recordName);
            $schemaRefRead = '#/components/schemas/' . self::schemaName($recordName) . 'Read';
            $schemaRefWrite = '#/components/schemas/' . self::schemaName($recordName) . 'Write';
            $tenantHeaderParameters = self::tenantHeaderParametersForTableConfig($config);
            $canRead = (bool) ($config->canRead ?? true);
            $canCreate = (bool) ($config->canCreate ?? true);
            $canUpdate = (bool) ($config->canUpdate ?? true);
            $canDelete = (bool) ($config->canDelete ?? true);
            $canUpsert = (bool) ($config->canUpsert ?? true);

            $tableConfigSource = 'config/records/tables/' . $recordName . '.php';
            $tenantScoped = (bool) ($config->hasTenantId ?? false);

            // Generate relationship description
            $relationshipDescription = self::generateRelationshipDescription($recordName, $config);

            $maxPerPage = RecordConfigService::perPageMax();
            $defaultPaginationMode = RecordConfigService::paginationDefaultMode();
            $compositeCursorsEnabled = RecordConfigService::cursorCompositeEnabled();
            $defaultPerPage = 25;
            $defaultMode = RecordConfigService::paginationDefaultMode();
            $skipTotalDefault = RecordConfigService::skipTotalDefault();
            $defaultCursorColumn = RecordConfigService::cursorDefaultColumn();

            // List & create (API endpoints use record name, but descriptions reference actual table)
            $basePath = '/' . $apiPrefix . '/' . $recordName;
            $columns = $config->columns ?? [];
            $paginationParameters = [
                [
                    'name' => 'page',
                    'in' => 'query',
                    'required' => false,
                    'description' => "Page number (offset pagination, default: 1). Not used when `cursor` is provided.",
                    'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                ],
                [
                    'name' => 'per_page',
                    'in' => 'query',
                    'required' => false,
                    'description' => sprintf('Items per page (default: %d, max: %d)', $defaultPerPage, $maxPerPage),
                    'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => $maxPerPage, 'default' => $defaultPerPage],
                ],
                [
                    'name' => 'cursor',
                    'in' => 'query',
                    'required' => false,
                    'description' => "Cursor value for cursor-based pagination. When present, `page` is ignored. See [pagination docs](#description/-pagination).",
                    'schema' => ['type' => 'string'],
                ],
                [
                    'name' => 'direction',
                    'in' => 'query',
                    'required' => false,
                    'description' => "Cursor direction (default: `next`). Only used with `cursor`.",
                    'schema' => ['type' => 'string', 'enum' => ['next', 'prev'], 'default' => 'next'],
                ],
                [
                    'name' => 'cursor_column',
                    'in' => 'query',
                    'required' => false,
                    'description' => sprintf('Column to use for cursor pagination. Defaults to the column the list is sorted by (whatever `sortby` resolves to — `created_at` when the table has one, otherwise the primary key), so the cursor always continues the applied ordering. Falls back to the configured `%s` when neither applies.', $defaultCursorColumn),
                    'schema' => ['type' => 'string'],
                ],
                [
                    'name' => 'total',
                    'in' => 'query',
                    'required' => false,
                    'description' => "Include total count metadata. Use `total=false` to skip the COUNT(*) query for performance, or `total=true` to include totals even when `pagination.skip_total_default=true`. Only boolean-like values are treated as this control; non-boolean values such as `total=gte.100` remain normal column filters.",
                    'schema' => ['type' => 'boolean', 'default' => !$skipTotalDefault],
                ],
                [
                    'name' => 'skip_total',
                    'in' => 'query',
                    'required' => false,
                    'deprecated' => true,
                    'description' => "Legacy alias for `total=false`. Skip the total count query for performance (default: " . ($skipTotalDefault ? 'true' : 'false') . ", configurable via `pagination.skip_total_default`).",
                    'schema' => ['type' => 'boolean', 'default' => $skipTotalDefault],
                ],
            ];

            $getOnlyParameters = array_merge(
                $paginationParameters,
                self::queryShapeParameters($columns, $config->relationships ?? [], $config->searchable ?? []),
                self::columnFilterParameters($columns)
            );

            $listMetaProperties = [
                'request_id' => ['type' => 'string'],
                'total' => ['type' => 'integer', 'description' => 'Total records. Omitted when total=false or skip_total=true.'],
                'per_page' => ['type' => 'integer'],
                'current_page' => ['type' => 'integer', 'description' => 'Current page (offset pagination). Omitted during cursor pagination.'],
                'last_page' => ['type' => 'integer', 'description' => 'Last page number (offset pagination). Omitted during cursor pagination, total=false, or skip_total=true.'],
                'from' => ['type' => 'integer', 'description' => 'Starting record number (offset pagination). Omitted during cursor pagination, total=false, or skip_total=true.'],
                'to' => ['type' => 'integer', 'description' => 'Ending record number (offset pagination). Omitted during cursor pagination, total=false, or skip_total=true.'],
                'cursor' => ['type' => 'string', 'description' => 'Next cursor value (cursor pagination). Omitted during offset pagination.'],
                'direction' => ['type' => 'string', 'description' => 'Cursor direction (cursor pagination). Omitted during offset pagination.'],
                'cursor_column' => ['type' => 'string', 'description' => 'Cursor column used (cursor pagination). Omitted during offset pagination.'],
                'first_cursor' => ['type' => 'string', 'description' => 'Cursor to jump to first page (cursor pagination). Omitted when total=false or skip_total=true.'],
                'last_cursor' => ['type' => 'string', 'description' => 'Cursor to jump to last page (cursor pagination). Omitted when total=false or skip_total=true.'],
            ];
            $paths[$basePath] = array_filter([
                // Shared by GET and POST on this path — list-only params (pagination, filters,
                // select/sortby/order/search) live on the 'get' operation below instead, so they
                // aren't misrepresented as also applying to POST (create).
                'parameters' => $tenantHeaderParameters,
                'get' => $canRead ? [
                    'tags' => [$formattedRecordName],
                    'summary' => 'List ' . $formattedRecordName,
                    'description' => "Retrieve {$formattedRecordName} records with comprehensive query capabilities:\n\n**Advanced Filtering:** Multiple operators ([Filter](#description/-getting-started))\n\n{$relationshipDescription}",
                    'parameters' => $getOnlyParameters,
                    'responses' => [
                        '200' => [
                            'description' => 'Successful response',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'success' => ['type' => 'boolean', 'example' => true],
                                            'error_code' => ['type' => 'integer', 'example' => HttpErrorCodeConstant::SUCCESS],
                                            'data' => [
                                                'type' => 'array',
                                                'items' => ['$ref' => $schemaRefRead],
                                            ],
                                            'meta' => [
                                                'type' => 'object',
                                                'properties' => $listMetaProperties,
                                            ],
                                        ],
                                        'required' => ['success', 'error_code', 'data', 'meta'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'security' => self::security($config->isAuthRead),
                ] : [],
                'post' => $canCreate ? [
                    'tags' => [$formattedRecordName],
                    'summary' => 'Create ' . $formattedRecordName,
                    'description' => "Create a new {$formattedRecordName} record with comprehensive validation:\n\n**Advanced Validation:** Multiple rules ([Validation](#description/-getting-started))\n\n{$relationshipDescription}",
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => $schemaRefWrite],
                            ],
                        ],
                    ],
                    'responses' => [
                        '201' => [
                            'description' => 'Created',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'success' => ['type' => 'boolean', 'example' => true],
                                            'error_code' => ['type' => 'integer', 'example' => HttpErrorCodeConstant::SUCCESS],
                                            'data' => ['$ref' => $schemaRef],
                                            'meta' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'request_id' => ['type' => 'string'],
                                                ],
                                            ],
                                        ],
                                        'required' => ['success', 'error_code', 'data', 'meta'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'security' => self::security($config->isAuthWrite),
                ] : [],
            ], static fn(mixed $value): bool => [] !== $value);

            if (isset($paths[$basePath]['get'])) {
                $filterDocumentation = self::filterDocumentation();
                if (null !== $filterDocumentation) {
                    $paths[$basePath]['get']['externalDocs'] = $filterDocumentation;
                }

                $paths[$basePath]['get'] = self::appendNote(
                    self::tableOperationDocs($paths[$basePath]['get'], $config, $recordName, 'list', true, $tableConfigSource, $tenantScoped),
                    self::paginationDefaultNote()
                );
            }

            if (isset($paths[$basePath]['post'])) {
                $paths[$basePath]['post'] = self::tableOperationDocs($paths[$basePath]['post'], $config, $recordName, 'create', false, $tableConfigSource, $tenantScoped);
            }

            if (!isset($paths[$basePath]['get']) && !isset($paths[$basePath]['post'])) {
                unset($paths[$basePath]);
            }

            // Upsert
            if ($canUpsert) {
                $upsertPath = $basePath . '/upsert';
                $paths[$upsertPath] = [
                    'parameters' => array_merge($tenantHeaderParameters, [
                        [
                            'name' => 'match_on',
                            'in' => 'query',
                            'required' => true,
                            'description' => 'Comma-separated list of columns to use for matching records (e.g. "sku,name")',
                            'schema' => ['type' => 'string'],
                        ],
                    ]),
                    'post' => [
                        'tags' => [$formattedRecordName],
                        'summary' => 'Upsert ' . $formattedRecordName,
                        'description' => "Create or update a {$formattedRecordName} record based on match_on columns.\n\n{$relationshipDescription}",
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => $schemaRefWrite],
                                ],
                            ],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Upserted',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'success' => ['type' => 'boolean', 'example' => true],
                                                'error_code' => ['type' => 'integer', 'example' => HttpErrorCodeConstant::SUCCESS],
                                                'data' => ['$ref' => $schemaRef],
                                                'meta' => [
                                                    'type' => 'object',
                                                    'properties' => [
                                                        'request_id' => ['type' => 'string'],
                                                    ],
                                                ],
                                            ],
                                            'required' => ['success', 'error_code', 'data', 'meta'],
                                        ],
                                    ],
                                ],
                            ],
                            '400' => [
                                'description' => 'Validation Error',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'success' => ['type' => 'boolean', 'example' => false],
                                                'error_code' => ['type' => 'integer', 'example' => HttpErrorCodeConstant::INVALID_REQUEST],
                                                'message' => ['type' => 'string', 'example' => 'Validation failed'],
                                                'errors' => ['type' => 'array', 'items' => ['type' => 'string']],
                                                'meta' => [
                                                    'type' => 'object',
                                                    'properties' => [
                                                        'request_id' => ['type' => 'string'],
                                                    ],
                                                ],
                                            ],
                                            'required' => ['success', 'error_code', 'message', 'errors', 'meta'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'security' => self::security($config->isAuthWrite),
                    ],
                ];

                $paths[$upsertPath]['post'] = self::tableOperationDocs($paths[$upsertPath]['post'], $config, $recordName, 'upsert', false, $tableConfigSource, $tenantScoped);

                // Bulk Upsert
                $bulkUpsertPath = $basePath . '/bulk/upsert';
                $paths[$bulkUpsertPath] = [
                    'parameters' => array_merge($tenantHeaderParameters, [
                        [
                            'name' => 'match_on',
                            'in' => 'query',
                            'required' => true,
                            'description' => 'Comma-separated list of columns to use for matching records (e.g. "sku,name")',
                            'schema' => ['type' => 'string'],
                        ],
                    ]),
                    'post' => [
                        'tags' => [$formattedRecordName],
                        'summary' => 'Bulk Upsert ' . $formattedRecordName,
                        'description' => "Bulk create or update {$formattedRecordName} records based on match_on columns.\n\n{$relationshipDescription}",
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'array',
                                        'items' => ['$ref' => $schemaRefWrite],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Bulk Upserted',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'success' => ['type' => 'boolean', 'example' => true],
                                                'error_code' => ['type' => 'integer', 'example' => HttpErrorCodeConstant::SUCCESS],
                                                'data' => [
                                                    'type' => 'object',
                                                    'properties' => [
                                                        'count' => ['type' => 'integer'],
                                                    ],
                                                ],
                                                'meta' => [
                                                    'type' => 'object',
                                                    'properties' => [
                                                        'request_id' => ['type' => 'string'],
                                                        'total' => ['type' => 'integer'],
                                                    ],
                                                ],
                                            ],
                                            'required' => ['success', 'error_code', 'data', 'meta'],
                                        ],
                                    ],
                                ],
                            ],
                            '400' => [
                                'description' => 'Validation Error',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'success' => ['type' => 'boolean', 'example' => false],
                                                'error_code' => ['type' => 'integer', 'example' => HttpErrorCodeConstant::INVALID_REQUEST],
                                                'message' => ['type' => 'string', 'example' => 'Validation failed'],
                                                'errors' => ['type' => 'array', 'items' => ['type' => 'string']],
                                                'meta' => [
                                                    'type' => 'object',
                                                    'properties' => [
                                                        'request_id' => ['type' => 'string'],
                                                    ],
                                                ],
                                            ],
                                            'required' => ['success', 'error_code', 'message', 'errors', 'meta'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'security' => self::security($config->isAuthWrite),
                    ],
                ];

                $paths[$bulkUpsertPath]['post'] = self::tableOperationDocs($paths[$bulkUpsertPath]['post'], $config, $recordName, 'bulk_upsert', false, $tableConfigSource, $tenantScoped);
            }

            // Bulk create / update / delete. routes/api.php registers these only when
            // bulk operations are enabled, and gates each on the same can* flag, so the
            // spec mirrors that rather than advertising endpoints that 404.
            if (RecordConfigService::bulkOperationsEnabled()) {
                $bulkOperations = [];

                if ($canCreate) {
                    $bulkOperations['create'] = ['Create', 'data', ['$ref' => $schemaRefWrite]];
                }

                if ($canUpdate) {
                    $bulkOperations['update'] = ['Update', 'data', ['$ref' => $schemaRefWrite]];
                }

                if ($canDelete) {
                    $bulkOperations['delete'] = ['Delete', 'ids', ['type' => 'object']];
                }

                foreach ($bulkOperations as $operation => [$label, $envelopeKey, $itemSchema]) {
                    $bulkPath = $basePath . '/bulk/' . $operation;
                    $paths[$bulkPath] = [
                        'parameters' => $tenantHeaderParameters,
                        'post' => self::bulkOperationDocs(
                            formattedRecordName: $formattedRecordName,
                            label: $label,
                            envelopeKey: $envelopeKey,
                            itemSchema: $itemSchema,
                            isAuthWrite: (bool) $config->isAuthWrite,
                        ),
                    ];

                    $paths[$bulkPath]['post'] = self::tableOperationDocs($paths[$bulkPath]['post'], $config, $recordName, 'bulk_' . $operation, false, $tableConfigSource, $tenantScoped);
                }
            }

            // Read/Update/Delete
            $idPath = $basePath . '/{id}';
            $paths[$idPath] = array_filter([
                'parameters' => array_merge([self::pathIdParameter()], $tenantHeaderParameters),
                'get' => $canRead ? [
                    'tags' => [$formattedRecordName],
                    'summary' => sprintf('Get %s by ID', $formattedRecordName),
                    'responses' => [
                        '200' => [
                            'description' => 'Successful response',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'success' => ['type' => 'boolean', 'example' => true],
                                            'error_code' => ['type' => 'integer', 'example' => HttpErrorCodeConstant::SUCCESS],
                                            'data' => ['$ref' => $schemaRefRead],
                                            'meta' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'request_id' => ['type' => 'string'],
                                                ],
                                            ],
                                        ],
                                        'required' => ['success', 'error_code', 'data', 'meta'],
                                    ],
                                ],
                            ],
                        ],
                        '404' => [
                            'description' => 'Not Found',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'success' => ['type' => 'boolean', 'example' => false],
                                            'error_code' => ['type' => 'integer', 'example' => HttpErrorCodeConstant::RESOURCE_NOT_FOUND],
                                            'message' => ['type' => 'string', 'example' => 'Record not found'],
                                            'errors' => ['type' => 'array', 'items' => ['type' => 'string']],
                                            'meta' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'request_id' => ['type' => 'string'],
                                                ],
                                            ],
                                        ],
                                        'required' => ['success', 'error_code', 'message', 'errors', 'meta'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'security' => self::security($config->isAuthRead),
                ] : [],
                'put' => $canUpdate ? [
                    'tags' => [$formattedRecordName],
                    'summary' => 'Update ' . $formattedRecordName,
                    'description' => "Update an existing {$formattedRecordName} record with comprehensive validation:\n\n**Advanced Validation:** Multiple rules ([Validation](#description/-getting-started))\n\n{$relationshipDescription}",
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => $schemaRefWrite],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Updated',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'success' => ['type' => 'boolean', 'example' => true],
                                            'data' => ['$ref' => $schemaRef],
                                            'meta' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'request_id' => ['type' => 'string'],
                                                ],
                                            ],
                                        ],
                                        'required' => ['success', 'data', 'meta'],
                                    ],
                                ],
                            ],
                        ],
                        '404' => [
                            'description' => 'Not Found',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'success' => ['type' => 'boolean', 'example' => false],
                                            'message' => ['type' => 'string', 'example' => 'Record not found'],
                                            'errors' => ['type' => 'array', 'items' => ['type' => 'string']],
                                            'meta' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'request_id' => ['type' => 'string'],
                                                ],
                                            ],
                                        ],
                                        'required' => ['success', 'message', 'errors', 'meta'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'security' => self::security($config->isAuthWrite),
                ] : [],
                'delete' => $canDelete ? [
                    'tags' => [$formattedRecordName],
                    'summary' => 'Delete ' . $formattedRecordName,
                    'description' => sprintf('Delete an existing %s record.', $formattedRecordName),
                    'responses' => [
                        '200' => [
                            'description' => 'Deleted',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'success' => ['type' => 'boolean', 'example' => true],
                                            'data' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'deleted' => ['type' => 'integer', 'example' => 1],
                                                ],
                                            ],
                                            'meta' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'request_id' => ['type' => 'string'],
                                                ],
                                            ],
                                        ],
                                        'required' => ['success', 'data', 'meta'],
                                    ],
                                ],
                            ],
                        ],
                        '404' => [
                            'description' => 'Not Found',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'success' => ['type' => 'boolean', 'example' => false],
                                            'message' => ['type' => 'string', 'example' => 'Record not found'],
                                            'errors' => ['type' => 'array', 'items' => ['type' => 'string']],
                                            'meta' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'request_id' => ['type' => 'string'],
                                                ],
                                            ],
                                        ],
                                        'required' => ['success', 'message', 'errors', 'meta'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'security' => self::security($config->isAuthWrite),
                ] : [],
            ], static fn(mixed $value): bool => [] !== $value);

            if (isset($paths[$idPath]['get'])) {
                $paths[$idPath]['get'] = self::tableOperationDocs($paths[$idPath]['get'], $config, $recordName, 'show', true, $tableConfigSource, $tenantScoped);
            }

            if (isset($paths[$idPath]['put'])) {
                $paths[$idPath]['put'] = self::tableOperationDocs($paths[$idPath]['put'], $config, $recordName, 'update', false, $tableConfigSource, $tenantScoped);
            }

            if (isset($paths[$idPath]['delete'])) {
                $paths[$idPath]['delete'] = self::tableOperationDocs($paths[$idPath]['delete'], $config, $recordName, 'delete', false, $tableConfigSource, $tenantScoped);
            }

            $idPathOperations = array_diff_key($paths[$idPath], ['parameters' => true]);
            if ([] === $idPathOperations) {
                unset($paths[$idPath]);
            }

            // Restore
            if ($canUpdate && (bool) ($config->softDeletes ?? false)) {
                $paths[$basePath . '/{id}/restore'] = [
                    'parameters' => [self::pathIdParameter()],
                    'post' => [
                        'tags' => [$formattedRecordName],
                        'summary' => 'Restore ' . $formattedRecordName,
                        'description' => sprintf('Restore a deleted %s record.', $formattedRecordName),
                        'responses' => [
                            '200' => [
                                'description' => 'Restored',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'message' => ['type' => 'string', 'example' => 'Record restored successfully'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            '404' => [
                                'description' => 'Not Found',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'success' => ['type' => 'boolean', 'example' => false],
                                                'message' => ['type' => 'string', 'example' => 'Record not found'],
                                                'errors' => ['type' => 'array', 'items' => ['type' => 'string']],
                                                'meta' => [
                                                    'type' => 'object',
                                                    'properties' => [
                                                        'request_id' => ['type' => 'string'],
                                                    ],
                                                ],
                                            ],
                                            'required' => ['success', 'message', 'errors', 'meta'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'security' => self::security($config->isAuthWrite),
                    ],
                ];

                $paths[$basePath . '/{id}/restore']['post'] = self::tableOperationDocs($paths[$basePath . '/{id}/restore']['post'], $config, $recordName, 'restore', false, $tableConfigSource, $tenantScoped);
            }

            // Force Delete
            if ($canDelete) {
                $paths[$basePath . '/{id}/force'] = [
                    'parameters' => [self::pathIdParameter()],
                    'delete' => [
                        'tags' => [$formattedRecordName],
                        'summary' => 'Force delete ' . $formattedRecordName,
                        'description' => sprintf('Force delete an existing %s record.', $formattedRecordName),
                        'responses' => [
                            '200' => [
                                'description' => 'Deleted',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'success' => ['type' => 'boolean', 'example' => true],
                                                'data' => [
                                                    'type' => 'object',
                                                    'properties' => [
                                                        'deleted' => ['type' => 'integer', 'example' => 1],
                                                    ],
                                                ],
                                                'meta' => [
                                                    'type' => 'object',
                                                    'properties' => [
                                                        'request_id' => ['type' => 'string'],
                                                    ],
                                                ],
                                            ],
                                            'required' => ['success', 'data', 'meta'],
                                        ],
                                    ],
                                ],
                            ],
                            '404' => [
                                'description' => 'Not Found',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'message' => ['type' => 'string', 'example' => 'Record not found'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'security' => self::security($config->isAuthWrite),
                    ],
                ];

                $paths[$basePath . '/{id}/force']['delete'] = self::tableOperationDocs($paths[$basePath . '/{id}/force']['delete'], $config, $recordName, 'force_delete', false, $tableConfigSource, $tenantScoped);
            }
        }

        return $paths;
    }

    /**
     * @param array<string, RecordTableType> $tables
     */
    private static function rpcPaths(array $tables, array $globalFunctions): array
    {
        $apiPrefix = RecordConfigService::apiPrefix();
        $rpcPrefix = RecordConfigService::rpcPrefix();
        $paths = [];

        // Global RPC Functions - Generate individual endpoints
        foreach ($globalFunctions as $functionName => $functionConfig) {
            // Normalize Array Config to Object (for legacy support)
            if (is_array($functionConfig)) {
                $functionConfig = (object) $functionConfig;
            }

            // Resolve Class-Based Config
            if (is_string($functionConfig) && class_exists($functionConfig)) {
                $instance = new $functionConfig();
                if ($instance instanceof RecordFunctionInterface) {
                    $functionConfig = $instance->toFunctionType();
                } elseif ($instance instanceof RecordFunctionType) {
                    $functionConfig = $instance;
                }
            }

            $allowedMethods = gettype($functionConfig->httpMethod) === 'string' ? [$functionConfig->httpMethod] : $functionConfig->httpMethod ?? ['GET'];
            $methodName = self::rpcMethodName($functionConfig, $functionName);
            $summary = sprintf('RPC - %s', $methodName);
            $description = sprintf('%s', $methodName);

            // Get schemas from config
            $querySchema = $functionConfig->querySchema ?? null;
            $payloadSchema = $functionConfig->payloadSchema ?? null;
            $responseSchema = $functionConfig->responseSchema ?? null;

            $endpoint = $rpcPrefix === ''
                ? '/' . $apiPrefix . '/' . $functionName
                : '/' . $apiPrefix . '/' . $rpcPrefix . '/' . $functionName;
            $paths[$endpoint] = [];

            foreach ($allowedMethods as $method) {
                $methodLower = strtolower((string) $method);

                $rpcTag = 'RPC';
                $segments = explode('/', trim((string) $functionName, '/'));
                if (count($segments) > 1 && $segments[0] !== '') {
                    $rpcTag = 'RPC - ' . ucwords(str_replace('_', ' ', $segments[0]));
                }

                // Determine response schema
                $successResponseSchema = [
                    'type' => 'object',
                    'properties' => [
                        'success' => ['type' => 'boolean'],
                        'data' => ['type' => 'object'],
                        'meta' => ['type' => 'object'],
                    ],
                ];

                if ($responseSchema) {
                    $successResponseSchema = $responseSchema;
                }

                $paths[$endpoint][$methodLower] = [
                    'tags' => [$rpcTag],
                    'summary' => $summary,
                    'description' => $description,
                    'operationId' => 'globalRpc' . ucfirst((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $functionName)) . ucfirst($methodLower),
                    'responses' => [
                        '200' => [
                            'description' => 'Successful response',
                            'content' => [
                                'application/json' => [
                                    'schema' => $successResponseSchema,
                                ],
                            ],
                        ],
                        '400' => [
                            'description' => 'Bad Request',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'success' => ['type' => 'boolean', 'example' => false],
                                            'message' => ['type' => 'string', 'example' => 'Bad request'],
                                            'errors' => ['type' => 'array', 'items' => ['type' => 'string']],
                                            'meta' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'request_id' => ['type' => 'string'],
                                                ],
                                            ],
                                        ],
                                        'required' => ['success', 'message', 'errors', 'meta'],
                                    ],
                                ],
                            ],
                        ],
                        '401' => [
                            'description' => 'Unauthorized',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'success' => ['type' => 'boolean', 'example' => false],
                                            'message' => ['type' => 'string', 'example' => 'Unauthorized'],
                                            'errors' => ['type' => 'array', 'items' => ['type' => 'string']],
                                            'meta' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'request_id' => ['type' => 'string'],
                                                ],
                                            ],
                                        ],
                                        'required' => ['success', 'message', 'errors', 'meta'],
                                    ],
                                ],
                            ],
                        ],
                        '403' => [
                            'description' => 'Forbidden',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'success' => ['type' => 'boolean', 'example' => false],
                                            'message' => ['type' => 'string', 'example' => 'Forbidden'],
                                            'errors' => ['type' => 'array', 'items' => ['type' => 'string']],
                                            'meta' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'request_id' => ['type' => 'string'],
                                                ],
                                            ],
                                        ],
                                        'required' => ['success', 'message', 'errors', 'meta'],
                                    ],
                                ],
                            ],
                        ],
                        '404' => [
                            'description' => 'Function not found',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'success' => ['type' => 'boolean', 'example' => false],
                                            'message' => ['type' => 'string', 'example' => 'Function not found'],
                                            'errors' => ['type' => 'array', 'items' => ['type' => 'string']],
                                            'meta' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'request_id' => ['type' => 'string'],
                                                ],
                                            ],
                                        ],
                                        'required' => ['success', 'message', 'errors', 'meta'],
                                    ],
                                ],
                            ],
                        ],
                        '500' => [
                            'description' => 'Internal Server Error',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'success' => ['type' => 'boolean', 'example' => false],
                                            'message' => ['type' => 'string', 'example' => 'Internal server error'],
                                            'errors' => ['type' => 'array', 'items' => ['type' => 'string']],
                                            'meta' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'request_id' => ['type' => 'string'],
                                                ],
                                            ],
                                        ],
                                        'required' => ['success', 'message', 'errors', 'meta'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'security' => self::security(!($functionConfig->isPublic ?? true)),
                ];

                $paths[$endpoint][$methodLower] = self::functionOperationDocs(
                    $paths[$endpoint][$methodLower],
                    $functionConfig,
                    (bool) ($functionConfig->isPublic ?? true),
                    'config/records/global-functions/' . $functionName . '.php',
                    '',
                    'global_function'
                );

                // Add request body for POST, PUT, PATCH methods
                if (in_array($methodLower, ['post', 'put', 'patch'])) {
                    $bodySchema = [
                        'type' => 'object',
                        'description' => 'Function parameters',
                    ];

                    if ($payloadSchema) {
                        $bodySchema = $payloadSchema;
                    }

                    $paths[$endpoint][$methodLower]['requestBody'] = [
                        'required' => !empty($payloadSchema['required']),
                        'content' => [
                            'application/json' => [
                                'schema' => $bodySchema,
                            ],
                        ],
                    ];
                }

                // Add query parameters for GET method
                if ('get' === $methodLower) {
                    if ($querySchema) {
                        $paths[$endpoint][$methodLower]['parameters'] = self::schemaToQueryParameters($querySchema);
                    } else {
                        $paths[$endpoint][$methodLower]['parameters'] = [
                            [
                                'name' => 'params',
                                'in' => 'query',
                                'required' => false,
                                'description' => 'Function parameters as JSON string',
                                'schema' => ['type' => 'string'],
                            ],
                        ];
                    }
                }
            }
        }

        // Table RPC Functions - Generate individual endpoints
        foreach ($tables as $tableName => $config) {
            $formattedTableName = ucwords(str_replace('_', ' ', $tableName));
            $functions = Arr::get((array) $config, 'functions', []);
            $tenantHeaderParameters = self::tenantHeaderParametersForTableConfig($config);
            $tableConfigSource = 'config/records/tables/' . $tableName . '.php';

            foreach ($functions as $functionName => $functionConfig) {
                // Normalize Array Config to Object (for legacy support)
                if (is_array($functionConfig)) {
                    $functionConfig = (object) $functionConfig;
                }

                // Resolve Class-Based Config
                if (is_string($functionConfig) && class_exists($functionConfig)) {
                    $instance = new $functionConfig();
                    if ($instance instanceof RecordFunctionInterface) {
                        $functionConfig = $instance->toFunctionType();
                    } elseif ($instance instanceof RecordFunctionType) {
                        $functionConfig = $instance;
                    }
                }

                $allowedMethods = gettype($functionConfig->httpMethod) === 'string' ? [$functionConfig->httpMethod] : $functionConfig->httpMethod ?? ['GET'];
                $methodName = self::rpcMethodName($functionConfig, $functionName);
                $summary = sprintf('RPC - %s', $methodName);
                $description = sprintf('%s', $methodName);

                // Get schemas from config
                $querySchema = $functionConfig->querySchema ?? null;
                $payloadSchema = $functionConfig->payloadSchema ?? null;
                $responseSchema = $functionConfig->responseSchema ?? null;

                // Handle parameterized endpoints like 'update/{id}'
                $endpoint = $rpcPrefix === ''
                    ? sprintf('/%s/%s/%s', $apiPrefix, $tableName, $functionName)
                    : sprintf('/%s/%s/%s/%s', $apiPrefix, $tableName, $rpcPrefix, $functionName);
                $paths[$endpoint] = [];

                // Check if function name contains parameters
                $hasParameters = str_contains((string) $functionName, '{');
                $parameters = [];

                if ($hasParameters) {
                    // Extract parameters from function name like 'update/{id}'
                    preg_match_all('/\{([^}]+)\}/', (string) $functionName, $matches);
                    foreach ($matches[1] as $paramName) {
                        $parameters[] = [
                            'name' => $paramName,
                            'in' => 'path',
                            'required' => true,
                            'description' => ucfirst($paramName) . ' parameter',
                            'schema' => ['type' => 'string'],
                        ];
                    }
                }

                foreach ($allowedMethods as $method) {
                    $methodLower = strtolower((string) $method);
                    $operationId = 'tableRpc' . ucfirst((string) $tableName) . ucfirst(str_replace(['{', '}', '/'], '', $functionName)) . ucfirst($methodLower);

                    // Determine response schema
                    $successResponseSchema = [
                        'type' => 'object',
                        'properties' => [
                            'success' => ['type' => 'boolean'],
                            'data' => ['type' => 'object'],
                            'meta' => ['type' => 'object'],
                        ],
                    ];

                    if ($responseSchema) {
                        $successResponseSchema = $responseSchema;
                    }

                    $paths[$endpoint][$methodLower] = [
                        'tags' => [$formattedTableName],
                        'summary' => $summary,
                        'description' => $description,
                        'operationId' => $operationId,
                        'parameters' => $tenantHeaderParameters,
                        'responses' => [
                            '200' => [
                                'description' => 'Successful response',
                                'content' => [
                                    'application/json' => [
                                        'schema' => $successResponseSchema,
                                    ],
                                ],
                            ],
                            '400' => [
                                'description' => 'Bad Request',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'success' => ['type' => 'boolean', 'example' => false],
                                                'message' => ['type' => 'string', 'example' => 'Bad request'],
                                                'errors' => ['type' => 'array', 'items' => ['type' => 'string']],
                                                'meta' => [
                                                    'type' => 'object',
                                                    'properties' => [
                                                        'request_id' => ['type' => 'string'],
                                                    ],
                                                ],
                                            ],
                                            'required' => ['success', 'message', 'errors', 'meta'],
                                        ],
                                    ],
                                ],
                            ],
                            '401' => [
                                'description' => 'Unauthorized',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'success' => ['type' => 'boolean', 'example' => false],
                                                'message' => ['type' => 'string', 'example' => 'Unauthorized'],
                                                'errors' => ['type' => 'array', 'items' => ['type' => 'string']],
                                                'meta' => [
                                                    'type' => 'object',
                                                    'properties' => [
                                                        'request_id' => ['type' => 'string'],
                                                    ],
                                                ],
                                            ],
                                            'required' => ['success', 'message', 'errors', 'meta'],
                                        ],
                                    ],
                                ],
                            ],
                            '403' => [
                                'description' => 'Forbidden',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'success' => ['type' => 'boolean', 'example' => false],
                                                'message' => ['type' => 'string', 'example' => 'Forbidden'],
                                                'errors' => ['type' => 'array', 'items' => ['type' => 'string']],
                                                'meta' => [
                                                    'type' => 'object',
                                                    'properties' => [
                                                        'request_id' => ['type' => 'string'],
                                                    ],
                                                ],
                                            ],
                                            'required' => ['success', 'message', 'errors', 'meta'],
                                        ],
                                    ],
                                ],
                            ],
                            '404' => [
                                'description' => 'Function not found',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'success' => ['type' => 'boolean', 'example' => false],
                                                'message' => ['type' => 'string', 'example' => 'Function not found'],
                                                'errors' => ['type' => 'array', 'items' => ['type' => 'string']],
                                                'meta' => [
                                                    'type' => 'object',
                                                    'properties' => [
                                                        'request_id' => ['type' => 'string'],
                                                    ],
                                                ],
                                            ],
                                            'required' => ['success', 'message', 'errors', 'meta'],
                                        ],
                                    ],
                                ],
                            ],
                            '500' => [
                                'description' => 'Internal Server Error',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'success' => ['type' => 'boolean', 'example' => false],
                                                'message' => ['type' => 'string', 'example' => 'Internal server error'],
                                                'errors' => ['type' => 'array', 'items' => ['type' => 'string']],
                                                'meta' => [
                                                    'type' => 'object',
                                                    'properties' => [
                                                        'request_id' => ['type' => 'string'],
                                                    ],
                                                ],
                                            ],
                                            'required' => ['success', 'message', 'errors', 'meta'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'security' => self::security(!($functionConfig->isPublic ?? false)),
                    ];

                    $paths[$endpoint][$methodLower] = self::functionOperationDocs(
                        $paths[$endpoint][$methodLower],
                        $functionConfig,
                        (bool) ($functionConfig->isPublic ?? false),
                        $tableConfigSource,
                        $tableName,
                        'table_function'
                    );

                    // Add path parameters if any
                    if (!empty($parameters)) {
                        $paths[$endpoint][$methodLower]['parameters'] = array_merge($paths[$endpoint][$methodLower]['parameters'], $parameters);
                    }

                    // Add request body for POST, PUT, PATCH methods
                    if (in_array($methodLower, ['post', 'put', 'patch'])) {
                        $bodySchema = [
                            'type' => 'object',
                            'description' => 'Function parameters',
                        ];

                        if ($payloadSchema) {
                            $bodySchema = $payloadSchema;
                        }

                        $paths[$endpoint][$methodLower]['requestBody'] = [
                            'required' => !empty($payloadSchema['required']),
                            'content' => [
                                'application/json' => [
                                    'schema' => $bodySchema,
                                ],
                            ],
                        ];
                    }

                    // Add query parameters for GET method
                    if ('get' === $methodLower) {
                        $queryParams = [];
                        if ($querySchema) {
                            $queryParams = self::schemaToQueryParameters($querySchema);
                        } elseif (empty($parameters)) {
                            // Only add default 'params' if no path parameters and no schema
                            $queryParams[] = [
                                'name' => 'params',
                                'in' => 'query',
                                'required' => false,
                                'description' => 'Function parameters as JSON string',
                                'schema' => ['type' => 'string'],
                            ];
                        }

                        if (!empty($queryParams)) {
                            if (!isset($paths[$endpoint][$methodLower]['parameters'])) {
                                $paths[$endpoint][$methodLower]['parameters'] = [];
                            }

                            $paths[$endpoint][$methodLower]['parameters'] = array_merge($paths[$endpoint][$methodLower]['parameters'], $queryParams);
                        }
                    }
                }
            }
        }

        return $paths;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function schemaToQueryParameters(array $schema): array
    {
        $parameters = [];
        $properties = $schema['properties'] ?? [];
        $required = $schema['required'] ?? [];

        foreach ($properties as $name => $propSchema) {
            $parameters[] = [
                'name' => $name,
                'in' => 'query',
                'required' => in_array($name, $required, true),
                'description' => $propSchema['description'] ?? '',
                'schema' => Arr::except($propSchema, ['description', 'required']),
            ];
        }

        return $parameters;
    }

    /**
     * @return array<string, array<string, string>|string|bool>
     */
    private static function pathIdParameter(): array
    {
        $idType = RecordConfigService::idType();
        $schema = 'integer' === $idType
            ? ['type' => 'integer', 'format' => 'int64']
            : ['type' => 'string', 'format' => 'uuid'];

        return [
            'name' => 'id',
            'in' => 'path',
            'required' => true,
            'schema' => $schema,
            'description' => 'Primary key of the record (' . ('integer' === $idType ? 'auto-increment integer' : 'UUID string') . '; governed by `record.id_type`).',
        ];
    }

    /**
     * @return array<string, string|bool|array<string, string>>
     */
    private static function tenantHeaderParameter(bool $required = true): array
    {
        $tenantHeader = RecordConfigService::tenantHeader();
        $tenantColumn = RecordConfigService::tenantColumn();

        return [
            'name' => $tenantHeader,
            'in' => 'header',
            'required' => $required,
            'schema' => ['type' => 'string'],
            'description' => sprintf('Tenant identifier header. Used to filter records by `%s` when multi-tenant mode is enabled.', $tenantColumn),
        ];
    }

    private static function tenantHeaderParametersForTableConfig(mixed $config): array
    {
        if (!RecordConfigService::enableTenantId()) {
            return [];
        }

        if (!($config->hasTenantId ?? false)) {
            return [];
        }

        return [self::tenantHeaderParameter(true)];
    }

    /**
     * A representative '{operator}.{value}' example per column type, used only to make
     * the filter parameter's syntax immediately obvious from the schema itself.
     */
    private static function columnFilterExample(string $type): string
    {
        return match ($type) {
            'integer', 'bigint', 'smallint', 'tinyint', 'int',
            'decimal', 'float', 'double', 'numeric', 'unsigned' => 'gte.100',
            'datetime', 'date', 'timestamp', 'time' => 'gte.2026-01-01',
            'boolean', 'bool' => 'eq.true',
            default => 'eq.value',
        };
    }

    /**
     * OpenAPI supports external documentation on an operation, but not on a
     * Parameter Object. Link the canonical guide once on each list operation
     * instead of repeating the full operator catalogue for every table field.
     *
     * @return array{description: string, url: string}|null
     */
    private static function filterDocumentation(): ?array
    {
        $url = trim((string) config('sp-laravel-api.openapi.filter_documentation_url', ''));

        if ('' === $url) {
            return null;
        }

        return [
            'description' => 'Filter syntax and supported operators',
            'url' => $url,
        ];
    }

    /**
     * One query parameter per table column, documenting the actual '{column}={operator}.{value}'
     * filter syntax directly in the OpenAPI schema. The full operator catalogue is linked from
     * the list operation's optional OpenAPI externalDocs field.
     *
     * @param array<string, mixed> $columns
     * @return array<int, array<string, mixed>>
     */
    private static function columnFilterParameters(array $columns): array
    {
        $parameters = [];
        foreach ($columns as $columnName => $columnDef) {
            if (!is_string($columnName)) {
                continue;
            }

            $type = is_array($columnDef) ? (string) ($columnDef['type'] ?? 'string') : (string) $columnDef;

            $parameters[] = [
                'name' => $columnName,
                'in' => 'query',
                'required' => false,
                'description' => sprintf('Filter value for `%s`; use `{operator}.{value}` syntax.', $columnName),
                'schema' => ['type' => 'string'],
                'example' => self::columnFilterExample($type),
            ];
        }

        return $parameters;
    }

    /**
     * 'select' / 'sortby' / 'order' / 'search' query parameters for the list endpoint,
     * structurally declared (not just described in prose) so a schema-driven client/agent
     * knows they exist without having to read the free-text description.
     *
     * @param array<string, mixed> $columns
     * @param array<string, mixed> $relationships
     * @param string[] $searchable
     * @return array<int, array<string, mixed>>
     */
    private static function queryShapeParameters(array $columns, array $relationships, array $searchable): array
    {
        $relationAliases = array_values(array_filter(array_keys($relationships), 'is_string'));
        $selectExample = [] !== $relationAliases ? '*,' . $relationAliases[0] . '(*)' : '*';

        $sortableColumns = array_values(array_filter(array_keys($columns), 'is_string'));

        return [
            [
                'name' => 'select',
                'in' => 'query',
                'required' => false,
                'description' => 'Comma-separated columns to return. Embed relationships with parentheses: `relation(cols)`. Use `*` for all main-table columns.',
                'schema' => ['type' => 'string'],
                'example' => $selectExample,
            ],
            [
                'name' => 'sortby',
                'in' => 'query',
                'required' => false,
                'description' => 'Column to sort by.',
                'schema' => [] !== $sortableColumns
                    ? ['type' => 'string', 'enum' => $sortableColumns]
                    : ['type' => 'string'],
            ],
            [
                'name' => 'order',
                'in' => 'query',
                'required' => false,
                'description' => 'Sort direction.',
                'schema' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'desc'],
            ],
            [
                'name' => 'search',
                'in' => 'query',
                'required' => false,
                'description' => [] !== $searchable
                    ? 'Search across this table\'s configured searchable fields: ' . implode(', ', $searchable) . '.'
                    : 'Search across this table\'s configured searchable fields (none configured).',
                'schema' => ['type' => 'string'],
            ],
            [
                'name' => 'lazy',
                'in' => 'query',
                'required' => false,
                'description' => 'Defer query execution for performance: filters are stored and executed only when the query actually runs (batching + caching). Use `lazy=true` for large datasets or complex multi-filter queries.',
                'schema' => ['type' => 'boolean', 'enum' => [true, false], 'default' => false],
            ],
        ];
    }

    /**
     * Generate relationship description for API documentation.
     */
    private static function generateRelationshipDescription(string $recordName, mixed $config): string
    {
        if (empty($config->relationships)) {
            return '';
        }

        $lines = [];
        $arrayPayloadRows = [];
        $fkRows = [];

        foreach (array_keys($config->relationships) as $alias) {
            if (!is_string($alias)) {
                continue;
            }

            if ('' === $alias) {
                continue;
            }

            $resolved = RelationshipResolverUtils::resolveRelationship($recordName, $alias);
            if (!is_array($resolved)) {
                continue;
            }

            $type = (string) ($resolved['type'] ?? 'unknown');

            if ('belongsTo' === $type) {
                $foreignKey = (string) ($resolved['foreign_key'] ?? (rtrim($alias, 's') . '_id'));
                $fkRows[] = sprintf('| `%s` | `%s` |', $alias, $foreignKey);
                continue;
            }

            if (in_array($type, ['hasMany', 'belongsToMany', 'morphMany', 'morphToMany', 'morphByMany', 'hasManyThrough'], true)) {
                $arrayPayloadRows[] = sprintf('| `%s` | `%s` | `array<id|object>` |', $alias, $type);
            }
        }

        $lines[] = '**Relationship payload guide (write endpoints):**';
        if ([] !== $arrayPayloadRows) {
            $lines[] = '**Array relationship keys accepted in payload:**';
            $lines[] = '| Alias | Type | Payload shape |';
            $lines[] = '|---|---|---|';
            $lines = array_merge($lines, $arrayPayloadRows);
        } else {
            $lines[] = '- Array relationship keys accepted in payload: _none_';
        }

        $lines[] = '';
        if ([] !== $fkRows) {
            $lines[] = '**FK relationship input (belongsTo):**';
            $lines[] = '| Relationship alias | Use scalar FK field |';
            $lines[] = '|---|---|';
            $lines = array_merge($lines, $fkRows);
        } else {
            $lines[] = '- FK relationship input (belongsTo): _none_';
        }

        $lines[] = '';
        $lines[] = '**Payload examples:**';
        $lines[] = '- FK (belongsTo): `{"customer_id": 10}`';
        $lines[] = '- hasMany: `{"items": [{"name":"Line A"},{"id": 15,"_delete": true}]}`';
        $lines[] = '- belongsToMany/morphToMany: `{"roles": [1, {"id": 2}]}`';
        $lines[] = '- Full relationship type examples: [Relationship Write Payload Guide](#relationship-write-payload-guide)';
        $lines[] = '';
        $lines[] = '**Unknown fields are rejected:** every top-level payload key must be a real column or one of the relationship aliases above — an invented or misspelled key returns `422` naming it and listing the valid columns/relationships, instead of being silently dropped.';
        $lines[] = '';
        $lines[] = 'These rules align with docs/api-documentation.md relationship sections.';

        return implode("\n", $lines);
    }
}
