<?php

declare(strict_types=1);

namespace Sopheak\Core\Types;

/**
 * Class RecordTablePublic.
 *
 * Represents the public access configuration for a table.
 *
 * @deprecated Use `isAuthRead` / `isAuthWrite` on RecordTableType instead.
 *             `public` is derived automatically: public read = !isAuthRead,
 *             public write = !isAuthWrite. This class is only kept for legacy
 *             configs that still set it explicitly.
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
     * @param array<string, mixed> $properties
     */
    public static function __set_state(array $properties): self
    {
        return new self(
            read: $properties['read'] ?? false,
            write: $properties['write'] ?? false,
        );
    }
}
