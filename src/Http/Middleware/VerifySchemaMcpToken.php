<?php

declare(strict_types=1);

namespace Sopheak\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the Schema MCP. The rules moved here unchanged from
 * ApiSchemaMcpController, except that the token is compared in constant time:
 * a configured token must match the bearer token; with none configured, access
 * is open only in the `local` environment.
 */
final class VerifySchemaMcpToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('sp-api-mcp.token');

        if (null !== $token) {
            // An empty configured token (SP_API_MCP_TOKEN=) must never match an empty
            // bearer: hash_equals('', '') is true, so require both to be non-empty.
            $bearer = (string) $request->bearerToken();
            if ('' === (string) $token || '' === $bearer || !hash_equals((string) $token, $bearer)) {
                return $this->unauthorized($request, 'Invalid MCP token');
            }
        } elseif (!app()->environment('local')) {
            // Non-local without a configured token requires auth.
            return $this->unauthorized($request, 'MCP schema requires authentication');
        }

        return $next($request);
    }

    private function unauthorized(Request $request, string $message): Response
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $request->json('id'),
            'error' => [
                'code' => -32001,
                'message' => $message,
            ],
        ], 401);
    }
}
