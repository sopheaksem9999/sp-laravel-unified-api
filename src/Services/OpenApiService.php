<?php

namespace Sopheak\Core\Services;

use Sopheak\Core\Interfaces\RecordFunctionInterface;
use Sopheak\Core\Types\RecordFunctionType;
use Exception;
use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

class OpenApiService
{
    /**
     * Load OpenAPI 3.0 specification from internal JSON file.
     * This method is for internal team use only - not exposed publicly.
     *
     * @return array The OpenAPI specification
     *
     * @throws Exception If the specification file is not found or invalid
     */
    public static function load(): array
    {
        $specPath = storage_path('openapi-v2-spec.json');

        if (!File::exists($specPath)) {
            throw new Exception('OpenAPI specification file not found. Run php artisan openapi:generate to create it.');
        }

        $content = File::get($specPath);
        $spec = json_decode($content, true);

        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new Exception('Invalid OpenAPI specification JSON: ' . json_last_error_msg());
        }

        return $spec;
    }

    /**
     * Generate and save OpenAPI 3.0 specification for internal team use.
     * This method creates a comprehensive specification with filter documentation.
     *
     * @return array The generated OpenAPI specification
     */
    public static function generateInternal(): array
    {
        $tables = SchemaRegistryUtils::get();
        $apiPrefix = RecordConfigService::apiPrefix();
        $tenantHeader = RecordConfigService::tenantHeader();
        $tenantColumn = RecordConfigService::tenantColumn();

        $schemas = [];
        foreach ($tables as $recordName => $config) {
            $columns = $config->columns ?? [];
            $actualTableName = $config->table ?? $recordName; // Use actual table name from config

            // Full schema (for responses)
            $schemas[self::schemaName($recordName)] = self::tableSchema($actualTableName, $columns);

            // Read schema (for GET operations - exclude created_at, updated_at)
            $schemas[self::schemaName($recordName) . 'Read'] = self::tableSchemaRead($actualTableName, $columns);

            // Write schema (for POST/PUT/PATCH operations - exclude created_at, updated_at, deleted_at)
            $schemas[self::schemaName($recordName) . 'Write'] = self::tableSchemaWrite($actualTableName, $columns);
        }

        $paths = self::paths($tables);
        if (RecordConfigService::auditEnabled()) {
            // $schemas['AuditLog'] = self::auditLogSchema();
            // $schemas['AuditStats'] = self::auditStatsSchema();
            // $schemas['AuditTimelineEntry'] = self::auditTimelineEntrySchema();
            // $paths += self::auditPaths();
        }

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
                'title' => config('app.name') . ' – Internal Documentation',
                'version' => '2.0.0',
                'description' => '# API Documentation

A powerful, flexible API for accessing system data with advanced filtering, relationships, and performance optimizations.

## 📋 What This API Does

This API provides **unified access** to all tables through a single endpoint pattern:
- **CRUD Operations**: Create, read, update, delete records
- **Dynamic Filtering**: 25+ filter operators for precise data queries  
- **Relationship Embedding**: Load related data in a single request
- **Bulk Operations**: Process multiple records efficiently
- **Custom Functions**: Execute business logic via RPC endpoints

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

### Load Related Data
```bash
# Get invoices with customer and items
GET /' . $apiPrefix . '/invoices?select=id,ref_number,customer:customers(id,name),items(*)

# Get users with their roles
GET /' . $apiPrefix . '/users?select=id,name,roles(id,name)
```

## 🏢 Multi-Tenant Header

If multi-tenant mode is enabled (`record.enable_tenant_id=true`) and the table is configured with `has_tenant_id=true`, include the tenant header on requests:
```bash
' . $tenantHeader . ': <tenant-id>
```
Records are filtered by the `' . $tenantColumn . '` column.

## 🔧 Key Features

### Filtering & Search
- **Equality**: `eq` (equal), `neq` (not equal)
- **Text Search**: `like`/`contains` (contains), `starts_with`, `ends_with`, `not_like`, `regex`
- **Comparisons**: `gt` (greater than), `lt` (less than), `gte` (greater/equal), `lte` (less/equal)
- **Lists**: `in` (value in list), `not_in` (value not in list), `between`, `not_between`
- **Date Filters**: `date_eq`, `date_gt`, `date_lt`, `date_gte`, `date_lte`
- **Null Checks**: `is` (is null), `is_not` (is not null), `empty` (null or empty), `not_empty`
- **Advanced**: Column-to-column comparisons, lazy loading with `lazy=true`

### Column Selection
- **Basic**: `select=id,name,email` (specific columns)
- **All Columns**: `select=*` (all table columns)
- **Relationships**: `select=id,name,customer:customers(id,name)` (include related data)
- **Nested**: `select=id,items(id,name,product:products(*))` (deep relationships)
- **Mixed**: `select=*,customer:customers(id,name),items(*)` (combine table and relationship columns)

### Ordering & Sorting
- **Basic**: `sortby=name&order=asc` (sort by column)
- **Direction**: `order=desc` (default) or `order=asc`
- **Table Qualified**: `sortby=table.column` (explicit table reference)
- **Default**: Falls back to `id` or first available column if invalid
- **Validation**: Only allows columns that exist in table schema

### Pagination
- **Traditional**: `page=1&per_page=25` (offset-based for small datasets)
- **Cursor-Based**: `cursor=12345&direction=next` (high-performance for large datasets)
- **Auto-Detection**: Automatically switches to cursor pagination for tables >10,000 rows
- **Custom Cursor**: `cursor_column=created_at` (use different cursor column)
- **Composite**: `composite_cursor=true&sortby=created_at` (multi-column cursors)
- **Limits**: `per_page` max 100, default 25

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
            'tags' => self::tags($tables),
            'paths' => $paths + self::rpcPaths($tables, $globalFunctions),
            'components' => [
                'schemas' => $schemas,
                'securitySchemes' => $securitySchemes,
            ],
            'security' => [['bearerAuth' => []]],
        ];

        // Save to internal storage
        $specPath = storage_path('internal/openapi-v2-spec.json');
        $directory = dirname($specPath);

        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        File::put($specPath, json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $spec;
    }

    private static function schemaName(string $table): string
    {
        return str_replace(['-', ' '], '_', ucwords(strtolower($table)));
    }

    private static function tableSchema(string $table, array $columns): array
    {
        $properties = [];
        $required = [];

        // Exclude deleted_at from all response schemas
        $excludeFields = ['deleted_at'];

        foreach ($columns as $name => $info) {
            if (in_array($name, $excludeFields, true)) {
                continue;
            }

            $mapped = self::mapColumnToOpenApi($info['type'] ?? 'string');
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
            'description' => sprintf('Schema for table `%s` (excludes soft delete field)', $table),
        ];
    }

    private static function mapColumnToOpenApi(string $dbType): array
    {
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

    private static function tableSchemaRead(string $table, array $columns): array
    {
        $properties = [];
        $required = [];

        // Exclude created_at, updated_at, and deleted_at for read operations
        $excludeFields = ['created_at', 'updated_at', 'deleted_at'];

        foreach ($columns as $name => $info) {
            if (in_array($name, $excludeFields, true)) {
                continue;
            }

            $mapped = self::mapColumnToOpenApi($info['type'] ?? 'string');
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
            'description' => sprintf('Read schema for table `%s` (excludes system timestamps and soft delete)', $table),
        ];
    }

    private static function tableSchemaWrite(string $table, array $columns): array
    {
        $properties = [];
        $required = [];

        // Exclude created_at, updated_at, and deleted_at for write operations
        $excludeFields = ['created_at', 'updated_at', 'deleted_at'];

        foreach ($columns as $name => $info) {
            if (in_array($name, $excludeFields, true)) {
                continue;
            }

            $mapped = self::mapColumnToOpenApi($info['type'] ?? 'string');
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
            'description' => sprintf('Write schema for table `%s` (excludes system-managed fields)', $table),
        ];
    }

    private static function tags(array $tables): array
    {
        $tags = [];
        foreach (array_keys($tables) as $table) {
            $formattedTableName = ucwords(str_replace('_', ' ', $table));
            $tags[] = ['name' => $formattedTableName, 'description' => sprintf('Operations for `%s` records', $formattedTableName)];
        }

        $tags[] = ['name' => 'RPC', 'description' => 'Global RPC functions'];
        if (RecordConfigService::auditEnabled()) {
            $tags[] = ['name' => 'Audit', 'description' => 'Audit log operations'];
        }

        return $tags;
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
            $canRead = (bool) ($config->can_read ?? true);
            $canCreate = (bool) ($config->can_create ?? true);
            $canUpdate = (bool) ($config->can_update ?? true);
            $canDelete = (bool) ($config->can_delete ?? true);

            // Generate relationship description
            $relationshipDescription = self::generateRelationshipDescription($config);

            // List & create (API endpoints use record name, but descriptions reference actual table)
            $basePath = '/' . $apiPrefix . '/' . $recordName;
            $paths[$basePath] = array_filter([
                'parameters' => $tenantHeaderParameters,
                'get' => $canRead ? [
                    'tags' => [$formattedRecordName],
                    'summary' => 'List ' . $formattedRecordName,
                    'description' => "Retrieve {$recordName} records with comprehensive query capabilities:\n\n**Advanced Filtering:** Multiple operators ([Filter](#description/-getting-started))\n\n{$relationshipDescription}",
                    'responses' => [
                        '200' => [
                            'description' => 'Successful response',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'success' => ['type' => 'boolean', 'example' => true],
                                            'data' => [
                                                'type' => 'array',
                                                'items' => ['$ref' => $schemaRefRead],
                                            ],
                                            'meta' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'request_id' => ['type' => 'string'],
                                                    'total' => ['type' => 'integer'],
                                                    'per_page' => ['type' => 'integer'],
                                                    'current_page' => ['type' => 'integer'],
                                                    'last_page' => ['type' => 'integer'],
                                                    'from' => ['type' => 'integer'],
                                                    'to' => ['type' => 'integer'],
                                                ],
                                            ],
                                        ],
                                        'required' => ['success', 'data', 'meta'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'security' => [['bearerAuth' => []]],
                ] : [],
                'post' => $canCreate ? [
                    'tags' => [$formattedRecordName],
                    'summary' => 'Create ' . $recordName,
                    'description' => "Create a new {$recordName} record with comprehensive validation:\n\n**Advanced Validation:** Multiple rules ([Validation](#description/-getting-started))\n\n{$relationshipDescription}",
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
                    ],
                    'security' => [['bearerAuth' => []]],
                ] : [],
            ], static fn(mixed $value): bool => [] !== $value);

            if (!isset($paths[$basePath]['get']) && !isset($paths[$basePath]['post'])) {
                unset($paths[$basePath]);
            }

            // Read/Update/Delete
            $idPath = $basePath . '/{id}';
            $paths[$idPath] = array_filter([
                'parameters' => array_merge([self::pathIdParameter()], $tenantHeaderParameters),
                'get' => $canRead ? [
                    'tags' => [$formattedRecordName],
                    'summary' => sprintf('Get %s by ID', $recordName),
                    'responses' => [
                        '200' => [
                            'description' => 'Successful response',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'success' => ['type' => 'boolean', 'example' => true],
                                            'data' => ['$ref' => $schemaRefRead],
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
                    'security' => [['bearerAuth' => []]],
                ] : [],
                'put' => $canUpdate ? [
                    'tags' => [$formattedRecordName],
                    'summary' => 'Update ' . $formattedRecordName,
                    'description' => "Update an existing {$recordName} record with comprehensive validation:\n\n**Advanced Validation:** Multiple rules ([Validation](#description/-getting-started))\n\n{$relationshipDescription}",
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
                    'security' => [['bearerAuth' => []]],
                ] : [],
                // 'patch' => $canUpdate ? [
                //     'tags' => [$formattedRecordName],
                //     'summary' => 'Partially update ' . $formattedRecordName,
                //     'description' => "Update an existing {$recordName} record with comprehensive validation:\n\n**Advanced Validation:** Multiple rules ([Validation](#description/-getting-started))\n\n{$relationshipDescription}",
                //     'requestBody' => [
                //         'required' => true,
                //         'content' => [
                //             'application/json' => [
                //                 'schema' => ['$ref' => $schemaRefWrite],
                //             ],
                //         ],
                //     ],
                //     'responses' => [
                //         '200' => [
                //             'description' => 'Updated',
                //             'content' => [
                //                 'application/json' => [
                //                     'schema' => [
                //                         'type' => 'object',
                //                         'properties' => [
                //                             'success' => ['type' => 'boolean', 'example' => true],
                //                             'data' => ['$ref' => $schemaRef],
                //                             'meta' => [
                //                                 'type' => 'object',
                //                                 'properties' => [
                //                                     'request_id' => ['type' => 'string'],
                //                                 ],
                //                             ],
                //                         ],
                //                         'required' => ['success', 'data', 'meta'],
                //                     ],
                //                 ],
                //             ],
                //         ],
                //         '404' => [
                //             'description' => 'Not Found',
                //             'content' => [
                //                 'application/json' => [
                //                     'schema' => [
                //                         'type' => 'object',
                //                         'properties' => [
                //                             'success' => ['type' => 'boolean', 'example' => false],
                //                             'message' => ['type' => 'string', 'example' => 'Record not found'],
                //                             'errors' => ['type' => 'array', 'items' => ['type' => 'string']],
                //                             'meta' => [
                //                                 'type' => 'object',
                //                                 'properties' => [
                //                                     'request_id' => ['type' => 'string'],
                //                                 ],
                //                             ],
                //                         ],
                //                         'required' => ['success', 'message', 'errors', 'meta'],
                //                     ],
                //                 ],
                //             ],
                //         ],
                //     ],
                //     'security' => [['bearerAuth' => []]],
                // ] : [],
                'delete' => $canDelete ? [
                    'tags' => [$formattedRecordName],
                    'summary' => 'Delete ' . $formattedRecordName,
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
                    'security' => [['bearerAuth' => []]],
                ] : [],
            ], static fn(mixed $value): bool => [] !== $value);

            $idPathOperations = array_diff_key($paths[$idPath], ['parameters' => true]);
            if ([] === $idPathOperations) {
                unset($paths[$idPath]);
            }

            // Restore
            if ($canUpdate && (bool) ($config->soft_deletes ?? false)) {
                $paths[$basePath . '/{id}/restore'] = [
                    'parameters' => [self::pathIdParameter()],
                    'post' => [
                        'tags' => [$formattedRecordName],
                        'summary' => 'Restore ' . $formattedRecordName,
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
                        'security' => [['bearerAuth' => []]],
                    ],
                ];
            }

            // Force Delete
            if ($canDelete) {
                $paths[$basePath . '/{id}/force'] = [
                    'parameters' => [self::pathIdParameter()],
                    'delete' => [
                        'tags' => [$formattedRecordName],
                        'summary' => 'Force delete ' . $formattedRecordName,
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
                        'security' => [['bearerAuth' => []]],
                    ],
                ];
            }
        }

        return $paths;
    }

    private static function rpcPaths(array $tables, array $globalFunctions): array
    {
        $apiPrefix = RecordConfigService::apiPrefix();
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

            $allowedMethods = $functionConfig->method ?? ['GET'];
            $methodName = empty($functionConfig->description) ? self::schemaName($functionName) : $functionConfig->description;
            $summary = sprintf('RPC - %s', $methodName);
            $description = sprintf('%s', $methodName);

            // Get schemas from config
            $querySchema = $functionConfig->query_schema ?? null;
            $payloadSchema = $functionConfig->payload_schema ?? null;
            $responseSchema = $functionConfig->response_schema ?? null;

            $endpoint = '/' . $apiPrefix . '/' . RecordConfigService::rpcPrefix() . '/' . $functionName;
            $paths[$endpoint] = [];

            foreach ($allowedMethods as $method) {
                $methodLower = strtolower((string) $method);

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
                    'tags' => ['RPC Endpoints'],
                    'summary' => $summary,
                    'description' => $description,
                    'operationId' => 'globalRpc' . ucfirst((string) $functionName) . ucfirst($methodLower),
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
                    'security' => [['bearerAuth' => []]],
                ];

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

                $allowedMethods = gettype($functionConfig->method) === 'string' ? [$functionConfig->method] : $functionConfig->method ?? ['GET'];
                $methodName = empty($functionConfig->description) ? self::schemaName($functionName) : $functionConfig->description;
                $summary = sprintf('RPC - %s', $methodName);
                $description = sprintf('%s', $methodName);

                // Get schemas from config
                $querySchema = $functionConfig->query_schema ?? null;
                $payloadSchema = $functionConfig->payload_schema ?? null;
                $responseSchema = $functionConfig->response_schema ?? null;

                // Handle parameterized endpoints like 'update/{id}'
                $endpoint = sprintf('/%s/%s/%s/%s', $apiPrefix, $tableName, RecordConfigService::rpcPrefix(), $functionName);
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
                        'security' => [['bearerAuth' => []]],
                    ];

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

    private static function auditLogSchema(): array
    {
        $properties = [
            'id' => ['type' => 'integer'],
        ];

        if (RecordConfigService::enableTenantId()) {
            $tenantColumn = RecordConfigService::tenantColumn();
            $properties[$tenantColumn] = ['type' => 'integer', 'nullable' => true];
        }

        $properties['entity_name'] = ['type' => 'string', 'nullable' => true];
        $properties['entity_type'] = ['type' => 'string', 'nullable' => true];
        $properties['entity_id'] = ['type' => 'integer', 'nullable' => true];
        $properties['user_id'] = ['type' => 'integer', 'nullable' => true];
        $properties['event'] = ['type' => 'string', 'nullable' => true];
        $properties['title'] = ['type' => 'string', 'nullable' => true];
        $properties['subject'] = ['type' => 'string', 'nullable' => true];
        $properties['recap'] = ['type' => 'string', 'nullable' => true];
        $properties['old_data'] = ['type' => 'object', 'nullable' => true, 'additionalProperties' => true];
        $properties['new_data'] = ['type' => 'object', 'nullable' => true, 'additionalProperties' => true];
        $properties['metadata'] = ['type' => 'object', 'nullable' => true, 'additionalProperties' => true];
        $properties['created_at'] = ['type' => 'string', 'format' => 'date-time'];
        $properties['updated_at'] = ['type' => 'string', 'format' => 'date-time', 'nullable' => true];

        return [
            'type' => 'object',
            'properties' => $properties,
        ];
    }

    private static function auditStatsSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'total_logs' => ['type' => 'integer'],
                'actions_breakdown' => [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'integer'],
                ],
                'top_users' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'user_name' => ['type' => 'string'],
                            'count' => ['type' => 'integer'],
                        ],
                        'required' => ['user_name', 'count'],
                    ],
                ],
                'entity_types' => [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'integer'],
                ],
            ],
        ];
    }

    private static function auditTimelineEntrySchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'changed_at' => ['type' => 'string', 'format' => 'date-time'],
                'old_value' => ['type' => 'string', 'nullable' => true],
                'new_value' => ['type' => 'string', 'nullable' => true],
                'change_type' => ['type' => 'string'],
                'data_type' => ['type' => 'string'],
                'user_name' => ['type' => 'string'],
                'event' => ['type' => 'string'],
            ],
        ];
    }

    private static function auditPaths(): array
    {
        $apiPrefix = RecordConfigService::apiPrefix();
        $tenantHeaderParameters = RecordConfigService::enableTenantId() ? [self::tenantHeaderParameter(false)] : [];
        $paths = [];

        $auditLogRef = '#/components/schemas/AuditLog';
        $auditStatsRef = '#/components/schemas/AuditStats';
        $auditTimelineEntryRef = '#/components/schemas/AuditTimelineEntry';

        $paths['/' . $apiPrefix . '/audit/logs'] = [
            'parameters' => $tenantHeaderParameters,
            'get' => [
                'tags' => ['Audit'],
                'summary' => 'Get audit logs',
                'parameters' => [
                    [
                        'name' => 'entity_type',
                        'in' => 'query',
                        'required' => true,
                        'schema' => ['type' => 'string'],
                    ],
                    [
                        'name' => 'entity_id',
                        'in' => 'query',
                        'required' => true,
                        'schema' => ['type' => 'integer'],
                    ],
                    [
                        'name' => 'limit',
                        'in' => 'query',
                        'required' => false,
                        'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                    ],
                ],
                'responses' => [
                    '200' => [
                        'description' => 'Successful response',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'success' => ['type' => 'boolean', 'example' => true],
                                        'data' => ['type' => 'array', 'items' => ['$ref' => $auditLogRef]],
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
                ],
                'security' => [['bearerAuth' => []]],
            ],
        ];

        $paths['/' . $apiPrefix . '/audit/stats'] = [
            'parameters' => $tenantHeaderParameters,
            'get' => [
                'tags' => ['Audit'],
                'summary' => 'Get audit statistics',
                'parameters' => [
                    [
                        'name' => 'entity_type',
                        'in' => 'query',
                        'required' => false,
                        'schema' => ['type' => 'string'],
                    ],
                    [
                        'name' => 'entity_id',
                        'in' => 'query',
                        'required' => false,
                        'schema' => ['type' => 'integer'],
                    ],
                    [
                        'name' => 'start_date',
                        'in' => 'query',
                        'required' => false,
                        'schema' => ['type' => 'string', 'format' => 'date'],
                    ],
                    [
                        'name' => 'end_date',
                        'in' => 'query',
                        'required' => false,
                        'schema' => ['type' => 'string', 'format' => 'date'],
                    ],
                    [
                        'name' => 'event',
                        'in' => 'query',
                        'required' => false,
                        'schema' => [
                            'type' => 'string',
                            'enum' => ['created', 'updated', 'deleted', 'login', 'logout', 'failed_login'],
                        ],
                    ],
                ],
                'responses' => [
                    '200' => [
                        'description' => 'Successful response',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'success' => ['type' => 'boolean', 'example' => true],
                                        'data' => ['$ref' => $auditStatsRef],
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
                ],
                'security' => [['bearerAuth' => []]],
            ],
        ];

        $paths['/' . $apiPrefix . '/audit/timeline'] = [
            'parameters' => $tenantHeaderParameters,
            'get' => [
                'tags' => ['Audit'],
                'summary' => 'Get field timeline',
                'parameters' => [
                    [
                        'name' => 'entity_type',
                        'in' => 'query',
                        'required' => true,
                        'schema' => ['type' => 'string'],
                    ],
                    [
                        'name' => 'entity_id',
                        'in' => 'query',
                        'required' => true,
                        'schema' => ['type' => 'integer'],
                    ],
                    [
                        'name' => 'field',
                        'in' => 'query',
                        'required' => true,
                        'schema' => ['type' => 'string'],
                    ],
                    [
                        'name' => 'limit',
                        'in' => 'query',
                        'required' => false,
                        'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                    ],
                ],
                'responses' => [
                    '200' => [
                        'description' => 'Successful response',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'success' => ['type' => 'boolean', 'example' => true],
                                        'data' => [
                                            'type' => 'array',
                                            'items' => ['$ref' => $auditTimelineEntryRef],
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
                ],
                'security' => [['bearerAuth' => []]],
            ],
        ];

        $paths['/' . $apiPrefix . '/audit/logs/{id}'] = [
            'parameters' => array_merge([self::pathAuditIdParameter()], $tenantHeaderParameters),
            'get' => [
                'tags' => ['Audit'],
                'summary' => 'Get audit log by ID',
                'responses' => [
                    '200' => [
                        'description' => 'Successful response',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'success' => ['type' => 'boolean', 'example' => true],
                                        'data' => ['$ref' => $auditLogRef],
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
                                        'errors' => ['type' => 'string', 'example' => 'Audit log not found'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'security' => [['bearerAuth' => []]],
            ],
        ];

        return $paths;
    }

    private static function pathAuditIdParameter(): array
    {
        return [
            'name' => 'id',
            'in' => 'path',
            'required' => true,
            'schema' => ['type' => 'integer'],
            'description' => 'Audit log identifier',
        ];
    }

    private static function pathIdParameter(): array
    {
        return [
            'name' => 'id',
            'in' => 'path',
            'required' => true,
            'schema' => ['type' => 'string'],
            'description' => 'Record identifier (UUID or string)',
        ];
    }

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

        if (!($config->has_tenant_id ?? false)) {
            return [];
        }

        return [self::tenantHeaderParameter(true)];
    }

    /**
     * Generate relationship description for API documentation.
     */
    private static function generateRelationshipDescription(mixed $config): string
    {
        if (empty($config->relationships)) {
            return '';
        }

        $relationships = array_keys($config->relationships);
        $relationshipList = implode(', ', $relationships);

        return ' **Relationships:** ' . $relationshipList;
    }
}
