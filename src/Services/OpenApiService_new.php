<?php

namespace App\Utilities\Services;

use Exception;
use App\Utilities\Support\SchemaRegistry;
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
        $specPath = storage_path('internal/openapi-v2-spec.json');

        if (!File::exists($specPath)) {
            throw new Exception('OpenAPI specification file not found. Run php artisan openapi:generate to create it.');
        }

        $content = File::get($specPath);
        $spec = json_decode($content, true);

        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new Exception('Invalid OpenAPI specification JSON: '.json_last_error_msg());
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
        $tables = SchemaRegistry::get();

        $schemas = [];
        foreach ($tables as $recordName => $config) {
            $columns = $config->columns ?? [];
            $actualTableName = $config->table ?? $recordName; // Use actual table name from config

            // Full schema (for responses)
            $schemas[self::schemaName($recordName)] = self::tableSchema($actualTableName, $columns, $config);

            // Read schema (for GET operations - exclude created_at, updated_at)
            $schemas[self::schemaName($recordName).'Read'] = self::tableSchemaRead($actualTableName, $columns, $config);

            // Write schema (for POST/PUT/PATCH operations - exclude created_at, updated_at, deleted_at)
            $schemas[self::schemaName($recordName).'Write'] = self::tableSchemaWrite($actualTableName, $columns, $config);
        }

        $paths = self::paths($tables);

        $servers = [
            [
                'url' => rtrim((string) config('app.url'), '/').'/api',
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

        $globalFunctions = config('record.global_functions', []);

        $spec = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'QBO Finance ERP API v2 – Internal Documentation',
                'version' => '2.0.0',
                'description' => '# QBO Finance ERP Dynamic Record API

A powerful, flexible API for accessing ERP system data with advanced filtering, relationships, and performance optimizations.

## 📋 What This API Does

This API provides **unified access** to all ERP tables through a single endpoint pattern:
- **CRUD Operations**: Create, read, update, delete records
- **Dynamic Filtering**: 25+ filter operators for precise data queries  
- **Relationship Embedding**: Load related data in a single request
- **Bulk Operations**: Process multiple records efficiently
- **Custom Functions**: Execute business logic via RPC endpoints

## 🚀 Getting Started

### Basic Usage
```bash
# Get all paid invoices
GET /api/v2/record/invoices?status=eq.PAID

# Search customers by name
GET /api/v2/record/customers?name=like.John

# Get products in price range
GET /api/v2/record/products?price=between.100,1000
```

### Load Related Data
```bash
# Get invoices with customer and items
GET /api/v2/record/invoices?select=id,ref_number,customer:customers(id,name),items(*)

# Get users with their roles
GET /api/v2/record/users?select=id,name,roles(id,name)
```

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
`POST /api/v2/record/{table}/bulk` - Create, update, delete in one request
```json
{
  "create": [
    {"name": "New Customer 1", "email": "customer1@example.com"},
    {"name": "New Customer 2", "email": "customer2@example.com"}
  ],
  "update": [
    {"id": "123", "name": "Updated Customer", "status": "active"},
    {"id": "456", "email": "newemail@example.com"}
  ],
  "delete": ["789", "101112"]
}
```

#### Bulk Create
`POST /api/v2/record/{table}/bulk/create` - Create multiple records
```json
[
  {"name": "Product A", "price": 99.99, "category": "electronics"},
  {"name": "Product B", "price": 149.99, "category": "electronics"},
  {"name": "Product C", "price": 79.99, "category": "books"}
]
```

#### Bulk Update
`POST /api/v2/record/{table}/bulk/update` - Update multiple records
```json
[
  {"id": "123", "price": 89.99, "status": "active"},
  {"id": "456", "price": 129.99, "discount": 10},
  {"id": "789", "status": "discontinued"}
]
```

#### Bulk Delete
`POST /api/v2/record/{table}/bulk/delete` - Delete multiple records
```json
["record-id-1", "record-id-2", "record-id-3"]
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
                    'name' => 'Speedx Development Team',
                    'email' => 'dev@speedx.com',
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
        return str_replace(['-', ' '], '_', ucfirst($table));
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

        return $tags;
    }

    private static function paths(array $tables): array
    {
        $paths = [];

        foreach ($tables as $recordName => $config) {
            $actualTableName = $config->table ?? $recordName; // Use actual table name from config
            $formattedRecordName = ucwords(str_replace('_', ' ', $recordName));
            $formattedTableName = ucwords(str_replace('_', ' ', $actualTableName));
            $schemaRef = '#/components/schemas/'.self::schemaName($recordName);
            $schemaRefRead = '#/components/schemas/'.self::schemaName($recordName).'Read';
            $schemaRefWrite = '#/components/schemas/'.self::schemaName($recordName).'Write';

            // Generate relationship description
            $relationshipDescription = self::generateRelationshipDescription($config);

            // List & create (API endpoints use record name, but descriptions reference actual table)
            $basePath = '/v2/record/'.$recordName;
            $paths[$basePath] = [
                'get' => [
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
                ],
                'post' => [
                    'tags' => [$formattedRecordName],
                    'summary' => 'Create ' . $recordName,
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
                ],
            ];

            // Read/Update/Delete
            $idPath = $basePath.'/{id}';
            $paths[$idPath] = [
                'parameters' => [self::pathIdParameter()],
                'get' => [
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
                ],
                'put' => [
                    'tags' => [$formattedRecordName],
                    'summary' => 'Update ' . $formattedRecordName,
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
                ],
                'patch' => [
                    'tags' => [$formattedRecordName],
                    'summary' => 'Partially update ' . $formattedRecordName,
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
                ],
                'delete' => [
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
                ],
            ];

            // Restore & Force Delete
            $paths[$basePath.'/{id}/restore'] = [
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
            $paths[$basePath.'/{id}/force'] = [
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

        return $paths;
    }

    private static function rpcPaths(array $tables, array $globalFunctions): array
    {
        $paths = [];

        // Global RPC Functions - Generate individual endpoints
        foreach ($globalFunctions as $functionName => $functionConfig) {
            $allowedMethods = $functionConfig->method ?? ['GET'];
            $description = $functionConfig->description ?? 'RPC - ' . $functionName;

            $endpoint = '/v2/record/rpc/' . $functionName;
            $paths[$endpoint] = [];

            foreach ($allowedMethods as $method) {
                $methodLower = strtolower((string) $method);
                $paths[$endpoint][$methodLower] = [
                    'tags' => ['RPC Endpoints'],
                    'summary' => $description,
                    'description' => $description,
                    'operationId' => 'globalRpc'.ucfirst((string) $functionName).ucfirst($methodLower),
                    'responses' => [
                        '200' => [
                            'description' => 'Successful response',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'success' => ['type' => 'boolean'],
                                            'data' => ['type' => 'object'],
                                            'meta' => ['type' => 'object'],
                                        ],
                                    ],
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
                    $paths[$endpoint][$methodLower]['requestBody'] = [
                        'required' => false,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'description' => 'Function parameters',
                                ],
                            ],
                        ],
                    ];
                }

                // Add query parameters for GET method
                if ('get' === $methodLower) {
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

        // Table RPC Functions - Generate individual endpoints
        foreach ($tables as $tableName => $config) {
            $formattedTableName = ucwords(str_replace('_', ' ', $tableName));
            $functions = Arr::get((array) $config, 'functions', []);

            foreach ($functions as $functionName => $functionConfig) {
                $allowedMethods = $functionConfig->method ?? ['GET'];
                $description = $functionConfig->description ?? sprintf('RPC - %s: %s', $tableName, $functionName);

                // Handle parameterized endpoints like 'update/{id}'
                $endpoint = sprintf('/v2/record/%s/rpc/%s', $tableName, $functionName);
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
                            'description' => ucfirst($paramName).' parameter',
                            'schema' => ['type' => 'string'],
                        ];
                    }
                }

                foreach ($allowedMethods as $method) {
                    $methodLower = strtolower((string) $method);
                    $operationId = 'tableRpc'.ucfirst((string) $tableName).ucfirst(str_replace(['{', '}', '/'], '', $functionName)).ucfirst($methodLower);

                    $paths[$endpoint][$methodLower] = [
                        'tags' => [$formattedTableName],
                        'summary' => $description,
                        'description' => $description,
                        'operationId' => $operationId,
                        'responses' => [
                            '200' => [
                                'description' => 'Successful response',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'success' => ['type' => 'boolean'],
                                                'data' => ['type' => 'object'],
                                                'meta' => ['type' => 'object'],
                                            ],
                                        ],
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
                        $paths[$endpoint][$methodLower]['parameters'] = $parameters;
                    }

                    // Add request body for POST, PUT, PATCH methods
                    if (in_array($methodLower, ['post', 'put', 'patch'])) {
                        $paths[$endpoint][$methodLower]['requestBody'] = [
                            'required' => false,
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'description' => 'Function parameters',
                                    ],
                                ],
                            ],
                        ];
                    }

                    // Add query parameters for GET method (if no path parameters)
                    if ('get' === $methodLower && empty($parameters)) {
                        if (!isset($paths[$endpoint][$methodLower]['parameters'])) {
                            $paths[$endpoint][$methodLower]['parameters'] = [];
                        }

                        $paths[$endpoint][$methodLower]['parameters'][] = [
                            'name' => 'params',
                            'in' => 'query',
                            'required' => false,
                            'description' => 'Function parameters as JSON string',
                            'schema' => ['type' => 'string'],
                        ];
                    }
                }
            }
        }

        return $paths;
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
