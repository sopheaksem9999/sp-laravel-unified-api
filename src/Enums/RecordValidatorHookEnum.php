<?php

declare(strict_types=1);

namespace Sopheak\Core\Enums;

/**
 * Enum RecordValidatorHookEnum.
 *
 * Defines the supported validation hooks for #[RecordValidator].
 */
enum RecordValidatorHookEnum: string
{
    case CREATE = 'create';
    case UPDATE = 'update';
    case DELETE = 'delete';

    /**
     * Return all supported validator hook values.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $case): string => $case->value, self::cases());
    }
}

