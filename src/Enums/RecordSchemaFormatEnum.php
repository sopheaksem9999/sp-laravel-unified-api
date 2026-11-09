<?php

declare(strict_types=1);

namespace Sopheak\Core\Enums;

/**
 * OpenAPI 3.0 formats for `RecordSchemaType`, each valid only with the types
 * {@see self::types()} lists. A format outside this list can still be passed
 * as a string.
 */
enum RecordSchemaFormatEnum: string
{
    // integer
    case INT32 = 'int32';
    case INT64 = 'int64';

    // number
    case FLOAT = 'float';
    case DOUBLE = 'double';
    case DECIMAL = 'decimal';

    // string
    case DATE = 'date';
    case DATE_TIME = 'date-time';
    case TIME = 'time';
    case UUID = 'uuid';
    case EMAIL = 'email';
    case URI = 'uri';
    case HOSTNAME = 'hostname';
    case IPV4 = 'ipv4';
    case IPV6 = 'ipv6';
    case PASSWORD = 'password';
    case BYTE = 'byte';
    case BINARY = 'binary';

    /** @return list<RecordSchemaTypeEnum> The types this format applies to. */
    public function types(): array
    {
        return match ($this) {
            self::INT32, self::INT64 => [RecordSchemaTypeEnum::INTEGER],
            self::FLOAT, self::DOUBLE, self::DECIMAL => [RecordSchemaTypeEnum::NUMBER],
            default => [RecordSchemaTypeEnum::STRING],
        };
    }
}
