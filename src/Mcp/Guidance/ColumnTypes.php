<?php

declare(strict_types=1);

namespace Sopheak\Core\Mcp\Guidance;

/**
 * Column type vocabulary for the schema tools. Column configs carry whatever
 * the database or the table config calls a type (`bigInteger`, `int8`,
 * `timestamptz`, ...). This class folds those spellings into the few families
 * that decide which filter operators apply and which JSON-Schema type and
 * format an agent should send.
 */
final class ColumnTypes
{
    private const INTEGERS = [
        'integer', 'int', 'bigint', 'biginteger', 'smallint', 'smallinteger', 'tinyint', 'tinyinteger',
        'mediumint', 'mediuminteger', 'increments', 'bigincrements', 'smallincrements', 'mediumincrements',
        'tinyincrements', 'id', 'foreignid', 'int2', 'int4', 'int8', 'serial', 'bigserial',
    ];

    private const DECIMALS = ['decimal', 'float', 'double', 'doubleprecision', 'numeric', 'real', 'money', 'float4', 'float8'];

    private const DATE_TIMES = ['datetime', 'datetimetz', 'timestamp', 'timestamptz'];

    private const TIMES = ['time', 'timetz', 'year'];

    private const JSON = ['json', 'jsonb', 'object'];

    private const TEXTS = [
        'string', 'text', 'varchar', 'char', 'longtext', 'mediumtext', 'tinytext', 'enum',
        'charactervarying', 'character', 'bpchar', 'nvarchar', 'nchar', 'citext',
    ];

    /**
     * Folds the spellings databases and table configs use for one type into a
     * single lower-case token: `unsignedBigInteger` and `bigint unsigned` both
     * become `biginteger`/`bigint`; `varchar(255)` becomes `varchar`;
     * `timestamp without time zone` becomes `timestamp`. MySQL and SQLite report
     * booleans as `tinyint(1)`, which stays a boolean.
     */
    public static function normalize(string $type): string
    {
        $type = strtolower(trim($type));
        if (1 === preg_match('/^tinyint\(\s*1\s*\)/', $type)) {
            return 'boolean';
        }

        $type = (string) preg_replace('/\(.*?\)/', '', $type);
        $type = (string) preg_replace('/unsigned|zerofill|with(out)? time zone/', '', $type);
        $type = (string) preg_replace('/[\s_\-]+/', '', $type);

        return '' === $type ? 'integer' : $type;
    }

    /** One of `text`, `number`, `temporal`, `boolean`, `json`, `array`, `uuid`, `range`, `other`. */
    public static function family(string $type): string
    {
        $type = self::normalize($type);

        return match (true) {
            in_array($type, self::INTEGERS, true), in_array($type, self::DECIMALS, true) => 'number',
            'boolean' === $type, 'bool' === $type => 'boolean',
            'date' === $type, in_array($type, self::DATE_TIMES, true), in_array($type, self::TIMES, true) => 'temporal',
            'uuid' === $type, 'ulid' === $type => 'uuid',
            in_array($type, self::JSON, true) => 'json',
            'array' === $type => 'array',
            str_contains($type, 'range') => 'range',
            in_array($type, self::TEXTS, true) => 'text',
            default => 'other',
        };
    }

    /**
     * @return array<string, string> `type`, plus `format` for dates and uuids
     */
    public static function jsonSchema(string $type): array
    {
        $normalized = self::normalize($type);

        return match (true) {
            in_array($normalized, self::INTEGERS, true) => ['type' => 'integer'],
            in_array($normalized, self::DECIMALS, true) => ['type' => 'number'],
            'boolean' === $normalized, 'bool' === $normalized => ['type' => 'boolean'],
            'date' === $normalized => ['type' => 'string', 'format' => 'date'],
            in_array($normalized, self::DATE_TIMES, true) => ['type' => 'string', 'format' => 'date-time'],
            'uuid' === $normalized => ['type' => 'string', 'format' => 'uuid'],
            in_array($normalized, self::JSON, true) => ['type' => 'object'],
            'array' === $normalized => ['type' => 'array'],
            default => ['type' => 'string'],
        };
    }

    /** A valid example value for a column type. */
    public static function sample(string $type): mixed
    {
        $normalized = self::normalize($type);

        return match (true) {
            in_array($normalized, self::INTEGERS, true) => 1,
            in_array($normalized, self::DECIMALS, true) => 9.99,
            'boolean' === $normalized, 'bool' === $normalized => true,
            'date' === $normalized => '2026-01-01',
            in_array($normalized, self::DATE_TIMES, true) => '2026-01-01T00:00:00Z',
            'uuid' === $normalized => '3f2504e0-4f89-11d3-9a0c-0305e82c3301',
            in_array($normalized, self::JSON, true), 'array' === $normalized => [],
            default => 'example',
        };
    }
}
