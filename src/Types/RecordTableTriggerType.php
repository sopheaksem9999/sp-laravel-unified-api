<?php

namespace Sopheak\Core\Types;

use InvalidArgumentException;

class RecordTableTriggerType
{
    public function __construct(
        public string $class,
        public string $functionName,
        public ?string $description = null,
    ) {
        if (empty($class)) {
            throw new InvalidArgumentException('class cannot be empty');
        }

        if (empty($functionName)) {
            throw new InvalidArgumentException('functionName cannot be empty');
        }
    }

    public static function __set_state(array $properties): self
    {
        return new self(
            class: $properties['class'] ?? throw new InvalidArgumentException('class is required'),
            functionName: $properties['functionName'] ?? throw new InvalidArgumentException('functionName is required'),
            description: $properties['description'] ?? null,
        );
    }

    public function toArray(): array
    {
        $config = [
            'class' => $this->class,
            'functionName' => $this->functionName,
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
            functionName: $config['functionName'] ?? throw new InvalidArgumentException('functionName is required in config array'),
            description: $config['description'] ?? null,
        );
    }
}
