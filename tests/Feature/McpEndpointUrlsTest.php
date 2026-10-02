<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Auth\GenericUser;
use Sopheak\Core\Mcp\SchemaTools;
use Sopheak\Core\Tests\TestCase;

/**
 * The SSE endpoint advertised a URL that 404s, never sent a response on the
 * stream, and held a PHP worker open forever; and `record.mcp.route_prefix`
 * never moved a route although the guidance tool advertised it.
 *
 * @internal
 */
class McpEndpointUrlsTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('record.mcp.enabled', true);
        $app['config']->set('record.mcp.middleware', []);
        $app['config']->set('sp-api-mcp.enabled', true);
        $app['config']->set('sp-api-mcp.token', 'secret');
    }

    /** @test */
    public function the_sse_endpoint_answers_405_instead_of_hanging(): void
    {
        $this->get('/api/mcp/sse')->assertStatus(405)->assertHeader('Allow', 'POST');
    }

    /** @test */
    public function the_sse_route_name_is_still_registered(): void
    {
        $this->assertTrue(Route::has('mcp.sse'));
    }

    /** @test */
    public function the_guidance_advertises_the_urls_that_exist_even_when_route_prefix_is_set(): void
    {
        config(['record.mcp.route_prefix' => 'ai']);

        $guidance = (new SchemaTools())->apiGuidance();

        $this->assertSame('/api/mcp/message', $guidance['dataMcp']['route']);
        $this->assertSame('/api/mcp/schema', $guidance['schemaMcp']['route']);

        $this->actingAs(new GenericUser(['id' => 1]), 'api');
        $this->postJson($guidance['dataMcp']['route'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])->assertOk();
        $this->postJson($guidance['schemaMcp']['route'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'], ['Authorization' => 'Bearer secret'])->assertOk();
    }
}
