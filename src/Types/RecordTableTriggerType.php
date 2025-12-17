<?php

namespace Sopheak\Core\Types;

use InvalidArgumentException;

class RecordTableTriggerType
{
    public function __construct(
        public string $class,
        public string $function_method,
        public ?string $description = null,
    ) {
        if (empty($class)) {
            throw new InvalidArgumentException('class cannot be empty');
        }

        if (empty($function_method)) {
            throw new InvalidArgumentException('function_method cannot be empty');
        }
    }

    public static function __set_state(array $properties): self
    {
        return new self(
            class: $properties['class'] ?? throw new InvalidArgumentException('class is required'),
            function_method: $properties['function_method'] ?? throw new InvalidArgumentException('function_method is required'),
            description: $properties['description'] ?? null,
        );
    }

    public function toArray(): array
    {
        $config = [
            'class' => $this->class,
            'function_method' => $this->function_method,
        ];

        if (null !== $this->description) {
            $config['description'] = $this->description;
        }

        return $config;
    }

    public static function fromArray(array $config): self
    {
        return new self(
            class: $config['class'] ?? throw new InvalidArgumentException('class is required in config array'),
            function_method: $config['function_method'] ?? throw new InvalidArgumentException('function_method is required in config array'),
            description: $config['description'] ?? null,
        );
    }
}
