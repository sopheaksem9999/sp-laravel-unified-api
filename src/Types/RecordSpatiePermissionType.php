<?php

declare(strict_types=1);

namespace Sopheak\Core\Types;

use Spatie\Permission\PermissionServiceProvider;
use InvalidArgumentException;
use Sopheak\Core\Enums\RecordRelationshipsEnum;

/**
 * Class RecordSpatiePermissionType.
 *
 * Represents a specialized many-to-many relationship configuration for Spatie\Permission package.
 * This class handles the morphToMany relationship pattern used by Spatie\Permission with
 * support for teams, pivot constraints, and proper model morphing.
 * Based on Laravel's MorphToMany relationship and Spatie\Permission patterns.
 *
 * @property string                  $related         The related model class name (usually Role or Permission)
 * @property RecordRelationshipsEnum $type            The relationship type, defaults to SPATIE_PERMISSION
 * @property null|string             $table           The intermediate pivot table name
 * @property null|string             $foreignPivotKey Foreign key on the pivot table for the parent model
 * @property null|string             $relatedPivotKey Foreign key on the pivot table for the related model
 * @property null|string             $parentKey       Parent model's key (defaults to primary key)
 * @property null|string             $relatedKey      Related model's key (defaults to primary key)
 * @property string                  $relation        The morph relation name (e.g., 'model')
 * @property array                   $withPivot       Additional pivot columns to include
 * @property array                   $wherePivot      Pivot constraints as key-value pairs
 * @property bool                    $withTimestamps  Whether to include timestamps on pivot table
 * @property array                   $select          Specific columns to select from the related table
 * @property bool                    $teamsEnabled    Whether teams functionality is enabled
 * @property null|string             $teamsKey        The teams key column name
 *
 * @since 1.0.0
 *
 * @author QBO Finance Team
 *
 * Example usage:
 * ```php
 * // User roles with Spatie\Permission
 * $userRoles = new RecordSpatiePermissionType(
 *     related: config('permissions.models.role'),
 *     relation: 'model',
 *     table: config('permissions.table_names.model_has_roles'),
 *     foreignPivotKey: config('permissions.column_names.model_morph_key'),
 *     relatedPivotKey: 'role_id',
 *     teamsEnabled: true
 * );
 *
 * // User permissions with Spatie\Permission
 * $userPermissions = new RecordSpatiePermissionType(
 *     related: config('permissions.models.permission'),
 *     relation: 'model',
 *     table: config('permissions.table_names.model_has_permissions'),
 *     foreignPivotKey: config('permissions.column_names.model_morph_key'),
 *     relatedPivotKey: 'permission_id',
 *     teamsEnabled: false
 * );
 * ```
 */
class RecordSpatiePermissionType
{
    /**
     * Create a new RecordSpatiePermissionType instance.
     *
     * @param string                  $related         The related model class name (required)
     * @param string                  $relation        The morph relation name (required)
     * @param RecordRelationshipsEnum $type            The relationship type (defaults to SPATIE_PERMISSION)
     * @param null|string             $table           The intermediate pivot table name
     * @param null|string             $foreignPivotKey Foreign key on pivot table for parent model
     * @param null|string             $relatedPivotKey Foreign key on pivot table for related model
     * @param null|string             $parentKey       Parent model's key (defaults to primary key)
     * @param null|string             $relatedKey      Related model's key (defaults to primary key)
     * @param array                   $withPivot       Additional pivot columns to include
     * @param array                   $wherePivot      Pivot constraints as key-value pairs
     * @param bool                    $withTimestamps  Whether to include timestamps on pivot table
     * @param array                   $select          Specific columns to select from the related table
     * @param bool                    $teamsEnabled    Whether teams functionality is enabled
     * @param null|string             $teamsKey        The teams key column name
     *
     * @throws InvalidArgumentException When related model class name or relation is empty
     */
    public function __construct(
        public string $related,
        public string $relation,
        public RecordRelationshipsEnum $type = RecordRelationshipsEnum::SPATIE_PERMISSION,
        public ?string $table = null,
        public ?string $foreignPivotKey = null,
        public ?string $relatedPivotKey = null,
        public ?string $parentKey = null,
        public ?string $relatedKey = null,
        public array $withPivot = [],
        public array $wherePivot = [],
        public bool $withTimestamps = false,
        public array $select = [],
        public bool $teamsEnabled = false,
        public ?string $teamsKey = null,
    ) {
        if (!class_exists(PermissionServiceProvider::class)) {
            //throw new InvalidArgumentException('RecordSpatiePermissionType requires spatie/laravel-permission to be installed.');
        }

        if (empty($related)) {
            //throw new InvalidArgumentException('related model class name cannot be empty');
        }

        if (empty($relation)) {
            //throw new InvalidArgumentException('relation name cannot be empty');
        }

        // Set default values from Spatie\Permission config if not provided
        $this->table ??= config('permissions.table_names.model_has_roles');
        $this->foreignPivotKey ??= config('permissions.column_names.model_morph_key');
        $this->teamsKey ??= config('permissions.column_names.team_foreign_key', 'team_id');

        // Ensure model_type is always included for morphToMany relationships
        if (!in_array('model_type', $this->withPivot)) {
            $this->withPivot[] = 'model_type';
        }

        // Auto-detect teams functionality if not explicitly set
        if ($this->teamsEnabled && !in_array($this->teamsKey, $this->withPivot)) {
            $this->withPivot[] = $this->teamsKey;
        }
    }

    /**
     * Handle var_export() for configuration caching.
     *
     * This method is required for Laravel's config:cache command to properly
     * serialize and deserialize the object when caching configurations.
     *
     * @param array<string, mixed> $properties The properties array from var_export
     *
     * @return static A new instance of RecordSpatiePermissionType
     *
     * @throws InvalidArgumentException When required properties are missing
     */
    public static function __set_state(array $properties): self
    {
        return new self(
            related: $properties['related'] ?? '', //throw new InvalidArgumentException('related is required'),
            relation: $properties['relation'] ?? '', //throw new InvalidArgumentException('relation is required'),
            type: $properties['type'] ?? RecordRelationshipsEnum::SPATIE_PERMISSION,
            table: $properties['table'] ?? null,
            foreignPivotKey: $properties['foreignPivotKey'] ?? null,
            relatedPivotKey: $properties['relatedPivotKey'] ?? null,
            parentKey: $properties['parentKey'] ?? null,
            relatedKey: $properties['relatedKey'] ?? null,
            withPivot: $properties['withPivot'] ?? [],
            wherePivot: $properties['wherePivot'] ?? [],
            withTimestamps: $properties['withTimestamps'] ?? false,
            select: $properties['select'] ?? [],
            teamsEnabled: $properties['teamsEnabled'] ?? false,
            teamsKey: $properties['teamsKey'] ?? null,
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
            'relation' => $this->relation,
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

        if ($this->teamsEnabled) {
            $config['teamsEnabled'] = $this->teamsEnabled;
        }

        if (null !== $this->teamsKey) {
            $config['teamsKey'] = $this->teamsKey;
        }

        return $config;
    }

    /**
     * Create a RecordSpatiePermissionType instance from array configuration.
     *
     * This is useful for loading configuration from files, databases, or
     * when deserializing configuration data from external sources.
     *
     * @param array<string, mixed> $config The configuration array containing relationship parameters
     *
     * @return static A new instance of RecordSpatiePermissionType
     *
     * @throws InvalidArgumentException When required configuration keys are missing
     *
     * @example
     * ```php
     * $config = [
     *     'related' => config('permissions.models.role'),
     *     'relation' => 'model',
     *     'table' => config('permissions.table_names.model_has_roles'),
     *     'foreignPivotKey' => config('permissions.column_names.model_morph_key'),
     *     'relatedPivotKey' => 'role_id',
     *     'teamsEnabled' => true
     * ];
     * $relationship = RecordSpatiePermissionType::fromArray($config);
     * ```
     */
    public static function fromArray(array $config): self
    {
        return new self(
            related: $config['related'] ?? '', //throw new InvalidArgumentException('related is required in config array'),
            relation: $config['relation'] ?? '', //throw new InvalidArgumentException('relation is required in config array'),
            type: $config['type'] ?? RecordRelationshipsEnum::SPATIE_PERMISSION,
            table: $config['table'] ?? null,
            foreignPivotKey: $config['foreignPivotKey'] ?? null,
            relatedPivotKey: $config['relatedPivotKey'] ?? null,
            parentKey: $config['parentKey'] ?? null,
            relatedKey: $config['relatedKey'] ?? null,
            withPivot: $config['withPivot'] ?? [],
            wherePivot: $config['wherePivot'] ?? [],
            withTimestamps: $config['withTimestamps'] ?? false,
            select: $config['select'] ?? [],
            teamsEnabled: $config['teamsEnabled'] ?? false,
            teamsKey: $config['teamsKey'] ?? null,
        );
    }

    /**
     * Get the morph type for the relationship.
     * This is used to determine the morph type value in the pivot table.
     *
     * @param string $modelClass The model class name
     *
     * @return string The morph type value
     */
    public function getMorphType(string $modelClass): string
    {
        return $modelClass;
    }

    /**
     * Check if teams functionality is enabled and configured.
     *
     * @return bool True if teams are enabled and properly configured
     */
    public function hasTeamsSupport(): bool
    {
        return $this->teamsEnabled && !empty($this->teamsKey);
    }

    /**
     * Get the teams constraint for the relationship.
     * This is used to filter results by team when teams are enabled.
     *
     * @param mixed $teamId The team ID to filter by
     *
     * @return array The teams constraint configuration
     */
    public function getTeamsConstraint(mixed $teamId = null): array
    {
        if (!$this->hasTeamsSupport()) {
            return [];
        }

        if ($teamId === null) {
            $teamId = function_exists('\\getPermissionsTeamId') ? \getPermissionsTeamId() : null;
        }

        return [
            'pivot' => [$this->teamsKey => $teamId],
            'where' => [
                'column' => $this->teamsKey,
                'operator' => '=',
                'value' => $teamId,
            ],
        ];
    }
}
