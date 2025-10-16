<?php

namespace Sopheak\Core\Types;

use App\Utilities\Enums\RecordRelationshipsEnum;

/**
 * Class RecordBelongToType.
 *
 * Represents a relationship configuration between models.
 *
 * @property string                  $table      The related table name (e.g. 'users')
 * @property RecordRelationshipsEnum $type       The relationship type, defaults to BELONGS_TO
 * @property null|string             $foreignKey Foreign key on the source model (e.g. 'created_by')
 * @property null|string             $ownerKey   Primary key on the target model (e.g. 'id')
 *
 * Sample usage:
 * [
 *     'table' => 'users',
 *     'type' => 'belongsTo',
 *     'foreignKey' => 'created_by',
 *     'ownerKey' => 'id',
 * ]
 */
class RecordBelongsToType
{
    public function __construct(
        public string $table,
        public RecordRelationshipsEnum $type = RecordRelationshipsEnum::BELONGS_TO,
        public ?string $foreignKey = null,
        public ?string $ownerKey = 'id',
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
            table: $properties['table'],
            type: $properties['type'] ?? RecordRelationshipsEnum::BELONGS_TO,
            foreignKey: $properties['foreignKey'] ?? null,
            ownerKey: $properties['ownerKey'] ?? 'id',
        );
    }
}
