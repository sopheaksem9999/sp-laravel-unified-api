<?php

namespace Sopheak\Core\Types;

use InvalidArgumentException;

class RecordValidationType
{
    public function __construct(
        public string $class,
        public string $functionName,
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
        );
    }

    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'functionName' => $this->functionName,
        ];
    }

    public static function fromArray(array $config): self
    {
        if (isset($config[0])) {
            $first = $config[0];
            if ($first instanceof self) {
                return $first;
            }

            if (is_array($first) && (isset($first['class']) || isset($first['functionName']))) {
                return new self(
                    class: $first['class'] ?? throw new InvalidArgumentException('class is required in config array'),
                    functionName: $first['functionName'] ?? throw new InvalidArgumentException('functionName is required in config array'),
                );
            }
        }

        return new self(
            class: $config['class'] ?? throw new InvalidArgumentException('class is required in config array'),
            functionName: $config['functionName'] ?? throw new InvalidArgumentException('functionName is required in config array'),
        );
    }
}
