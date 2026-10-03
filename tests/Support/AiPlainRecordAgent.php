<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Support;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

/**
 * An agent that is not Conversational: a tool approval cannot be resumed on it.
 */
final class AiPlainRecordAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * @param iterable<mixed> $recordTools
     */
    public function __construct(private iterable $recordTools = []) {}

    public function instructions(): string
    {
        return 'Use the record tools to answer.';
    }

    public function tools(): iterable
    {
        return $this->recordTools;
    }
}
