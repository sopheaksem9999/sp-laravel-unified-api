<?php

declare(strict_types=1);

namespace Sopheak\Core\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Sopheak\Core\Services\McpServerService;

class ApiSchemaMcpController extends Controller
{
    /**
     * Handle an incoming MCP JSON-RPC request (schema-only mode).
     *
     * This endpoint only serves the three schema discovery tools:
     *   - sp_api_list_endpoints
     *   - sp_api_get_endpoint
     *   - sp_api_list_permissions
     *
     * CRUD data-access tools are NEVER exposed through this route.
     */
    public function handle(Request $request)
    {
        // Auth check
        $token = config('sp-api-mcp.token');
        $isLocal = app()->environment('local');

        if ($token !== null) {
            if ($request->bearerToken() !== $token) {
                return response()->json([
                    'jsonrpc' => '2.0',
                    'id' => $request->json('id'),
                    'error' => [
                        'code' => -32001,
                        'message' => 'Invalid MCP token',
                    ],
                ], 401);
            }
        } elseif (!$isLocal) {
            // Non-local without token configured = require auth
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => $request->json('id'),
                'error' => [
                    'code' => -32001,
                    'message' => 'MCP schema requires authentication',
                ],
            ], 401);
        }

        $service = new McpServerService(schemaOnly: true);
        $response = $service->handleRequest($request->json()->all());

        if ($response === null) {
            return response()->noContent();
        }

        return response()->json($response);
    }
}
