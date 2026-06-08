<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Sopheak\Core\Http\Middleware\SetPostgresTenantContext;
use Illuminate\Routing\Router;
use Sopheak\Core\Http\Middleware\RecordRouteMiddleware;
use Sopheak\Core\Http\Middleware\RequestId;
use Sopheak\Core\Tests\TestCase;

class CoreSpLaravelApiProviderBootTest extends TestCase
{
    public function test_provider_registers_expected_middleware_aliases(): void
    {
        /** @var Router $router */
        $router = $this->app->make('router');

        $this->assertSame(RequestId::class, $router->getMiddleware()['request.id'] ?? null);
        $this->assertSame(RecordRouteMiddleware::class, $router->getMiddleware()['record.route.middleware'] ?? null);
        $this->assertSame(SetPostgresTenantContext::class, $router->getMiddleware()['pgsql.tenant'] ?? null);
    }
}
