<?php

declare(strict_types=1);

namespace Sopheak\Core\Types;

use InvalidArgumentException;
use Sopheak\Core\Enums\RecordColumnTypeEnum;

/**
 * Class RecordComputedAttributeType.
 *
 * Defines a computed / virtual attribute returned when requested via `?select=`.
 * Specifies the resolving class and method (surviving `var_export()` for config caching)
 * and optional type metadata for OpenAPI and MCP schema tools.
 *
 * Example usage:
 * ```php
 * attributes: [
 *     'full_name' => new RecordComputedAttributeType(
 *         class: UserFullNameResolver::class,
 *         functionName: 'handle',
 *         type: RecordColumnTypeEnum::STRING,
 *         description: 'Full name combined from first and last name',
 *     ),
 *     'stats' => new RecordComputedAttributeType(
 *         class: UserStatsService::class,
 *         functionName: 'compute',
 *         type: RecordColumnTypeEnum::JSON,
 *     ),
 * ],
 * ```
 */
class RecordComputedAttributeType
{
    public ?RecordColumnTypeEnum $type;

    public function __construct(
        public string $class,
        public string $functionName = 'handle',
        RecordColumnTypeEnum|string|null $type = null,
        public ?string $description = null,
        public mixed $example = null,
        public bool $nullable = true,
    ) {
        if ('' === trim($this->class)) {
            throw new InvalidArgumentException('class cannot be empty');
        }

        if ('' === trim($this->functionName)) {
            throw new InvalidArgumentException('functionName cannot be empty');
        }

        if (is_string($type)) {
            $this->type = RecordColumnTypeEnum::tryFrom($type) ?? RecordColumnTypeEnum::fromNative($type);
        } else {
            $this->type = $type;
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function toCallable(): array
    {
        return [$this->class, $this->functionName];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'class' => $this->class,
            'functionName' => $this->functionName,
            'type' => $this->type?->value,
            'description' => $this->description,
            'example' => $this->example,
            'nullable' => $this->nullable,
        ], static fn(mixed $v): bool => null !== $v);
    }

    /**
     * @param array<string, mixed> $properties
     */
    public static function __set_state(array $properties): self
    {
        return new self(
            class: $properties['class'] ?? throw new InvalidArgumentException('class is required'),
            functionName: $properties['functionName'] ?? 'handle',
            type: $properties['type'] ?? null,
            description: $properties['description'] ?? null,
            example: $properties['example'] ?? null,
            nullable: (bool) ($properties['nullable'] ?? true),
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            class: (string) ($config['class'] ?? $config[0] ?? throw new InvalidArgumentException('class is required')),
            functionName: (string) ($config['functionName'] ?? $config['method'] ?? $config[1] ?? 'handle'),
            type: $config['type'] ?? null,
            description: isset($config['description']) ? (string) $config['description'] : null,
            example: $config['example'] ?? null,
            nullable: (bool) ($config['nullable'] ?? true),
        );
    }
}

