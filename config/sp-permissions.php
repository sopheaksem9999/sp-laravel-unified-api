<?php

use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Types\RecordMetaBelongsToManyType;
use Sopheak\Core\Types\RecordTableType;

return [
    /*
    |--------------------------------------------------------------------------
    | Permission System Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains the configuration for the built-in role/permission
    | system. When enabled, it replaces the default Gate-based authorization
    | with the integrated permission system.
    |
    | When disabled (default), the package continues to use the existing
    | Gate::forUser($user)->allows() flow — zero breaking change.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Enable Built-in Permission System
    |--------------------------------------------------------------------------
    |
    | When set to true, the package will:
    | 1. Auto-register permissions from config/sp-record.php (pmsName + can* flags)
    | 2. Check permissions via the HasRoles trait (direct + role-based)
    | 3. Cache resolved permissions for the authenticated user
    |
    | When false, the existing Gate::forUser()->allows() flow is used.
    |
    */
    'enabled' => env('SP_PERMISSION_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Auto-Register Permissions from Config
    |--------------------------------------------------------------------------
    |
    | When enabled, on every boot the system scans all tables in config/sp-record.php
    | and auto-creates permissions based on pmsName + canRead/canCreate/etc flags.
    |
    | Example: pmsName: 'invoice', canRead: true → creates 'view:invoice' permission
    |
    | Custom permission maps on RecordTableType::$permissions are also registered.
    |
    */
    'auto_register' => env('SP_PERMISSION_AUTO_REGISTER', true),

    /*
    |--------------------------------------------------------------------------
    | Auto-Register Function Permissions
    |--------------------------------------------------------------------------
    |
    | When enabled, permissions are also auto-created from RecordFunctionType
    | entries that have a pmsName.
    |
    */
    'auto_register_functions' => env('SP_PERMISSION_AUTO_REGISTER_FUNCTIONS', true),

    /*
    |--------------------------------------------------------------------------
    | Cache TTL
    |--------------------------------------------------------------------------
    |
    | How long (in seconds) to cache resolved permissions for a user.
    | The cache is automatically invalidated when roles/permissions change.
    |
    */
    'cache_ttl' => env('SP_PERMISSION_CACHE_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Tenant Scoping
    |--------------------------------------------------------------------------
    |
    | When enabled, role and permission assignments are scoped per tenant.
    | The tenant_id column on pivot tables is used for filtering.
    |
    | When disabled (default), roles and permissions are global — the tenant_id
    | column exists but is nullable and unused unless explicitly set.
    |
    */
    'tenant_scoped' => env('SP_PERMISSION_TENANT_SCOPED', false),

    /*
    |--------------------------------------------------------------------------
    | Super-Admin Bypass
    |--------------------------------------------------------------------------
    |
    | When set to a callable, users for whom this callback returns true will
    | implicitly have all permissions — the DB permission check is bypassed.
    |
    | The callback receives the authenticated user and must return bool.
    |
    | Example (Passport super-admin scope + is_admin flag):
    | 'super_admin_callback' => fn ($user) => $user->tokenCan('super-admin') || $user->is_admin,
    |
    | Default: null (no bypass — all users must have explicit permissions).
    |
    */
    'super_admin_callback' => null,

    /*
    |--------------------------------------------------------------------------
    | Migrate From Legacy
    |--------------------------------------------------------------------------
    |
    | When set to true, the sp-laravel-api:migrate-from-legacy command is
    | available to migrate data from legacy permission tables (e.g. from
    | spatie/laravel-permission) to the built-in sp_* tables.
    |
    | Old legacy tables are never modified or dropped by the migration.
    |
    */
    'migrate_from_legacy' => env('SP_PERMISSION_MIGRATE_FROM_LEGACY', false),

    /*
    |--------------------------------------------------------------------------
    | Permission Tables Configuration
    |--------------------------------------------------------------------------
    |
    | These table configurations are automatically merged into the main
    | record.tables by RecordConfigService.
    |
    | sp_permissions and sp_roles are visible via CRUD but with restrictions:
    | - sp_permissions: canCreate = false (auto-registered from config)
    | - sp_roles: canCreate = true (roles can be created manually)
    |
    */
    'tables' => [
        'sp_permissions' => new RecordTableType(
            table: 'sp_permissions',
            pmsName: 'permission',
            primaryKey: 'id',
            softDeletes: false,
            hasTenantId: false,
            isAuthRead: true,
            isAuthWrite: true,
            canRead: true,
            canCreate: false,
            canUpdate: true,
            canDelete: false,
            canUpsert: false,
            columns: [
                'id' => ['type' => RecordConfigService::idType() === 'uuid' ? 'uuid' : 'bigIncrements', 'nullable' => false],
                'name' => ['type' => 'string', 'nullable' => false],
                'group' => ['type' => 'string', 'nullable' => true],
                'guard_name' => ['type' => 'string', 'nullable' => false],
                'description' => ['type' => 'text', 'nullable' => true],
            ],
            relationships: [
                'roles' => new RecordMetaBelongsToManyType(
                    related: 'sp_roles',
                    table: 'sp_role_permissions',
                    foreignPivotKey: 'permission_id',
                    relatedPivotKey: 'role_id',
                    parentKey: 'id',
                    relatedKey: 'id',
                    withTimestamps: true,
                ),
            ],
        ),
        'sp_roles' => new RecordTableType(
            table: 'sp_roles',
            pmsName: 'role',
            primaryKey: 'id',
            softDeletes: false,
            hasTenantId: config('record.enable_tenant_id', false),
            isAuthRead: true,
            isAuthWrite: true,
            canRead: true,
            canCreate: true,
            canUpdate: true,
            canDelete: true,
            canUpsert: false,
            columns: array_merge(
                [
                    'id' => ['type' => RecordConfigService::idType() === 'uuid' ? 'uuid' : 'bigIncrements', 'nullable' => false],
                    'name' => ['type' => 'string', 'nullable' => false],
                    'key' => ['type' => 'string', 'nullable' => true],
                    'guard_name' => ['type' => 'string', 'nullable' => true, 'default' => 'api'],
                    'description' => ['type' => 'text', 'nullable' => true],
                    'is_system' => ['type' => 'boolean', 'nullable' => false, 'default' => false],
                    'is_master' => ['type' => 'boolean', 'nullable' => false, 'default' => false],
                    'is_default' => ['type' => 'boolean', 'nullable' => false, 'default' => false],
                ],
                config('record.enable_tenant_id', false)
                    ? [config('record.tenant_column', 'tenant_id') => ['type' => 'string', 'nullable' => true]]
                    : []
            ),
            relationships: [
                'permissions' => new RecordMetaBelongsToManyType(
                    related: 'sp_permissions',
                    table: 'sp_role_permissions',
                    foreignPivotKey: 'role_id',
                    relatedPivotKey: 'permission_id',
                    parentKey: 'id',
                    relatedKey: 'id',
                    withTimestamps: true,
                ),
            ],
        ),
    ],
];
