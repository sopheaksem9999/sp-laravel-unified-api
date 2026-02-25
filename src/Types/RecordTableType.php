<?php

namespace Sopheak\Core\Types;

use Closure;

/**
 * Class RecordTableType.
 *
 * Represents a table configuration for record management.
 * This class defines the structure and behavior of a database table.
 *
 * @property null|string       $table            The database table name (defaults to resource name)
 * @property string|array|null $pmsName         The name of the table in the PMS system
 * @property bool              $hasTenantId    Whether the table has tenant ID column
 * @property bool              $softDeletes     Whether soft deletes are enabled for this table
 * @property bool              $disableAuditLog Whether audit logging is disabled for this table
 * @property bool              $disableCache    Whether query caching is disabled for this table
 * @property RecordTablePublic $public           Public configuration settings for the table
 * @property null|array        $relationships    Array of relationships with other tables
 * @property null|array        $functions        Array of function configurations
 * @property null|string       $primaryKey      The primary key column name (defaults to 'id')
 * @property null|array        $columns          Array of column definitions
 * @property null|array        $columnHiddens   Columns to hide from responses
 * @property null|array        $columnWriteDisabled    Columns that cannot be written via API payloads
 * @property null|array        $columnIndexes  Array of full-text index configurations for optimized search
 * @property string|array|null $customAuditLog Custom audit logger callback (callable string or [class, httpMethod])
 *
 * Example usage:
 * ```php
 * $table = new RecordTableType(
 *     table: 'users',
 *     pmsName: 'users',
 *     hasTenantId: false,
 *     softDeletes: true,
 *     disableAuditLog: false,
 *     disableCache: false,
 *     canRead: true,
 *     canCreate: true,
 *     canUpdate: true,
 *     canDelete: true,
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
 *             functionName: 'getFullName',
 *             httpMethod: ['GET'],
 *             description: 'Get the full name of the user'
 *         ),
 *
 *         // Using array configuration (legacy support)
 *         'calculateStats' => [
 *             'type' => 'class',
 *             'class' => 'App\\Services\\UserStatsService',
 *             'functionName' => 'calculate',
 *             'httpMethod' => ['POST'],
 *             'required_params' => ['period'],
 *             'description' => 'Calculate user statistics'
 *         ],
 *
 *         // Query-based function
 *         'getActiveUsers' => new RecordFunctionType(
 *             type: 'query',
 *             query: 'SELECT * FROM users WHERE active = 1 AND created_at >= ::since',
 *             httpMethod: ['GET'],
 *             required_params: ['since'],
 *             description: 'Get active users since a specific date'
 *         ),
 *     ],
 *     primaryKey: 'id',
 *     columns: [
 *         'name' => ['type' => 'string', 'nullable' => false],
 *         'email' => ['type' => 'string', 'unique' => true],
 * ],
 *     columnHiddens: ['password', 'remember_token'],
 *     columnIndexes: [
 *         ['name', 'description'],
 *         ['content'],
 *     ],
 * );
 * ```
 */
class RecordTableType
{
    public function __construct(
        public ?string $table = null,
        public string|array|null $pmsName = null,
        public bool $hasTenantId = false,
        public bool $softDeletes = false,
        public bool $disableAuditLog = false,
        public bool $disableCache = false,
        public bool $canRead = true,
        public bool $canCreate = true,
        public bool $canUpdate = true,
        public bool $canDelete = true,
        public bool $canUpsert = true,
        public RecordTablePublic|bool $public = new RecordTablePublic(),
        public ?string $primaryKey = 'id',
        public ?array $columns = [],
        public ?array $columnHiddens = [],
        public ?array $columnWriteDisabled = [],
        public ?array $columnIndexes = [],
        public ?array $relationships = [],
        public ?array $functions = [],
        public string|array|null $customAuditLog = null,
        public Closure|RecordValidationType|array|null $createValidator = null,
        public Closure|RecordValidationType|array|null $updateValidator = null,
        public Closure|RecordValidationType|array|null $deleteValidator = null,
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
     * This httpMethod is required for Laravel's config:cache command.
     */
    public static function __set_state(array $properties): self
    {
        $legacyCanWrite = $properties['can_write'] ?? null;
        $legacyCanRead = $properties['can_read'] ?? null;

        return new self(
            table: $properties['table'] ?? null,
            pmsName: $properties['pmsName'] ?? null,
            hasTenantId: $properties['hasTenantId'] ?? false,
            softDeletes: $properties['softDeletes'] ?? false,
            disableAuditLog: $properties['disableAuditLog'] ?? false,
            disableCache: $properties['disableCache'] ?? false,
            canRead: $properties['canRead'] ?? ($legacyCanRead ?? true),
            canCreate: $properties['canCreate'] ?? ($legacyCanWrite ?? true),
            canUpdate: $properties['canUpdate'] ?? ($legacyCanWrite ?? true),
            canDelete: $properties['canDelete'] ?? ($legacyCanWrite ?? true),
            canUpsert: $properties['canUpsert'] ?? ($legacyCanWrite ?? true),
            public: is_array($properties['public'] ?? null) ? RecordTablePublic::__set_state($properties['public']) : ($properties['public'] ?? new RecordTablePublic()),
            primaryKey: $properties['primaryKey'] ?? 'id',
            columns: $properties['columns'] ?? [],
            columnHiddens: $properties['columnHiddens'] ?? [],
            columnWriteDisabled: $properties['columnWriteDisabled'] ?? [],
            columnIndexes: $properties['columnIndexes'] ?? [],
            relationships: $properties['relationships'] ?? [],
            functions: $properties['functions'] ?? [],
            customAuditLog: $properties['customAuditLog'] ?? null,
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
