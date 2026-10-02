<?php

declare(strict_types=1);

namespace Sopheak\Core\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Sopheak\Core\Http\Middleware\VerifySchemaMcpToken;
use Sopheak\Core\Services\McpServerService;

class ApiSchemaMcpController extends Controller
{
    /**
     * The bearer-token check travels with the controller, so it applies
     * wherever this controller is routed.
     */
    public function __construct()
    {
        $this->middleware(VerifySchemaMcpToken::class);
    }

    /**
     * Handle an incoming MCP JSON-RPC request (schema-only mode).
     *
     * This endpoint only serves the four schema discovery tools:
     *   - sp_api_list_endpoints
     *   - sp_api_get_endpoint
     *   - sp_api_list_permissions
     *   - sp_api_get_api_guidance
     *
     * CRUD data-access tools are NEVER exposed through this route.
     */
    public function handle(Request $request)
    {
        $service = new McpServerService(schemaOnly: true);
        $response = $service->handleRequest($request->json()->all());

        if ($response === null) {
            return response()->noContent();
        }

        return response()->json($response);
    }
}
