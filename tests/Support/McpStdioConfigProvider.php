<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Support;

use Illuminate\Support\ServiceProvider;

/**
 * Test-only. A bare Testbench CLI app has no published config/sp-record.php, so
 * `record.mcp.*` is empty. This provider (listed first in testbench.yaml so it
 * registers before the package) reads the SP_MCP_* environment into that
 * config, letting the stdio test choose a driver for its subprocess. PHPUnit
 * never loads testbench.yaml, so it has no effect on the test suite.
 */
final class McpStdioConfigProvider extends ServiceProvider
{
    public function register(): void
    {
        config([
            'record.mcp.enabled' => filter_var(getenv('SP_MCP_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN),
            'record.mcp.read_only' => true,
            'record.mcp.driver' => getenv('SP_MCP_DRIVER') ?: 'legacy',
        ]);
    }
}
