<?php

declare(strict_types=1);

namespace Sopheak\Core\Enums;

use InvalidArgumentException;

/**
 * Enum RecordCastEnum.
 *
 * Defines built-in response cast types for RecordTableType::$casting and RecordColumnType::$cast.
 */
enum RecordCastEnum: string
{
    case INT = 'int';
    case INTEGER = 'integer';
    case FLOAT = 'float';
    case DOUBLE = 'double';
    case REAL = 'real';
    case DECIMAL = 'decimal';
    case STRING = 'string';
    case BOOL = 'bool';
    case BOOLEAN = 'boolean';
    case ARRAY = 'array';
    case JSON = 'json';
    case OBJECT = 'object';
    case DATE = 'date';
    case DATETIME = 'datetime';
    case TIMESTAMP = 'timestamp';

    /**
     * Build a fixed-scale decimal cast string (e.g., `decimal:2`).
     */
    public static function decimal(int $places = 2): string
    {
        if ($places < 0) {
            throw new InvalidArgumentException('Decimal places must be a non-negative integer.');
        }

        return 'decimal:' . $places;
    }

    /**
     * Return all built-in cast string values.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $case): string => $case->value, self::cases());
    }
}

