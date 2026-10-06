<?php

declare(strict_types=1);

namespace Sopheak\Core\Services;

use Exception;
use Sopheak\Core\Mcp\ToolCatalog;
use Sopheak\Core\Mcp\ToolError;
use Sopheak\Core\Mcp\ToolExecutor;
use Sopheak\Core\Mcp\ToolDefinition;

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
     * @return array<string, array<int, array<string, string>>>
     */
    protected function handleResourcesList(array $params): array
    {
        return ['resources' => (new ToolCatalog())->resources()];
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function handleResourcesRead(array $params): array
    {
        try {
            return (new ToolExecutor($this->schemaOnly))->readResource((string) ($params['uri'] ?? ''));
        } catch (ToolError $toolError) {
            throw new Exception($toolError->getMessage(), $toolError->getCode(), $toolError);
        }
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function handleToolsList(array $params): array
    {
        return ['tools' => array_map(
            static fn(ToolDefinition $tool): array => $tool->toWireArray(),
            (new ToolCatalog())->tools($this->schemaOnly)
        )];
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function handleToolsCall(array $params): array
    {
        try {
            return (new ToolExecutor($this->schemaOnly))
                ->call((string) ($params['name'] ?? ''), $params['arguments'] ?? [])
                ->toWireArray();
        } catch (ToolError $toolError) {
            throw new Exception(message: $toolError->getMessage(), code: $toolError->getCode(), previous: $toolError);
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




    // ─── Helpers ────────────────────────────────────────────────────────────

















}
