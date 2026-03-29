<?php

namespace Sopheak\Core\Attributes;

use Attribute;

/**
 * Declare a relationship on an Eloquent model for use by the dynamic CRUD system.
 *
 * Multiple #[RecordRelationship] attributes may be placed on the same class.
 * They are collected by AttributeDiscoveryService and merged into the
 * RecordTableType `relationships` array.
 *
 * Example:
 * ```php
 * #[RecordTable(pmsName: 'invoice')]
 * #[RecordRelationship('customer', type: 'belongsTo', foreignKey: 'customer_id')]
 * #[RecordRelationship('items',    type: 'hasMany',   foreignKey: 'invoice_id')]
 * class Invoice extends Model {}
 * ```
 *
 * @see \Sopheak\Core\Attributes\RecordTable
 * @see \Sopheak\Core\Services\AttributeDiscoveryService
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class RecordRelationship
{
    /**
     * @param string      $name        Relationship key used in ?select= (e.g. "customer")
     * @param string      $type        Relationship type: belongsTo | hasMany | hasOne | hasManyThrough | belongsToMany
     * @param string|null $foreignKey  Foreign key column
     * @param string|null $relatedTable Related database table name (defaults to snake_plural of name)
     * @param string|null $localKey    Local key (defaults to primary key)
     * @param string|null $ownerKey    Owner key for belongsTo (defaults to primary key of related table)
     * @param string|null $through     Intermediate table for hasManyThrough
     * @param string|null $throughForeignKey Foreign key on the intermediate table for hasManyThrough
     * @param string|null $pivot       Pivot table for belongsToMany
     * @param string|null $pivotForeignKey Foreign key on the pivot table
     * @param string|null $pivotRelatedKey Related key on the pivot table
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type = 'belongsTo',
        public readonly ?string $foreignKey = null,
        public readonly ?string $relatedTable = null,
        public readonly ?string $localKey = null,
        public readonly ?string $ownerKey = null,
        public readonly ?string $through = null,
        public readonly ?string $throughForeignKey = null,
        public readonly ?string $pivot = null,
        public readonly ?string $pivotForeignKey = null,
        public readonly ?string $pivotRelatedKey = null,
    ) {}
}
