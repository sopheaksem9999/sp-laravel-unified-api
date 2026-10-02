<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Sopheak\Core\Tests\TestCase;

require_once __DIR__ . '/../Support/PassportStub.php';

/**
 * Opt-in OAuth discovery (spec §6.5). The tests use a stub Passport class, so
 * the real Passport token flow is NOT covered here and needs a manual check
 * against an app with laravel/passport installed.
 *
 * @internal
 */
class McpOAuthTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('record.mcp.enabled', true);
        $app['config']->set('record.mcp.driver', 'laravel');
        $app['config']->set('record.mcp.oauth', true);
        $app['config']->set('record.mcp.middleware', ['api', 'auth:api']);
    }

    /** @test */
    public function the_protected_resource_metadata_is_published(): void
    {
        $response = $this->getJson('/.well-known/oauth-protected-resource');

        $response->assertOk();
        $response->assertJsonPath('resource', url('/'));
        $this->assertContains('mcp:use', $response->json('scopes_supported'));
        $this->assertNotEmpty($response->json('authorization_servers'));
    }

    /** @test */
    public function the_dynamic_client_registration_route_exists(): void
    {
        $this->assertTrue(Route::has('mcp.oauth.protected-resource'));
        // An empty registration is rejected by the controller (400), not missing (404).
        $this->postJson('/oauth/register', [])->assertStatus(400);
    }

    /** @test */
    public function an_unauthenticated_call_points_the_client_at_the_discovery_metadata(): void
    {
        $response = $this->postJson('/api/mcp/message', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);

        $response->assertStatus(401);
        $this->assertStringContainsString('resource_metadata=', (string) $response->headers->get('WWW-Authenticate'));
    }

    /** @test */
    public function get_is_method_not_allowed_even_when_unauthenticated(): void
    {
        $this->get('/api/mcp/message')->assertStatus(405)->assertHeader('Allow', 'POST');
    }
}
