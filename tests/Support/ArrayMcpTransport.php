<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Support;

use Closure;
use Laravel\Mcp\Server\Contracts\Transport;

/**
 * An in-memory laravel/mcp transport: feed a JSON-RPC request, read the reply.
 * Used to drive the package's MCP servers without HTTP or STDIN.
 */
final class ArrayMcpTransport implements Transport
{
    /** @var array<int, array<string, mixed>> */
    public array $sent = [];

    private ?Closure $handler = null;

    public function onReceive(Closure $handler): void
    {
        $this->handler = $handler;
    }

    public function run()
    {
        return null;
    }

    public function send(string $message): void
    {
        $this->sent[] = json_decode($message, true);
    }

    public function stream(Closure $stream): void
    {
        $stream();
    }

    public function feed(array $request): array
    {
        $before = count($this->sent);
        ($this->handler)(json_encode($request));

        return $this->sent[$before] ?? [];
    }
}
