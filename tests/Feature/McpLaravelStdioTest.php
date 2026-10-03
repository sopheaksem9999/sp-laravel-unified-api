<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The stdio command owns STDIN and STDOUT, so it is tested as a real
 * subprocess through the Testbench CLI (see testbench.yaml).
 *
 * @internal
 */
class McpLaravelStdioTest extends TestCase
{
    /**
     * @param array<string, bool|string> $env
     * @return array<int, array<string, mixed>>
     */
    private function runCommand(array $env): array
    {
        $process = new Process([PHP_BINARY, 'vendor/bin/testbench', 'sp-laravel-api:mcp'], dirname(__DIR__, 2), $env + ['SP_MCP_ENABLED' => 'true'], null, 90);
        $process->setInput(
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']]) . "\n"
            . json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'sp_api_list_permissions', 'arguments' => (object) []]]) . "\n"
            . json_encode(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'frobnicate_things', 'arguments' => (object) []]]) . "\n"
        );
        $process->run();

        return array_map(
            static fn(string $line): array => (array) json_decode($line, true),
            array_values(array_filter(explode("\n", $process->getOutput())))
        );
    }

    /** @test */
    public function the_stdio_command_serves_the_laravel_driver(): void
    {
        $lines = $this->runCommand(['SP_MCP_DRIVER' => 'laravel']);

        $this->assertCount(3, $lines);
        $this->assertSame('2025-06-18', $lines[0]['result']['protocolVersion']);
        $this->assertArrayHasKey('permissions', $lines[1]['result']['structuredContent']);
        $this->assertSame(-32601, $lines[2]['error']['code']);
        $this->assertSame('Tool not found: frobnicate_things', $lines[2]['error']['message']);
    }

    /** @test */
    public function the_stdio_command_still_serves_the_legacy_driver_by_default(): void
    {
        // `false` removes the variable from the subprocess, even under the driver matrix.
        $lines = $this->runCommand(['SP_MCP_DRIVER' => false]);

        $this->assertCount(3, $lines);
        $this->assertSame('2024-11-05', $lines[0]['result']['protocolVersion']);
        $this->assertArrayHasKey('permissions', $lines[1]['result']['structuredContent']);
        $this->assertSame(-32601, $lines[2]['error']['code']);
    }
}
