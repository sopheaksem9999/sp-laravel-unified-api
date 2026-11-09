<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use Sopheak\Core\Services\OpenApiService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Sopheak\Core\Enums\RecordColumnTypeEnum as T;
use Sopheak\Core\Mcp\Guidance\ColumnTypes;
use Sopheak\Core\Services\RecordApiResponseService;
use Sopheak\Core\Types\RecordColumnType;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\DefaultValidationUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class RecordColumnTypeTest extends TestCase
{
    /**
     * What the package does with one column definition. Two definitions with
     * the same signature behave the same everywhere.
     *
     * @param array<string, mixed> $info
     * @return array<string, mixed>
     */
    private function signature(array $info): array
    {
        $rule = new ReflectionMethod(DefaultValidationUtils::class, 'mapTypeToRule');
        $cast = new ReflectionMethod(RecordApiResponseService::class, 'inferBuiltinCastFromColumnMeta');
        $type = (string) ($info['type'] ?? '');

        // Mirrors the ?search= column test in QueryBuilderFiltersUtils.
        $base = str_replace(['character varying', 'character'], ['varchar', 'char'], (string) preg_replace('/\([^)]*\)/', '', strtolower((string) ($info['type'] ?? $info['udt_name'] ?? ''))));

        return [
            'rule' => $rule->invoke(null, $type, (string) ($info['udt_name'] ?? '')),
            'required' => DefaultValidationUtils::isRequiredColumn($info),
            'cast' => empty($info['compositeFields']) ? $cast->invoke(null, $info) : 'composite',
            'family' => ColumnTypes::family($type),
            'jsonSchema' => ColumnTypes::jsonSchema($type),
            'uuid' => SchemaRegistryUtils::isUuidColumnType($info),
            'searchable' => in_array($base, ['varchar', 'text', 'char', 'string', 'longtext', 'mediumtext', 'citext', 'uuid', 'json', 'jsonb'], true),
            'openapi' => OpenApiService::columnTypeSchema($info),
            'nullable' => (bool) ($info['nullable'] ?? true),
            'enum' => $info['enum'] ?? null,
            'annotations' => [$info['description'] ?? null, $info['example'] ?? null, $info['maxLength'] ?? null],
        ];
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function databaseShapes(): array
    {
        $pg = static fn(string $type, string $udt, bool $nullable = true, mixed $default = null, array $more = []): array => ['type' => $type, 'udt_name' => $udt, 'udt_schema' => 'pg_catalog', 'nullable' => $nullable, 'key' => '', 'default' => $default, 'extra' => ''] + $more;
        $my = static fn(string $type, bool $nullable = true, mixed $default = null, string $key = '', string $extra = '', array $more = []): array => ['type' => $type, 'key' => $key, 'nullable' => $nullable, 'default' => $default, 'extra' => $extra] + $more;
        $lite = static fn(string $type, bool $nullable = true, mixed $default = null, bool $pk = false, array $more = []): array => ['type' => $type, 'key' => $pk ? 'PRI' : '', 'nullable' => $nullable, 'default' => $default, 'extra' => ''] + $more;

        return [
            'pgsql uuid' => [$pg('uuid', 'uuid', false)],
            'pgsql timestamptz' => [$pg('timestamp with time zone', 'timestamptz', false, 'CURRENT_TIMESTAMP')],
            'pgsql timestamp' => [$pg('timestamp without time zone', 'timestamp')],
            'pgsql date' => [$pg('date', 'date')],
            'pgsql time' => [$pg('time without time zone', 'time')],
            'pgsql numeric' => [$pg('numeric', 'numeric', false, "'0'::numeric")],
            'pgsql double' => [$pg('double precision', 'float8')],
            'pgsql real' => [$pg('real', 'float4')],
            'pgsql bigint' => [$pg('bigint', 'int8', false, "'0'::bigint")],
            'pgsql integer' => [$pg('integer', 'int4')],
            'pgsql smallint' => [$pg('smallint', 'int2')],
            'pgsql serial' => [$pg('bigint', 'int8', false, "nextval('t_id_seq'::regclass)")],
            'pgsql boolean' => [$pg('boolean', 'bool', false, 'false')],
            'pgsql text' => [$pg('text', 'text')],
            'pgsql varchar' => [$pg('character varying', 'varchar')],
            'pgsql char' => [$pg('character', 'bpchar')],
            'pgsql json' => [$pg('json', 'json')],
            'pgsql jsonb' => [$pg('jsonb', 'jsonb')],
            'pgsql bytea' => [$pg('bytea', 'bytea')],
            'pgsql array' => [$pg('ARRAY', '_text')],
            'pgsql enum' => [$pg('USER-DEFINED', 'status', false, null, ['udt_schema' => 'public', 'enum' => ['open', 'paid']])],
            'pgsql composite' => [$pg('USER-DEFINED', 'money_t', false, null, ['udt_schema' => 'billing', 'compositeFields' => ['amount' => 'numeric', 'currency' => 'text']])],
            'pgsql citext' => [$pg('USER-DEFINED', 'citext')],
            'mysql bigint pk' => [$my('bigint unsigned', false, null, 'PRI', 'auto_increment')],
            'mysql int' => [$my('int(11)')],
            'mysql tinyint(1)' => [$my('tinyint(1)', false, '0')],
            'mysql tinyint(4)' => [$my('tinyint(4)')],
            'mysql varchar' => [$my('varchar(255)', false, null, 'UNI')],
            'mysql char(36)' => [$my('char(36)', false, null, 'PRI')],
            'mysql decimal' => [$my('decimal(10,2)', false, '0.00')],
            'mysql double' => [$my('double')],
            'mysql datetime' => [$my('datetime')],
            'mysql timestamp' => [$my('timestamp', true, 'CURRENT_TIMESTAMP', '', 'on update CURRENT_TIMESTAMP')],
            'mysql date' => [$my('date')],
            'mysql time' => [$my('time')],
            'mysql json' => [$my('json')],
            'mysql longtext' => [$my('longtext')],
            'mysql enum' => [$my("enum('a','b')", true, 'a', 'MUL', '', ['enum' => ['a', 'b']])],
            'mysql blob' => [$my('blob')],
            'mysql year' => [$my('year')],
            'sqlite integer pk' => [$lite('INTEGER', false, null, true)],
            'sqlite varchar' => [$lite('varchar', false, "'open'", false, ['enum' => ['open', 'paid']])],
            'sqlite datetime' => [$lite('datetime')],
            'sqlite numeric' => [$lite('numeric')],
            'sqlite tinyint(1)' => [$lite('tinyint(1)', false, "'0'")],
            'sqlite typeless' => [$lite('')],
            'pgsql vector' => [$pg('USER-DEFINED', 'vector', true, null, ['udt_schema' => 'public'])],
            'pgsql halfvec' => [$pg('USER-DEFINED', 'halfvec')],
            'pgsql geometry' => [$pg('USER-DEFINED', 'geometry', true, null, ['udt_schema' => 'public'])],
            'pgsql hstore' => [$pg('USER-DEFINED', 'hstore')],
            'pgsql int array' => [$pg('ARRAY', '_int4')],
            'pgsql uuid array' => [$pg('ARRAY', '_uuid')],
            'pgsql inet' => [$pg('inet', 'inet')],
            'pgsql cidr' => [$pg('cidr', 'cidr')],
            'pgsql macaddr' => [$pg('macaddr', 'macaddr')],
            'pgsql interval' => [$pg('interval', 'interval')],
            'pgsql timetz' => [$pg('time with time zone', 'timetz')],
            'pgsql money' => [$pg('money', 'money')],
            'pgsql tsvector' => [$pg('tsvector', 'tsvector')],
            'pgsql xml' => [$pg('xml', 'xml')],
            'pgsql bit' => [$pg('bit varying', 'varbit')],
            'pgsql point' => [$pg('point', 'point')],
            'pgsql int4range' => [$pg('int4range', 'int4range')],
            'pgsql tstzrange' => [$pg('tstzrange', 'tstzrange')],
            'pgsql daterange' => [$pg('daterange', 'daterange')],
            'mysql set' => [$my("set('a','b')")],
            'mysql geometry' => [$my('geometry')],
            'mysql point' => [$my('point')],
            'mysql varbinary' => [$my('varbinary(16)')],
            'mysql mediumint' => [$my('mediumint(9)')],
            'mysql float' => [$my('float')],
            'mysql bit' => [$my('bit(1)')],
            'sqlite real' => [$lite('REAL')],
            'sqlite blob' => [$lite('BLOB')],
            'with annotations' => [['type' => 'string', 'nullable' => false, 'description' => 'Customer name', 'example' => 'Acme', 'maxLength' => 120]],
            'pgsql timestamp without tz' => [$pg('timestamp(0) without time zone', 'timestamp')],
            'legacy config' => [['type' => 'string', 'nullable' => false]],
            'legacy laravel names' => [['type' => 'bigIncrements', 'nullable' => false]],
            'legacy timestampTz' => [['type' => 'timestampTz']],
            'legacy cast' => [['type' => 'integer', 'cast' => 'boolean']],
        ];
    }

    /** @param array<string, mixed> $info */
    #[DataProvider('databaseShapes')]
    public function test_the_typed_form_behaves_like_the_database_array(array $info): void
    {
        $typed = RecordColumnType::fromArray($info)->toArray();

        $this->assertSame($this->signature($info), $this->signature($typed));
        $this->assertEquals($typed, RecordColumnType::fromArray($typed)->toArray(), 'stable on a second round trip');
    }

    public function test_generated_postgres_columns_drop_the_driver_noise(): void
    {
        $id = RecordColumnType::fromArray(['type' => 'uuid', 'udt_name' => 'uuid', 'udt_schema' => 'pg_catalog', 'nullable' => false, 'key' => '', 'default' => null, 'extra' => '']);
        $this->assertSame(T::UUID, $id->type);
        $this->assertSame(['type' => 'uuid', 'nullable' => false], $id->toArray());

        $created = RecordColumnType::fromArray(['type' => 'timestamp with time zone', 'udt_name' => 'timestamptz', 'udt_schema' => 'pg_catalog', 'nullable' => false, 'key' => '', 'default' => 'CURRENT_TIMESTAMP', 'extra' => '']);
        $this->assertSame(T::TIMESTAMPTZ, $created->type);
        $this->assertSame('CURRENT_TIMESTAMP', $created->default);
        $this->assertSame([], $created->meta);

        $serial = RecordColumnType::fromArray(['type' => 'bigint', 'udt_name' => 'int8', 'nullable' => false, 'default' => "nextval('s'::regclass)"]);
        $this->assertTrue($serial->autoIncrement);
        $this->assertNull($serial->default);

        $composite = RecordColumnType::fromArray(['type' => 'USER-DEFINED', 'udt_name' => 'money_t', 'udt_schema' => 'billing', 'compositeFields' => ['amount' => 'numeric']]);
        $this->assertSame('billing.money_t', $composite->compositeType);
    }

    public function test_postgres_extension_types_resolve_through_udt_name(): void
    {
        $vector = RecordColumnType::fromArray(['type' => 'USER-DEFINED', 'udt_name' => 'vector', 'udt_schema' => 'public', 'nullable' => true, 'key' => '', 'default' => null, 'extra' => '']);
        $this->assertSame(T::VECTOR, $vector->type);
        $this->assertNull($vector->nativeType);
        $this->assertSame(['type' => 'vector', 'nullable' => true], $vector->toArray());

        $this->assertSame(T::GEOMETRY, RecordColumnType::fromArray(['type' => 'USER-DEFINED', 'udt_name' => 'geometry'])->type);
        $this->assertSame(T::ARRAY, RecordColumnType::fromArray(['type' => 'ARRAY', 'udt_name' => '_text'])->type);
    }

    public function test_every_case_spelling_maps_back_to_itself(): void
    {
        foreach (T::cases() as $case) {
            $this->assertSame($case, T::fromNative($case->spelling()), $case->name);
        }
    }

    /** @return array<string, array{0: string, 1: T|null}> */
    public static function mysqlMariadbSqliteSpellings(): array
    {
        return [
            'mysql tinyint(1)' => ['tinyint(1)', T::BOOLEAN], 'mysql tinyint(1) unsigned' => ['tinyint(1) unsigned', T::BOOLEAN],
            'mysql tinyint(4)' => ['tinyint(4)', T::SMALLINT], 'mysql int(10) unsigned zerofill' => ['int(10) unsigned zerofill', T::INTEGER],
            'mysql bigint(20) unsigned' => ['bigint(20) unsigned', T::BIGINT], 'mysql decimal unsigned' => ['decimal(10,2) unsigned', T::DECIMAL],
            'mysql double(8,2)' => ['double(8,2) unsigned', T::DOUBLE], 'mysql bit(1)' => ['bit(1)', T::BIT],
            'mysql datetime(6)' => ['datetime(6)', T::DATETIME], 'mysql timestamp(6)' => ['timestamp(6)', T::TIMESTAMP],
            'mysql year(4)' => ['year(4)', T::YEAR], 'mysql char(36)' => ['char(36)', null], 'mysql varbinary' => ['varbinary(255)', T::BINARY],
            'mysql longblob' => ['longblob', T::BINARY], 'mysql set' => ["set('a','b')", T::SET], 'mysql multipolygon' => ['multipolygon', T::POLYGON],
            'mysql geomcollection' => ['geomcollection', T::GEOMETRY],
            'mariadb uuid' => ['uuid', T::UUID], 'mariadb inet4' => ['inet4', T::INET], 'mariadb inet6' => ['inet6', T::INET], 'mariadb vector' => ['vector(3)', T::VECTOR],
            'sqlite UNSIGNED BIG INT' => ['UNSIGNED BIG INT', T::BIGINT], 'sqlite VARYING CHARACTER' => ['VARYING CHARACTER(255)', T::STRING],
            'sqlite NATIVE CHARACTER' => ['NATIVE CHARACTER(70)', T::CHAR], 'sqlite NVARCHAR' => ['NVARCHAR(100)', T::STRING],
            'sqlite CLOB' => ['CLOB', T::TEXT], 'sqlite REAL' => ['REAL', T::FLOAT], 'sqlite DOUBLE PRECISION' => ['DOUBLE PRECISION', T::DOUBLE],
            'sqlite NUMERIC' => ['NUMERIC', T::DECIMAL], 'sqlite BOOLEAN' => ['BOOLEAN', T::BOOLEAN], 'sqlite typeless' => ['', null],
        ];
    }

    #[DataProvider('mysqlMariadbSqliteSpellings')]
    public function test_mysql_mariadb_and_sqlite_spellings(string $native, ?T $expected): void
    {
        $this->assertSame($expected, T::fromNative($native));
    }

    public function test_native_spellings_cast_to_the_standard_type(): void
    {
        $this->assertSame(T::TIMESTAMPTZ, (new RecordColumnType('timestamptz'))->type);
        $this->assertSame(T::TIMESTAMPTZ, (new RecordColumnType('timestamp with time zone'))->type);
        $this->assertSame(T::TIMESTAMP, (new RecordColumnType('timestamp without time zone'))->type);
        $this->assertSame(T::DOUBLE, (new RecordColumnType('double precision'))->type);
        $this->assertSame(T::VECTOR, (new RecordColumnType('vector(1536)'))->type);
        $this->assertSame(T::BOOLEAN, (new RecordColumnType('tinyint(1)'))->type);
        $this->assertSame(T::BIGINT, (new RecordColumnType('bigIncrements'))->type);
        $this->assertSame(T::DECIMAL, (new RecordColumnType('decimal(10,2)'))->type);
        $this->assertSame(T::UUID, (new RecordColumnType('uuid'))->type);

        $unknown = new RecordColumnType('my_custom_type');
        $this->assertSame(T::STRING, $unknown->type);
        $this->assertSame('my_custom_type', $unknown->toArray()['type'], 'an unknown type keeps its database spelling');
    }

    public function test_invalid_definitions_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RecordColumnType(type: T::ENUM);
    }

    public function test_typed_and_legacy_columns_mix_in_a_table(): void
    {
        $table = new RecordTableType(table: 't', columns: [
            'id' => new RecordColumnType(type: T::UUID, nullable: false, primary: true),
            'status' => new RecordColumnType(type: T::ENUM, nullable: false, default: 'open', enum: ['open', 'paid']),
            'qty' => ['type' => 'integer', 'unsigned' => true],
        ]);

        $this->assertSame(['type' => 'uuid', 'nullable' => false, 'key' => 'PRI'], $table->columns['id']);
        $this->assertSame(['type' => 'enum', 'nullable' => false, 'default' => 'open', 'enum' => ['open', 'paid']], $table->columns['status']);
        $this->assertSame(['type' => 'integer', 'unsigned' => true], $table->columns['qty']);
    }

    public function test_config_cache_round_trip(): void
    {
        $table = new RecordTableType(table: 't', columns: ['a' => new RecordColumnType(type: T::STRING, nullable: false)]);
        $restored = eval('return ' . var_export($table, true) . ';');

        $this->assertSame(['a' => ['type' => 'varchar', 'nullable' => false]], $restored->columns);
    }
}
