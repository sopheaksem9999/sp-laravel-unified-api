<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Symfony\Component\HttpFoundation\Response;

class MiddlewareMapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $router = $this->app['router'];
        $router->aliasMiddleware('test.require-auth', RequireAuthHeaderMiddleware::class);
        $router->aliasMiddleware('test.require-subscription', RequireSubscriptionHeaderMiddleware::class);

        Schema::create('bills', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->decimal('balance_due', 14, 2)->nullable();
            $table->timestamps();
        });

        Config::set('record.tables', [
            'bills' => new RecordTableType(
                table: 'bills',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
            ),
        ]);

        Config::set('record.middleware_map', [
            'default' => [
                '*' => [],
                'read' => [],
                'write' => ['test.require-auth'],
                'function' => [],
            ],
            'tables' => [
            ],
        ]);

        SchemaRegistryUtils::refresh();
    }

    /** @test */
    public function it_supports_public_read_but_requires_auth_for_write_based_on_middleware_map(): void
    {
        $endpoint = $this->billsEndpoint();

        $this->getJson($endpoint)->assertStatus(200);

        $this->postJson($endpoint, ['name' => 'No Auth'])->assertStatus(401);

        $this->postJson($endpoint, ['name' => 'With Auth'], ['Authorization' => 'Bearer demo-token'])
            ->assertStatus(200);
    }

    /** @test */
    public function it_supports_auth_plus_subscription_for_specific_table_write_routes(): void
    {
        $endpoint = $this->billsEndpoint();

        Config::set('record.middleware_map.tables.bills.write', ['test.require-subscription']);

        $this->postJson($endpoint, ['vendor_id' => 27], ['Authorization' => 'Bearer demo-token'])
            ->assertStatus(402);

        $this->postJson($endpoint, ['vendor_id' => 27], [
            'Authorization' => 'Bearer demo-token',
            'X-Subscribed' => '1',
        ])->assertStatus(200);
    }

    private function billsEndpoint(): string
    {
        $routes = $this->app['router']->getRoutes()->getRoutes();
        $methodMap = [];
        foreach ($routes as $route) {
            $uri = $route->uri();
            $methods = $route->methods();

            if (!str_ends_with((string) $uri, '{table}')) {
                continue;
            }

            if (str_contains((string) $uri, 'audit')) {
                continue;
            }

            $key = (string) $uri;
            $methodMap[$key] = array_unique(array_merge($methodMap[$key] ?? [], $methods));
        }

        foreach ($methodMap as $uri => $methods) {
            if (in_array('GET', $methods, true) && in_array('POST', $methods, true)) {
                return '/' . str_replace('{table}', 'bills', $uri);
            }
        }

        $prefix = trim((string) config('record.api_prefix', 'api/v1'), '/');

        return '/' . $prefix . '/bills';
    }
}


class RequireAuthHeaderMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $authorization = (string) $request->header('Authorization', '');
        if (!str_starts_with($authorization, 'Bearer ')) {
            return response()->json(['message' => 'Auth header required'], 401);
        }

        return $next($request);
    }
}

class RequireSubscriptionHeaderMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if ('1' !== (string) $request->header('X-Subscribed', '0')) {
            return response()->json(['message' => 'Subscription required'], 402);
        }

        return $next($request);
    }
}
