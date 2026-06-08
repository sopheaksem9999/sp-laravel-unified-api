<?php

declare(strict_types=1);

namespace Sopheak\Core\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Sopheak\Core\Services\McpServerService;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function handleSse(Request $request): StreamedResponse
    {
        $response = new StreamedResponse(function (): void {
            $sessionId = uniqid('mcp_', true);
            $postUrl = url(config('record.mcp.route_prefix', 'mcp') . '/message?session_id=' . $sessionId);
            echo "event: endpoint\n";
            echo "data: " . $postUrl . "\n\n";
            ob_flush();
            flush();
            // Just keep connection open. Real implementation would use Redis/broadcast to send messages.
            while (true) {
                echo ": keepalive\n\n";
                ob_flush();
                flush();
                sleep(15);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');

        return $response;
    }
}
