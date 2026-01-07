<?php

namespace Sopheak\Core\Types;

/**
 * Class RecordTableType.
 *
 * Represents a table configuration for record management.
 * This class defines the structure and behavior of a database table.
 *
 * @property string            $pms_name         The name of the table in the PMS system
 * @property bool              $soft_deletes     Whether soft deletes are enabled for this table
 * @property RecordTablePublic $public           Public configuration settings for the table
 * @property null|array        $relationships    Array of relationships with other tables
 * @property null|array        $functions        Array of function configurations
 * @property null|string       $primary_key      The primary key column name (defaults to 'id')
 * @property bool              $has_tenant_id    Whether the table has tenant ID column
 * @property null|array        $columns          Array of column definitions
 * @property null|array        $fulltext_indexes Array of full-text index configurations for optimized search
 * @property null|string       $auditLogFn       The function name for audit logging (optional)
 *
 * Example usage:
 * ```php
 * $table = new RecordTableType(
 *     pms_name: 'users',
 *     soft_deletes: true,
 *     disable_auditLog: false,
 *     disable_cache: false,
 *     can_read: true,
 *     can_write: true,
 *     public: new RecordTablePublic(),
 *     relationships: [
 *         'roles' => new RecordMetaBelongsToManyType(...),
 *         'profile' => new RecordHasOneType(...),
 *     ],
 *     functions: [
 *         // Using RecordFunctionType object (recommended)
 *         'getFullName' => new RecordFunctionType(
 *             type: 'class',
 *             class: 'App\\Services\\UserService',
 *             function_method: 'getFullName',
 *             method: ['GET'],
 *             description: 'Get the full name of the user'
 *         ),
 *
 *         // Using array configuration (legacy support)
 *         'calculateStats' => [
 *             'type' => 'class',
 *             'class' => 'App\\Services\\UserStatsService',
 *             'function_method' => 'calculate',
 *             'method' => ['POST'],
 *             'required_params' => ['period'],
 *             'description' => 'Calculate user statistics'
 *         ],
 *
 *         // Query-based function
 *         'getActiveUsers' => new RecordFunctionType(
 *             type: 'query',
 *             query: 'SELECT * FROM users WHERE active = 1 AND created_at >= ::since',
 *             method: ['GET'],
 *             required_params: ['since'],
 *             description: 'Get active users since a specific date'
 *         ),
 *     ],
 *     primary_key: 'id',
 *     has_tenant_id: false,
 *     columns: [
 * 'name' => ['type' => 'string', 'nullable' => false],
 * 'email' => ['type' => 'string', 'unique' => true],
 * ],
 * fulltext_indexes: [
 * ['name', 'description'],  // Multi-column full-text index
 * ['content'],              // Single-column full-text index
 * ],
 * );
 * ```
 */
class RecordTableType
{
    public function __construct(
        public ?string $pms_name = null,
        public ?string $table = null,
        public bool $has_tenant_id = false,
        public bool $soft_deletes = false,
        public bool $disable_auditLog = false,
        public bool $disable_cache = false,
        public bool $can_read = true,
        public bool $can_write = true,
        public RecordTablePublic $public = new RecordTablePublic(),
        public ?array $relationships = [],
        public ?array $functions = [],
        public ?string $primary_key = 'id',
        public ?array $columns = [],
        public ?array $column_hiddens = [],
        public ?array $fulltext_indexes = [],
        public ?string $auditLogFn = null,
        public $createValidator = null,
        public $updateValidator = null,
        public $deleteValidator = null,
        public RecordTableTriggerType|array|null $beforeRead = null,
        public RecordTableTriggerType|array|null $afterRead = null,
        public RecordTableTriggerType|array|null $beforeCreate = null,
        public RecordTableTriggerType|array|null $afterCreate = null,
        public RecordTableTriggerType|array|null $beforeUpdate = null,
        public RecordTableTriggerType|array|null $afterUpdate = null,
        public RecordTableTriggerType|array|null $beforeDelete = null,
        public RecordTableTriggerType|array|null $afterDelete = null,
    ) {}

    /**
     * Handle var_export() for configuration caching.
     * This method is required for Laravel's config:cache command.
     */
    public static function __set_state(array $properties): self
    {
        return new self(
            pms_name: $properties['pms_name'] ?? null,
            table: $properties['table'] ?? null,
            soft_deletes: $properties['soft_deletes'] ?? false,
            disable_auditLog: $properties['disable_auditLog'] ?? false,
            disable_cache: $properties['disable_cache'] ?? false,
            can_read: $properties['can_read'] ?? true,
            can_write: $properties['can_write'] ?? true,
            public: $properties['public'] ?? new RecordTablePublic(),
            relationships: $properties['relationships'] ?? [],
            functions: $properties['functions'] ?? [],
            primary_key: $properties['primary_key'] ?? 'id',
            has_tenant_id: $properties['has_tenant_id'] ?? false,
            columns: $properties['columns'] ?? [],
            column_hiddens: $properties['column_hiddens'] ?? [],
            fulltext_indexes: $properties['fulltext_indexes'] ?? [],
            auditLogFn: $properties['auditLogFn'] ?? null,
            createValidator: $properties['createValidator'] ?? null,
            updateValidator: $properties['updateValidator'] ?? null,
            deleteValidator: $properties['deleteValidator'] ?? null,
            beforeRead: $properties['beforeRead'] ?? null,
            afterRead: $properties['afterRead'] ?? null,
            beforeCreate: $properties['beforeCreate'] ?? null,
            afterCreate: $properties['afterCreate'] ?? null,
            beforeUpdate: $properties['beforeUpdate'] ?? null,
            afterUpdate: $properties['afterUpdate'] ?? null,
            beforeDelete: $properties['beforeDelete'] ?? null,
            afterDelete: $properties['afterDelete'] ?? null,
        );
    }
}
