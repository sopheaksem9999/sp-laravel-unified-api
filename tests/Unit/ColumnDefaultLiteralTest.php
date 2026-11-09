<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordColumnType;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\RecordPayloadExtractor;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * Database defaults are SQL (`'0'::numeric`, `CURRENT_TIMESTAMP`); only
 * literals may be filled into a create payload, or the insert fails with
 * `invalid input syntax for type numeric: "'0'::numeric"`.
 */
class ColumnDefaultLiteralTest extends TestCase
{
    /** @return array<string, array{0: mixed, 1: bool, 2: mixed}> */
    public static function defaults(): array
    {
        return [
            'pgsql numeric cast' => ["'0'::numeric", true, '0'],
            'pgsql varchar cast' => ["'draft'::character varying", true, 'draft'],
            'pgsql escaped quote' => ["'it''s'::text", true, "it's"],
            'pgsql jsonb' => ["'{}'::jsonb", true, '{}'],
            'pgsql array' => ["'{a,b}'::text[]", true, '{a,b}'],
            'pgsql boolean' => ['false', true, false],
            'pgsql negative' => ['(-1)', true, '-1'],
            'pgsql bigint cast' => ['2::bigint', true, '2'],
            'pgsql sequence' => ["nextval('t_id_seq'::regclass)", false, null],
            'pgsql null cast' => ['NULL::character varying', false, null],
            'pgsql uuid fn' => ['gen_random_uuid()', false, null],
            'current timestamp' => ['CURRENT_TIMESTAMP', false, null],
            'mysql now()' => ['now()', false, null],
            'mysql decimal' => ['0.00', true, '0.00'],
            'mysql leading zeros stay text' => ['007', true, '007'],
            'mysql plain string' => ['active', true, 'active'],
            'sqlite quoted' => ["'open'", true, 'open'],
            'php int' => [0, true, 0],
            'php bool' => [true, true, true],
        ];
    }

    #[DataProvider('defaults')]
    public function test_database_defaults_parse_to_php_literals(mixed $default, bool $isLiteral, mixed $value): void
    {
        $this->assertSame([$isLiteral, $value], SchemaRegistryUtils::literalDefault($default));
    }

    public function test_a_create_payload_gets_literal_defaults_and_leaves_expressions_to_the_database(): void
    {
        $table = new RecordTableType(table: 'products', columns: [
            'name' => ['type' => 'varchar', 'nullable' => false],
            'standard_cost' => ['type' => 'numeric', 'nullable' => true, 'default' => "'0'::numeric"],
            'status' => ['type' => 'character varying', 'nullable' => false, 'default' => "'draft'::character varying"],
            'is_active' => ['type' => 'boolean', 'nullable' => false, 'default' => 'true'],
            'posted_at' => ['type' => 'timestamp with time zone', 'nullable' => false, 'default' => 'CURRENT_TIMESTAMP'],
            'code' => ['type' => 'uuid', 'nullable' => false, 'default' => 'gen_random_uuid()'],
        ]);

        $payload = RecordPayloadExtractor::fromArray(data: ['name' => 'P1'], recordTableSchema: $table);

        $this->assertSame('P1', $payload['name']);
        $this->assertSame('0', $payload['standard_cost']);
        $this->assertSame('draft', $payload['status']);
        $this->assertTrue($payload['is_active']);
        $this->assertArrayNotHasKey('posted_at', $payload);
        $this->assertArrayNotHasKey('code', $payload);
    }

    public function test_the_typed_form_writes_the_literal(): void
    {
        $column = RecordColumnType::fromArray(['type' => 'numeric', 'udt_name' => 'numeric', 'nullable' => false, 'default' => "'0'::numeric"]);

        $this->assertSame('0', $column->default);
        $this->assertSame('CURRENT_TIMESTAMP', RecordColumnType::fromArray(['type' => 'timestamptz', 'default' => 'CURRENT_TIMESTAMP'])->default);
    }
}
