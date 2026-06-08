<?php

declare(strict_types=1);

namespace Sopheak\Core\Types;

use Sopheak\Core\Enums\RecordRelationshipsEnum;

/**
 * Create a new RecordHasManyType instance.
 *
 * This class represents a has-many relationship configuration between models.
 * It is typically used to define how one model relates to multiple records in another table.
 *
 * @param string                  $table      The name of the related table (e.g., 'receive_payment_items')
 * @param RecordRelationshipsEnum $type       The relationship type, defaults to HAS_MANY
 * @param string                  $foreignKey The foreign key in the related table (e.g., 'invoice_id')
 * @param string                  $localKey   The local key in the parent table, defaults to 'id'
 * @param null|array              $with       Array of relationships to eager load, defaults to empty array
 *
 * Example usage:
 * ```php
 * // Define a has-many relationship for Invoice -> ReceivePaymentItems
 * $relation = new RecordHasManyType(
 *     table: 'receive_payment_items',
 *     type: RecordRelationshipsEnum::HAS_MANY,
 *     foreignKey: 'invoice_id',
 *     localKey: 'id',
 *     with: ['receivePayment', 'otherRelation']
 * );
 *
 * // Can also be created from array
 * $relation = new RecordHasManyType(...[
 *     'table' => 'receive_payment_items',
 *     'type' => RecordRelationshipsEnum::HAS_MANY,
 *     'foreignKey' => 'invoice_id',
 *     'localKey' => 'id',
 *     'with' => ['receivePayment']
 * ]);
 * ```
 */
class RecordHasManyType
{
    public function __construct(
        public string $table,
        public string $foreignKey,
        public RecordRelationshipsEnum $type = RecordRelationshipsEnum::HAS_MANY,
        public string $localKey = 'id',
        public ?array $with = [],   // hint for eager child include when requested
        public bool $allowCreate = true,
        public bool $allowUpdate = true,
        public bool $allowDelete = true,
    ) {}

    /**
     * Handle var_export() for configuration caching.
     * This method is required for Laravel's config:cache command.
     * @param array<string, mixed> $properties
     */
    public static function __set_state(array $properties): self
    {
        return new self(
            table: $properties['table'],
            foreignKey: $properties['foreignKey'],
            type: $properties['type'] ?? RecordRelationshipsEnum::HAS_MANY,
            localKey: $properties['localKey'] ?? 'id',
            with: $properties['with'] ?? [],
            allowCreate: $properties['allowCreate'] ?? true,
            allowUpdate: $properties['allowUpdate'] ?? true,
            allowDelete: $properties['allowDelete'] ?? true,
        );
    }
}
