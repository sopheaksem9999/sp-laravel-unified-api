<?php

declare(strict_types=1);

namespace Sopheak\Core\Types;

use ReflectionClass;
use Sopheak\Core\Attributes\RecordValidator;
use Sopheak\Core\Attributes\RecordTrigger;
use Sopheak\Core\Enums\RecordCastEnum;
use Sopheak\Core\Enums\RecordTriggerHookEnum;
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
 * @property bool              $isAuthRead      Whether read endpoints require authentication
 * @property bool              $isAuthWrite     Whether write endpoints require authentication
 * @property RecordTablePublic|bool $public      Legacy public configuration — DEPRECATED. Derived automatically from
 *                                               `isAuthRead`/`isAuthWrite` (public read = !isAuthRead, public write = !isAuthWrite).
 *                                               Only used as a legacy override when both auth flags are left at their defaults.
 * @property null|array        $relationships    Array of relationships with other tables
 * @property null|array        $functions        Array of function configurations
 * @property null|string       $primaryKey      The primary key column name (defaults to 'id')
 * @property null|string       $ownerColumn     Column holding the record owner's user id, used by `viewOwn:*` scoping.
 *                                               Defaults to auto-detection via `record.own_records_owner_columns`.
 * @property null|array        $columns          Column definitions, each a RecordColumnType or the legacy array (stored as arrays)
 * @property null|array        $columnHiddens   Columns to hide from responses
 * @property null|array        $columnWriteDisabled    Columns that cannot be written via API payloads
 * @property null|array        $columnIndexes  Array of full-text index configurations for optimized search
 * @property null|array        $searchable      Explicit searchable fields for `?search=` (supports `relationship.column`)
 * @property RecordTableTriggerType|string|array|null $customAuditLog Custom audit logger callback (RecordTableTriggerType, callable string, or [class, method])
 *
 * Example usage:
 * ```php
 * $table = new RecordTableType(
 *     table: 'users',
 *     pmsName: 'users',
 *     hasTenantId: false,
 *     softDeletes: true,
 *     disableAuditLog: false,
 *     disableCache: true,
 *     canRead: true,
 *     canCreate: true,
 *     canUpdate: true,
 *     canDelete: true,
 *     isAuthRead: true,
 *     isAuthWrite: true,
 *     relationships: [
 *         'roles' => new RecordMetaBelongsToManyType(...),
 *         'profile' => new RecordHasManyType(...),
 *     ],
 *     functions: [
 *         // Using RecordFunctionType object (recommended)
 *         'getFullName' => new RecordFunctionType(
 *             httpMethod: [\Sopheak\Core\Enums\RecordFunctionMethodEnum::GET->value],
 *             class: 'App\\Services\\UserService',
 *             functionName: 'getFullName',
 *             description: 'Get the full name of the user'
 *         ),
 *
 *         // Using array configuration (legacy support)
 *         'calculateStats' => [
 *             'class' => 'App\\Services\\UserStatsService',
 *             'functionName' => 'calculate',
 *             'httpMethod' => [\Sopheak\Core\Enums\RecordFunctionMethodEnum::POST->value],
 *             'required_params' => ['period'],
 *             'description' => 'Calculate user statistics'
 *         ],
 *     ],
 *     primaryKey: 'id',
 *     columns: [
 *         'name' => new RecordColumnType(type: 'string', nullable: false),
 *         'email' => ['type' => 'string', 'unique' => true], // legacy array form still works
 *     ],
 *     columnHiddens: ['password', 'remember_token'],
 *     columnIndexes: [
 *         ['name', 'description'],
 *         ['content'],
 *     ],
 * );
 * ```
 *
 * > **Note:** `public` is deprecated and derived from `isAuthRead`/`isAuthWrite` —
 * > do not set it directly. Set the auth flags instead.
 */
class RecordTableType
{
    public ?RecordTablePermissionType $permissionsType = null;

    public function __construct(
        public ?string $table = null,
        public string|array|null $pmsName = null,
        public bool $hasTenantId = false,
        public bool $softDeletes = false,
        public bool $disableAuditLog = false,
        public bool $disableCache = true,
        public bool $disableBroadcast = false,
        public bool $canRead = true,
        public bool $canCreate = true,
        public bool $canUpdate = true,
        public bool $canDelete = true,
        public bool $canUpsert = true,
        public bool $isAuthRead = true,
        public bool $isAuthWrite = true,
        public RecordTablePublic|bool $public = new RecordTablePublic(),
        public ?string $primaryKey = 'id',
        public ?string $ownerColumn = null,
        /** @var array<string, RecordColumnType|array<string, mixed>>|null */
        public ?array $columns = [],
        public ?array $columnHiddens = [],
        public ?array $columnWriteDisabled = [],
        public ?array $columnIndexes = [],
        public ?array $searchable = [],
        public ?array $relationships = [],
        public ?array $functions = [],
        public RecordTableTriggerType|string|array|null $customAuditLog = null,
        public Closure|RecordValidationType|array|string|null $createValidator = null,
        public Closure|RecordValidationType|array|string|null $updateValidator = null,
        public Closure|RecordValidationType|array|string|null $deleteValidator = null,
        public RecordTableTriggerType|array|null $beforeRead = null,
        public RecordTableTriggerType|array|null $afterRead = null,
        public RecordTableTriggerType|array|null $beforeCreate = null,
        public RecordTableTriggerType|array|null $afterCreate = null,
        public RecordTableTriggerType|array|null $beforeUpdate = null,
        public RecordTableTriggerType|array|null $afterUpdate = null,
        public RecordTableTriggerType|array|null $beforeDelete = null,
        public RecordTableTriggerType|array|null $afterDelete = null,
        public RecordTableTriggerType|array|null $beforeRestore = null,
        public RecordTableTriggerType|array|null $afterRestore = null,
        /** @var array<string>|null */
        public ?array $triggers = null,
        public bool $overrideTimestamps = false,
        public bool $overrideUserstamps = false,
        /** @var array<string, RecordComputedAttributeType|Closure|array{0: class-string, 1: string}|string>|null */
        public ?array $attributes = null,
        /** @var array<string, RecordCastEnum|RecordCastType|Closure|array{0: class-string, 1: string}|string>|null */
        public ?array $casting = null,
        public RecordTablePermissionType|array|null $permissions = null,
    ) {
        $this->columns = $this->normalizeColumns($this->columns);

        if ($this->permissions instanceof RecordTablePermissionType) {
            $this->permissionsType = $this->permissions;
            $this->permissions = $this->permissions->toArray();
        } elseif (is_array($this->permissions)) {
            $this->permissionsType = RecordTablePermissionType::fromArray($this->permissions);
        }

        if ($this->isAuthRead && $this->isAuthWrite && $this->hasLegacyPublicOverride($this->public)) {
            $authFlags = $this->deriveAuthFlagsFromPublic($this->public);
            $this->isAuthRead = $authFlags['read'];
            $this->isAuthWrite = $authFlags['write'];
        }

        $this->public = $this->derivePublicFromAuthFlags(isAuthRead: $this->isAuthRead, isAuthWrite: $this->isAuthWrite);

        if (!empty($this->triggers)) {
            $this->resolveClassTriggers();
        }

        $this->resolveClassValidators();
    }

    /**
     * Store every column as the legacy array form, so a RecordColumnType and a
     * hand-written array are interchangeable and readers keep seeing arrays.
     *
     * @param array<string, RecordColumnType|array<string, mixed>>|null $columns
     * @return array<string, mixed>|null
     */
    private function normalizeColumns(?array $columns): ?array
    {
        if (null === $columns) {
            return null;
        }

        foreach ($columns as $name => $definition) {
            if ($definition instanceof RecordColumnType) {
                $columns[$name] = $definition->toArray();
            }
        }

        return $columns;
    }

    /**
     * Resolve class-level validators using PHP Attributes.
     */
    private function resolveClassValidators(): void
    {
        $validatorClasses = array_filter([
            is_string($this->createValidator) ? $this->createValidator : null,
            is_string($this->updateValidator) ? $this->updateValidator : null,
            is_string($this->deleteValidator) ? $this->deleteValidator : null,
        ]);

        // Also check triggers array for combined Handler classes
        if (!empty($this->triggers)) {
            foreach ($this->triggers as $triggerClass) {
                if (is_string($triggerClass)) {
                    $validatorClasses[] = $triggerClass;
                }
            }
        }

        $validatorClasses = array_unique($validatorClasses);

        foreach ($validatorClasses as $validatorClass) {
            if (!class_exists($validatorClass)) {
                continue;
            }

            $reflection = new ReflectionClass($validatorClass);
            foreach ($reflection->getMethods() as $method) {
                $attributes = $method->getAttributes(RecordValidator::class);
                foreach ($attributes as $attribute) {
                    /** @var RecordValidator $validatorAttr */
                    $validatorAttr = $attribute->newInstance();
                    $hook = $validatorAttr->hook . 'Validator'; // e.g., 'create' -> 'createValidator'

                    if (property_exists($this, $hook) && (is_string($this->{$hook}) || $this->{$hook} === null)) {
                        $this->{$hook} = [$validatorClass, $method->getName()];
                    }
                }
            }
        }
    }

    private function hasTrigger(mixed $hook, string $class, string $method): bool
    {
        foreach (is_array($hook) && !array_is_list($hook) ? [$hook] : (array) (is_object($hook) ? [$hook] : $hook) as $existing) {
            $existingClass = is_object($existing) ? ($existing->class ?? null) : ($existing['class'] ?? $existing[0] ?? null);
            $existingMethod = is_object($existing) ? ($existing->functionName ?? null) : ($existing['functionName'] ?? $existing[1] ?? null);
            if ($existingClass === $class && $existingMethod === $method) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve class-level triggers using PHP Attributes.
     */
    private function resolveClassTriggers(): void
    {
        foreach ($this->triggers as $triggerClass) {
            if (!is_string($triggerClass)) {
                continue;
            }

            if (!class_exists($triggerClass)) {
                continue;
            }

            $reflection = new ReflectionClass($triggerClass);
            foreach ($reflection->getMethods() as $method) {
                $attributes = $method->getAttributes(RecordTrigger::class);
                foreach ($attributes as $attribute) {
                    /** @var RecordTrigger $triggerAttr */
                    $triggerAttr = $attribute->newInstance();
                    $hook = $triggerAttr->hook;

                    if (in_array($hook, RecordTriggerHookEnum::values(), true)) {
                        if ($this->hasTrigger($this->{$hook}, $triggerClass, $method->getName())) {
                            // Already resolved: __set_state() (config:cache) passes both the
                            // resolved hooks and the `triggers` list they came from.
                            continue;
                        }

                        $triggerType = new RecordTableTriggerType(
                            class: $triggerClass,
                            functionName: $method->getName(),
                            description: $triggerAttr->description
                        );

                        if ($this->{$hook} === null) {
                            $this->{$hook} = [$triggerType];
                        } elseif (is_array($this->{$hook})) {
                            $this->{$hook}[] = $triggerType;
                        } else {
                            $this->{$hook} = [$this->{$hook}, $triggerType];
                        }
                    }
                }
            }
        }
    }

    /**
     * Handle var_export() for configuration caching.
     * This httpMethod is required for Laravel's config:cache command.
     * @param array<string, mixed> $properties
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
            disableCache: $properties['disableCache'] ?? true,
            disableBroadcast: $properties['disableBroadcast'] ?? false,
            canRead: $properties['canRead'] ?? ($legacyCanRead ?? true),
            canCreate: $properties['canCreate'] ?? ($legacyCanWrite ?? true),
            canUpdate: $properties['canUpdate'] ?? ($legacyCanWrite ?? true),
            canDelete: $properties['canDelete'] ?? ($legacyCanWrite ?? true),
            canUpsert: $properties['canUpsert'] ?? ($legacyCanWrite ?? true),
            isAuthRead: self::normalizeBool($properties['isAuthRead'] ?? ($properties['is_auth_read'] ?? true)),
            isAuthWrite: self::normalizeBool($properties['isAuthWrite'] ?? ($properties['is_auth_write'] ?? true)),
            public: is_array($properties['public'] ?? null) ? RecordTablePublic::__set_state($properties['public']) : ($properties['public'] ?? new RecordTablePublic()),
            primaryKey: $properties['primaryKey'] ?? 'id',
            ownerColumn: self::normalizeOwnerColumn($properties['ownerColumn'] ?? ($properties['owner_column'] ?? null)),
            columns: $properties['columns'] ?? [],
            columnHiddens: $properties['columnHiddens'] ?? [],
            columnWriteDisabled: $properties['columnWriteDisabled'] ?? [],
            columnIndexes: $properties['columnIndexes'] ?? [],
            searchable: $properties['searchable'] ?? [],
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
            beforeRestore: $properties['beforeRestore'] ?? null,
            afterRestore: $properties['afterRestore'] ?? null,
            triggers: $properties['triggers'] ?? null,
            overrideTimestamps: $properties['overrideTimestamps'] ?? false,
            overrideUserstamps: $properties['overrideUserstamps'] ?? false,
            attributes: $properties['attributes'] ?? null,
            casting: $properties['casting'] ?? null,
            permissions: $properties['permissions'] ?? null,
        );
    }

    private function derivePublicFromAuthFlags(bool $isAuthRead, bool $isAuthWrite): RecordTablePublic
    {
        return new RecordTablePublic(
            read: !$isAuthRead,
            write: !$isAuthWrite,
        );
    }

    /**
     * Legacy array configs may carry the key in snake_case; anything that is not
     * a non-empty string is treated as "not configured" so resolution falls back
     * to the auto-detected audit columns.
     */
    private static function normalizeOwnerColumn(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }

    /** Anything that is not a real bool means "auth required" (fails closed). */
    private static function normalizeBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return true;
    }

    private function hasLegacyPublicOverride(RecordTablePublic|bool $publicConfig): bool
    {
        if (is_bool($publicConfig)) {
            return $publicConfig;
        }

        return (bool) ($publicConfig->read ?? false) || (bool) ($publicConfig->write ?? false);
    }

    private function deriveAuthFlagsFromPublic(RecordTablePublic|bool $publicConfig): array
    {
        if (is_bool($publicConfig)) {
            return [
                'read' => !$publicConfig,
                'write' => !$publicConfig,
            ];
        }

        return [
            'read' => !(bool) ($publicConfig->read ?? false),
            'write' => !(bool) ($publicConfig->write ?? false),
        ];
    }
}
