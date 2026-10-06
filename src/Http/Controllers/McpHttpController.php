<?php

declare(strict_types=1);

namespace Sopheak\Core\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Sopheak\Core\Services\McpServerService;
use Symfony\Component\HttpFoundation\Response;

class McpHttpController extends Controller
{
    public function __construct(protected McpServerService $mcpService) {}

    public function handlePost(Request $request)
    {
        $payload = $request->json()->all();
        $response = $this->mcpService->handleRequest($payload);

        if ($response === null) {
            return response()->noContent();
        }

        return response()->json($response);
    }

    /**
     * The legacy SSE transport. It advertised a message URL that did not exist,
     * never delivered a response on the stream, and held a PHP worker open for
     * as long as the client stayed connected. The route and its name stay so
     * existing references resolve; it now answers like Streamable HTTP does for
     * a GET: not allowed, POST only.
     */
    public function handleSse(Request $request): Response
    {
        return response('', 405)->header('Allow', 'POST');
    }
}
