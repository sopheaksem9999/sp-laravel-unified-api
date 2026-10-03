<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Concerns;

use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Ai;
use Laravel\Ai\Gateway\FakeTextGateway;
use Sopheak\Core\CoreSpLaravelApiProvider;

/**
 * For tests of the AI SDK record tools. laravel/ai needs PHP 8.3+ and is a dev
 * dependency only, so these tests skip themselves when it is not installed
 * (call requireLaravelAi() first in setUp(), before the app boots).
 */
trait UsesLaravelAi
{
    protected function getPackageProviders($app): array
    {
        $providers = [CoreSpLaravelApiProvider::class];
        if (class_exists(AiServiceProvider::class)) {
            $providers[] = AiServiceProvider::class;
        }

        return $providers;
    }

    protected function requireLaravelAi(): void
    {
        if (!interface_exists(Tool::class)) {
            $this->markTestSkipped('laravel/ai is not installed (it needs PHP 8.3+).');
        }
    }

    /**
     * Replace the default text provider's gateway with a scripted one. Unlike
     * Ai::fakeAgent(), the agent is not "faked", so a resumed tool approval
     * really executes the approved tool call.
     *
     * @param array<int, mixed> $responses ToolCall instances and/or strings, in order
     */
    protected function scriptedGateway(array $responses): FakeTextGateway
    {
        config(['ai.default' => 'openai', 'ai.providers.openai' => ['driver' => 'openai', 'key' => 'test']]);

        $gateway = new FakeTextGateway($responses);
        Ai::textProvider()->useTextGateway($gateway);

        return $gateway;
    }
}
