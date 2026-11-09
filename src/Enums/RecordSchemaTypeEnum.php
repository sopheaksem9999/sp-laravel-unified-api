<?php

declare(strict_types=1);

namespace Sopheak\Core\Enums;

/**
 * OpenAPI 3.0 data types for `RecordSchemaType` (function query, payload and
 * response schemas).
 */
enum RecordSchemaTypeEnum: string
{
    case OBJECT = 'object';
    case ARRAY = 'array';
    case STRING = 'string';
    case INTEGER = 'integer';
    case NUMBER = 'number';
    case BOOLEAN = 'boolean';
}
