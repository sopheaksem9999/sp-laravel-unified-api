<?php

declare(strict_types=1);

namespace Sopheak\Core\Types;

use Sopheak\Core\Enums\RecordRelationshipsEnum;

/**
 * Create a new RecordMorphHasManyType instance.
 *
 * This class represents a polymorphic has-many relationship configuration.
 * It links a parent table to a related table through a discriminator column
 * (e.g. `target_type`) plus a foreign key column (e.g. `target_id`).
 *
 * @param string                  $table      The related table name (e.g. 'translations')
 * @param string                  $morphType  The discriminator column in the related table (e.g. 'target_type')
 * @param string                  $morphId    The FK column in the related table (e.g. 'target_id')
 * @param string                  $morphClass The discriminator value for this parent (e.g. 'videos')
 * @param string                  $localKey   The local key in the parent table, defaults to 'id'
 * @param RecordRelationshipsEnum $type       The relationship type, defaults to MORPH_MANY
 *
 * Example usage:
 * ```php
 * $relation = new RecordMorphHasManyType(
 *     table: 'translations',
 *     morphType: 'target_type',
 *     morphId: 'target_id',
 *     morphClass: 'videos',
 *     localKey: 'id',
 * );
 * ```
 */
class RecordMorphHasManyType
{
    public function __construct(
        public string $table,
        public string $morphType,
        public string $morphId,
        public string $morphClass,
        public RecordRelationshipsEnum $type = RecordRelationshipsEnum::MORPH_MANY,
        public string $localKey = 'id',
        public bool $allowCreate = true,
        public bool $allowUpdate = true,
        public bool $allowDelete = true,
    ) {}

    /**
     * Handle var_export() for configuration caching.
     * This method is required for Laravel's config:cache command.
     *
     * @param array<string, mixed> $properties
     */
    public static function __set_state(array $properties): self
    {
        return new self(
            table: $properties['table'],
            morphType: $properties['morphType'],
            morphId: $properties['morphId'],
            morphClass: $properties['morphClass'],
            type: $properties['type'] ?? RecordRelationshipsEnum::MORPH_MANY,
            localKey: $properties['localKey'] ?? 'id',
            allowCreate: $properties['allowCreate'] ?? true,
            allowUpdate: $properties['allowUpdate'] ?? true,
            allowDelete: $properties['allowDelete'] ?? true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'morphType' => $this->morphType,
            'morphId' => $this->morphId,
            'morphClass' => $this->morphClass,
            'type' => $this->type,
            'localKey' => $this->localKey,
            'allowCreate' => $this->allowCreate,
            'allowUpdate' => $this->allowUpdate,
            'allowDelete' => $this->allowDelete,
        ];
    }
}
