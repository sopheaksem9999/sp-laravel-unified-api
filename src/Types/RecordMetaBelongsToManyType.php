<?php

namespace Sopheak\Core\Types;

use Sopheak\Core\Enums\RecordRelationshipsEnum;

/**
 * Class RecordMetaBelongsToManyType.
 *
 * Represents a many-to-many relationship configuration between models.
 * This class defines the structure for belongsToMany relationships with
 * support for pivot tables, morph relationships, and additional pivot columns.
 * Based on Laravel's BelongsToMany relationship pattern.
 *
 * @property string                  $related         The related model class name or table name
 * @property RecordRelationshipsEnum $type            The relationship type, defaults to BELONGS_TO_MANY
 * @property null|string             $table           The intermediate pivot table name
 * @property null|string             $foreignPivotKey Foreign key on the pivot table for the parent model
 * @property null|string             $relatedPivotKey Foreign key on the pivot table for the related model
 * @property null|string             $parentKey       Parent model's key (defaults to primary key)
 * @property null|string             $relatedKey      Related model's key (defaults to primary key)
 * @property null|string             $relation        The relation name for morph relationships
 * @property array                   $withPivot       Additional pivot columns to include
 * @property array                   $wherePivot      Pivot constraints as key-value pairs
 * @property bool                    $withTimestamps  Whether to include timestamps on pivot table
 * @property array                   $select          Specific columns to select from the related table
 *
 * @since 1.0.0
 *
 * @author QBO Finance Team
 *
 * Example usage:
 * ```php
 * // Simple many-to-many relationship
 * $userRoles = new RecordMetaBelongsToManyType(
 *     related: 'roles',
 *     table: 'user_roles',
 *     foreignPivotKey: 'user_id',
 *     relatedPivotKey: 'role_id'
 * );
 *
 * // Morph many-to-many with pivot data (like Laravel Permission)
 * $modelRoles = new RecordMetaBelongsToManyType(
 *     related: config('permission.models.role'),
 *     relation: 'model',
 *     table: config('permission.table_names.model_has_roles'),
 *     foreignPivotKey: config('permission.column_names.model_morph_key'),
 *     relatedPivotKey: 'role_id',
 *     withPivot: ['team_id'],
 *     wherePivot: ['team_id' => 1]
 * );
 *
 * // Legacy format support
 * $products = new RecordMetaBelongsToManyType(
 *     related: 'products',
 *     table: 'order_products',
 *     foreignPivotKey: 'order_id',
 *     relatedPivotKey: 'product_id',
 *     select: ['products.id', 'products.name', 'products.price']
 * );
 * ```
 */
class RecordMetaBelongsToManyType
{
    /**
     * Create a new RecordMetaBelongsToManyType instance.
     *
     * @param string                  $related         The related model class name or table name (required)
     * @param RecordRelationshipsEnum $type            The relationship type
     * @param null|string             $table           The intermediate pivot table name
     * @param null|string             $foreignPivotKey Foreign key on pivot table for parent model
     * @param null|string             $relatedPivotKey Foreign key on pivot table for related model
     * @param null|string             $parentKey       Parent model's key (defaults to primary key)
     * @param null|string             $relatedKey      Related model's key (defaults to primary key)
     * @param null|string             $relation        The relation name for morph relationships
     * @param array                   $withPivot       Additional pivot columns to include
     * @param array                   $wherePivot      Pivot constraints as key-value pairs
     * @param bool                    $withTimestamps  Whether to include timestamps on pivot table
     * @param array                   $select          Specific columns to select from the related table
     * @param array                   $pivotWhere      Legacy pivot where conditions (deprecated, use wherePivot)
     *
     * @throws \InvalidArgumentException When related model/table name is empty or invalid
     */
    public function __construct(
        public string $related,
        public RecordRelationshipsEnum $type = RecordRelationshipsEnum::BELONGS_TO_MANY,
        public ?string $table = null,
        public ?string $foreignPivotKey = null,
        public ?string $relatedPivotKey = null,
        public ?string $parentKey = null,
        public ?string $relatedKey = null,
        public ?string $relation = null,
        public array $withPivot = [],
        public array $wherePivot = [],
        public bool $withTimestamps = false,
        public array $select = [],
        public array $pivotWhere = [], // Legacy support
    ) {
        if (empty($related)) {
            throw new \InvalidArgumentException('related model/table name cannot be empty');
        }

        // Merge legacy pivotWhere into wherePivot for backward compatibility
        if (!empty($pivotWhere) && empty($wherePivot)) {
            $this->wherePivot = $this->convertLegacyPivotWhere($pivotWhere);
        }
    }

    /**
     * Handle var_export() for configuration caching.
     *
     * This method is required for Laravel's config:cache command to properly
     * serialize and deserialize the object when caching configurations.
     *
     * @param array $properties The properties array from var_export
     *
     * @return static A new instance of RecordMetaBelongsToManyType
     *
     * @throws \InvalidArgumentException When required properties are missing
     */
    public static function __set_state(array $properties): static
    {
        return new static(
            related: $properties['related'] ?? $properties['table'] ?? throw new \InvalidArgumentException('related is required'),
            type: $properties['type'] ?? RecordRelationshipsEnum::BELONGS_TO_MANY,
            table: $properties['table'] ?? null,
            foreignPivotKey: $properties['foreignPivotKey'] ?? null,
            relatedPivotKey: $properties['relatedPivotKey'] ?? null,
            parentKey: $properties['parentKey'] ?? null,
            relatedKey: $properties['relatedKey'] ?? null,
            relation: $properties['relation'] ?? null,
            withPivot: $properties['withPivot'] ?? [],
            wherePivot: $properties['wherePivot'] ?? [],
            withTimestamps: $properties['withTimestamps'] ?? false,
            select: $properties['select'] ?? [],
            pivotWhere: $properties['pivotWhere'] ?? [],
        );
    }

    /**
     * Convert the relationship configuration to array format.
     * This is useful for serialization and configuration export.
     *
     * @return array The relationship configuration as an associative array
     */
    public function toArray(): array
    {
        $config = [
            'related' => $this->related,
            'type' => $this->type,
        ];

        // Add optional properties only if they have values
        if (null !== $this->table) {
            $config['table'] = $this->table;
        }

        if (null !== $this->foreignPivotKey) {
            $config['foreignPivotKey'] = $this->foreignPivotKey;
        }

        if (null !== $this->relatedPivotKey) {
            $config['relatedPivotKey'] = $this->relatedPivotKey;
        }

        if (null !== $this->parentKey) {
            $config['parentKey'] = $this->parentKey;
        }

        if (null !== $this->relatedKey) {
            $config['relatedKey'] = $this->relatedKey;
        }

        if (null !== $this->relation) {
            $config['relation'] = $this->relation;
        }

        if (!empty($this->withPivot)) {
            $config['withPivot'] = $this->withPivot;
        }

        if (!empty($this->wherePivot)) {
            $config['wherePivot'] = $this->wherePivot;
        }

        if ($this->withTimestamps) {
            $config['withTimestamps'] = $this->withTimestamps;
        }

        if (!empty($this->select)) {
            $config['select'] = $this->select;
        }

        // Include legacy pivotWhere for backward compatibility
        if (!empty($this->pivotWhere)) {
            $config['pivotWhere'] = $this->pivotWhere;
        }

        return $config;
    }

    /**
     * Create a RecordMetaBelongsToManyType instance from array configuration.
     *
     * This is useful for loading configuration from files, databases, or
     * when deserializing configuration data from external sources.
     *
     * @param array $config The configuration array containing relationship parameters
     *
     * @return static A new instance of RecordMetaBelongsToManyType
     *
     * @throws \InvalidArgumentException When required configuration keys are missing
     *
     * @example
     * ```php
     * $config = [
     *     'related' => 'App\\Models\\Role',
     *     'table' => 'user_roles',
     *     'foreignPivotKey' => 'user_id',
     *     'relatedPivotKey' => 'role_id',
     *     'withPivot' => ['assigned_at'],
     *     'withTimestamps' => true
     * ];
     * $relationship = RecordMetaBelongsToManyType::fromArray($config);
     * ```
     */
    public static function fromArray(array $config): static
    {
        return new static(
            related: $config['related'] ?? $config['table'] ?? throw new \InvalidArgumentException('related is required in config array'),
            type: $config['type'] ?? RecordRelationshipsEnum::BELONGS_TO_MANY,
            table: $config['table'] ?? null,
            foreignPivotKey: $config['foreignPivotKey'] ?? null,
            relatedPivotKey: $config['relatedPivotKey'] ?? null,
            parentKey: $config['parentKey'] ?? null,
            relatedKey: $config['relatedKey'] ?? null,
            relation: $config['relation'] ?? null,
            withPivot: $config['withPivot'] ?? [],
            wherePivot: $config['wherePivot'] ?? [],
            withTimestamps: $config['withTimestamps'] ?? false,
            select: $config['select'] ?? [],
            pivotWhere: $config['pivotWhere'] ?? [],
        );
    }

    /**
     * Convert legacy pivotWhere format to wherePivot format.
     *
     * @param array $pivotWhere Legacy pivot where conditions
     *
     * @return array Converted wherePivot conditions
     */
    private function convertLegacyPivotWhere(array $pivotWhere): array
    {
        $converted = [];
        foreach ($pivotWhere as $condition) {
            if (isset($condition['column'], $condition['value'])) {
                $converted[$condition['column']] = $condition['value'];
            }
        }

        return $converted;
    }
}
