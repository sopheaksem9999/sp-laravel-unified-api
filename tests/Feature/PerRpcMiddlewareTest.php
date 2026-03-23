<?php

namespace Sopheak\Core\Tests\Feature;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordFunctionType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-RPC middleware feature tests.
 *
 * IMPORTANT: Global function routes (/api/rpc/{functionName}) are built at boot time from
 * config('record.global_functions'). All function names used by tests must be registered in
 * getEnvironmentSetUp() so their routes exist. Per-test config overrides (via Config::set)
 * update the RecordFunctionType configs; the middleware lookup reads these at request time.
 *
 * Table function routes (/api/{table}/rpc/{functionName}) use a catch-all '.*' constraint
 * and are always registered, so table function tests do not require getEnvironmentSetUp changes.
 */
class PerRpcMiddlewareTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Register all function names used across tests at boot time.
        // Their configs will be overridden per-test via Config::set().
        $app['config']->set('record.global_functions', [
            'login'   => new RecordFunctionType(
                httpMethod: 'POST',
                class: RpcEchoHandler::class,
                functionName: 'handle',
                isPublic: true,
                middleware: [],   // default: public
            ),
            'profile' => new RecordFunctionType(
                httpMethod: 'GET',
                class: RpcEchoHandler::class,
                functionName: 'handle',
                isPublic: true,
                // middleware: null — falls back to middleware_map
            ),
            'logout'  => new RecordFunctionType(
                httpMethod: 'POST',
                class: RpcEchoHandler::class,
                functionName: 'handle',
                isPublic: true,
                middleware: [],   // default: public, overridden per-test
            ),
            'auth/me' => new RecordFunctionType(
                httpMethod: 'GET',
                class: RpcEchoHandler::class,
                functionName: 'handle',
                isPublic: true,
                middleware: [],   // public
            ),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $router = $this->app['router'];
        $router->aliasMiddleware('test.require-token', RpcRequireTokenMiddleware::class);
        $router->aliasMiddleware('test.require-plan',  RpcRequirePlanMiddleware::class);

        // Default: all RPCs require a token unless overridden by function-level middleware
        Config::set('record.middleware_map', [
            'default' => [
                '*'        => [],
                'read'     => [],
                'write'    => [],
                'function' => ['test.require-token'],
            ],
            'tables' => [],
        ]);
    }

    // ── Global function tests ────────────────────────────────────────────────

    /** @test */
    public function global_function_with_empty_middleware_bypasses_middleware_map(): void
    {
        // login has middleware: [] — explicitly no middleware, map's token requirement is skipped
        $this->postJson('/api/rpc/login')
            ->assertStatus(200);
    }

    /** @test */
    public function global_function_with_null_middleware_falls_back_to_middleware_map(): void
    {
        // profile has middleware: null — map applies, requires token
        $this->getJson('/api/rpc/profile')
            ->assertStatus(401);

        $this->getJson('/api/rpc/profile', ['X-Token' => 'valid'])
            ->assertStatus(200);
    }

    /** @test */
    public function global_function_with_custom_middleware_replaces_middleware_map(): void
    {
        // Override logout to require a plan header instead of the default token
        Config::set('record.global_functions.logout', new RecordFunctionType(
            httpMethod: 'POST',
            class: RpcEchoHandler::class,
            functionName: 'handle',
            isPublic: true,
            middleware: ['test.require-plan'],
        ));

        // Token alone doesn't pass (requires plan, not token)
        $this->postJson('/api/rpc/logout', [], ['X-Token' => 'valid'])
            ->assertStatus(402);

        // Plan header passes (token not required)
        $this->postJson('/api/rpc/logout', [], ['X-Plan' => 'active'])
            ->assertStatus(200);
    }

    /** @test */
    public function global_function_with_slash_in_name_resolves_middleware_correctly(): void
    {
        // auth/me has middleware: [] — public, no token needed
        $this->getJson('/api/rpc/auth/me')
            ->assertStatus(200);
    }

    // ── Table function tests ─────────────────────────────────────────────────

    /** @test */
    public function table_function_with_empty_middleware_bypasses_middleware_map(): void
    {
        Config::set('record.tables', [
            'orders' => new RecordTableType(
                table: 'orders',
                public: new RecordTablePublic(read: true, write: true),
                functions: [
                    'summary' => new RecordFunctionType(
                        httpMethod: 'GET',
                        class: RpcEchoHandler::class,
                        functionName: 'handle',
                        isPublic: true,
                        middleware: [],   // public — map's token requirement is skipped
                    ),
                ],
            ),
        ]);
        SchemaRegistryUtils::refresh();

        $this->getJson('/api/orders/rpc/summary')
            ->assertStatus(200);
    }

    /** @test */
    public function table_function_with_null_middleware_falls_back_to_middleware_map(): void
    {
        Config::set('record.tables', [
            'orders' => new RecordTableType(
                table: 'orders',
                public: new RecordTablePublic(read: true, write: true),
                functions: [
                    'export' => new RecordFunctionType(
                        httpMethod: 'GET',
                        class: RpcEchoHandler::class,
                        functionName: 'handle',
                        isPublic: true,
                        // middleware: null — map applies
                    ),
                ],
            ),
        ]);
        SchemaRegistryUtils::refresh();

        $this->getJson('/api/orders/rpc/export')
            ->assertStatus(401);

        $this->getJson('/api/orders/rpc/export', ['X-Token' => 'valid'])
            ->assertStatus(200);
    }
}

// ── Test helpers ─────────────────────────────────────────────────────────────

class RpcEchoHandler
{
    // Returning a plain array is fine — RecordService wraps it in a 200 JsonResponse.
    public function handle(Request $request): array
    {
        return ['ok' => true];
    }
}

class RpcRequireTokenMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->hasHeader('X-Token')) {
            return response()->json(['message' => 'Token required'], 401);
        }
        return $next($request);
    }
}

class RpcRequirePlanMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->hasHeader('X-Plan')) {
            return response()->json(['message' => 'Plan required'], 402);
        }
        return $next($request);
    }
}
