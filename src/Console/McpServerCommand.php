<?php

declare(strict_types=1);

namespace Sopheak\Core\Console;

use Sopheak\Core\Services\McpServerService;
use Sopheak\Core\Support\CacheRequestContext;
use Illuminate\Console\Command;

class McpServerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sp-laravel-api:mcp';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the MCP Stdio Server';

    /**
     * @var resource|null
     */
    public static $stdinMock;

    /**
     * @var resource|null
     */
    public static $stdoutMock;

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $mcpService = app(McpServerService::class);

        // Run an infinite loop reading from STDIN
        $stdin = self::$stdinMock ?: fopen('php://stdin', 'r');
        $stdout = self::$stdoutMock ?: fopen('php://stdout', 'w');

        if (!$stdin || !$stdout) {
            $this->error('Failed to open STDIN or STDOUT.');
            return;
        }

        while (!feof($stdin)) {
            $line = fgets($stdin);
            if ($line === false) {
                break;
            }

            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // A long-running console loop gets neither RouteMatched nor the queue
            // worker's forgetScopedInstances(), so without this the namespace memo
            // would survive for the life of the process and keep serving reads that
            // another process has already invalidated.
            app(CacheRequestContext::class)->reset();

            $payload = json_decode($line, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errorResponse = [
                    'jsonrpc' => '2.0',
                    'id' => null,
                    'error' => [
                        'code' => -32700,
                        'message' => 'Parse error',
                    ],
                ];
                fwrite($stdout, json_encode($errorResponse) . "\n");
                continue;
            }

            $response = $mcpService->handleRequest($payload);

            if ($response !== null) {
                fwrite($stdout, json_encode($response) . "\n");
                fflush($stdout);
            }
        }

        if (self::$stdinMock === null) {
            fclose($stdin);
            fclose($stdout);
        }
    }
}
