<?php

declare(strict_types=1);

namespace Sopheak\Core\Enums;

/**
 * Enum RecordTriggerHookEnum.
 *
 * Defines the supported lifecycle trigger hooks for RecordTableType and #[RecordTrigger].
 */
enum RecordTriggerHookEnum: string
{
    case BEFORE_READ = 'beforeRead';
    case AFTER_READ = 'afterRead';
    case BEFORE_CREATE = 'beforeCreate';
    case AFTER_CREATE = 'afterCreate';
    case BEFORE_UPDATE = 'beforeUpdate';
    case AFTER_UPDATE = 'afterUpdate';
    case BEFORE_DELETE = 'beforeDelete';
    case AFTER_DELETE = 'afterDelete';
    case BEFORE_RESTORE = 'beforeRestore';
    case AFTER_RESTORE = 'afterRestore';

    /**
     * Return all supported trigger hook values.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $case): string => $case->value, self::cases());
    }
}

