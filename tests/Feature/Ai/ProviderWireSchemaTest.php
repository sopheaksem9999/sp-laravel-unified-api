<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature\Ai;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Gateway\Anthropic\AnthropicGateway;
use Laravel\Ai\Gateway\Gemini\GeminiGateway;
use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;
use Sopheak\Core\Ai\RecordTool;
use Sopheak\Core\Ai\RecordTools;
use Sopheak\Core\Tests\Concerns\BuildsGuidanceFixture;
use Sopheak\Core\Tests\Concerns\UsesLaravelAi;
use Sopheak\Core\Tests\TestCase;

/**
 * What a provider is actually sent. FakeTextGateway bypasses provider mapping,
 * so a schema that is fine in PHP can still read as "this object takes no keys"
 * on the wire; this maps every tool through the real gateways.
 *
 * @internal
 */
class ProviderWireSchemaTest extends TestCase
{
    use BuildsGuidanceFixture;
    use RefreshDatabase;
    use UsesLaravelAi;

    protected function setUp(): void
    {
        $this->requireLaravelAi();
        parent::setUp();
        $this->buildGuidanceFixture();
    }

    /**
     * @return array<string, mixed>
     */
    private function wire(object $gateway, RecordTool $tool): array
    {
        $mapped = (fn(RecordTool $t): array => $this->mapTool($t))->call($gateway, $tool);

        return $mapped['parameters'] ?? $mapped['input_schema'] ?? $mapped;
    }

    /**
     * @param array<string, mixed> $node
     * @return list<string> paths of objects that declare no properties
     */
    private function emptyObjects(array $node, string $path = ''): array
    {
        $found = [];
        $type = $node['type'] ?? null;
        $isObject = 'object' === $type || (is_array($type) && in_array('object', $type, true));

        if ($isObject && [] === ($node['properties'] ?? [])) {
            $found[] = '' === $path ? '(root)' : $path;
        }

        foreach ((array) ($node['properties'] ?? []) as $name => $child) {
            if (is_array($child)) {
                $found = [...$found, ...$this->emptyObjects($child, '' === $path ? (string) $name : $path . '.' . $name)];
            }
        }

        if (isset($node['items']) && is_array($node['items'])) {
            return [...$found, ...$this->emptyObjects($node['items'], $path . '[]')];
        }

        return $found;
    }

    public function test_no_provider_is_told_an_object_takes_no_keys(): void
    {
        $gateways = [
            'openai' => new OpenAiGateway(app('events')),
            'anthropic' => new AnthropicGateway(app('events')),
            'gemini' => new GeminiGateway(app('events')),
        ];

        foreach ([...RecordTools::for('invoices'), ...RecordTools::schema()] as $tool) {
            foreach ($gateways as $name => $gateway) {
                $parameters = $this->wire($gateway, $tool);
                $empty = array_filter($this->emptyObjects($parameters), static fn(string $path): bool => '(root)' !== $path);

                $this->assertSame([], array_values($empty), sprintf('%s sends %s an object with no properties', $name, $tool->name()));
            }
        }
    }

    public function test_the_wire_schema_of_a_write_has_json_text_parameters(): void
    {
        $tool = iterator_to_array(RecordTools::for('invoices'))[2];
        $this->assertSame('create_invoices', $tool->name());

        $openai = $this->wire(new OpenAiGateway(app('events')), $tool);

        $this->assertSame('string', $openai['properties']['payload']['type']);
        $this->assertSame('string', $openai['properties']['queryParams']['type']);
        $this->assertSame(['payload'], $openai['required']);
    }
}
