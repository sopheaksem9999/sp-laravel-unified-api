<?php

declare(strict_types=1);

namespace Sopheak\Core\Types;

use InvalidArgumentException;
use Sopheak\Core\Enums\RecordSchemaFormatEnum;
use Sopheak\Core\Enums\RecordSchemaTypeEnum;

/**
 * Class RecordSchemaType.
 *
 * A typed OpenAPI 3.0 schema for `RecordFunctionType::$querySchema`,
 * `$payloadSchema` and `$responseSchema`. It is checked when constructed
 * (properties only on objects, `items` only on arrays, `required` names a
 * declared property, a format that fits the type, enum values of the type)
 * and stored as the plain array OpenAPI, the MCP tools and the AI SDK read,
 * so the array form keeps working and both can be mixed.
 *
 * Example usage:
 * ```php
 * use Sopheak\Core\Types\RecordSchemaType;
 *
 * payloadSchema: RecordSchemaType::object(
 *     properties: [
 *         'booking_id' => RecordSchemaType::uuid(description: 'Target booking'),
 *         'status' => RecordSchemaType::string(enum: ['accepted', 'rejected']),
 *         'latitude' => RecordSchemaType::number(),
 *         'tags' => RecordSchemaType::array(RecordSchemaType::string()),
 *     ],
 *     required: ['booking_id', 'status'],
 * ),
 * ```
 */
class RecordSchemaType
{
    /**
     * @param array<string, RecordSchemaType|array<string, mixed>>|null $properties Object properties
     * @param list<string>|null                                          $required   Required property names (objects)
     * @param RecordSchemaType|array<string, mixed>|null                 $items      Item schema (arrays)
     * @param list<mixed>|null                                           $enum       Allowed values
     * @param bool|RecordSchemaType|array<string, mixed>|null            $additionalProperties
     * @param list<RecordSchemaType|array<string, mixed>>|null           $oneOf
     * @param list<RecordSchemaType|array<string, mixed>>|null           $anyOf
     * @param array<string, mixed>                                       $meta       Any other OpenAPI keyword, passed through
     */
    public function __construct(
        public RecordSchemaTypeEnum $type,
        public RecordSchemaFormatEnum|string|null $format = null,
        public ?string $title = null,
        public ?string $description = null,
        public mixed $example = null,
        public mixed $default = null,
        public bool $nullable = false,
        public ?array $enum = null,
        public ?array $properties = null,
        public ?array $required = null,
        public RecordSchemaType|array|null $items = null,
        public bool|RecordSchemaType|array|null $additionalProperties = null,
        public ?array $oneOf = null,
        public ?array $anyOf = null,
        public int|float|null $minimum = null,
        public int|float|null $maximum = null,
        public ?int $minLength = null,
        public ?int $maxLength = null,
        public ?string $pattern = null,
        public ?int $minItems = null,
        public ?int $maxItems = null,
        public bool $readOnly = false,
        public bool $writeOnly = false,
        public array $meta = [],
    ) {
        $this->assertValid();
    }

    /**
     * @param array<string, RecordSchemaType|array<string, mixed>> $properties
     * @param list<string>                                          $required
     */
    public static function object(array $properties = [], array $required = [], ?string $description = null, bool|RecordSchemaType|array|null $additionalProperties = null, bool $nullable = false): self
    {
        return new self(RecordSchemaTypeEnum::OBJECT, description: $description, nullable: $nullable, properties: [] === $properties ? null : $properties, required: [] === $required ? null : $required, additionalProperties: $additionalProperties);
    }

    /** @param RecordSchemaType|array<string, mixed> $items */
    public static function array(RecordSchemaType|array $items, ?string $description = null, ?int $minItems = null, ?int $maxItems = null, bool $nullable = false): self
    {
        return new self(RecordSchemaTypeEnum::ARRAY, description: $description, nullable: $nullable, items: $items, minItems: $minItems, maxItems: $maxItems);
    }

    /** @param list<string>|null $enum */
    public static function string(?string $description = null, ?array $enum = null, RecordSchemaFormatEnum|string|null $format = null, mixed $example = null, ?int $maxLength = null, bool $nullable = false): self
    {
        return new self(RecordSchemaTypeEnum::STRING, format: $format, description: $description, example: $example, nullable: $nullable, enum: $enum, maxLength: $maxLength);
    }

    public static function integer(?string $description = null, int|null $minimum = null, int|null $maximum = null, mixed $example = null, bool $nullable = false): self
    {
        return new self(RecordSchemaTypeEnum::INTEGER, description: $description, example: $example, nullable: $nullable, minimum: $minimum, maximum: $maximum);
    }

    public static function number(?string $description = null, int|float|null $minimum = null, int|float|null $maximum = null, mixed $example = null, bool $nullable = false): self
    {
        return new self(RecordSchemaTypeEnum::NUMBER, description: $description, example: $example, nullable: $nullable, minimum: $minimum, maximum: $maximum);
    }

    public static function boolean(?string $description = null, mixed $example = null, bool $nullable = false): self
    {
        return new self(RecordSchemaTypeEnum::BOOLEAN, description: $description, example: $example, nullable: $nullable);
    }

    public static function uuid(?string $description = null, bool $nullable = false): self
    {
        return self::string($description, format: RecordSchemaFormatEnum::UUID, nullable: $nullable);
    }

    public static function date(?string $description = null, bool $nullable = false): self
    {
        return self::string($description, format: RecordSchemaFormatEnum::DATE, nullable: $nullable);
    }

    public static function dateTime(?string $description = null, bool $nullable = false): self
    {
        return self::string($description, format: RecordSchemaFormatEnum::DATE_TIME, nullable: $nullable);
    }

    /**
     * The OpenAPI array form, nested schemas included.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $node = static fn(RecordSchemaType|array $schema): array => $schema instanceof self ? $schema->toArray() : $schema;

        $out = ['type' => $this->type->value];
        $optional = [
            'format' => $this->format instanceof RecordSchemaFormatEnum ? $this->format->value : $this->format,
            'title' => $this->title,
            'description' => $this->description,
            'example' => $this->example,
            'default' => $this->default,
            'nullable' => $this->nullable ?: null,
            'enum' => $this->enum,
            'properties' => null === $this->properties ? null : array_map($node, $this->properties),
            'required' => $this->required,
            'items' => null === $this->items ? null : $node($this->items),
            'additionalProperties' => is_bool($this->additionalProperties) || null === $this->additionalProperties ? $this->additionalProperties : $node($this->additionalProperties),
            'oneOf' => null === $this->oneOf ? null : array_map($node, $this->oneOf),
            'anyOf' => null === $this->anyOf ? null : array_map($node, $this->anyOf),
            'minimum' => $this->minimum,
            'maximum' => $this->maximum,
            'minLength' => $this->minLength,
            'maxLength' => $this->maxLength,
            'pattern' => $this->pattern,
            'minItems' => $this->minItems,
            'maxItems' => $this->maxItems,
            'readOnly' => $this->readOnly ?: null,
            'writeOnly' => $this->writeOnly ?: null,
        ];

        return $out + array_filter($optional, static fn(mixed $value): bool => null !== $value) + $this->meta;
    }

    /**
     * A schema in the array form, a RecordSchemaType, or null, as the array
     * form. Used by RecordFunctionType to store schemas.
     *
     * @param RecordSchemaType|array<string, mixed>|null $schema
     * @return array<string, mixed>|null
     */
    public static function normalize(RecordSchemaType|array|null $schema): ?array
    {
        return $schema instanceof self ? $schema->toArray() : $schema;
    }

    private function assertValid(): void
    {
        $isObject = RecordSchemaTypeEnum::OBJECT === $this->type;
        $isArray = RecordSchemaTypeEnum::ARRAY === $this->type;

        if (!$isObject && (null !== $this->properties || null !== $this->required || null !== $this->additionalProperties)) {
            throw new InvalidArgumentException(sprintf('properties, required and additionalProperties apply only to object schemas, not %s', $this->type->value));
        }

        if ($isArray && null === $this->items) {
            throw new InvalidArgumentException('an array schema needs items');
        }

        if (!$isArray && (null !== $this->items || null !== $this->minItems || null !== $this->maxItems)) {
            throw new InvalidArgumentException(sprintf('items, minItems and maxItems apply only to array schemas, not %s', $this->type->value));
        }

        foreach ((array) $this->properties as $name => $property) {
            if (!is_string($name) || '' === $name) {
                throw new InvalidArgumentException('object properties must be keyed by name');
            }

            if (!$property instanceof self && !is_array($property)) {
                throw new InvalidArgumentException(sprintf('property %s must be a RecordSchemaType or an array schema', $name));
            }
        }

        $unknown = array_diff($this->required ?? [], array_keys($this->properties ?? []));
        if ([] !== $unknown) {
            throw new InvalidArgumentException(sprintf('required lists properties that are not declared: %s', implode(', ', $unknown)));
        }

        if ($this->format instanceof RecordSchemaFormatEnum && !in_array($this->type, $this->format->types(), true)) {
            throw new InvalidArgumentException(sprintf('format %s does not apply to %s schemas', $this->format->value, $this->type->value));
        }

        $isText = RecordSchemaTypeEnum::STRING === $this->type;
        $isNumeric = in_array($this->type, [RecordSchemaTypeEnum::INTEGER, RecordSchemaTypeEnum::NUMBER], true);
        if (!$isText && (null !== $this->minLength || null !== $this->maxLength || null !== $this->pattern)) {
            throw new InvalidArgumentException(sprintf('minLength, maxLength and pattern apply only to string schemas, not %s', $this->type->value));
        }

        if (!$isNumeric && (null !== $this->minimum || null !== $this->maximum)) {
            throw new InvalidArgumentException(sprintf('minimum and maximum apply only to integer and number schemas, not %s', $this->type->value));
        }

        if (null !== $this->pattern && false === @preg_match('/' . str_replace('/', '\/', $this->pattern) . '/', '')) {
            throw new InvalidArgumentException(sprintf('pattern %s is not a valid regular expression', $this->pattern));
        }

        foreach ($this->enum ?? [] as $value) {
            if (!$this->valueFitsType($value)) {
                throw new InvalidArgumentException(sprintf('enum value %s is not a %s', var_export($value, true), $this->type->value));
            }
        }

        if ([] === $this->enum) {
            throw new InvalidArgumentException('enum cannot be empty if provided');
        }

        if ($this->readOnly && $this->writeOnly) {
            throw new InvalidArgumentException('a schema cannot be both readOnly and writeOnly');
        }
    }

    private function valueFitsType(mixed $value): bool
    {
        if (null === $value) {
            return $this->nullable;
        }

        return match ($this->type) {
            RecordSchemaTypeEnum::STRING => is_string($value),
            RecordSchemaTypeEnum::INTEGER => is_int($value),
            RecordSchemaTypeEnum::NUMBER => is_int($value) || is_float($value),
            RecordSchemaTypeEnum::BOOLEAN => is_bool($value),
            RecordSchemaTypeEnum::ARRAY => is_array($value) && array_is_list($value),
            RecordSchemaTypeEnum::OBJECT => is_array($value),
        };
    }

    /**
     * Handle var_export() for configuration caching.
     *
     * @param array<string, mixed> $properties
     */
    public static function __set_state(array $properties): self
    {
        return new self(...$properties);
    }
}
