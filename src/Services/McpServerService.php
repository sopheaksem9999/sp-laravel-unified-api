<?php

declare(strict_types=1);

namespace Sopheak\Core\Services;

use Throwable;
use Closure;
use Exception;
use Sopheak\Core\Utilities\RecordUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Sopheak\Core\Types\RecordFunctionType;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Types\RecordValidationType;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Utilities\PermissionUtils;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Constants\RecordConstants;
use Sopheak\Core\Enums\RecordRelationshipsEnum;
use Sopheak\Core\Exceptions\NestedWriteRefusedException;
use Sopheak\Core\Utilities\NestedWriteAuthorizer;
use Illuminate\Support\Facades\DB;

class McpServerService
{
    public function __construct(protected bool $schemaOnly = false) {}

    /**
     * Handle an incoming JSON-RPC request payload.
     *
     * @return array|null The JSON-RPC response payload, or null if it's a notification
     * @param array<string, mixed> $payload
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
            default => throw new Exception(message: 'Method not found', code: -32601),
        };
    }

    /**
     * @return array<string, array<string, array<string, bool>>|array<string, string>|string>
     */
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

    /**
     * @return array<string, list<array<string, string>>>
     */
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

    /**
     * @param array<string, mixed> $params
     * @return array<string, array<int, array<string, mixed>>>
     */
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
                'additionalProperties' => false,
            ],
            'outputSchema' => $this->endpointsOutputSchema(),
        ];

        $tools[] = [
            'name' => 'sp_api_get_endpoint',
            'description' => 'Get full schema for a single endpoint: fields, filters, sorts, includes, relationships, validation rules, and auth requirements. '
                . 'Check includes[].writable/payloadHint before writing related data — relationships marked writable:true can be nested in the SAME create/update payload (one request) instead of a separate request per child table.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'endpoint' => [
                        'type' => 'string',
                        'description' => "Endpoint name from sp_api_list_endpoints, e.g. 'invoices'",
                    ],
                ],
                'required' => ['endpoint'],
                'additionalProperties' => false,
            ],
            'outputSchema' => $this->endpointOutputSchema(),
        ];

        $tools[] = [
            'name' => 'sp_api_list_permissions',
            'description' => 'List all available permissions grouped by resource.',
            'inputSchema' => [
                'type' => 'object',
                'additionalProperties' => false,
            ],
            'outputSchema' => $this->permissionsOutputSchema(),
        ];

        $tools[] = [
            'name' => 'sp_api_get_api_guidance',
            'description' => 'Explain the Data MCP and Schema MCP roles, then guide an agent through discovering and safely calling a documented API endpoint.',
            'inputSchema' => [
                'type' => 'object',
                'additionalProperties' => false,
            ],
            'outputSchema' => $this->guidanceOutputSchema(),
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
                                'description' => "Filters are {column: 'operator.value'} pairs, e.g. {\"status\": \"eq.open\", \"total_amount\": \"gte.100\"} "
                                    . '(see filters[] on sp_api_get_endpoint for the operators each field supports). '
                                    . "Supports 'limit' (RECOMMENDED for AI queries: fast top/recent record retrieval without COUNT overhead), 'select', 'sortby', 'order'. "
                                    . "Use 'per_page' and 'page' only when actively paginating across multiple pages. "
                                    . "Do not nest a filter under a 'filter' key or use bracket syntax like column[operator]=value — pass the column name directly as the queryParams key.",
                                'additionalProperties' => true,
                            ],
                        ],
                    ],
                    'outputSchema' => $this->dataToolOutputSchema(),
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
                        ],
                        'required' => ['id'],
                    ],
                    'outputSchema' => $this->dataToolOutputSchema(),
                ];

                if (!$readOnly) {
                    $relationshipHint = !empty($config->relationships)
                        ? ' This table has relationships — call sp_api_get_endpoint first and check includes[].writable/payloadHint: '
                            . 'writable relations can be nested directly in payload to write parent + related rows in a single call, '
                            . 'instead of one request per table.'
                        : '';
                    $unknownFieldHint = ' Every payload key must be a real column or relationship alias from sp_api_get_endpoint '
                        . '(fields[]/includes[]) — an invented or misspelled key is rejected with an error naming it, not silently dropped.';

                    $tools[] = [
                        'name' => 'create_' . $table,
                        'description' => 'Create a new record in ' . $table . '.' . $relationshipHint . $unknownFieldHint,
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'payload' => ['type' => 'object', 'additionalProperties' => true],
                                'queryParams' => ['type' => 'object', 'additionalProperties' => true],
                            ],
                            'required' => ['payload'],
                        ],
                        'outputSchema' => $this->dataToolOutputSchema(),
                    ];

                    $tools[] = [
                        'name' => 'update_' . $table,
                        'description' => 'Update an existing record in ' . $table . '.' . $relationshipHint . $unknownFieldHint,
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => ['string', 'integer']],
                                'payload' => ['type' => 'object', 'additionalProperties' => true],
                                'queryParams' => ['type' => 'object', 'additionalProperties' => true],
                            ],
                            'required' => ['id', 'payload'],
                        ],
                        'outputSchema' => $this->dataToolOutputSchema(),
                    ];

                    $tools[] = [
                        'name' => 'delete_' . $table,
                        'description' => 'Delete a record from ' . $table,
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => ['string', 'integer']],
                                'queryParams' => ['type' => 'object', 'additionalProperties' => true],
                            ],
                            'required' => ['id'],
                        ],
                        'outputSchema' => $this->dataToolOutputSchema(),
                    ];
                }
            }
        }

        return ['tools' => $tools];
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function handleToolsCall(array $params): array
    {
        $name = $params['name'] ?? '';
        $args = $params['arguments'] ?? [];

        // Schema discovery tools
        if (in_array($name, ['sp_api_list_endpoints', 'sp_api_get_endpoint', 'sp_api_list_permissions', 'sp_api_get_api_guidance'], true)) {
            try {
                $result = match ($name) {
                    'sp_api_list_endpoints' => $this->handleSchemaListEndpoints($args),
                    'sp_api_get_endpoint' => $this->handleSchemaGetEndpoint($args),
                    'sp_api_list_permissions' => $this->handleSchemaListPermissions(),
                    'sp_api_get_api_guidance' => $this->handleSchemaGetApiGuidance(),
                };

                $structuredContent = match ($name) {
                    'sp_api_list_endpoints' => ['endpoints' => $result],
                    'sp_api_list_permissions' => ['permissions' => $result],
                    default => $result,
                };

                return $this->toolResult($structuredContent, $result);
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
            throw new Exception(message: 'Tool not found: ' . $name, code: -32601);
        }

        $parts = explode('_', (string) $name, 2);
        if (count($parts) !== 2) {
            throw new Exception(message: 'Tool not found: ' . $name, code: -32601);
        }

        $action = $parts[0];
        $table = $parts[1];

        $readOnly = config('record.mcp.read_only', true);
        if ($readOnly && in_array($action, ['create', 'update', 'delete'])) {
            throw new Exception(message: 'Tool not found or read-only mode is enabled: ' . $name, code: -32601);
        }

        $validActions = ['list', 'read', 'create', 'update', 'delete'];
        if (!in_array($action, $validActions)) {
            throw new Exception(message: 'Tool not found: ' . $name, code: -32601);
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
        $tenantId = $this->resolveToolTenantId($table, $args);

        try {
            $result = match ($action) {
                'list' => RecordService::executeGetByFilter($table, $queryParams, $tenantId, true, 'id'),
                'read' => RecordService::executeGetById($table, $id, $queryParams, $tenantId),
                'create' => NestedWriteAuthorizer::enforce(fn (): array => DB::transaction(fn (): array => RecordService::executeCreate($table, $payload, $queryParams, $tenantId))),
                'update' => NestedWriteAuthorizer::enforce(fn (): array => DB::transaction(fn (): array => RecordService::executeUpdate($table, $id, $payload, $queryParams, $tenantId))),
                'delete' => RecordService::executeDelete($table, $id, $queryParams, $tenantId),
            };

            if (isset($result['data'])) {
                $tableSchema = SchemaRegistryUtils::getTable($table);
                $result['data'] = RecordService::stripHiddenColumns($result['data'], $tableSchema);
            }

            return $this->dataToolResult($result);
        } catch (NestedWriteRefusedException $refused) {
            // Same contract as a refused parent (authorizeAction()): a
            // JSON-RPC error, not an isError tool result.
            throw PermissionUtils::DECISION_UNAUTHENTICATED === $refused->decision
                ? new Exception(message: 'Unauthenticated', code: -32001)
                : new Exception(message: 'Forbidden', code: -32002);
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

    /**
     * Resolve the tenant this tool call is allowed to touch.
     *
     * The tenant is taken from the request — the same authority the HTTP
     * controllers use — and never from the tool arguments. A model can put any
     * value in `arguments`, so trusting `tenantId` there let one company's
     * client read and write another company's rows simply by naming their id,
     * and omitting it disabled scoping altogether.
     *
     * A `tenantId` argument is therefore only an assertion: it must agree with
     * the request, or the call is refused. When a tenant-scoped table resolves
     * no tenant at all, the call is refused rather than silently widened to
     * every tenant.
     *
     * The resolved tenant is returned even for a table that is not itself
     * tenant-scoped. Such a table is still a pivot into tenant-scoped children:
     * `RecordService::executeGetByFilter()` only stamps `resolved_tenant_id`
     * onto its synthetic Request when a tenant is passed, and without it
     * `resolveRelationshipTenantId()` falls through to `input('tenant_id')` —
     * which on that synthetic request is the model's own `queryParams`. Passing
     * the tenant through closes that path; it cannot affect the parent's own
     * query, which is gated on `shouldApplyTenantId()` separately.
     *
     * @param array<string, mixed> $args
     * @throws Exception
     */
    protected function resolveToolTenantId(string $table, array $args): mixed
    {
        if (!RecordConfigService::enableTenantId()) {
            return null;
        }

        $tableSchema = SchemaRegistryUtils::getTable($table);
        if (!$tableSchema instanceof RecordTableType) {
            // "I cannot identify this table" is not the same as "this table has
            // no tenancy"; only the latter may proceed without a tenant.
            throw new Exception(message: 'Unknown table: ' . $table, code: -32001);
        }

        $resolved = RecordUtils::resolveTenantIdFromRequest(request());

        if (array_key_exists('tenantId', $args) && !RecordUtils::isTenantIdMissing($args['tenantId'])) {
            $claimed = $args['tenantId'];

            if (!is_scalar($claimed)) {
                throw new Exception(
                    message: 'The tenantId argument must be a scalar value.',
                    code: -32001,
                );
            }

            // Loose comparison: a header is always a string, an argument may be
            // an int, and "1" and 1 name the same tenant.
            if (
                RecordUtils::isTenantIdMissing($resolved)
                || (string) RecordUtils::normalizeTenantId($claimed) !== (string) RecordUtils::normalizeTenantId($resolved)
            ) {
                throw new Exception(
                    message: 'The tenantId argument does not match the authenticated tenant context.',
                    code: -32001,
                );
            }
        }

        if (RecordUtils::isTenantIdMissing($resolved)) {
            if (RecordUtils::shouldApplyTenantId($tableSchema)) {
                throw new Exception(
                    message: 'Tenant context is required for ' . $table . ' but none was resolved from the request.',
                    code: -32001,
                );
            }

            return null;
        }

        return $resolved;
    }

    protected function authorizeAction(string $table, string $action): void
    {
        // Same decision as HasControllerHelpers::authorizeAction(), mapped to
        // the MCP error codes.
        $decision = PermissionUtils::actionDecision(auth(RecordConfigService::authGuard())->user(), $table, $action);

        if (PermissionUtils::DECISION_UNAUTHENTICATED === $decision) {
            throw new Exception(message: 'Unauthenticated', code: -32001);
        }

        if (PermissionUtils::DECISION_FORBIDDEN === $decision) {
            throw new Exception(message: 'Forbidden', code: -32002);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function successResponse(mixed $id, mixed $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    /**
     * @return array<string, mixed>
     */
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
    /**
     * @param array<string, mixed> $args
     */
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

            // Upsert endpoint
            if ($config->canUpsert ?? true) {
                $tableEndpoints[] = [
                    'name' => $table . '.upsert',
                    'method' => ['POST'],
                    'uri' => '/' . $apiPrefix . '/' . $table . '/upsert',
                    'table' => $table,
                    'actions' => ['upsert'],
                    'isRead' => false,
                    'isWrite' => true,
                ];
            }

            // Restore endpoint (soft-deletable tables only)
            if ($config->canUpdate && ($config->softDeletes ?? false)) {
                $tableEndpoints[] = [
                    'name' => $table . '.restore',
                    'method' => ['POST'],
                    'uri' => '/' . $apiPrefix . '/' . $table . '/{id}/restore',
                    'table' => $table,
                    'actions' => ['restore'],
                    'isRead' => false,
                    'isWrite' => true,
                ];
            }

            // Force delete endpoint
            if ($config->canDelete) {
                $tableEndpoints[] = [
                    'name' => $table . '.forceDelete',
                    'method' => ['DELETE'],
                    'uri' => '/' . $apiPrefix . '/' . $table . '/{id}/force',
                    'table' => $table,
                    'actions' => ['forceDelete'],
                    'isRead' => false,
                    'isWrite' => true,
                ];
            }

            // Bulk endpoints
            if (RecordConfigService::bulkOperationsEnabled()) {
                if ($config->canCreate) {
                    $tableEndpoints[] = [
                        'name' => $table . '.bulkCreate',
                        'method' => ['POST'],
                        'uri' => '/' . $apiPrefix . '/' . $table . '/bulk/create',
                        'table' => $table,
                        'actions' => ['bulkCreate'],
                        'isRead' => false,
                        'isWrite' => true,
                    ];
                }

                if ($config->canUpdate) {
                    $tableEndpoints[] = [
                        'name' => $table . '.bulkUpdate',
                        'method' => ['POST'],
                        'uri' => '/' . $apiPrefix . '/' . $table . '/bulk/update',
                        'table' => $table,
                        'actions' => ['bulkUpdate'],
                        'isRead' => false,
                        'isWrite' => true,
                    ];
                }

                if ($config->canDelete) {
                    $tableEndpoints[] = [
                        'name' => $table . '.bulkDelete',
                        'method' => ['POST'],
                        'uri' => '/' . $apiPrefix . '/' . $table . '/bulk/delete',
                        'table' => $table,
                        'actions' => ['bulkDelete'],
                        'isRead' => false,
                        'isWrite' => true,
                    ];
                }

                if ($config->canUpsert ?? true) {
                    $tableEndpoints[] = [
                        'name' => $table . '.bulkUpsert',
                        'method' => ['POST'],
                        'uri' => '/' . $apiPrefix . '/' . $table . '/bulk/upsert',
                        'table' => $table,
                        'actions' => ['bulkUpsert'],
                        'isRead' => false,
                        'isWrite' => true,
                    ];
                }

                if ($config->canCreate && $config->canUpdate && $config->canDelete) {
                    $tableEndpoints[] = [
                        'name' => $table . '.bulk',
                        'method' => ['POST'],
                        'uri' => '/' . $apiPrefix . '/' . $table . '/bulk',
                        'table' => $table,
                        'actions' => ['bulkMixed'],
                        'isRead' => false,
                        'isWrite' => true,
                    ];
                }
            }

            // Table RPC functions
            if (!empty($config->functions)) {
                foreach ($config->functions as $fnName => $fn) {
                    $fnConfig = $fn instanceof RecordFunctionType ? $fn->toArray() : (array) $fn;
                    $methods = (array) ($fnConfig['httpMethod'] ?? 'GET');
                    $rpcUri = $rpcPrefix === ''
                        ? '/' . $apiPrefix . '/' . $table . '/' . $fnName
                        : '/' . $apiPrefix . '/' . $table . '/' . $rpcPrefix . '/' . $fnName;

                    $tableEndpoints[] = array_merge([
                        'name' => $table . '.' . $fnName,
                        'method' => $methods,
                        'uri' => $rpcUri,
                        'table' => $table,
                        'actions' => ['rpc'],
                        'permission' => $fnConfig['pmsName'] ?? null,
                        'isPublic' => $fnConfig['isPublic'] ?? false,
                    ], $this->functionCallContext($fnConfig));
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

                    return str_contains(mb_strtolower($table), $search);
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

            $endpoints[] = array_merge([
                'name' => 'global.' . $fnName,
                'method' => $methods,
                'uri' => $globalUri,
                'table' => null,
                'actions' => ['rpc'],
                'permission' => $fnConfig['pmsName'] ?? null,
                'isPublic' => $fnConfig['isPublic'] ?? false,
            ], $this->functionCallContext($fnConfig));
        }

        return $this->withEndpointSummaries($endpoints);
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, bool|string|mixed[]|null>
     */
    protected function handleSchemaGetEndpoint(array $args): array
    {
        $endpoint = $args['endpoint'] ?? null;
        if (!$endpoint) {
            throw new Exception('Missing required parameter: endpoint');
        }

        SchemaRegistryUtils::refresh();
        $config = SchemaRegistryUtils::getTable($endpoint);
        if (!$config instanceof RecordTableType || !($config instanceof RecordTableType)) {
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

        if ($config->canUpsert ?? true) {
            $actions['upsert'] = [
                'method' => 'POST',
                'uri' => '/' . $apiPrefix . '/' . $config->table . '/upsert',
                'note' => 'Requires a ?match_on=col1,col2 query parameter naming the columns to match an existing record on.',
            ];
        }

        if ($config->canUpdate && ($config->softDeletes ?? false)) {
            $actions['restore'] = [
                'method' => 'POST',
                'uri' => '/' . $apiPrefix . '/' . $config->table . '/{id}/restore',
                'note' => 'Restores a soft-deleted record.',
            ];
        }

        if ($config->canDelete) {
            $actions['forceDelete'] = [
                'method' => 'DELETE',
                'uri' => '/' . $apiPrefix . '/' . $config->table . '/{id}/force',
                'note' => 'Permanently deletes the record, bypassing soft deletes.',
            ];
        }

        if (RecordConfigService::bulkOperationsEnabled()) {
            $bulkMax = RecordConfigService::bulkMax();

            if ($config->canCreate) {
                $actions['bulkCreate'] = [
                    'method' => 'POST',
                    'uri' => '/' . $apiPrefix . '/' . $config->table . '/bulk/create',
                    'note' => "Body: a JSON array of records to create (max {$bulkMax} per request).",
                ];
            }

            if ($config->canUpdate) {
                $actions['bulkUpdate'] = [
                    'method' => 'POST',
                    'uri' => '/' . $apiPrefix . '/' . $config->table . '/bulk/update',
                    'note' => "Body: a JSON array of records to update, each including its primary key (max {$bulkMax} per request).",
                ];
            }

            if ($config->canDelete) {
                $actions['bulkDelete'] = [
                    'method' => 'POST',
                    'uri' => '/' . $apiPrefix . '/' . $config->table . '/bulk/delete',
                    'note' => "Body: a JSON array of records naming the primary key to delete (max {$bulkMax} per request).",
                ];
            }

            if ($config->canUpsert ?? true) {
                $actions['bulkUpsert'] = [
                    'method' => 'POST',
                    'uri' => '/' . $apiPrefix . '/' . $config->table . '/bulk/upsert',
                    'note' => "Body: a JSON array of records to upsert (max {$bulkMax} per request). Requires ?match_on=col1,col2.",
                ];
            }

            if ($config->canCreate && $config->canUpdate && $config->canDelete) {
                $actions['bulkMixed'] = [
                    'method' => 'POST',
                    'uri' => '/' . $apiPrefix . '/' . $config->table . '/bulk',
                    'note' => "Body: a JSON array of records (max {$bulkMax} per request). Each item's operation (create/update/delete/upsert) "
                        . "is auto-detected from its shape, or set explicitly via an 'operation' field per item.",
                ];
            }
        }

        // Fields from column definitions
        $fields = [];
        if (!empty($config->columns)) {
            $hiddenCols = (array) ($config->columnHiddens ?? []);
            foreach ($config->columns as $colName => $colDef) {
                if (is_string($colDef)) {
                    $colDef = ['type' => $colDef];
                }

                $isHidden = in_array($colName, $hiddenCols, true);
                $isWriteDisabled = in_array($colName, (array) ($config->columnWriteDisabled ?? []), true);

                $in = [];
                if (!$isHidden) {
                    $in[] = 'read';
                }
                if (!$isWriteDisabled) {
                    $in[] = 'write';
                }

                $field = [
                    'name' => $colName,
                    'type' => $colDef['type'] ?? 'string',
                    'nullable' => !(($colDef['nullable'] ?? false) === false),
                    'in' => $in,
                ];
                if ($isHidden) {
                    $field['hidden'] = true;
                }
                if (isset($colDef['enum'])) {
                    $field['enum'] = $colDef['enum'];
                }

                $fields[] = $field;
            }
        }

        $actions = $this->withActionContexts($actions, $fields);

        // Relationships / includes
        $includes = [];
        if (!empty($config->relationships)) {
            foreach ($config->relationships as $relName => $rel) {
                $relConfig = is_object($rel) && method_exists($rel, 'toArray') ? $rel->toArray() : (array) $rel;
                $type = $relConfig['type'] ?? 'unknown';
                $typeValue = $type instanceof RecordRelationshipsEnum ? $type->value : (string) $type;
                $foreignKey = $relConfig['foreignKey'] ?? ($relConfig['foreignPivotKey'] ?? null);

                $include = [
                    'name' => $relName,
                    'type' => $typeValue,
                    'table' => $relConfig['table'] ?? ($relConfig['relatedTable'] ?? null),
                    'foreignKey' => $foreignKey,
                    // Whether this relationship can be sent inline in the parent's create/update
                    // payload — i.e. written in the SAME request instead of a separate follow-up
                    // request per child table. See payloadHint for the exact shape.
                    'writable' => in_array($typeValue, [
                        'hasMany', 'belongsToMany', 'hasManyThrough',
                        'morphMany', 'morphToMany', 'morphByMany', 'spatiePermission',
                    ], true),
                ];

                if ($include['writable']) {
                    $include['allowCreate'] = $relConfig['allowCreate'] ?? true;
                    $include['allowUpdate'] = $relConfig['allowUpdate'] ?? true;
                    $include['allowDelete'] = $relConfig['allowDelete'] ?? true;
                    $include['payloadHint'] = sprintf(
                        '"%s": [1, {"id": 2}, {...fields to create}, {"id": 5, "_delete": true}] — send this alongside the parent fields in one create/update call',
                        $relName
                    );
                } elseif ('belongsTo' === $typeValue) {
                    $include['payloadHint'] = sprintf(
                        'Use the root field "%s": <id> in the same request — do not nest a "%s" object in the payload',
                        $foreignKey ?? ($relName . '_id'),
                        $relName
                    );
                } else {
                    $include['payloadHint'] = 'Not a nested-write alias — set the underlying columns directly on the parent, or use a custom function';
                }

                $includes[] = $include;
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
                $rf = array_merge($rf, $this->functionCallContext($fnConfig));

                $rpcFunctions[] = $rf;
            }
        }

        // Build filter list from columns (excluding hidden columns)
        $filters = [];
        if (!empty($config->columns)) {
            $hiddenCols = (array) ($config->columnHiddens ?? []);
            foreach ($config->columns as $colName => $colDef) {
                if (in_array($colName, $hiddenCols, true)) {
                    continue;
                }

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

        // Sortable fields — all visible columns are sortable
        $hiddenCols = (array) ($config->columnHiddens ?? []);
        $allCols = array_keys((array) ($config->columns ?? []));
        $sorts = array_values(array_diff($allCols, $hiddenCols));
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
            } catch (Throwable) {
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

        foreach (SchemaRegistryUtils::get() as $config) {
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
                } catch (Throwable) {
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

    /** @return array<string, mixed> */
    protected function handleSchemaGetApiGuidance(): array
    {
        $apiPrefix = trim(RecordConfigService::apiPrefix(), '/');
        $mcpPrefix = trim((string) config('record.mcp.route_prefix', 'mcp'), '/');

        return [
            'dataMcp' => [
                'route' => '/' . $apiPrefix . '/' . $mcpPrefix . '/message',
                'purpose' => 'Use authorized CRUD tools to read or modify real database records.',
            ],
            'schemaMcp' => [
                'route' => '/' . $apiPrefix . '/mcp/schema',
                'purpose' => 'Use schema discovery tools for API metadata only; this endpoint never returns database rows.',
            ],
            'workflow' => [
                'Call sp_api_list_endpoints to discover an endpoint.',
                'Call sp_api_get_endpoint before making an HTTP or Data MCP call.',
                'Use only the documented method, URI, parameters, and writeable fields.',
            ],
            'httpRules' => [
                'get' => 'Do not send a request body; send filters, selection, sorting, and pagination as query parameters. For optimal query performance, prefer "limit" over "per_page" when retrieving records without needing multi-page navigation.',
                'write' => 'Send only documented writeable fields in the JSON request body.',
                'response' => 'Successful package API responses use success, error_code, data, and meta.',
            ],
            'querySyntaxExamples' => [
                'description' => 'For GET HTTP requests and list_{table} queryParams. Do not use bracket syntax (filter[col]=val).',
                'fieldFiltering' => [
                    'syntax' => '{column: "operator.value"}',
                    'examples' => [
                        'status' => 'eq.active',
                        'total_amount' => 'gte.100',
                        'role' => 'in.admin,manager',
                        'name' => 'like.%acme%',
                        'deleted_at' => 'is.null',
                    ],
                ],
                'relationshipSelection' => [
                    'syntax' => 'select=col1,col2,relation(*),childRelation(id,name)',
                    'examples' => [
                        'select' => 'id,title,total_amount,items(*),customer(*)',
                    ],
                ],
                'paginationAndSorting' => [
                    'recordLimiting' => [
                        'syntax' => 'limit=N',
                        'recommendedForAgents' => true,
                        'explanation' => 'Use "limit" to fetch a fixed number of records (e.g. top 5, latest 10, search previews). This performs an ultra-fast query without computing expensive COUNT(*) pagination totals.',
                        'example' => ['limit' => 10, 'sortby' => 'created_at', 'order' => 'desc'],
                    ],
                    'pageBased' => [
                        'syntax' => 'page=N&per_page=M',
                        'explanation' => 'Use "per_page" with "page" ONLY when multi-page UI pagination is actively required. Computing total counts for per_page adds query overhead.',
                        'example' => ['page' => 1, 'per_page' => 25],
                    ],
                    'cursorBased' => [
                        'syntax' => 'cursor=TOKEN&limit=N',
                        'explanation' => 'Use cursor pagination for large-scale sequential traversal without offset degradation.',
                        'example' => ['cursor' => 'eyJpZCI6MTAwfQ==', 'direction' => 'next', 'limit' => 25],
                    ],
                    'sorting' => ['sortby' => 'created_at', 'order' => 'desc'],
                ],
                'groupedLogic' => [
                    'syntax' => 'or=(condition1,condition2)',
                    'example' => ['or' => '(status.eq.pending,priority.eq.high)'],
                ],
            ],
            'nestedWriteExamples' => [
                'description' => 'In create_{table} and update_{table} payloads, writable relationships (hasMany, belongsToMany, morphMany) can be nested directly in the parent payload in a single atomic request.',
                'childCollections' => [
                    'explanation' => 'Pass an array under the relationship alias key. Include "id" to update, omit "id" to create, or add "_delete": true to delete.',
                    'example' => [
                        'title' => 'Invoice #1001',
                        'customer_id' => 42,
                        'items' => [
                            ['description' => 'Development services', 'quantity' => 10, 'unit_price' => 150.0],
                            ['id' => 105, 'quantity' => 12],
                            ['id' => 88, '_delete' => true],
                        ],
                    ],
                ],
                'manyToManyPivot' => [
                    'explanation' => 'Pass an array of IDs or object maps to sync/attach pivot associations.',
                    'example' => [
                        'name' => 'Support Agent',
                        'roles' => [1, 3, 5],
                    ],
                ],
                'parentBelongsTo' => [
                    'explanation' => 'Always set the foreign key column on the parent record. Do not nest an object under the belongsTo relation alias.',
                    'example' => [
                        'customer_id' => 42,
                    ],
                ],
            ],
        ];
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $structuredContent
     * @return array<string, mixed>
     */
    private function toolResult(array $structuredContent, mixed $legacyContent): array
    {
        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode($legacyContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                ],
            ],
            'structuredContent' => $structuredContent,
        ];
    }

    /**
     * RecordService returns the current Laravel request for internal processing.
     * It is not part of an API result and cannot be represented as MCP JSON.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function dataToolResult(array $result): array
    {
        unset($result['request']);

        return $this->toolResult(['response' => $result], $result);
    }

    /** @return array<string, mixed> */
    private function endpointsOutputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'endpoints' => ['type' => 'array', 'items' => ['type' => 'object']],
            ],
            'required' => ['endpoints'],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function endpointOutputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function permissionsOutputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'permissions' => ['type' => 'array', 'items' => ['type' => 'object']],
            ],
            'required' => ['permissions'],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function guidanceOutputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'dataMcp' => ['type' => 'object'],
                'schemaMcp' => ['type' => 'object'],
                'workflow' => ['type' => 'array', 'items' => ['type' => 'string']],
                'httpRules' => ['type' => 'object'],
                'querySyntaxExamples' => ['type' => 'object'],
                'nestedWriteExamples' => ['type' => 'object'],
            ],
            'required' => ['dataMcp', 'schemaMcp', 'workflow', 'httpRules', 'querySyntaxExamples', 'nestedWriteExamples'],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function dataToolOutputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'response' => ['type' => 'object'],
            ],
            'required' => ['response'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $actions
     * @param array<int, array<string, mixed>> $fields
     * @return array<string, array<string, mixed>>
     */
    private function withActionContexts(array $actions, array $fields): array
    {
        foreach ($actions as $action => $definition) {
            $bodyless = in_array($action, ['list', 'read', 'delete', 'forceDelete', 'restore'], true);
            $definition['request'] = [
                'pathParameters' => str_contains((string) $definition['uri'], '{id}') ? ['id'] : [],
                'queryParameters' => $this->queryParametersForAction($action),
                'payload' => $bodyless ? null : $this->payloadSchemaForAction($action, $fields),
            ];
            $definition['response'] = [
                'envelope' => ['success', 'error_code', 'data', 'meta'],
                'dataSchema' => $this->dataSchemaForAction($action, $fields),
            ];
            $definition['guidance'] = $bodyless
                ? 'Do not send a request body; send filters and selection as query parameters when the action supports them.'
                : 'Send only documented writeable fields in the JSON request body.';
            $actions[$action] = $definition;
        }

        return $actions;
    }

    /**
     * @param array<int, array<string, mixed>> $endpoints
     * @return array<int, array<string, mixed>>
     */
    private function withEndpointSummaries(array $endpoints): array
    {
        foreach ($endpoints as $index => $endpoint) {
            if (isset($endpoint['request'], $endpoint['response'])) {
                continue;
            }

            $actions = $endpoint['actions'] ?? [];
            $bodyless = array_reduce(
                $actions,
                static fn(bool $carry, mixed $action): bool => $carry && in_array($action, ['list', 'read', 'delete', 'forceDelete'], true),
                true,
            );
            $isCollection = in_array('list', $actions, true);
            $endpoint['request'] = [
                'payload' => $bodyless ? null : [
                    'description' => 'Inspect sp_api_get_endpoint for the exact payload schema before calling.',
                ],
            ];
            $endpoint['response'] = [
                'summary' => $isCollection
                    ? 'Returns a response containing an array of matching records and pagination metadata.'
                    : 'Returns a response containing the action result and metadata.',
            ];
            $endpoint['guidance'] = $bodyless
                ? 'Do not send a request body. Inspect sp_api_get_endpoint for supported query parameters.'
                : 'Call sp_api_get_endpoint before sending a request body.';
            $endpoints[$index] = $endpoint;
        }

        return $endpoints;
    }

    /**
     * @param array<string, mixed> $function
     * @return array<string, mixed>
     */
    private function functionCallContext(array $function): array
    {
        $payloadSchema = $function['payloadSchema'] ?? null;
        $responseSchema = $function['responseSchema'] ?? [
            'type' => 'object',
            'additionalProperties' => true,
            'description' => 'No response schema is configured for this custom RPC.',
        ];

        return [
            'request' => [
                'querySchema' => $function['querySchema'] ?? null,
                'payload' => $payloadSchema,
            ],
            'response' => [
                'dataSchema' => $responseSchema,
            ],
            'guidance' => $payloadSchema === null
                ? 'No request body schema is configured; do not invent a payload. Check querySchema and the function description before calling.'
                : 'Send a JSON request body that conforms to payloadSchema.',
        ];
    }

    /** @return array<int, string> */
    private function queryParametersForAction(string $action): array
    {
        return match ($action) {
            'list', 'read' => ['filters', 'select', 'with', 'sortby', 'order', 'limit', 'per_page', 'page', 'cursor'],
            'upsert', 'bulkUpsert' => ['match_on'],
            default => [],
        };
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @return array<string, mixed>
     */
    private function payloadSchemaForAction(string $action, array $fields): array
    {
        $recordSchema = $this->recordSchema($fields, writeableOnly: true);

        if (str_starts_with($action, 'bulk')) {
            return [
                'type' => 'array',
                'items' => $recordSchema,
            ];
        }

        return $recordSchema;
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @return array<string, mixed>
     */
    private function dataSchemaForAction(string $action, array $fields): array
    {
        $recordSchema = $this->recordSchema($fields);

        if (in_array($action, ['list', 'bulkCreate', 'bulkUpdate', 'bulkDelete', 'bulkUpsert', 'bulkMixed'], true)) {
            return [
                'type' => 'array',
                'items' => $recordSchema,
            ];
        }

        return $recordSchema;
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @return array<string, mixed>
     */
    private function recordSchema(array $fields, bool $writeableOnly = false): array
    {
        $properties = [];
        foreach ($fields as $field) {
            if ($writeableOnly && !in_array('write', $field['in'] ?? [], true)) {
                continue;
            }

            if (!$writeableOnly && !in_array('read', $field['in'] ?? [], true)) {
                continue;
            }

            $properties[(string) $field['name']] = [
                'type' => $this->jsonSchemaType((string) ($field['type'] ?? 'string')),
            ];
            if (isset($field['enum'])) {
                $properties[(string) $field['name']]['enum'] = $field['enum'];
            }
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'additionalProperties' => false,
        ];
    }

    private function jsonSchemaType(string $type): string
    {
        return match ($type) {
            'integer', 'bigint', 'smallint', 'tinyint', 'int', 'unsigned' => 'integer',
            'decimal', 'float', 'double', 'numeric' => 'number',
            'boolean', 'bool' => 'boolean',
            'json', 'array' => 'object',
            default => 'string',
        };
    }

    /**
     * Operator tokens returned here must match QueryBuilderFiltersUtils::FILTER_OPERATORS —
     * they are used as-is in the '{operator}.{value}' filter syntax (e.g. 'eq.5'), not as
     * SQL/comparison symbols.
     */
    private function filterOperatorsForType(string $type): array
    {
        $base = ['eq', 'neq', 'in', 'not_in'];

        return match ($type) {
            'string', 'text', 'varchar', 'char', 'longtext', 'mediumtext' => [
                ...$base, 'contains', 'starts_with', 'ends_with',
            ],
            'integer', 'bigint', 'smallint', 'tinyint', 'int',
            'decimal', 'float', 'double', 'numeric', 'unsigned' => [
                ...$base, 'gt', 'lt', 'gte', 'lte', 'between',
            ],
            'datetime', 'date', 'timestamp', 'time' => [
                'eq', 'neq', 'gt', 'lt', 'gte', 'lte', 'between',
            ],
            'boolean', 'bool' => ['eq', 'neq'],
            'json', 'array' => ['eq', 'contains'],
            default => $base,
        };
    }

    private function describeValidator(mixed $validator): string
    {
        if ($validator === null) {
            return 'none';
        }

        if ($validator instanceof Closure) {
            return 'custom (Closure)';
        }

        if ($validator instanceof RecordValidationType) {
            return $validator->class . '@' . $validator->functionName;
        }

        if (is_array($validator) && count($validator) === 2) {
            $class = is_object($validator[0]) ? $validator[0]::class : $validator[0];

            return $class . '@' . $validator[1];
        }

        if (is_string($validator)) {
            return $validator;
        }

        return 'custom';
    }
}
