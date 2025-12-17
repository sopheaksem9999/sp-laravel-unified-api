<?php

namespace Sopheak\Core\Services;

use Sopheak\Core\Support\SchemaRegistry;
use Sopheak\Core\Types\RecordFunctionType;

class OpenApiService
{
    /**
     * Generate complete OpenAPI specification
     */
    public function generateSpecification(): array
    {
        $schemas = $this->generateSchemas();
        $paths = $this->generatePaths();

        $servers = [
            [
                'url' => rtrim((string) config('app.url'), '/').'/api',
                'description' => 'Primary API server',
            ],
        ];

        $securitySchemes = [
            'bearerAuth' => [
                'type' => 'http',
                'scheme' => 'bearer',
                'bearerFormat' => 'JWT',
                'description' => 'Use `Authorization: Bearer <token>`',
            ],
        ];

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => config('app.name', 'Laravel') . ' API v2',
                'version' => '2.0.0',
                'description' => $this->generateApiDescription(),
                'contact' => [
                    'name' => 'Development Team',
                    'email' => 'dev@example.com',
                ],
                'license' => [
                    'name' => 'Proprietary',
                ],
            ],
            'servers' => $servers,
            'tags' => $this->generateTags(),
            'paths' => array_merge($paths, $this->generateTableRpcPaths(), $this->generateRpcPaths()),
            'components' => [
                'schemas' => $schemas,
                'securitySchemes' => $securitySchemes,
                'parameters' => $this->generateParameters(),
                'responses' => $this->generateResponses(),
            ],
            'security' => [['bearerAuth' => []]],
        ];
    }

    /**
     * Generate OpenAPI paths based on record configuration
     */
    protected function generatePaths(): array
    {
        $paths = [];
        $tables = config('record.tables', []);

        foreach ($tables as $tableName => $tableConfig) {
            $paths = array_merge($paths, $this->generateTablePaths($tableName, $tableConfig));
        }

        return $paths;
    }

    /**
     * Generate API description
     */
    protected function generateApiDescription(): string
    {
        return '# Dynamic Record API

A powerful, flexible API for accessing application data with advanced filtering, relationships, and performance optimizations.

## 📋 What This API Does

This API provides **unified access** to all application tables through a single endpoint pattern:
- **CRUD Operations**: Create, read, update, delete records
- **Dynamic Filtering**: 25+ filter operators for precise data queries  
- **Relationship Embedding**: Load related data in a single request
- **Bulk Operations**: Process multiple records efficiently
- **Custom Functions**: Execute business logic via RPC endpoints

## 🚀 Getting Started

### Basic Usage
```bash
# Get all active records
GET /api/v2/record/users?status=eq.active

# Search by name
GET /api/v2/record/users?name=like.John

# Get records in range
GET /api/v2/record/products?price=between.100,1000
```

### Load Related Data
```bash
# Get records with relationships
GET /api/v2/record/users?select=id,name,profile:profiles(id,bio)

# Get nested relationships
GET /api/v2/record/orders?select=id,items(id,product:products(*))
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
- **Relationships**: `select=id,name,user:users(id,name)` (include related data)
- **Nested**: `select=id,items(id,name,product:products(*))` (deep relationships)
- **Mixed**: `select=*,user:users(id,name),items(*)` (combine table and relationship columns)

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
    {"name": "New Record 1", "email": "record1@example.com"},
    {"name": "New Record 2", "email": "record2@example.com"}
  ],
  "update": [
    {"id": "123", "name": "Updated Record", "status": "active"},
    {"id": "456", "email": "newemail@example.com"}
  ],
  "delete": ["789", "101112"]
}
```

#### Bulk Create
`POST /api/v2/record/{table}/bulk/create` - Create multiple records
```json
[
  {"name": "Record A", "status": "active"},
  {"name": "Record B", "status": "pending"},
  {"name": "Record C", "status": "inactive"}
]
```

#### Bulk Update
`POST /api/v2/record/{table}/bulk/update` - Update multiple records
```json
[
  {"id": "123", "status": "active"},
  {"id": "456", "name": "Updated Name"},
  {"id": "789", "status": "archived"}
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
- Use cursor pagination (`cursor`) for large datasets';
    }

    /**
     * Generate tags for OpenAPI spec
     */
    protected function generateTags(): array
    {
        $tags = [];
        $tables = config('record.tables', []);
        
        foreach ($tables as $tableName => $config) {
            $formattedTableName = ucwords(str_replace('_', ' ', $tableName));
            $tags[] = [
                'name' => $formattedTableName,
                'description' => sprintf('Operations for `%s` records', $formattedTableName)
            ];
        }

        $tags[] = ['name' => 'RPC', 'description' => 'Global RPC functions'];

        return $tags;
    }

    /**
     * Generate schemas for all tables
     */
    protected function generateSchemas(): array
    {
        $schemas = [];
        $tables = SchemaRegistry::get();

        foreach ($tables as $tableName => $tableConfig) {
            $schemaName = $this->schemaName($tableName);
            
            // Get columns from table config
            $columns = $tableConfig->columns ?? [];
            
            // Generate full schema
            $schemas[$schemaName] = $this->generateTableSchema($tableName, $columns, $tableConfig);
            
            // Generate read schema
            $schemas[$schemaName . 'Read'] = $this->generateTableSchemaRead($tableName, $columns, $tableConfig);
            
            // Generate write schema
            $schemas[$schemaName . 'Write'] = $this->generateTableSchemaWrite($tableName, $columns, $tableConfig);

            $schemas[$schemaName . 'Update'] = $this->generateTableSchemaUpdate($tableName, $columns, $tableConfig);
        }

        // Add common schemas
        $schemas = array_merge($schemas, $this->generateCommonSchemas());

        return $schemas;
    }

    /**
     * Generate RPC paths for global functions
     */
    protected function generateRpcPaths(): array
    {
        $paths = [];
        $apiPrefix = config('record.api_prefix', 'api');
        $globalFunctions = config('record.global_functions', []);

        if (empty($globalFunctions)) {
            return $paths;
        }

        foreach ($globalFunctions as $functionName => $functionConfig) {
            $config = $this->normalizeFunctionConfig($functionConfig);
            $methods = $this->normalizeHttpMethods($config['method'] ?? ['POST']);
            $path = sprintf('/%s/%s', $apiPrefix, $functionName);

            foreach ($methods as $method) {
                $methodKey = strtolower((string) $method);
                $payloadSchema = $config['payload_schema'] ?? [
                    'type' => 'object',
                    'additionalProperties' => true,
                ];
                $responseSchema = $config['response_schema'] ?? [
                    'type' => 'object',
                    'properties' => [
                        'success' => [
                            'type' => 'boolean',
                            'example' => true,
                        ],
                        'data' => [
                            'description' => 'Function result',
                            'oneOf' => [
                                ['type' => 'object'],
                                ['type' => 'array'],
                                ['type' => 'string'],
                                ['type' => 'number'],
                                ['type' => 'boolean'],
                            ],
                        ],
                    ],
                ];

                $operation = [
                    'tags' => ['RPC'],
                    'summary' => $config['description'] ?? sprintf('Execute %s function', $functionName),
                    'description' => $config['description'] ?? sprintf('Execute the %s global function', $functionName),
                    'operationId' => sprintf('rpc_%s_%s', $functionName, $methodKey),
                    'security' => [['bearerAuth' => []]],
                    'responses' => [
                        '200' => [
                            'description' => 'Function executed successfully',
                            'content' => [
                                'application/json' => [
                                    'schema' => $responseSchema,
                                ],
                            ],
                        ],
                        '400' => [
                            'description' => 'Bad request - invalid parameters',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/Error'],
                                ],
                            ],
                        ],
                        '500' => [
                            'description' => 'Internal server error',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/Error'],
                                ],
                            ],
                        ],
                    ],
                ];

                $queryParams = $this->generateQueryParametersFromSchema($config['query_schema'] ?? null);
                if ([] !== $queryParams) {
                    $operation['parameters'] = $queryParams;
                }

                if ('get' !== $methodKey) {
                    $operation['requestBody'] = [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => $payloadSchema,
                            ],
                        ],
                    ];
                }

                $paths[$path][$methodKey] = $operation;
            }
        }

        return $paths;
    }

    protected function generateTableRpcPaths(): array
    {
        $paths = [];
        $apiPrefix = config('record.api_prefix', 'api');
        $tables = config('record.tables', []);

        foreach ($tables as $tableName => $tableConfig) {
            $functions = $tableConfig->functions ?? [];

            if (empty($functions)) {
                continue;
            }

            $isPublicRead = $tableConfig && isset($tableConfig->public) && $tableConfig->public->read ?? false;
            $isPublicWrite = $tableConfig && isset($tableConfig->public) && $tableConfig->public->write ?? false;

            foreach ($functions as $functionName => $functionConfig) {
                if (!is_string($functionName)) {
                    continue;
                }

                if ('' === $functionName) {
                    continue;
                }

                $config = $this->normalizeFunctionConfig($functionConfig);
                $methods = $this->normalizeHttpMethods($config['method'] ?? ['POST']);
                $path = sprintf('/%s/%s/rpc/%s', $apiPrefix, $tableName, $functionName);

                foreach ($methods as $method) {
                    $methodKey = strtolower((string) $method);
                    $payloadSchema = $config['payload_schema'] ?? [
                        'type' => 'object',
                        'additionalProperties' => true,
                    ];
                    $responseSchema = $config['response_schema'] ?? [
                        'type' => 'object',
                        'properties' => [
                            'success' => [
                                'type' => 'boolean',
                                'example' => true,
                            ],
                            'data' => [
                                'description' => 'Function result',
                                'oneOf' => [
                                    ['type' => 'object'],
                                    ['type' => 'array'],
                                    ['type' => 'string'],
                                    ['type' => 'number'],
                                    ['type' => 'boolean'],
                                ],
                            ],
                        ],
                    ];

                    $security = [];
                    if ('get' === $methodKey) {
                        if (!$isPublicRead) {
                            $security = [['bearerAuth' => []]];
                        }
                    } elseif (!$isPublicWrite) {
                        $security = [['bearerAuth' => []]];
                    }

                    $operation = [
                        'tags' => [ucfirst((string) $tableName)],
                        'summary' => $config['description'] ?? sprintf('Execute %s function', $functionName),
                        'description' => $config['description'] ?? sprintf('Execute the %s function for %s', $functionName, $tableName),
                        'operationId' => sprintf('%s_rpc_%s_%s', $tableName, $functionName, $methodKey),
                        'security' => $security,
                        'responses' => [
                            '200' => [
                                'description' => 'Function executed successfully',
                                'content' => [
                                    'application/json' => [
                                        'schema' => $responseSchema,
                                    ],
                                ],
                            ],
                            '400' => ['$ref' => '#/components/responses/BadRequest'],
                            '401' => ['$ref' => '#/components/responses/Unauthorized'],
                            '403' => ['$ref' => '#/components/responses/Forbidden'],
                            '500' => ['$ref' => '#/components/responses/InternalServerError'],
                        ],
                    ];

                    $queryParams = $this->generateQueryParametersFromSchema($config['query_schema'] ?? null);
                    if ([] !== $queryParams) {
                        $operation['parameters'] = $queryParams;
                    }

                    if ('get' !== $methodKey) {
                        $operation['requestBody'] = [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => $payloadSchema,
                                ],
                            ],
                        ];
                    }

                    $paths[$path][$methodKey] = $operation;
                }
            }
        }

        return $paths;
    }

    protected function normalizeFunctionConfig(array|RecordFunctionType $functionConfig): array
    {
        if ($functionConfig instanceof RecordFunctionType) {
            return $functionConfig->toArray();
        }

        return $functionConfig;
    }

    protected function normalizeHttpMethods(array|string $methods): array
    {
        $methodList = is_array($methods) ? $methods : [$methods];
        $normalized = [];

        foreach ($methodList as $method) {
            $normalized[] = strtoupper((string) $method);
        }

        return array_values(array_unique($normalized));
    }

    protected function generateQueryParametersFromSchema(?array $querySchema): array
    {
        if (null === $querySchema) {
            return [];
        }

        $properties = $querySchema['properties'] ?? [];
        if (!is_array($properties) || [] === $properties) {
            return [];
        }

        $required = $querySchema['required'] ?? [];
        $requiredList = is_array($required) ? $required : [];
        $parameters = [];

        foreach ($properties as $name => $schema) {
            if (!is_string($name)) {
                continue;
            }

            if ('' === $name) {
                continue;
            }

            $schemaObject = is_array($schema) ? $schema : ['type' => 'string'];
            $parameter = [
                'name' => $name,
                'in' => 'query',
                'required' => in_array($name, $requiredList, true),
                'schema' => $schemaObject,
            ];

            if (isset($schemaObject['description']) && is_string($schemaObject['description'])) {
                $parameter['description'] = $schemaObject['description'];
            }

            $parameters[] = $parameter;
        }

        return $parameters;
    }

    /**
     * Generate schema name for a table
     */
    protected function schemaName(string $tableName): string
    {
        return str_replace(['-', ' '], '_', ucfirst($tableName));
    }

    /**
     * Generate full schema for a table (for responses)
     */
    protected function generateTableSchema(string $tableName, array $columns, $tableConfig): array
    {
        $properties = [];
        $required = [];

        // Exclude deleted_at from all response schemas
        $excludeFields = ['deleted_at'];

        foreach ($columns as $name => $info) {
            if (in_array($name, $excludeFields, true)) {
                continue;
            }

            $mapped = $this->mapColumnToOpenApi($info);
            $properties[$name] = $mapped;
            // Avoid forcing typical system fields as required
            if (!(bool) ($info['nullable'] ?? true) && !in_array($name, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
                $required[] = $name;
            }
        }

        // Add default system fields if not present in columns
        if (!isset($properties['id'])) {
            $properties['id'] = [
                'type' => 'integer',
                'format' => 'int64',
                'description' => 'Unique identifier',
                'example' => 1
            ];
        }

        if (!isset($properties['created_at'])) {
            $properties['created_at'] = [
                'type' => 'string',
                'format' => 'date-time',
                'description' => 'Creation timestamp',
                'example' => '2023-01-01T00:00:00Z'
            ];
        }

        if (!isset($properties['updated_at'])) {
            $properties['updated_at'] = [
                'type' => 'string',
                'format' => 'date-time',
                'description' => 'Last update timestamp',
                'example' => '2023-01-01T00:00:00Z'
            ];
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'description' => sprintf('Schema for table `%s` (excludes soft delete field)', $tableName),
        ];
    }

    /**
     * Generate read schema for a table (excludes system timestamps and soft delete)
     */
    protected function generateTableSchemaRead(string $tableName, array $columns, $tableConfig): array
    {
        $properties = [];
        $required = [];

        // Exclude created_at, updated_at, and deleted_at for read operations
        $excludeFields = ['created_at', 'updated_at', 'deleted_at'];

        foreach ($columns as $name => $info) {
            if (in_array($name, $excludeFields, true)) {
                continue;
            }

            $mapped = $this->mapColumnToOpenApi($info);
            $properties[$name] = $mapped;
            // Avoid forcing typical system fields as required
            if (!(bool) ($info['nullable'] ?? true) && !in_array($name, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
                $required[] = $name;
            }
        }

        // Add id if not present in columns
        if (!isset($properties['id'])) {
            $properties['id'] = [
                'type' => 'integer',
                'format' => 'int64',
                'description' => 'Unique identifier',
                'example' => 1
            ];
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'description' => sprintf('Read schema for table `%s` (excludes system timestamps and soft delete)', $tableName),
        ];
    }

    /**
     * Generate write schema for a table (excludes system fields)
     */
    protected function generateTableSchemaWrite(string $tableName, array $columns, $tableConfig): array
    {
        $properties = [];
        $required = [];

        // Exclude system fields for write operations
        $excludeFields = ['id', 'created_at', 'updated_at', 'deleted_at'];

        foreach ($columns as $name => $info) {
            if (in_array($name, $excludeFields, true)) {
                continue;
            }

            $mapped = $this->mapColumnToOpenApi($info);
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
            'description' => sprintf('Write schema for table `%s` (excludes system fields)', $tableName),
        ];
    }

    protected function generateTableSchemaUpdate(string $tableName, array $columns, $tableConfig): array
    {
        $writeSchema = $this->generateTableSchemaWrite($tableName, $columns, $tableConfig);
        unset($writeSchema['required']);
        $writeSchema['description'] = sprintf('Update schema for table `%s` (excludes system fields)', $tableName);

        return $writeSchema;
    }

    /**
     * Generate common schemas used across the API
     */
    protected function generateCommonSchemas(): array
    {
        return [
            'PaginationLinks' => [
                'type' => 'object',
                'properties' => [
                    'first' => ['type' => 'string', 'nullable' => true],
                    'last' => ['type' => 'string', 'nullable' => true],
                    'prev' => ['type' => 'string', 'nullable' => true],
                    'next' => ['type' => 'string', 'nullable' => true]
                ]
            ],
            'PaginationMeta' => [
                'type' => 'object',
                'properties' => [
                    'current_page' => ['type' => 'integer'],
                    'from' => ['type' => 'integer', 'nullable' => true],
                    'last_page' => ['type' => 'integer'],
                    'path' => ['type' => 'string'],
                    'per_page' => ['type' => 'integer'],
                    'to' => ['type' => 'integer', 'nullable' => true],
                    'total' => ['type' => 'integer']
                ]
            ],
            'ErrorResponse' => [
                'type' => 'object',
                'properties' => [
                    'message' => ['type' => 'string'],
                    'errors' => [
                        'type' => 'object',
                        'additionalProperties' => [
                            'type' => 'array',
                            'items' => ['type' => 'string']
                        ]
                    ]
                ],
                'required' => ['message']
            ]
        ];
    }

    /**
     * Generate common parameters
     */
    protected function generateParameters(): array
    {
        return [
            'PathId' => [
                'name' => 'id',
                'in' => 'path',
                'required' => true,
                'description' => 'Resource ID',
                'schema' => ['type' => 'integer', 'format' => 'int64']
            ]
        ];
    }

    /**
     * Generate common responses
     */
    protected function generateResponses(): array
    {
        return [
            'BadRequest' => [
                'description' => 'Bad Request',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'message' => ['type' => 'string', 'example' => 'Bad request']
                            ]
                        ]
                    ]
                ]
            ],
            'Unauthorized' => [
                'description' => 'Unauthorized',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'message' => ['type' => 'string', 'example' => 'Unauthenticated.']
                            ]
                        ]
                    ]
                ]
            ],
            'Forbidden' => [
                'description' => 'Forbidden',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'message' => ['type' => 'string', 'example' => 'This action is unauthorized.']
                            ]
                        ]
                    ]
                ]
            ],
            'NotFound' => [
                'description' => 'Resource not found',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'message' => ['type' => 'string', 'example' => 'Resource not found.']
                            ]
                        ]
                    ]
                ]
            ],
            'ValidationError' => [
                'description' => 'Validation Error',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/ErrorResponse']
                    ]
                ]
            ],
            'InternalServerError' => [
                'description' => 'Internal Server Error',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'message' => ['type' => 'string', 'example' => 'Internal server error.']
                            ]
                        ]
                    ]
                ]
            ]
        ];
    }

    /**
     * Generate list description with filtering and sorting information
     */
    protected function generateListDescription(string $tableName): string
    {
        return "Retrieve a paginated list of {$tableName} records.\n\n" .
               "**Filtering:**\n" .
               "- Use `filter[column]=value` for exact matches\n" .
               "- Use `filter[column][operator]=value` for advanced filtering\n" .
               "- Available operators: `eq`, `ne`, `gt`, `gte`, `lt`, `lte`, `like`, `in`, `not_in`, `null`, `not_null`\n\n" .
               "**Sorting:**\n" .
               "- Use `sort=column` for ascending order\n" .
               "- Use `sort=-column` for descending order\n" .
               "- Multiple sorts: `sort=column1,-column2`\n\n" .
               "**Column Selection:**\n" .
               "- Use `columns=col1,col2` to select specific columns\n\n" .
               "**Relationships:**\n" .
               "- Use `with=relation1,relation2` to include related data";
    }

    /**
     * Generate list parameters
     */
    protected function generateListParameters(): array
    {
        return [
            [
                'name' => 'page',
                'in' => 'query',
                'description' => 'Page number for pagination',
                'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]
            ],
            [
                'name' => 'per_page',
                'in' => 'query',
                'description' => 'Number of items per page',
                'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 15]
            ],
            [
                'name' => 'sort',
                'in' => 'query',
                'description' => 'Sort by column(s). Use `-` prefix for descending order',
                'schema' => ['type' => 'string'],
                'example' => 'created_at,-id'
            ],
            [
                'name' => 'filter',
                'in' => 'query',
                'description' => 'Filter records using various operators',
                'style' => 'deepObject',
                'explode' => true,
                'schema' => ['type' => 'object'],
                'example' => ['status' => 'active', 'created_at' => ['gte' => '2023-01-01']]
            ],
            [
                'name' => 'columns',
                'in' => 'query',
                'description' => 'Select specific columns to return',
                'schema' => ['type' => 'string'],
                'example' => 'id,name,created_at'
            ],
            [
                'name' => 'with',
                'in' => 'query',
                'description' => 'Include related data',
                'schema' => ['type' => 'string'],
                'example' => 'user,category'
            ]
        ];
    }

    /**
     * Generate show parameters
     */
    protected function generateShowParameters(): array
    {
        return [
            [
                'name' => 'columns',
                'in' => 'query',
                'description' => 'Select specific columns to return',
                'schema' => ['type' => 'string'],
                'example' => 'id,name,created_at'
            ],
            [
                'name' => 'with',
                'in' => 'query',
                'description' => 'Include related data',
                'schema' => ['type' => 'string'],
                'example' => 'user,category'
            ]
        ];
    }

    /**
     * Map column configuration to OpenAPI schema
     */
    protected function mapColumnToOpenApi(array $columnConfig): array
    {
        $rawType = strtolower((string) ($columnConfig['type'] ?? 'string'));
        $baseType = trim((string) preg_replace('/\([^)]*\)/', '', $rawType));
        $baseType = trim(explode(' ', $baseType)[0] ?? $baseType);
        $type = '' === $baseType ? 'string' : $baseType;

        $schema = match ($type) {
            'integer', 'bigint' => ['type' => 'integer', 'format' => 'int64'],
            'decimal', 'float', 'double' => ['type' => 'number', 'format' => 'double'],
            'boolean' => ['type' => 'boolean'],
            'date' => ['type' => 'string', 'format' => 'date'],
            'datetime', 'timestamp' => ['type' => 'string', 'format' => 'date-time'],
            'json' => ['type' => 'object'],
            'text', 'longtext' => ['type' => 'string'],
            default => ['type' => 'string'],
        };

        // Add nullable if specified
        if (isset($columnConfig['nullable']) && $columnConfig['nullable']) {
            $schema['nullable'] = true;
        }

        // Add description if specified
        if (isset($columnConfig['description'])) {
            $schema['description'] = $columnConfig['description'];
        }

        // Add example if specified
        if (isset($columnConfig['example'])) {
            $schema['example'] = $columnConfig['example'];
        }

        // Add validation rules
        if (isset($columnConfig['max_length'])) {
            $schema['maxLength'] = $columnConfig['max_length'];
        }

        if (isset($columnConfig['min_length'])) {
            $schema['minLength'] = $columnConfig['min_length'];
        }

        if (isset($columnConfig['enum'])) {
            $schema['enum'] = $columnConfig['enum'];
        }

        return $schema;
    }

    /**
     * Generate paths for a specific table
     */
    protected function generateTablePaths(string $tableName, $tableConfig): array
    {
        $paths = [];
        $apiPrefix = config('record.api_prefix', 'api');
        $basePath = sprintf('/%s/%s', $apiPrefix, $tableName);
        $itemPath = sprintf('/%s/%s/{id}', $apiPrefix, $tableName);
        $bulkPath = sprintf('/%s/%s/bulk', $apiPrefix, $tableName);

        // Determine if operations are public
        $isPublicRead = $tableConfig && isset($tableConfig->public) && $tableConfig->public->read ?? false;
        $isPublicWrite = $tableConfig && isset($tableConfig->public) && $tableConfig->public->write ?? false;

        // List operation (GET /api/table)
        $paths[$basePath]['get'] = [
            'summary' => 'List ' . $tableName,
            'description' => $this->generateListDescription($tableName),
            'tags' => [ucfirst($tableName)],
            'security' => $isPublicRead ? [] : [['bearerAuth' => []]],
            'parameters' => $this->generateListParameters(),
            'responses' => [
                '200' => [
                    'description' => 'Successful response',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'data' => [
                                        'type' => 'array',
                                        'items' => ['$ref' => '#/components/schemas/' . $this->schemaName($tableName)]
                                    ],
                                    'links' => ['$ref' => '#/components/schemas/PaginationLinks'],
                                    'meta' => ['$ref' => '#/components/schemas/PaginationMeta']
                                ]
                            ]
                        ]
                    ]
                ],
                '400' => ['$ref' => '#/components/responses/BadRequest'],
                '401' => ['$ref' => '#/components/responses/Unauthorized'],
                '403' => ['$ref' => '#/components/responses/Forbidden'],
                '500' => ['$ref' => '#/components/responses/InternalServerError']
            ]
        ];

        // Create operation (POST /api/table)
        $paths[$basePath]['post'] = [
            'summary' => 'Create ' . $tableName,
            'description' => sprintf('Create a new %s record', $tableName),
            'tags' => [ucfirst($tableName)],
            'security' => $isPublicWrite ? [] : [['bearerAuth' => []]],
            'requestBody' => [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/' . $this->schemaName($tableName) . 'Write']
                    ]
                ]
            ],
            'responses' => [
                '201' => [
                    'description' => 'Resource created successfully',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'data' => ['$ref' => '#/components/schemas/' . $this->schemaName($tableName)]
                                ]
                            ]
                        ]
                    ]
                ],
                '400' => ['$ref' => '#/components/responses/BadRequest'],
                '401' => ['$ref' => '#/components/responses/Unauthorized'],
                '403' => ['$ref' => '#/components/responses/Forbidden'],
                '422' => ['$ref' => '#/components/responses/ValidationError'],
                '500' => ['$ref' => '#/components/responses/InternalServerError']
            ]
        ];

        // Show operation (GET /api/table/{id})
        $paths[$itemPath]['get'] = [
            'summary' => 'Get ' . $tableName,
            'description' => sprintf('Retrieve a specific %s record by ID', $tableName),
            'tags' => [ucfirst($tableName)],
            'security' => $isPublicRead ? [] : [['bearerAuth' => []]],
            'parameters' => array_merge(
                [['$ref' => '#/components/parameters/PathId']],
                $this->generateShowParameters()
            ),
            'responses' => [
                '200' => [
                    'description' => 'Successful response',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'data' => ['$ref' => '#/components/schemas/' . $this->schemaName($tableName)]
                                ]
                            ]
                        ]
                    ]
                ],
                '400' => ['$ref' => '#/components/responses/BadRequest'],
                '401' => ['$ref' => '#/components/responses/Unauthorized'],
                '403' => ['$ref' => '#/components/responses/Forbidden'],
                '404' => ['$ref' => '#/components/responses/NotFound'],
                '500' => ['$ref' => '#/components/responses/InternalServerError']
            ]
        ];

        // Update operations (PUT/PATCH /api/table/{id})
        $updateOperation = [
            'summary' => 'Update ' . $tableName,
            'description' => sprintf('Update a specific %s record', $tableName),
            'tags' => [ucfirst($tableName)],
            'security' => $isPublicWrite ? [] : [['bearerAuth' => []]],
            'parameters' => [['$ref' => '#/components/parameters/PathId']],
            'requestBody' => [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/' . $this->schemaName($tableName) . 'Update']
                    ]
                ]
            ],
            'responses' => [
                '200' => [
                    'description' => 'Resource updated successfully',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'data' => ['$ref' => '#/components/schemas/' . $this->schemaName($tableName)]
                                ]
                            ]
                        ]
                    ]
                ],
                '400' => ['$ref' => '#/components/responses/BadRequest'],
                '401' => ['$ref' => '#/components/responses/Unauthorized'],
                '403' => ['$ref' => '#/components/responses/Forbidden'],
                '404' => ['$ref' => '#/components/responses/NotFound'],
                '422' => ['$ref' => '#/components/responses/ValidationError'],
                '500' => ['$ref' => '#/components/responses/InternalServerError']
            ]
        ];

        $paths[$itemPath]['put'] = $updateOperation;
        $paths[$itemPath]['patch'] = $updateOperation;

        // Delete operation (DELETE /api/table/{id})
        $paths[$itemPath]['delete'] = [
            'summary' => 'Delete ' . $tableName,
            'description' => sprintf('Delete a specific %s record', $tableName),
            'tags' => [ucfirst($tableName)],
            'security' => $isPublicWrite ? [] : [['bearerAuth' => []]],
            'parameters' => [['$ref' => '#/components/parameters/PathId']],
            'responses' => [
                '200' => [
                    'description' => 'Resource deleted successfully',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'message' => ['type' => 'string', 'example' => 'Resource deleted successfully']
                                ]
                            ]
                        ]
                    ]
                ],
                '400' => ['$ref' => '#/components/responses/BadRequest'],
                '401' => ['$ref' => '#/components/responses/Unauthorized'],
                '403' => ['$ref' => '#/components/responses/Forbidden'],
                '404' => ['$ref' => '#/components/responses/NotFound'],
                '500' => ['$ref' => '#/components/responses/InternalServerError']
            ]
        ];

        // Bulk operation (POST /api/table/bulk)
        $paths[$bulkPath]['post'] = [
            'summary' => 'Bulk operations for ' . $tableName,
            'description' => sprintf('Perform bulk create, update, or delete operations on %s records', $tableName),
            'tags' => [ucfirst($tableName)],
            'security' => $isPublicWrite ? [] : [['bearerAuth' => []]],
            'requestBody' => [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'operation' => [
                                    'type' => 'string',
                                    'enum' => ['create', 'update', 'delete'],
                                    'description' => 'The bulk operation to perform'
                                ],
                                'data' => [
                                    'type' => 'array',
                                    'items' => ['$ref' => '#/components/schemas/' . $this->schemaName($tableName)],
                                    'description' => 'Array of records to process'
                                ]
                            ],
                            'required' => ['operation', 'data']
                        ]
                    ]
                ]
            ],
            'responses' => [
                '200' => [
                    'description' => 'Bulk operation completed successfully',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'message' => ['type' => 'string', 'example' => 'Bulk operation completed successfully'],
                                    'processed' => ['type' => 'integer', 'description' => 'Number of records processed'],
                                    'errors' => [
                                        'type' => 'array',
                                        'items' => ['type' => 'object'],
                                        'description' => 'Any errors that occurred during processing'
                                    ]
                                ]
                            ]
                        ]
                    ]
                ],
                '400' => ['$ref' => '#/components/responses/BadRequest'],
                '401' => ['$ref' => '#/components/responses/Unauthorized'],
                '403' => ['$ref' => '#/components/responses/Forbidden'],
                '422' => ['$ref' => '#/components/responses/ValidationError'],
                '500' => ['$ref' => '#/components/responses/InternalServerError']
            ]
        ];

        return $paths;
    }
}
