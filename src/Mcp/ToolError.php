<?php

declare(strict_types=1);

namespace Sopheak\Core\Mcp;

use RuntimeException;

/**
 * A tool call refused in a way MCP reports as a JSON-RPC error rather than an
 * `isError` tool result: Unauthenticated (-32001), unknown table (-32001),
 * Forbidden (-32002), tool not found (-32601).
 */
final class ToolError extends RuntimeException
{
    public function __construct(string $message, int $code = -32603)
    {
        parent::__construct($message, $code);
    }
}
