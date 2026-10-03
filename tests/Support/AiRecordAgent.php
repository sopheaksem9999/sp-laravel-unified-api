<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Support;

use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\AgentResponse;

/**
 * A minimal conversational agent over the given tools. It keeps its own history
 * so a paused turn can be resumed with approval decisions, as an application
 * that stores conversations would.
 */
final class AiRecordAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    /** @var array<int, mixed> */
    public array $history = [];

    /**
     * @param iterable<mixed> $recordTools
     */
    public function __construct(private iterable $recordTools = []) {}

    public function instructions(): string
    {
        return 'Use the record tools to answer.';
    }

    public function messages(): iterable
    {
        return $this->history;
    }

    public function tools(): iterable
    {
        return $this->recordTools;
    }

    public function resume(Decisions $decisions, AgentResponse $paused): AgentResponse
    {
        $this->history = $paused->messages->all();

        return $this->prompt($decisions);
    }
}
