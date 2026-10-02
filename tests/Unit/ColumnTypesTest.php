<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sopheak\Core\Mcp\Guidance\ColumnTypes;

class ColumnTypesTest extends TestCase
{
    /**
     * @param array<string, string> $expected
     */
    #[DataProvider('types')]
    public function test_type_mapping(string $type, string $family, array $expected): void
    {
        $this->assertSame($family, ColumnTypes::family($type));
        $this->assertSame($expected, ColumnTypes::jsonSchema($type));
    }

    /** @return array<string, array{0: string, 1: string, 2: array<string, string>}> */
    public static function types(): array
    {
        $int = ['type' => 'integer'];
        $out = [];
        foreach (['bigInteger', 'unsignedBigInteger', 'mediumInteger', 'smallInteger', 'tinyInteger', 'unsignedInteger', 'increments', 'bigIncrements', 'id', 'foreignId', 'int', 'bigint', 'integer', 'unsigned', 'int8'] as $type) {
            $out[$type] = [$type, 'number', $int];
        }

        foreach (['decimal', 'float', 'double', 'numeric', 'real'] as $type) {
            $out[$type] = [$type, 'number', ['type' => 'number']];
        }

        return $out + [
            'boolean' => ['boolean', 'boolean', ['type' => 'boolean']],
            'bool' => ['bool', 'boolean', ['type' => 'boolean']],
            'date' => ['date', 'temporal', ['type' => 'string', 'format' => 'date']],
            'datetime' => ['datetime', 'temporal', ['type' => 'string', 'format' => 'date-time']],
            'dateTime' => ['dateTime', 'temporal', ['type' => 'string', 'format' => 'date-time']],
            'timestamp' => ['timestamp', 'temporal', ['type' => 'string', 'format' => 'date-time']],
            'timestampTz' => ['timestampTz', 'temporal', ['type' => 'string', 'format' => 'date-time']],
            'time' => ['time', 'temporal', ['type' => 'string']],
            'uuid' => ['uuid', 'uuid', ['type' => 'string', 'format' => 'uuid']],
            'json' => ['json', 'json', ['type' => 'object']],
            'jsonb' => ['jsonb', 'json', ['type' => 'object']],
            'text' => ['text', 'text', ['type' => 'string']],
            'string' => ['string', 'text', ['type' => 'string']],
            'varchar' => ['varchar', 'text', ['type' => 'string']],
            'longText' => ['longText', 'text', ['type' => 'string']],
            'char' => ['char', 'text', ['type' => 'string']],
            'enum' => ['enum', 'text', ['type' => 'string']],
            'tinyint(1)' => ['tinyint(1)', 'boolean', ['type' => 'boolean']],
            'tinyint' => ['tinyint', 'number', ['type' => 'integer']],
            'numeric(8, 2)' => ['numeric(8, 2)', 'number', ['type' => 'number']],
            'varchar(255)' => ['varchar(255)', 'text', ['type' => 'string']],
            'bigint unsigned' => ['bigint unsigned', 'number', ['type' => 'integer']],
            'character varying' => ['character varying', 'text', ['type' => 'string']],
            'timestamp without time zone' => ['timestamp without time zone', 'temporal', ['type' => 'string', 'format' => 'date-time']],
            'double precision' => ['double precision', 'number', ['type' => 'number']],
            'enum values' => ["enum('a','b')", 'text', ['type' => 'string']],
            'array' => ['array', 'array', ['type' => 'array']],
            'ARRAY (postgres)' => ['ARRAY', 'array', ['type' => 'array']],
            'int4range' => ['int4range', 'range', ['type' => 'string']],
            'unknown' => ['geometry', 'other', ['type' => 'string']],
        ];
    }

    public function test_samples_are_valid_for_their_type(): void
    {
        $this->assertSame(1, ColumnTypes::sample('bigInteger'));
        $this->assertSame(9.99, ColumnTypes::sample('decimal'));
        $this->assertTrue(ColumnTypes::sample('boolean'));
        $this->assertSame('2026-01-01', ColumnTypes::sample('date'));
        $this->assertSame('example', ColumnTypes::sample('string'));
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) ColumnTypes::sample('uuid'));
    }
}
