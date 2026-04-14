<?php

namespace Sopheak\Core\Services;

use Exception;
use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Utilities\PermissionUtils;
use Sopheak\Core\Services\RecordConfigService;
use Illuminate\Support\Facades\Gate;
use Sopheak\Core\Constants\RecordConstants;

class McpServerService
{
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
        SchemaRegistryUtils::refresh();
        $tools = [];
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

        return ['tools' => $tools];
    }

    protected function handleToolsCall(array $params): array
    {
        $name = $params['name'] ?? '';
        $args = $params['arguments'] ?? [];

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
        foreach ($perms as $perm) {
            if ($authHandler !== null) {
                $granted = is_string($authHandler)
                    ? (bool) app($authHandler)->handle($user, $perm, $table, $action)
                    : (bool) $authHandler($user, $perm, $table, $action);
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
}
