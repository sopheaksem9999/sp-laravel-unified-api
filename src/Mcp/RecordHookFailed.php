<?php

declare(strict_types=1);

namespace Sopheak\Core\Mcp;

use RuntimeException;
use Throwable;

/**
 * A record hook or table validator threw while a data tool ran. Its message —
 * class names, URLs, tokens of the webhook clients hooks call — goes to the
 * application log, not to the MCP client or the model: over HTTP the same
 * failure answers "An error occurred".
 */
final class RecordHookFailed extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct('A record hook failed; the details are in the application log.', 0, $previous);
    }
}
