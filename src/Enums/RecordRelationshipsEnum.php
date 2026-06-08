<?php

declare(strict_types=1);

namespace Sopheak\Core\Enums;

/**
 * Enum for defining relationship types in the Record API system.
 *
 * This enum provides standardized relationship type definitions for
 * the dynamic API system, ensuring consistency across the application.
 */
enum RecordRelationshipsEnum: string
{
    case BELONGS_TO = 'belongsTo';
    case HAS_MANY = 'hasMany';
    case HAS_ONE = 'hasOne';
    case BELONGS_TO_MANY = 'belongsToMany';
    case HAS_MANY_THROUGH = 'hasManyThrough';
    case HAS_ONE_THROUGH = 'hasOneThrough';
    case MORPH_TO = 'morphTo';
    case MORPH_ONE = 'morphOne';
    case MORPH_MANY = 'morphMany';
    case MORPH_TO_MANY = 'morphToMany';
    case MORPH_BY_MANY = 'morphByMany';
    case SPATIE_PERMISSION = 'spatiePermission';

    /**
     * Get all available relationship types.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Check if a relationship type is a morph relationship.
     */
    public function isMorphRelationship(): bool
    {
        return in_array($this, [
            self::MORPH_TO,
            self::MORPH_ONE,
            self::MORPH_MANY,
            self::MORPH_TO_MANY,
            self::MORPH_BY_MANY,
            self::SPATIE_PERMISSION,
        ]);
    }

    /**
     * Check if a relationship type supports pivot tables.
     */
    public function supportsPivot(): bool
    {
        return in_array($this, [
            self::BELONGS_TO_MANY,
            self::MORPH_TO_MANY,
            self::MORPH_BY_MANY,
            self::SPATIE_PERMISSION,
        ]);
    }
}
