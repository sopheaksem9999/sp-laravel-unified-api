<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Concerns;

/**
 * For the few MCP assertions that pin a detail only the `legacy` JSON-RPC
 * driver produces (spec §7.2): when the suite runs under
 * `SP_MCP_DRIVER=laravel`, skip with the reason, and cover the `laravel`
 * equivalent in McpLaravelRoutesTest / McpDriverParityTest.
 */
trait SkipsOnLaravelMcpDriver
{
    protected function skipOnLaravelMcpDriver(string $reason): void
    {
        if ('laravel' === (getenv('SP_MCP_DRIVER') ?: 'legacy')) {
            $this->markTestSkipped('Legacy-driver detail: ' . $reason);
        }
    }
}
