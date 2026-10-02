<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Sopheak\Core\Http\Controllers\ApiSchemaMcpController;
use Sopheak\Core\Tests\TestCase;

/**
 * The Schema MCP's bearer-token rules. They moved from an inline check in the
 * controller into middleware that the controller attaches to itself; the
 * decisions and the 401 bodies must not change, and the comparison is now
 * constant-time.
 *
 * @internal
 */
class SchemaMcpTokenTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('sp-api-mcp.enabled', true);
    }

    private function rpc(?string $authorization, string $uri = '/api/mcp/schema'): TestResponse
    {
        return $this->postJson(
            $uri,
            ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/list'],
            null === $authorization ? [] : ['Authorization' => $authorization]
        );
    }

    /** @test */
    public function a_matching_token_is_accepted(): void
    {
        config(['sp-api-mcp.token' => 'secret']);

        $this->rpc('Bearer secret')->assertOk()->assertJsonPath('result.tools.0.name', 'sp_api_list_endpoints');
    }

    /** @test */
    public function a_wrong_token_is_a_401_with_the_invalid_token_body(): void
    {
        config(['sp-api-mcp.token' => 'secret']);

        $this->rpc('Bearer other')
            ->assertStatus(401)
            ->assertExactJson(['jsonrpc' => '2.0', 'id' => 7, 'error' => ['code' => -32001, 'message' => 'Invalid MCP token']]);
    }

    /** @test */
    public function a_token_that_only_shares_a_prefix_is_rejected(): void
    {
        config(['sp-api-mcp.token' => 'secret']);

        $this->rpc('Bearer secre')->assertStatus(401);
        $this->rpc('Bearer secretX')->assertStatus(401);
    }

    /** @test */
    public function an_empty_configured_token_never_opens_the_endpoint(): void
    {
        // SP_API_MCP_TOKEN= in .env makes the token '' rather than null. An empty
        // bearer must not match it: hash_equals('', '') is true.
        config(['sp-api-mcp.token' => '']);

        foreach (['local', 'production'] as $environment) {
            $this->app['env'] = $environment;

            $this->rpc(null)->assertStatus(401)->assertJsonPath('error.message', 'Invalid MCP token');
            $this->rpc('Bearer ')->assertStatus(401);
            $this->rpc('Bearer x')->assertStatus(401);
        }
    }

    /** @test */
    public function a_missing_token_is_a_401_when_one_is_configured(): void
    {
        config(['sp-api-mcp.token' => 'secret']);

        $this->rpc(null)->assertStatus(401)->assertJsonPath('error.message', 'Invalid MCP token');
    }

    /** @test */
    public function no_configured_token_outside_local_requires_authentication(): void
    {
        config(['sp-api-mcp.token' => null]);
        $this->app['env'] = 'production';

        $this->rpc(null)
            ->assertStatus(401)
            ->assertExactJson(['jsonrpc' => '2.0', 'id' => 7, 'error' => ['code' => -32001, 'message' => 'MCP schema requires authentication']]);
    }

    /** @test */
    public function no_configured_token_in_local_is_open(): void
    {
        config(['sp-api-mcp.token' => null]);
        $this->app['env'] = 'local';

        $this->rpc(null)->assertOk();
    }

    /** @test */
    public function the_controller_enforces_the_check_wherever_it_is_routed(): void
    {
        config(['sp-api-mcp.token' => 'secret']);
        Route::post('/custom-schema-mcp', [ApiSchemaMcpController::class, 'handle']);

        $this->rpc(null, '/custom-schema-mcp')->assertStatus(401);
        $this->rpc('Bearer secret', '/custom-schema-mcp')->assertOk();
    }
}
