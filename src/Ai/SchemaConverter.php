<?php

declare(strict_types=1);

namespace Sopheak\Core\Ai;

use Illuminate\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\ObjectType;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Schema\SchemaNormalizer;
use RuntimeException;
use Throwable;

/**
 * Turns a catalog tool's raw JSON Schema into the typed properties laravel/ai
 * wants from `Tool::schema()`.
 *
 * Two traps are closed here:
 *
 * - laravel/ai's own MCP bridge answers `[]` whenever conversion fails, which
 *   makes the tool parameterless, and its normalizer is allowed to drop
 *   keywords and properties silently. A conversion that fails, or loses a
 *   property or a required entry at any depth, is an error naming the tool.
 * - A free-form object (`payload`, `queryParams`: `{"type": "object"}` with
 *   `additionalProperties: true`) cannot be expressed to providers. Their
 *   mappers send `{"type":"object","additionalProperties":false}` with no
 *   properties, which reads as "send an empty object" (Gemini rejects it).
 *   Such a parameter is therefore declared as a string holding a JSON object,
 *   and RecordTool decodes it.
 */
final class SchemaConverter
{
    private const DEFAULT_DESCRIPTIONS = [
        'payload' => 'The record\'s fields as a JSON-encoded object, for example {"ref_number": "INV-1"}. Call sp_api_get_endpoint for the writable fields and the nested-write shapes.',
        'queryParams' => 'Optional query parameters as a JSON-encoded object, for example {"limit": 10, "select": "id,title"}.',
    ];

    private const JSON_NOTE = ' Send it as a JSON-encoded object (text), not as a nested object.';

    /**
     * @param array<string, mixed> $inputSchema
     * @return array<string, Type>
     */
    public static function properties(string $toolName, array $inputSchema): array
    {
        $schema = self::withJsonTextObjects($inputSchema);
        self::assertNoOpenObjects($toolName, $schema);

        try {
            $type = JsonSchema::fromArray(SchemaNormalizer::normalize($schema));
        } catch (Throwable $throwable) {
            throw new RuntimeException(sprintf("Tool '%s': its input schema could not be converted for the AI SDK: %s", $toolName, $throwable->getMessage()), 0, $throwable);
        }

        if (!$type instanceof ObjectType) {
            throw new RuntimeException(sprintf("Tool '%s': its input schema must be an object, got %s.", $toolName, $type::class));
        }

        self::assertLossless($toolName, $schema, $type->toArray(), '');

        /** @var array<string, Type> $properties */
        $properties = (fn(): array => $this->properties)->call($type);

        return $properties;
    }

    /**
     * The top-level parameters that travel as JSON text and must be decoded
     * before the call.
     *
     * @param array<string, mixed> $inputSchema
     * @return list<string>
     */
    public static function jsonStringProperties(array $inputSchema): array
    {
        $names = [];
        foreach ((array) ($inputSchema['properties'] ?? []) as $name => $definition) {
            if (is_array($definition) && self::isOpenObject($definition)) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $inputSchema
     * @return array<string, mixed>
     */
    private static function withJsonTextObjects(array $inputSchema): array
    {
        foreach ((array) ($inputSchema['properties'] ?? []) as $name => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            if (!self::isOpenObject($definition)) {
                continue;
            }

            $own = isset($definition['description']) && is_string($definition['description']) && '' !== trim($definition['description']);
            $inputSchema['properties'][$name] = [
                'type' => 'string',
                'description' => $own
                    ? rtrim((string) $definition['description']) . self::JSON_NOTE
                    : (self::DEFAULT_DESCRIPTIONS[$name] ?? 'A JSON-encoded object.'),
            ];
        }

        return $inputSchema;
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function isOpenObject(array $node): bool
    {
        $type = $node['type'] ?? null;
        $isObject = 'object' === $type || (is_array($type) && in_array('object', $type, true));

        return $isObject && [] === (array) ($node['properties'] ?? []);
    }

    /**
     * Anything still open once the top level was rewritten cannot be expressed
     * to a provider, and must not be sent as a bare object.
     *
     * @param array<string, mixed> $node
     */
    private static function assertNoOpenObjects(string $toolName, array $node, string $path = ''): void
    {
        foreach ((array) ($node['properties'] ?? []) as $name => $child) {
            if (!is_array($child)) {
                continue;
            }

            $childPath = '' === $path ? (string) $name : $path . '.' . $name;
            if ('' !== $path && self::isOpenObject($child)) {
                throw new RuntimeException(sprintf("Tool '%s': property '%s' is a free-form object, which providers' strict schemas cannot express.", $toolName, $childPath));
            }

            self::assertNoOpenObjects($toolName, $child, $childPath);
        }

        if (isset($node['items']) && is_array($node['items'])) {
            if (self::isOpenObject($node['items'])) {
                throw new RuntimeException(sprintf("Tool '%s': items of '%s' are free-form objects, which providers' strict schemas cannot express.", $toolName, '' === $path ? '(root)' : $path));
            }

            self::assertNoOpenObjects($toolName, $node['items'], $path . '[]');
        }
    }

    /**
     * Every property name and required entry of the raw schema must survive
     * normalizing and conversion, at every depth.
     *
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $converted
     */
    private static function assertLossless(string $toolName, array $raw, array $converted, string $path): void
    {
        $where = '' === $path ? 'the top level' : "'" . $path . "'";

        $lost = array_diff(array_keys((array) ($raw['properties'] ?? [])), array_keys((array) ($converted['properties'] ?? [])));
        if ([] !== $lost) {
            throw new RuntimeException(sprintf("Tool '%s': converting its input schema dropped the properties %s at %s.", $toolName, implode(', ', $lost), $where));
        }

        $lostRequired = array_diff((array) ($raw['required'] ?? []), (array) ($converted['required'] ?? []));
        if ([] !== $lostRequired) {
            throw new RuntimeException(sprintf("Tool '%s': converting its input schema dropped the required entries %s at %s.", $toolName, implode(', ', $lostRequired), $where));
        }

        foreach ((array) ($raw['properties'] ?? []) as $name => $child) {
            if (is_array($child)) {
                self::assertLossless($toolName, $child, (array) ($converted['properties'][$name] ?? []), '' === $path ? (string) $name : $path . '.' . $name);
            }
        }

        if (isset($raw['items']) && is_array($raw['items'])) {
            if (!isset($converted['items'])) {
                throw new RuntimeException(sprintf("Tool '%s': converting its input schema dropped the items of %s.", $toolName, $where));
            }

            self::assertLossless($toolName, $raw['items'], (array) $converted['items'], $path . '[]');
        }
    }
}
