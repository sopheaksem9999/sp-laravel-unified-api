<?php

namespace Sopheak\Core\Types;

/**
 * Class RecordTablePublic.
 *
 * Represents the public access configuration for a table.
 * This class is used to define whether a table is publicly accessible for read and write operations.
 *
 * @property bool $read  Indicates if the table is publicly accessible for read operations
 * @property bool $write Indicates if the table is publicly accessible for write operations
 *
 * Example usage:
 * ```php
 * $publicAccess = new RecordTablePublic(
 *     read: true,
 *     write: false,
 * );
 * ```
 */
class RecordTablePublic
{
    public function __construct(
        public bool $read = false,
        public bool $write = false,
    ) {}

    /**
     * Handle var_export() for configuration caching.
     * This method is required for Laravel's config:cache command.
     *
     * @param array $properties
     * @return static
     */
    public static function __set_state(array $properties): static
    {
        return new static(
            read: $properties['read'] ?? false,
            write: $properties['write'] ?? false,
        );
    }
}