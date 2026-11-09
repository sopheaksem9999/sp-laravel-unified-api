<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sopheak\Core\Enums\RecordSchemaFormatEnum as F;
use Sopheak\Core\Enums\RecordSchemaTypeEnum as T;
use Sopheak\Core\Types\RecordFunctionType;
use Sopheak\Core\Types\RecordSchemaType as S;

class RecordSchemaTypeTest extends TestCase
{
    public function test_typed_schema_produces_the_same_array_as_the_hand_written_one(): void
    {
        $array = [
            'type' => 'object',
            'properties' => [
                'booking_id' => ['type' => 'string', 'format' => 'uuid', 'description' => 'Target booking UUID'],
                'status' => ['type' => 'string', 'description' => 'Target status', 'enum' => ['accepted', 'rejected']],
                'latitude' => ['type' => 'number'],
                'count' => ['type' => 'integer', 'minimum' => 1],
                'paid' => ['type' => 'boolean'],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                'at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
            ],
            'required' => ['booking_id', 'status'],
        ];

        $typed = S::object(
            properties: [
                'booking_id' => S::uuid('Target booking UUID'),
                'status' => S::string('Target status', enum: ['accepted', 'rejected']),
                'latitude' => S::number(),
                'count' => S::integer(minimum: 1),
                'paid' => S::boolean(),
                'tags' => S::array(S::string()),
                'at' => S::dateTime(nullable: true),
            ],
            required: ['booking_id', 'status'],
        );

        $this->assertEquals($array, $typed->toArray());
    }

    public function test_function_type_stores_typed_schemas_as_arrays_and_mixes_with_arrays(): void
    {
        $function = new RecordFunctionType(
            httpMethod: 'POST',
            class: 'App\\Svc',
            functionName: 'run',
            querySchema: S::object(['id' => S::uuid()], ['id']),
            payloadSchema: ['type' => 'object', 'properties' => ['note' => S::string()->toArray()]],
            responseSchema: S::array(S::object(['ok' => S::boolean()])),
        );

        $this->assertSame(['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'format' => 'uuid']], 'required' => ['id']], $function->querySchema);
        $this->assertSame(['type' => 'object', 'properties' => ['note' => ['type' => 'string']]], $function->payloadSchema);
        $this->assertSame(['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']]]], $function->responseSchema);
        $this->assertSame([], RecordFunctionType::schemaProblems('querySchema', $function->querySchema));

        $restored = eval('return ' . var_export($function, true) . ';');
        $this->assertSame($function->querySchema, $restored->querySchema);
    }

    public function test_a_schema_object_survives_var_export(): void
    {
        $schema = S::object(['a' => S::string(maxLength: 5)], ['a']);
        $restored = eval('return ' . var_export($schema, true) . ';');

        $this->assertEquals($schema->toArray(), $restored->toArray());
    }

    /** @return array<string, array{0: callable(): mixed, 1: string}> */
    public static function invalidSchemas(): array
    {
        return [
            'required not declared' => [fn(): S => S::object(['a' => S::string()], ['b']), 'not declared: b'],
            'properties on a string' => [fn(): S => new S(T::STRING, properties: ['a' => S::string()]), 'only to object'],
            'array without items' => [fn(): S => new S(T::ARRAY), 'needs items'],
            'items on an object' => [fn(): S => new S(T::OBJECT, items: S::string()), 'only to array'],
            'uuid format on integer' => [fn(): S => new S(T::INTEGER, format: F::UUID), 'does not apply to integer'],
            'int64 format on string' => [fn(): S => new S(T::STRING, format: F::INT64), 'does not apply to string'],
            'maxLength on integer' => [fn(): S => new S(T::INTEGER, maxLength: 3), 'only to string'],
            'minimum on string' => [fn(): S => new S(T::STRING, minimum: 1), 'only to integer and number'],
            'enum value of the wrong type' => [fn(): S => new S(T::INTEGER, enum: ['1']), 'is not a integer'],
            'empty enum' => [fn(): S => new S(T::STRING, enum: []), 'cannot be empty'],
            'bad pattern' => [fn(): S => new S(T::STRING, pattern: '(unclosed'), 'not a valid regular expression'],
            'readOnly and writeOnly' => [fn(): S => new S(T::STRING, readOnly: true, writeOnly: true), 'both readOnly and writeOnly'],
            'unnamed property' => [fn(): S => S::object([S::string()]), 'keyed by name'],
        ];
    }

    #[DataProvider('invalidSchemas')]
    public function test_invalid_schemas_are_rejected(callable $build, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $build();
    }

    public function test_an_object_without_properties_has_no_properties_key(): void
    {
        $this->assertSame(['type' => 'object', 'description' => 'Any shape'], S::object(description: 'Any shape')->toArray());
    }

    public function test_custom_format_strings_and_meta_pass_through(): void
    {
        $schema = new S(T::STRING, format: 'phone', meta: ['x-label' => 'Phone']);

        $this->assertSame(['type' => 'string', 'format' => 'phone', 'x-label' => 'Phone'], $schema->toArray());
    }
}
