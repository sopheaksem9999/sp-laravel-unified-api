<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use ReflectionMethod;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Sopheak\Core\Enums\RecordColumnTypeEnum;
use Sopheak\Core\Services\OpenApiService;
use Sopheak\Core\Services\RecordApiResponseService;
use Sopheak\Core\Types\RecordColumnType;
use Sopheak\Core\Types\RecordComputedAttributeType;
use Sopheak\Core\Types\RecordTableType;

class DummyComputedAttributeResolver
{
    public function compute(array|object $row, string $table): string
    {
        $first = is_object($row) ? ($row->first_name ?? '') : ($row['first_name'] ?? '');
        $last = is_object($row) ? ($row->last_name ?? '') : ($row['last_name'] ?? '');

        return trim($first . ' ' . $last);
    }
}

class RecordComputedAttributeTypeTest extends TestCase
{
    public function test_it_constructs_with_defaults(): void
    {
        $attr = new RecordComputedAttributeType(
            class: DummyComputedAttributeResolver::class,
            functionName: 'compute',
            type: RecordColumnTypeEnum::STRING,
            description: 'Full name computed attribute',
        );

        $this->assertSame(DummyComputedAttributeResolver::class, $attr->class);
        $this->assertSame('compute', $attr->functionName);
        $this->assertSame(RecordColumnTypeEnum::STRING, $attr->type);
        $this->assertSame('Full name computed attribute', $attr->description);
        $this->assertTrue($attr->nullable);
        $this->assertSame([DummyComputedAttributeResolver::class, 'compute'], $attr->toCallable());
    }

    public function test_it_rejects_empty_class_or_function_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RecordComputedAttributeType(class: '   ');
    }

    public function test_it_survives_var_export_and_set_state(): void
    {
        $attr = new RecordComputedAttributeType(
            class: DummyComputedAttributeResolver::class,
            functionName: 'compute',
            type: RecordColumnTypeEnum::DECIMAL,
            description: 'Total amount',
            example: 49.99,
            nullable: false,
        );

        $exported = var_export($attr, true);
        $evaled = eval('return ' . $exported . ';');

        $this->assertInstanceOf(RecordComputedAttributeType::class, $evaled);
        $this->assertSame(DummyComputedAttributeResolver::class, $evaled->class);
        $this->assertSame('compute', $evaled->functionName);
        $this->assertSame(RecordColumnTypeEnum::DECIMAL, $evaled->type);
        $this->assertSame('Total amount', $evaled->description);
        $this->assertSame(49.99, $evaled->example);
        $this->assertFalse($evaled->nullable);
    }

    public function test_it_resolves_callable_in_record_api_response_service(): void
    {
        $attr = new RecordComputedAttributeType(
            class: DummyComputedAttributeResolver::class,
            functionName: 'compute',
            type: RecordColumnTypeEnum::STRING,
        );

        $data = [
            ['id' => 1, 'first_name' => 'Alice', 'last_name' => 'Smith'],
            ['id' => 2, 'first_name' => 'Bob', 'last_name' => 'Jones'],
        ];

        $result = RecordApiResponseService::applyAttributes(
            data: $data,
            table: 'users',
            attributes: ['full_name' => $attr],
            requestedCols: ['id', 'full_name']
        );

        $this->assertSame('Alice Smith', $result[0]['full_name']);
        $this->assertSame('Bob Jones', $result[1]['full_name']);
    }

    public function test_it_documents_computed_attributes_as_read_only_in_openapi(): void
    {
        $attr = new RecordComputedAttributeType(
            class: DummyComputedAttributeResolver::class,
            functionName: 'compute',
            type: RecordColumnTypeEnum::STRING,
            description: 'User full display name',
            example: 'Alice Smith',
        );

        $table = new RecordTableType(
            table: 'members',
            columns: [
                'id' => new RecordColumnType(type: RecordColumnTypeEnum::BIGINT, nullable: false),
            ],
            attributes: [
                'full_name' => $attr,
            ],
        );

        $schemaMethod = new ReflectionMethod(OpenApiService::class, 'tableSchemaRead');
        $schema = $schemaMethod->invoke(null, 'members', $table->columns, $table);

        $this->assertArrayHasKey('full_name', $schema['properties']);
        $this->assertTrue($schema['properties']['full_name']['readOnly']);
        $this->assertSame('string', $schema['properties']['full_name']['type']);
        $this->assertSame('User full display name', $schema['properties']['full_name']['description']);
        $this->assertSame('Alice Smith', $schema['properties']['full_name']['example']);
    }
}
