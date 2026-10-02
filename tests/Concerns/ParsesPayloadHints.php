<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Concerns;

/**
 * An include's payloadHint reads `"alias": <json array> — rules`. An agent
 * lifts the JSON array out of it; so do the tests that follow the hint.
 */
trait ParsesPayloadHints
{
    /**
     * @param array<string, mixed> $include an entry of sp_api_get_endpoint's includes[]
     * @return array<int, array<string, mixed>>
     */
    protected function hintExample(array $include): array
    {
        $this->assertSame(1, preg_match('/^"' . preg_quote((string) $include['name'], '/') . '": (\[.*?\]) — /s', (string) $include['payloadHint'], $matches), 'payloadHint has the shape "alias": [json] — rules');
        $example = json_decode($matches[1], true);
        $this->assertIsArray($example, 'the example in the hint is valid JSON');

        return $example;
    }
}
