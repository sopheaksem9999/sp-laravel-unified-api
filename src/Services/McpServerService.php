<?php

namespace Sopheak\Core\Services;

use Exception;
use Sopheak\Core\Authorization\PermissionService;
use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Sopheak\Core\Types\RecordFunctionType;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Types\RecordValidationType;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Utilities\PermissionUtils;
use Sopheak\Core\Services\RecordConfigService;
use Illuminate\Support\Facades\Gate;
use Sopheak\Core\Constants\RecordConstants;

class McpServerService
{
    public function __construct(protected bool $schemaOnly = false) {}

    /**
     * Handle an incoming JSON-RPC request payload.
     *
     * @return array|null The JSON-RPC response payload, or null if it's a notification
     */
    public function handleRequest(array $payload): ?array
    {
        if (!isset($payload['jsonrpc']) || $payload['jsonrpc'] !== '2.0') {
            return $this->errorResponse(null, -32600, 'Invalid Request');
        }

        $method = $payload['method'] ?? null;
        $id = $payload['id'] ?? null;
        $params = $payload['params'] ?? [];

        if (!$method) {
            return $this->errorResponse($id, -32600, 'Invalid Request');
        }

        try {
            $result = $this->routeMethod($method, $params);

            // If it's a notification (no ID), don't send a response
            if ($id === null) {
                return null;
            }

            return $this->successResponse($id, $result);
        } catch (Exception $exception) {
            if ($id === null) {
                return null;
            }

            // Code -32601 is Method not found
            $code = $exception->getCode() ?: -32603; // Internal error
            if ($exception->getMessage() === 'Method not found') {
                $code = -32601;
            }

            return $this->errorResponse($id, $code, $exception->getMessage());
        }
    }

    protected function routeMethod(string $method, array $params): mixed
    {
        return match ($method) {
            'initialize' => $this->handleInitialize($params),
            'notifications/initialized' => null,
            'resources/list' => $this->handleResourcesList($params),
            'resources/read' => $this->handleResourcesRead($params),
            'tools/list' => $this->handleToolsList($params),
            'tools/call' => $this->handleToolsCall($params),
            default => throw new Exception('Method not found', -32601),
        };
    }

    protected function handleInitialize(array $params): array
    {
        return [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [
                'resources' => [
                    'subscribe' => false,
                    'listChanged' => false,
                ],
                'tools' => [
                    'listChanged' => false,
                ],
            ],
            'serverInfo' => [
                'name' => 'sp-laravel-api-mcp',
                'version' => '1.0.0',
            ],
        ];
    }

    protected function handleResourcesList(array $params): array
    {
        SchemaRegistryUtils::refresh();
        $resources = [];

        foreach (SchemaRegistryUtils::get() as $table => $config) {
            if (!($config instanceof RecordTableType)) {
                continue;
            }

            $resources[] = [
                'uri' => 'schema://' . $table,
                'name' => $table . ' Schema',
                'description' => 'Database schema and configuration for ' . $table,
                'mimeType' => 'application/json',
            ];
        }

        return [
            'resources' => $resources,
        ];
    }

    protected function handleResourcesRead(array $params): array
    {
        $uri = $params['uri'] ?? '';
        if (!str_starts_with($uri, 'schema://')) {
            throw new Exception('Invalid resource URI: ' . $uri);
        }

        $table = substr($uri, 9); // Remove 'schema://'
        SchemaRegistryUtils::refresh();
        $config = SchemaRegistryUtils::get()[$table] ?? null;

        if (!$config || !($config instanceof RecordTableType)) {
            throw new Exception('Resource not found: ' . $uri);
        }

        $schemaData = [
            'table' => $table,
            'primaryKey' => $config->primaryKey,
            'softDeletes' => $config->softDeletes,
            'hasTenantId' => $config->hasTenantId,
            'isAuthRead' => $config->isAuthRead,
            'isAuthWrite' => $config->isAuthWrite,
            'canRead' => $config->canRead,
            'canCreate' => $config->canCreate,
            'canUpdate' => $config->canUpdate,
            'canDelete' => $config->canDelete,
            'canUpsert' => $config->canUpsert,
        ];

        return [
            'contents' => [
                [
                    'uri' => $uri,
                    'mimeType' => 'application/json',
                    'text' => json_encode($schemaData, JSON_PRETTY_PRINT),
                ],
            ],
        ];
    }

    protected function handleToolsList(array $params): array
    {
        $tools = [];

        // Schema discovery tools — always available
        $tools[] = [
            'name' => 'sp_api_list_endpoints',
            'description' => 'List all API endpoints (tables + custom RPCs). Returns endpoint name, HTTP method, URI, table, and supported actions. Supports substring search.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'search' => [
                        'type' => 'string',
                        'description' => 'Filter endpoints by name, table, or URI (case-insensitive substring match)',
                    ],
                ],
            ],
        ];

        $tools[] = [
            'name' => 'sp_api_get_endpoint',
            'description' => 'Get full schema for a single endpoint: fields, filters, sorts, includes, relationships, validation rules, and auth requirements.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'endpoint' => [
                        'type' => 'string',
                        'description' => "Endpoint name from sp_api_list_endpoints, e.g. 'invoices'",
                    ],
                ],
                'required' => ['endpoint'],
            ],
        ];

        $tools[] = [
            'name' => 'sp_api_list_permissions',
            'description' => 'List all available permissions grouped by resource.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => (object) [],
            ],
        ];

        // CRUD data-access tools — only in full mode
        if (! $this->schemaOnly) {
            SchemaRegistryUtils::refresh();
            $readOnly = config('record.mcp.read_only', true);

            foreach (SchemaRegistryUtils::get() as $table => $config) {
                if (!($config instanceof RecordTableType)) {
                    continue;
                }

                $tools[] = [
                    'name' => 'list_' . $table,
                    'description' => 'List records from ' . $table,
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'queryParams' => [
                                'type' => 'object',
                                'description' => 'Query parameters (e.g., filters, sortby, select)',
                                'additionalProperties' => true,
                            ],
                            'tenantId' => ['type' => ['string', 'integer', 'null']],
                        ],
                    ],
                ];

                $tools[] = [
                    'name' => 'read_' . $table,
                    'description' => 'Read a single record from ' . $table,
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => ['string', 'integer']],
                            'queryParams' => [
                                'type' => 'object',
                                'description' => 'Query parameters (e.g., select)',
                                'additionalProperties' => true,
                            ],
                            'tenantId' => ['type' => ['string', 'integer', 'null']],
                        ],
                        'required' => ['id'],
                    ],
                ];

                if (!$readOnly) {
                    $tools[] = [
                        'name' => 'create_' . $table,
                        'description' => 'Create a new record in ' . $table,
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'payload' => ['type' => 'object', 'additionalProperties' => true],
                                'queryParams' => ['type' => 'object', 'additionalProperties' => true],
                                'tenantId' => ['type' => ['string', 'integer', 'null']],
                            ],
                            'required' => ['payload'],
                        ],
                    ];

                    $tools[] = [
                        'name' => 'update_' . $table,
                        'description' => 'Update an existing record in ' . $table,
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => ['string', 'integer']],
                                'payload' => ['type' => 'object', 'additionalProperties' => true],
                                'queryParams' => ['type' => 'object', 'additionalProperties' => true],
                                'tenantId' => ['type' => ['string', 'integer', 'null']],
                            ],
                            'required' => ['id', 'payload'],
                        ],
                    ];

                    $tools[] = [
                        'name' => 'delete_' . $table,
                        'description' => 'Delete a record from ' . $table,
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => ['string', 'integer']],
                                'queryParams' => ['type' => 'object', 'additionalProperties' => true],
                                'tenantId' => ['type' => ['string', 'integer', 'null']],
                            ],
                            'required' => ['id'],
                        ],
                    ];
                }
            }
        }

        return ['tools' => $tools];
    }

    protected function handleToolsCall(array $params): array
    {
        $name = $params['name'] ?? '';
        $args = $params['arguments'] ?? [];

        // Schema discovery tools
        if (in_array($name, ['sp_api_list_endpoints', 'sp_api_get_endpoint', 'sp_api_list_permissions'], true)) {
            try {
                $result = match ($name) {
                    'sp_api_list_endpoints' => $this->handleSchemaListEndpoints($args),
                    'sp_api_get_endpoint' => $this->handleSchemaGetEndpoint($args),
                    'sp_api_list_permissions' => $this->handleSchemaListPermissions(),
                };

                return [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                        ],
                    ],
                ];
            } catch (Exception $exception) {
                return [
                    'isError' => true,
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $exception->getMessage(),
                        ],
                    ],
                ];
            }
        }

        // Schema-only mode: reject any non-schema tool
        if ($this->schemaOnly) {
            throw new Exception('Tool not found: ' . $name, -32601);
        }

        $parts = explode('_', $name, 2);
        if (count($parts) !== 2) {
            throw new Exception('Tool not found: ' . $name, -32601);
        }

        $action = $parts[0];
        $table = $parts[1];

        $readOnly = config('record.mcp.read_only', true);
        if ($readOnly && in_array($action, ['create', 'update', 'delete'])) {
            throw new Exception('Tool not found or read-only mode is enabled: ' . $name, -32601);
        }

        $validActions = ['list', 'read', 'create', 'update', 'delete'];
        if (!in_array($action, $validActions)) {
            throw new Exception('Tool not found: ' . $name, -32601);
        }

        $authAction = match ($action) {
            'list', 'read' => RecordConstants::READ,
            'create' => RecordConstants::ACTION_CREATE,
            'update' => RecordConstants::ACTION_UPDATE,
            'delete' => RecordConstants::ACTION_DELETE,
        };
        $this->authorizeAction($table, $authAction);

        $id = $args['id'] ?? null;
        $payload = $args['payload'] ?? [];
        $queryParams = $args['queryParams'] ?? [];
        $tenantId = $args['tenantId'] ?? null;

        try {
            $result = match ($action) {
                'list' => RecordService::executeGetByFilter($table, $queryParams, $tenantId, true, 'id'),
                'read' => RecordService::executeGetById($table, $id, $queryParams, $tenantId),
                'create' => RecordService::executeCreate($table, $payload, $queryParams, $tenantId),
                'update' => RecordService::executeUpdate($table, $id, $payload, $queryParams, $tenantId),
                'delete' => RecordService::executeDelete($table, $id, $queryParams, $tenantId),
            };

            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode($result, JSON_PRETTY_PRINT),
                    ],
                ],
            ];
        } catch (Exception $exception) {
            return [
                'isError' => true,
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $exception->getMessage(),
                    ],
                ],
            ];
        }
    }

    protected function authorizeAction(string $table, string $action): void
    {
        if (PermissionUtils::isPublicAction($table, $action)) {
            return;
        }

        $guard = RecordConfigService::authGuard();
        $user = auth($guard)->user();
        if (!$user) {
            throw new Exception('Unauthenticated', -32001);
        }

        $tableSchema = SchemaRegistryUtils::getTable($table);
        if ($tableSchema instanceof RecordTableType) {
            if (is_null($tableSchema->pmsName)) {
                return;
            }

            if (is_array($tableSchema->pmsName) && [] === $tableSchema->pmsName) {
                return;
            }
        }

        if ($tableSchema instanceof RecordTableType && is_array($tableSchema->permissions) && isset($tableSchema->permissions[$action])) {
            $perms = (array) $tableSchema->permissions[$action];
        } elseif ($action === 'force_delete' && $tableSchema instanceof RecordTableType && is_array($tableSchema->permissions) && !isset($tableSchema->permissions['force_delete'])) {
            $perms = PermissionUtils::mapPermissions($table, 'force_delete');
        } else {
            $perms = PermissionUtils::mapPermissions($table, $action);
        }

        $allowed = false;
        $authHandler = config('record.authorization');
        $gate = $authHandler === null ? Gate::forUser($user) : null;
        $permissionService = null;

        foreach ($perms as $perm) {
            if ($authHandler !== null) {
                $granted = is_string($authHandler)
                    ? (bool) app($authHandler)->handle($user, $perm, $table, $action)
                    : (bool) $authHandler($user, $perm, $table, $action);
            } elseif (config('permissions.enabled', false)) {
                $permissionService ??= app(PermissionService::class);
                $granted = $permissionService->userHasPermission($user, $perm);
            } else {
                $granted = $gate->allows($perm);
            }

            if ($granted) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed) {
            throw new Exception('Forbidden', -32002);
        }
    }

    protected function successResponse(mixed $id, mixed $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    protected function errorResponse(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }

    // ─── Schema Discovery Tool Handlers ─────────────────────────────────────

    protected function handleSchemaListEndpoints(array $args): array
    {
        SchemaRegistryUtils::refresh();
        $search = isset($args['search']) ? mb_strtolower((string) $args['search']) : null;
        $endpoints = [];

        $apiPrefix = RecordConfigService::apiPrefix();
        $rpcPrefix = trim(RecordConfigService::rpcPrefix(), '/');

        foreach (SchemaRegistryUtils::get() as $table => $config) {
            if (!($config instanceof RecordTableType)) {
                continue;
            }

            $tableEndpoints = [];

            // List endpoint
            if ($config->canRead) {
                $tableEndpoints[] = [
                    'name' => $table,
                    'method' => ['GET'],
                    'uri' => '/' . $apiPrefix . '/' . $table,
                    'table' => $table,
                    'actions' => ['list'],
                    'isRead' => true,
                    'isWrite' => false,
                ];

                // Detail endpoints
                $detailMethods = ['GET'];
                if ($config->canUpdate) {
                    $detailMethods[] = 'PUT';
                    $detailMethods[] = 'PUT';
                    $detailMethods[] = 'PATCH';
                }
                if ($config->canDelete) {
                    $detailMethods[] = 'DELETE';
                }

                $rawActions = ['read'];
                if ($config->canUpdate) {
                    $rawActions[] = 'update';
                }
                if ($config->canDelete) {
                    $rawActions[] = 'delete';
                }

                $tableEndpoints[] = [
                    'name' => $table . '.detail',
                    'method' => $detailMethods,
                    'uri' => '/' . $apiPrefix . '/' . $table . '/{id}',
                    'table' => $table,
                    'actions' => $rawActions,
                    'isRead' => true,
                    'isWrite' => $config->canUpdate || $config->canDelete,
                ];
            }

            // Create endpoint
            if ($config->canCreate) {
                $tableEndpoints[] = [
                    'name' => $table . '.create',
                    'method' => ['POST'],
                    'uri' => '/' . $apiPrefix . '/' . $table,
                    'table' => $table,
                    'actions' => ['create'],
                    'isRead' => false,
                    'isWrite' => true,
                ];
            }

            // Table RPC functions
            if (!empty($config->functions)) {
                foreach ($config->functions as $fnName => $fn) {
                    $fnConfig = $fn instanceof RecordFunctionType ? $fn->toArray() : (array) $fn;
                    $methods = (array) ($fnConfig['httpMethod'] ?? 'GET');
                    $rpcUri = $rpcPrefix === ''
                        ? '/' . $apiPrefix . '/' . $table . '/' . $fnName
                        : '/' . $apiPrefix . '/' . $table . '/' . $rpcPrefix . '/' . $fnName;

                    $tableEndpoints[] = [
                        'name' => $table . '.' . $fnName,
                        'method' => $methods,
                        'uri' => $rpcUri,
                        'table' => $table,
                        'actions' => ['rpc'],
                        'permission' => $fnConfig['pmsName'] ?? null,
                        'isPublic' => $fnConfig['isPublic'] ?? false,
                    ];
                }
            }

            // Filter by search
            if ($search !== null) {
                $tableEndpoints = array_values(array_filter($tableEndpoints, function (array $ep) use ($search, $table): bool {
                    if (str_contains(mb_strtolower($ep['name']), $search)) {
                        return true;
                    }
                    if (str_contains(mb_strtolower($ep['uri']), $search)) {
                        return true;
                    }
                    if (str_contains(mb_strtolower($table), $search)) {
                        return true;
                    }

                    return false;
                }));
            }

            $endpoints = array_merge($endpoints, $tableEndpoints);
        }

        // Global RPC functions
        $globalFunctions = RecordConfigService::globalFunctions();
        foreach ($globalFunctions as $fnName => $fn) {
            $fnConfig = $fn instanceof RecordFunctionType ? $fn->toArray() : (array) $fn;
            $methods = (array) ($fnConfig['httpMethod'] ?? 'GET');
            $globalUri = $rpcPrefix === ''
                ? '/' . $apiPrefix . '/' . $fnName
                : '/' . $apiPrefix . '/' . $rpcPrefix . '/' . $fnName;

            $endpoints[] = [
                'name' => 'global.' . $fnName,
                'method' => $methods,
                'uri' => $globalUri,
                'table' => null,
                'actions' => ['rpc'],
                'permission' => $fnConfig['pmsName'] ?? null,
                'isPublic' => $fnConfig['isPublic'] ?? false,
            ];
        }

        return $endpoints;
    }

    protected function handleSchemaGetEndpoint(array $args): array
    {
        $endpoint = $args['endpoint'] ?? null;
        if (!$endpoint) {
            throw new Exception('Missing required parameter: endpoint');
        }

        SchemaRegistryUtils::refresh();
        $config = SchemaRegistryUtils::getTable($endpoint);
        if (!$config || !($config instanceof RecordTableType)) {
            throw new Exception('Endpoint not found: ' . $endpoint);
        }

        $apiPrefix = RecordConfigService::apiPrefix();
        $rpcPrefix = trim(RecordConfigService::rpcPrefix(), '/');

        // Actions
        $actions = [];
        if ($config->canRead) {
            $actions['list'] = ['method' => 'GET', 'uri' => '/' . $apiPrefix . '/' . $config->table];
            $actions['read'] = ['method' => 'GET', 'uri' => '/' . $apiPrefix . '/' . $config->table . '/{id}'];
        }
        if ($config->canCreate) {
            $actions['create'] = ['method' => 'POST', 'uri' => '/' . $apiPrefix . '/' . $config->table];
        }
        if ($config->canUpdate) {
            $actions['update'] = ['method' => ['PUT', 'PATCH'], 'uri' => '/' . $apiPrefix . '/' . $config->table . '/{id}'];
        }
        if ($config->canDelete) {
            $actions['delete'] = ['method' => 'DELETE', 'uri' => '/' . $apiPrefix . '/' . $config->table . '/{id}'];
        }

        // Fields from column definitions
        $fields = [];
        if (!empty($config->columns)) {
            foreach ($config->columns as $colName => $colDef) {
                if (is_string($colDef)) {
                    $colDef = ['type' => $colDef];
                }
                $in = ['read'];
                if (!in_array($colName, (array) ($config->columnWriteDisabled ?? []), true)) {
                    $in[] = 'write';
                }
                $field = [
                    'name' => $colName,
                    'type' => $colDef['type'] ?? 'string',
                    'nullable' => !(($colDef['nullable'] ?? false) === false),
                    'in' => $in,
                ];
                if (isset($colDef['enum'])) {
                    $field['enum'] = $colDef['enum'];
                }
                $fields[] = $field;
            }
        }

        // Relationships / includes
        $includes = [];
        if (!empty($config->relationships)) {
            foreach ($config->relationships as $relName => $rel) {
                $relConfig = is_object($rel) && method_exists($rel, 'toArray') ? $rel->toArray() : (array) $rel;
                $includes[] = [
                    'name' => $relName,
                    'type' => $relConfig['type'] ?? 'unknown',
                    'table' => $relConfig['table'] ?? ($relConfig['relatedTable'] ?? null),
                    'foreignKey' => $relConfig['foreignKey'] ?? ($relConfig['foreignPivotKey'] ?? null),
                ];
            }
        }

        // RPC functions
        $rpcFunctions = [];
        if (!empty($config->functions)) {
            foreach ($config->functions as $fnName => $fn) {
                $fnConfig = $fn instanceof RecordFunctionType ? $fn->toArray() : (array) $fn;
                $methods = (array) ($fnConfig['httpMethod'] ?? 'GET');
                $rpcUri = $rpcPrefix === ''
                    ? '/' . $apiPrefix . '/' . $config->table . '/' . $fnName
                    : '/' . $apiPrefix . '/' . $config->table . '/' . $rpcPrefix . '/' . $fnName;

                $rf = [
                    'name' => $fnName,
                    'method' => $methods,
                    'uri' => $rpcUri,
                ];
                if (!empty($fnConfig['description'])) {
                    $rf['description'] = $fnConfig['description'];
                }
                $rpcFunctions[] = $rf;
            }
        }

        // Build filter list from columns
        $filters = [];
        if (!empty($config->columns)) {
            foreach ($config->columns as $colName => $colDef) {
                if (is_string($colDef)) {
                    $colDef = ['type' => $colDef];
                }
                $type = $colDef['type'] ?? 'string';
                $operators = $this->filterOperatorsForType($type);
                if (!empty($operators)) {
                    $filters[] = ['field' => $colName, 'operators' => $operators];
                }
            }
        }

        // Sortable fields — all columns are sortable
        $sorts = array_keys((array) ($config->columns ?? []));
        if (empty($sorts)) {
            $sorts = [(string) ($config->primaryKey ?? 'id')];
        }

        // Permissions
        $perms = [];
        $permissionActions = [
            RecordConstants::READ => 'read',
            RecordConstants::WRITE => 'write',
            RecordConstants::ACTION_CREATE => 'create',
            RecordConstants::ACTION_UPDATE => 'update',
            RecordConstants::ACTION_DELETE => 'delete',
            'force_delete' => 'force_delete',
        ];
        foreach ($permissionActions as $action => $key) {
            try {
                $mapped = PermissionUtils::mapPermissions($config->table, $action);
                if (!empty($mapped)) {
                    $perms[$key] = $mapped;
                }
            } catch (\Throwable) {
                // Skip if mapping fails
            }
        }

        // RPC function permissions
        if (!empty($config->functions)) {
            foreach ($config->functions as $fnName => $fn) {
                $fnConfig = $fn instanceof RecordFunctionType ? $fn->toArray() : (array) $fn;
                $pmsName = $fnConfig['pmsName'] ?? null;
                if ($pmsName && !($fnConfig['isPublic'] ?? false)) {
                    $perms[$fnName] = (array) $pmsName;
                }
            }
        }

        // Validation info
        $validation = [];
        if ($config->createValidator !== null) {
            $validation['create'] = $this->describeValidator($config->createValidator);
        }
        if ($config->updateValidator !== null) {
            $validation['update'] = $this->describeValidator($config->updateValidator);
        }
        if ($config->deleteValidator !== null) {
            $validation['delete'] = $this->describeValidator($config->deleteValidator);
        }

        // Scopes from searchable / column indexes
        $scopes = [];
        if (!empty($config->searchable)) {
            $scopes = $config->searchable;
        }

        return [
            'name' => $config->table,
            'table' => $config->table,
            'primaryKey' => $config->primaryKey ?? 'id',
            'softDeletes' => $config->softDeletes,
            'hasTenantId' => $config->hasTenantId,
            'isAuthRead' => $config->isAuthRead,
            'isAuthWrite' => $config->isAuthWrite,
            'authGuard' => RecordConfigService::authGuard(),
            'actions' => $actions,
            'fields' => $fields,
            'filters' => $filters,
            'sorts' => $sorts,
            'includes' => $includes,
            'rpcFunctions' => $rpcFunctions,
            'permissions' => $perms,
            'validation' => $validation,
            'scopes' => $scopes,
        ];
    }

    protected function handleSchemaListPermissions(): array
    {
        SchemaRegistryUtils::refresh();
        $permissions = [];
        $guard = RecordConfigService::authGuard();

        $permissionActions = [
            RecordConstants::READ,
            RecordConstants::WRITE,
            RecordConstants::ACTION_CREATE,
            RecordConstants::ACTION_UPDATE,
            RecordConstants::ACTION_DELETE,
            'force_delete',
        ];

        foreach (SchemaRegistryUtils::get() as $table => $config) {
            if (!($config instanceof RecordTableType)) {
                continue;
            }

            foreach ($permissionActions as $action) {
                try {
                    $mapped = PermissionUtils::mapPermissions($config->table, $action);
                    foreach ($mapped as $perm) {
                        $permissions[] = [
                            'name' => $perm,
                            'guard' => $guard,
                            'table' => $config->table,
                        ];
                    }
                } catch (\Throwable) {
                    // Skip if mapping fails
                }
            }

            // RPC function permissions
            if (!empty($config->functions)) {
                foreach ($config->functions as $fn) {
                    $fnConfig = $fn instanceof RecordFunctionType ? $fn->toArray() : (array) $fn;
                    $pmsName = $fnConfig['pmsName'] ?? null;
                    if ($pmsName && !($fnConfig['isPublic'] ?? false)) {
                        foreach ((array) $pmsName as $perm) {
                            $permissions[] = [
                                'name' => $perm,
                                'guard' => $guard,
                                'table' => $config->table,
                            ];
                        }
                    }
                }
            }
        }

        // Deduplicate
        $seen = [];
        $permissions = array_filter($permissions, function (array $p) use (&$seen): bool {
            $key = $p['name'] . '|' . $p['guard'];
            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;

            return true;
        });

        return array_values($permissions);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function filterOperatorsForType(string $type): array
    {
        $base = ['=', '!=', 'in', 'not_in'];

        return match ($type) {
            'string', 'text', 'varchar', 'char', 'longtext', 'mediumtext' => [
                ...$base, 'contains', 'starts_with', 'ends_with',
            ],
            'integer', 'bigint', 'smallint', 'tinyint', 'int',
            'decimal', 'float', 'double', 'numeric', 'unsigned' => [
                ...$base, '>', '<', '>=', '<=', 'between',
            ],
            'datetime', 'date', 'timestamp', 'time' => [
                '=', '!=', '>', '<', '>=', '<=', 'between',
            ],
            'boolean', 'bool' => ['=', '!='],
            'json', 'array' => ['=', 'contains'],
            default => $base,
        };
    }

    private function describeValidator(mixed $validator): string|array
    {
        if ($validator === null) {
            return 'none';
        }

        if ($validator instanceof \Closure) {
            return 'custom (Closure)';
        }

        if ($validator instanceof RecordValidationType) {
            return $validator->class . '@' . $validator->functionName;
        }

        if (is_array($validator) && count($validator) === 2) {
            $class = is_object($validator[0]) ? get_class($validator[0]) : $validator[0];

            return $class . '@' . $validator[1];
        }

        if (is_string($validator)) {
            return $validator;
        }

        return 'custom';
    }
}
