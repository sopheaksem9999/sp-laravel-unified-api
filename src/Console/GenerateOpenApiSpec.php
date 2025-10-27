<?php

namespace Sopheak\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Sopheak\Core\Types\RecordTableType;

class GenerateOpenApiSpec extends Command
{
    protected $signature = 'sp-laravel-api:openapi {--out= : Output file path}';
    protected $description = 'Generate OpenAPI 3 specification based on record configuration.';

    public function handle(): int
    {
        $defaultOut = storage_path('api-v2.json');
        $out = $this->option('out') ?: $defaultOut;

        $this->info('Generating OpenAPI specification from record configuration...');

        $spec = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => config('app.name', 'Laravel') . ' API',
                'version' => 'v2',
                'description' => 'Dynamic API for database operations based on record configuration'
            ],
            'servers' => [
                ['url' => config('app.url') ?: 'http://localhost']
            ],
            'paths' => $this->generatePaths(),
            'components' => $this->generateComponents(),
            'x-generated-at' => now()->toIso8601String(),
            'x-request-id' => (string) Str::uuid(),
        ];

        try {
            $dir = dirname($out);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($out, json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info('✅ OpenAPI spec written to: ' . $out);
            
            $tableCount = count(config('record.tables', []));
            $this->info("📊 Generated documentation for {$tableCount} table(s)");
            
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('❌ Failed to write spec: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Generate OpenAPI paths based on record configuration
     */
    protected function generatePaths(): array
    {
        $paths = [];
        $apiPrefix = config('record.api_prefix', 'api');
        $tables = config('record.tables', []);

        foreach ($tables as $tableName => $config) {
            $tableConfig = $config instanceof RecordTableType ? $config : null;
            
            // Generate CRUD paths for each table
            $basePath = "/{$apiPrefix}/{$tableName}";
            $itemPath = "/{$apiPrefix}/{$tableName}/{id}";

            // List/Create operations
            $paths[$basePath] = [
                'get' => $this->generateListOperation($tableName, $tableConfig),
                'post' => $this->generateCreateOperation($tableName, $tableConfig),
            ];

            // Show/Update/Delete operations
            $paths[$itemPath] = [
                'get' => $this->generateShowOperation($tableName, $tableConfig),
                'put' => $this->generateUpdateOperation($tableName, $tableConfig),
                'patch' => $this->generateUpdateOperation($tableName, $tableConfig),
                'delete' => $this->generateDeleteOperation($tableName, $tableConfig),
            ];

            // Bulk operations
            $paths["{$basePath}/bulk"] = [
                'post' => $this->generateBulkOperation($tableName, $tableConfig),
            ];
        }

        return $paths;
    }

    /**
     * Generate OpenAPI components (schemas, security schemes, etc.)
     */
    protected function generateComponents(): array
    {
        return [
            'securitySchemes' => [
                'bearerAuth' => [
                    'type' => 'http',
                    'scheme' => 'bearer',
                    'bearerFormat' => 'JWT'
                ]
            ],
            'schemas' => $this->generateSchemas(),
            'responses' => [
                'UnauthorizedError' => [
                    'description' => 'Authentication information is missing or invalid',
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
                'ForbiddenError' => [
                    'description' => 'Insufficient permissions',
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
                'ValidationError' => [
                    'description' => 'Validation failed',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'message' => ['type' => 'string'],
                                    'errors' => ['type' => 'object']
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];
    }

    /**
     * Generate schemas for each table
     */
    protected function generateSchemas(): array
    {
        $schemas = [];
        $tables = config('record.tables', []);

        foreach ($tables as $tableName => $config) {
            $schemas[ucfirst($tableName)] = [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'example' => 1],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                    'updated_at' => ['type' => 'string', 'format' => 'date-time'],
                ],
                'description' => "Schema for {$tableName} table"
            ];

            // Add soft delete timestamp if enabled
            $tableConfig = $config instanceof RecordTableType ? $config : null;
            if ($tableConfig && $tableConfig->soft_deletes) {
                $schemas[ucfirst($tableName)]['properties']['deleted_at'] = [
                    'type' => 'string',
                    'format' => 'date-time',
                    'nullable' => true
                ];
            }

            // Add tenant_id if enabled
            if ($tableConfig && $tableConfig->has_tenant_id) {
                $schemas[ucfirst($tableName)]['properties']['tenant_id'] = [
                    'type' => 'integer',
                    'example' => 1
                ];
            }
        }

        return $schemas;
    }

    /**
     * Generate list operation
     */
    protected function generateListOperation(string $tableName, ?RecordTableType $config): array
    {
        $isPublicRead = $config && $config->public && $config->public->read;
        
        return [
            'summary' => "List {$tableName}",
            'description' => "Retrieve a paginated list of {$tableName} records with filtering and sorting",
            'tags' => [ucfirst($tableName)],
            'security' => $isPublicRead ? [] : [['bearerAuth' => []]],
            'parameters' => [
                ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'maximum' => 100], 'description' => 'Items per page'],
                ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer'], 'description' => 'Page number'],
                ['name' => 'search', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Search term'],
                ['name' => 'sortby', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Sort field'],
                ['name' => 'order', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['asc', 'desc']], 'description' => 'Sort order'],
            ],
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
                                        'items' => ['$ref' => "#/components/schemas/" . ucfirst($tableName)]
                                    ],
                                    'meta' => ['type' => 'object'],
                                    'links' => ['type' => 'object']
                                ]
                            ]
                        ]
                    ]
                ],
                '401' => ['$ref' => '#/components/responses/UnauthorizedError'],
                '403' => ['$ref' => '#/components/responses/ForbiddenError']
            ]
        ];
    }

    /**
     * Generate create operation
     */
    protected function generateCreateOperation(string $tableName, ?RecordTableType $config): array
    {
        $isPublicWrite = $config && $config->public && $config->public->write;
        
        return [
            'summary' => "Create {$tableName}",
            'description' => "Create a new {$tableName} record",
            'tags' => [ucfirst($tableName)],
            'security' => $isPublicWrite ? [] : [['bearerAuth' => []]],
            'requestBody' => [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => "#/components/schemas/" . ucfirst($tableName)]
                    ]
                ]
            ],
            'responses' => [
                '201' => [
                    'description' => 'Record created successfully',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'data' => ['$ref' => "#/components/schemas/" . ucfirst($tableName)]
                                ]
                            ]
                        ]
                    ]
                ],
                '401' => ['$ref' => '#/components/responses/UnauthorizedError'],
                '403' => ['$ref' => '#/components/responses/ForbiddenError'],
                '422' => ['$ref' => '#/components/responses/ValidationError']
            ]
        ];
    }

    /**
     * Generate show operation
     */
    protected function generateShowOperation(string $tableName, ?RecordTableType $config): array
    {
        $isPublicRead = $config && $config->public && $config->public->read;
        
        return [
            'summary' => "Get {$tableName}",
            'description' => "Retrieve a specific {$tableName} record",
            'tags' => [ucfirst($tableName)],
            'security' => $isPublicRead ? [] : [['bearerAuth' => []]],
            'parameters' => [
                ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer'], 'description' => 'Record ID']
            ],
            'responses' => [
                '200' => [
                    'description' => 'Successful response',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'data' => ['$ref' => "#/components/schemas/" . ucfirst($tableName)]
                                ]
                            ]
                        ]
                    ]
                ],
                '404' => ['description' => 'Record not found'],
                '401' => ['$ref' => '#/components/responses/UnauthorizedError'],
                '403' => ['$ref' => '#/components/responses/ForbiddenError']
            ]
        ];
    }

    /**
     * Generate update operation
     */
    protected function generateUpdateOperation(string $tableName, ?RecordTableType $config): array
    {
        $isPublicWrite = $config && $config->public && $config->public->write;
        
        return [
            'summary' => "Update {$tableName}",
            'description' => "Update a specific {$tableName} record",
            'tags' => [ucfirst($tableName)],
            'security' => $isPublicWrite ? [] : [['bearerAuth' => []]],
            'parameters' => [
                ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer'], 'description' => 'Record ID']
            ],
            'requestBody' => [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => "#/components/schemas/" . ucfirst($tableName)]
                    ]
                ]
            ],
            'responses' => [
                '200' => [
                    'description' => 'Record updated successfully',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'data' => ['$ref' => "#/components/schemas/" . ucfirst($tableName)]
                                ]
                            ]
                        ]
                    ]
                ],
                '404' => ['description' => 'Record not found'],
                '401' => ['$ref' => '#/components/responses/UnauthorizedError'],
                '403' => ['$ref' => '#/components/responses/ForbiddenError'],
                '422' => ['$ref' => '#/components/responses/ValidationError']
            ]
        ];
    }

    /**
     * Generate delete operation
     */
    protected function generateDeleteOperation(string $tableName, ?RecordTableType $config): array
    {
        $isPublicWrite = $config && $config->public && $config->public->write;
        
        return [
            'summary' => "Delete {$tableName}",
            'description' => "Delete a specific {$tableName} record",
            'tags' => [ucfirst($tableName)],
            'security' => $isPublicWrite ? [] : [['bearerAuth' => []]],
            'parameters' => [
                ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer'], 'description' => 'Record ID']
            ],
            'responses' => [
                '200' => ['description' => 'Record deleted successfully'],
                '404' => ['description' => 'Record not found'],
                '401' => ['$ref' => '#/components/responses/UnauthorizedError'],
                '403' => ['$ref' => '#/components/responses/ForbiddenError']
            ]
        ];
    }

    /**
     * Generate bulk operation
     */
    protected function generateBulkOperation(string $tableName, ?RecordTableType $config): array
    {
        $isPublicWrite = $config && $config->public && $config->public->write;
        
        return [
            'summary' => "Bulk operations for {$tableName}",
            'description' => "Perform bulk create, update, or delete operations",
            'tags' => [ucfirst($tableName)],
            'security' => $isPublicWrite ? [] : [['bearerAuth' => []]],
            'requestBody' => [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'operation' => ['type' => 'string', 'enum' => ['create', 'update', 'delete']],
                                'data' => [
                                    'type' => 'array',
                                    'items' => ['$ref' => "#/components/schemas/" . ucfirst($tableName)]
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            'responses' => [
                '200' => ['description' => 'Bulk operation completed successfully'],
                '401' => ['$ref' => '#/components/responses/UnauthorizedError'],
                '403' => ['$ref' => '#/components/responses/ForbiddenError'],
                '422' => ['$ref' => '#/components/responses/ValidationError']
            ]
        ];
    }
}
