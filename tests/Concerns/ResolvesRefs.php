<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Concerns;

/**
 * sp_api_get_endpoint keeps the first copy of a repeated schema inline and
 * points later copies at it with {"$ref": "#/json/pointer"}. Tests that
 * assert on schema content read the document through this resolver, exactly
 * as an agent follows the reference.
 */
trait ResolvesRefs
{
    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    protected function resolveRefs(array $document): array
    {
        return $this->resolveNode($document, $document);
    }

    private function resolveNode(mixed $node, array $root): mixed
    {
        if (!is_array($node)) {
            return $node;
        }

        if (isset($node['$ref']) && is_string($node['$ref'])) {
            $target = $root;
            foreach (explode('/', ltrim(substr($node['$ref'], 1), '/')) as $segment) {
                $this->assertIsArray($target, 'dangling $ref ' . $node['$ref']);
                $this->assertArrayHasKey($segment, $target, 'dangling $ref ' . $node['$ref']);
                $target = $target[$segment];
            }

            return $this->resolveNode($target, $root);
        }

        foreach ($node as $key => $child) {
            $node[$key] = $this->resolveNode($child, $root);
        }

        return $node;
    }
}
