<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Sopheak\Core\Mcp\SchemaTools;
use Sopheak\Core\Tests\TestCase;

/**
 * With both MCP endpoints disabled no route exists to resolve a URL from, so the
 * guidance falls back to the conventional paths.
 *
 * @internal
 */
class McpEndpointUrlsFallbackTest extends TestCase
{
    /** @test */
    public function the_guidance_falls_back_to_the_conventional_urls_when_the_routes_are_not_registered(): void
    {
        $this->assertFalse(Route::has('mcp.message'));
        $this->assertFalse(Route::has('api_schema_mcp'));

        $guidance = (new SchemaTools())->apiGuidance();

        $this->assertSame('/api/mcp/message', $guidance['dataMcp']['route']);
        $this->assertSame('/api/mcp/schema', $guidance['schemaMcp']['route']);
    }
}
