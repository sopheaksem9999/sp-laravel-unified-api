<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Sopheak\Core\Tests\TestCase;

/**
 * OAuth discovery is off unless record.mcp.oauth is true.
 *
 * @internal
 */
class McpOAuthDisabledTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('record.mcp.enabled', true);
        $app['config']->set('record.mcp.driver', 'laravel');
        $app['config']->set('record.mcp.oauth', false);
    }

    /** @test */
    public function no_discovery_route_is_published_by_default(): void
    {
        $this->getJson('/.well-known/oauth-protected-resource')->assertNotFound();
        $this->assertFalse(Route::has('mcp.oauth.protected-resource'));
    }
}
