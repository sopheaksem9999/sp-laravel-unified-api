<?php

declare(strict_types=1);

namespace Sopheak\Core\Types;

use InvalidArgumentException;

/**
 * Class RecordCastType.
 *
 * Typed configuration for custom class-based column casts in RecordTableType::$casting
 * and RecordColumnType::$cast. Survives `var_export()` for `php artisan config:cache`.
 */
class RecordCastType
{
    public readonly string $class;

    public readonly string $functionName;

    public function __construct(
        string $class,
        string $functionName = 'get',
        public readonly ?string $description = null,
    ) {
        $class = trim($class);
        $functionName = trim($functionName);

        if ('' === $class) {
            throw new InvalidArgumentException('RecordCastType class cannot be empty.');
        }

        if ('' === $functionName) {
            throw new InvalidArgumentException('RecordCastType functionName cannot be empty.');
        }

        $this->class = $class;
        $this->functionName = $functionName;
    }

    /**
     * Return the `[ClassName::class, 'method']` callable array representation.
     *
     * @return array{0: string, 1: string}
     */
    public function toCallable(): array
    {
        return [$this->class, $this->functionName];
    }

    /**
     * Return the `'ClassName@method'` string representation.
     */
    public function toCastString(): string
    {
        return $this->class . '@' . $this->functionName;
    }

    /**
     * Handle var_export() for configuration caching.
     *
     * @param array<string, mixed> $properties
     */
    public static function __set_state(array $properties): self
    {
        return new self(
            class: (string) ($properties['class'] ?? ''),
            functionName: (string) ($properties['functionName'] ?? 'get'),
            description: isset($properties['description']) ? (string) $properties['description'] : null,
        );
    }
}

