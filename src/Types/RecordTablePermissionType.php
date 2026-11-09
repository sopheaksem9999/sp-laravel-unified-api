<?php

declare(strict_types=1);

namespace Sopheak\Core\Types;

use ArrayAccess;
use InvalidArgumentException;

/**
 * Class RecordTablePermissionType.
 *
 * Strongly-typed permission configuration for a table in RecordTableType.
 * Maps CRUD and custom operations to required permission names.
 *
 * Example usage:
 * ```php
 * permissions: new RecordTablePermissionType(
 *     read: ['view_booking', 'viewOwn_booking'],
 *     create: 'create_booking',
 *     update: 'update_booking',
 *     delete: 'delete_booking',
 *     forceDelete: 'permanently_delete_booking',
 *     restore: 'restore_booking',
 *     custom: [
 *         'export' => 'export_bookings',
 *     ],
 * ),
 * ```
 *
 * @implements ArrayAccess<string, list<string>>
 */
class RecordTablePermissionType implements ArrayAccess
{
    /**
     * @param string|list<string>|null $read
     * @param string|list<string>|null $create
     * @param string|list<string>|null $update
     * @param string|list<string>|null $delete
     * @param string|list<string>|null $forceDelete
     * @param string|list<string>|null $force_delete Alias for forceDelete
     * @param string|list<string>|null $restore
     * @param array<string, string|list<string>> $custom
     */
    public function __construct(
        public string|array|null $read = null,
        public string|array|null $create = null,
        public string|array|null $update = null,
        public string|array|null $delete = null,
        public string|array|null $forceDelete = null,
        public string|array|null $force_delete = null,
        public string|array|null $restore = null,
        public array $custom = [],
    ) {
        $this->forceDelete ??= $this->force_delete;
        $this->force_delete = $this->forceDelete;
    }

    /**
     * @return array<string, list<string>>
     */
    public function toArray(): array
    {
        $map = [];

        $normalize = static function (string|array|null $val): ?array {
            if ($val === null) {
                return null;
            }

            if (is_string($val)) {
                $trimmed = trim($val);

                return '' === $trimmed ? null : [$trimmed];
            }

            $clean = array_values(array_filter(array_map(strval(...), $val), static fn(string $v): bool => trim($v) !== ''));

            return [] === $clean ? null : $clean;
        };

        if (($read = $normalize($this->read)) !== null) {
            $map['read'] = $read;
        }

        if (($create = $normalize($this->create)) !== null) {
            $map['create'] = $create;
        }

        if (($update = $normalize($this->update)) !== null) {
            $map['update'] = $update;
        }

        if (($delete = $normalize($this->delete)) !== null) {
            $map['delete'] = $delete;
        }

        if (($force = $normalize($this->forceDelete ?? $this->force_delete)) !== null) {
            $map['force_delete'] = $force;
        }

        if (($restore = $normalize($this->restore)) !== null) {
            $map['restore'] = $restore;
        }

        foreach ($this->custom as $action => $permissions) {
            if (($customPerms = $normalize($permissions)) !== null) {
                $map[(string) $action] = $customPerms;
            }
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $properties
     */
    public static function __set_state(array $properties): self
    {
        return new self(
            read: $properties['read'] ?? null,
            create: $properties['create'] ?? null,
            update: $properties['update'] ?? null,
            delete: $properties['delete'] ?? null,
            forceDelete: $properties['forceDelete'] ?? $properties['force_delete'] ?? null,
            restore: $properties['restore'] ?? null,
            custom: $properties['custom'] ?? [],
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $read = $config['read'] ?? null;
        $create = $config['create'] ?? null;
        $update = $config['update'] ?? null;
        $delete = $config['delete'] ?? null;
        $forceDelete = $config['force_delete'] ?? $config['forceDelete'] ?? null;
        $restore = $config['restore'] ?? null;

        $standardKeys = ['read', 'create', 'update', 'delete', 'force_delete', 'forceDelete', 'restore'];
        $custom = array_diff_key($config, array_flip($standardKeys));

        return new self(
            read: $read,
            create: $create,
            update: $update,
            delete: $delete,
            forceDelete: $forceDelete,
            restore: $restore,
            custom: $custom,
        );
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->toArray()[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->toArray()[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new InvalidArgumentException('RecordTablePermissionType is immutable');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new InvalidArgumentException('RecordTablePermissionType is immutable');
    }
}
