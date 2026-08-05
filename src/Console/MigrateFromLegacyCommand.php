<?php

declare(strict_types=1);

namespace Sopheak\Core\Console;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Authorization\Models\Role;
use Sopheak\Core\Services\RecordConfigService;

class MigrateFromLegacyCommand extends Command
{
    protected $signature = 'sp-laravel-api:migrate-from-legacy
                            {--force : Skip confirmation prompt}';

    protected $description = 'Migrate data from legacy permission tables to the built-in sp_* permission tables';

    public function handle(): int
    {
        if (!config('permissions.migrate_from_legacy', false) && !$this->option('force')) {
            $this->warn('Legacy migration is not enabled in config/sp-permissions.php.');
            $this->warn('Set SP_PERMISSION_MIGRATE_FROM_LEGACY=true or migrate_from_legacy to true.');
            $this->warn('Use --force to skip this check.');

            if (!$this->confirm('Do you want to proceed anyway?')) {
                return Command::FAILURE;
            }
        }

        $legacyTables = ['permissions', 'roles', 'model_has_permissions', 'model_has_roles', 'role_has_permissions'];
        $existing = [];

        foreach ($legacyTables as $table) {
            if (Schema::hasTable($table)) {
                $existing[] = $table;
            }
        }

        if (empty($existing)) {
            $this->warn('No legacy permission tables found. Nothing to migrate.');

            return Command::SUCCESS;
        }

        $this->info('Found legacy tables: ' . implode(', ', $existing));

        if (!Schema::hasTable('sp_permissions')) {
            $this->error('sp_permissions table does not exist. Run php artisan migrate first.');

            return Command::FAILURE;
        }

        $legacyTeamColumn = config('permissions.column_names.team_foreign_key', 'team_id');
        $targetTenantColumn = RecordConfigService::tenantColumn();
        $enableTenant = Schema::hasColumn('sp_roles', $targetTenantColumn);

        if ($enableTenant) {
            $this->info(sprintf('Mapping legacy %s → sp_* %s', $legacyTeamColumn, $targetTenantColumn));
        } else {
            $this->warn(sprintf("Tenant column '%s' not found on sp_roles — skipping tenant mapping.", $targetTenantColumn));
        }

        DB::beginTransaction();

        try {
            $stats = [
                'permissions' => 0,
                'roles' => 0,
                'role_permissions' => 0,
                'model_roles' => 0,
                'model_permissions' => 0,
            ];

            if (in_array('permissions', $existing, true)) {
                $stats['permissions'] = $this->migratePermissions();
            }

            if (in_array('roles', $existing, true)) {
                $stats['roles'] = $this->migrateRoles($legacyTeamColumn, $targetTenantColumn, $enableTenant);
            }

            if (in_array('role_has_permissions', $existing, true)) {
                $stats['role_permissions'] = $this->migrateRolePermissions($legacyTeamColumn, $targetTenantColumn, $enableTenant);
            }

            if (in_array('model_has_roles', $existing, true)) {
                $stats['model_roles'] = $this->migrateModelRoles($legacyTeamColumn, $targetTenantColumn, $enableTenant);
            }

            if (in_array('model_has_permissions', $existing, true)) {
                $stats['model_permissions'] = $this->migrateModelPermissions($legacyTeamColumn, $targetTenantColumn, $enableTenant);
            }

            DB::commit();

            $this->info('Migration complete!');
            $this->table(
                ['Table', 'Records Migrated'],
                [
                    ['sp_permissions', $stats['permissions']],
                    ['sp_roles', $stats['roles']],
                    ['sp_role_permissions', $stats['role_permissions']],
                    ['sp_model_has_roles', $stats['model_roles']],
                    ['sp_model_permissions', $stats['model_permissions']],
                ]
            );

            $this->warn('Old permission tables have been left untouched for safety.');
            $this->warn('You can drop them manually after verifying the migration.');
            $this->warn('  - permissions, roles, role_has_permissions, model_has_roles, model_has_permissions');

            return Command::SUCCESS;
        } catch (Exception $exception) {
            DB::rollBack();

            $this->error('Migration failed: ' . $exception->getMessage());

            return Command::FAILURE;
        }
    }

    protected function migratePermissions(): int
    {
        $count = 0;
        $guardName = config('sp-laravel-api.auth.guard', 'api');
        $separator = config('record.permission_separator', ':');

        DB::table('permissions')->orderBy('id')->chunk(200, function ($permissions) use (&$count, $guardName, $separator): void {
            foreach ($permissions as $perm) {
                $group = $this->inferGroup($perm->name, $separator);

                DB::table('sp_permissions')->updateOrInsert(
                    ['name' => $perm->name],
                    [
                        'group' => $group,
                        'guard_name' => $perm->guard_name ?? $guardName,
                        'description' => null,
                        'created_at' => $perm->created_at ?? now(),
                        'updated_at' => $perm->updated_at ?? now(),
                    ]
                );

                $count++;
            }
        });

        return $count;
    }

    protected function migrateRoles(string $legacyTeamColumn, string $targetTenantColumn, bool $enableTenant): int
    {
        $count = 0;
        $guardName = config('sp-laravel-api.auth.guard', 'api');

        DB::table('roles')->orderBy('id')->chunk(200, function ($roles) use (&$count, $guardName, $legacyTeamColumn, $targetTenantColumn, $enableTenant): void {
            foreach ($roles as $role) {
                $identity = ['name' => $role->name];
                $data = [
                    'guard_name' => $role->guard_name ?? $guardName,
                    'description' => null,
                    'is_system' => false,
                    'created_at' => $role->created_at ?? now(),
                    'updated_at' => $role->updated_at ?? now(),
                ];

                if ($enableTenant && isset($role->{$legacyTeamColumn})) {
                    $identity[$targetTenantColumn] = $role->{$legacyTeamColumn};
                    $data[$targetTenantColumn] = $role->{$legacyTeamColumn};
                }

                // Use Eloquent so the creating event fires (auto-generates `key` from name)
                Role::query()->updateOrCreate($identity, $data);

                $count++;
            }
        });

        return $count;
    }

    protected function migrateRolePermissions(string $legacyTeamColumn, string $targetTenantColumn, bool $enableTenant): int
    {
        $count = 0;

        DB::table('role_has_permissions')->orderBy('permission_id')->chunk(200, function ($pivots) use (&$count, $legacyTeamColumn, $targetTenantColumn, $enableTenant): void {
            foreach ($pivots as $pivot) {
                $roleResult = $this->findNewRoleId($pivot->role_id, $legacyTeamColumn, $targetTenantColumn, $enableTenant);
                $permId = $this->findNewPermissionId($pivot->permission_id);

                if ($roleResult && $permId) {
                    $data = [
                        'role_id' => $roleResult->id,
                        'permission_id' => $permId,
                    ];

                    if ($enableTenant && $roleResult->tenantValue !== null) {
                        $data[$targetTenantColumn] = $roleResult->tenantValue;
                    }

                    DB::table('sp_role_permissions')->updateOrInsert(
                        $data,
                        [
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );

                    $count++;
                }
            }
        });

        return $count;
    }

    protected function migrateModelRoles(string $legacyTeamColumn, string $targetTenantColumn, bool $enableTenant): int
    {
        $count = 0;

        DB::table('model_has_roles')->orderBy('role_id')->chunk(200, function ($pivots) use (&$count, $legacyTeamColumn, $targetTenantColumn, $enableTenant): void {
            foreach ($pivots as $pivot) {
                $result = $this->findNewRoleId($pivot->role_id, $legacyTeamColumn, $targetTenantColumn, $enableTenant);

                if ($result !== null) {
                    $data = [
                        'model_type' => $pivot->model_type,
                        'model_id' => $pivot->model_id,
                        'role_id' => $result->id,
                    ];

                    if ($enableTenant && $result->tenantValue !== null) {
                        $data[$targetTenantColumn] = $result->tenantValue;
                    }

                    DB::table('sp_model_has_roles')->updateOrInsert(
                        $data,
                        [
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );

                    $count++;
                }
            }
        });

        return $count;
    }

    protected function migrateModelPermissions(string $legacyTeamColumn, string $targetTenantColumn, bool $enableTenant): int
    {
        $count = 0;

        DB::table('model_has_permissions')->orderBy('permission_id')->chunk(200, function ($pivots) use (&$count, $legacyTeamColumn, $targetTenantColumn, $enableTenant): void {
            foreach ($pivots as $pivot) {
                $permId = $this->findNewPermissionId($pivot->permission_id);

                if ($permId) {
                    $data = [
                        'model_type' => $pivot->model_type,
                        'model_id' => $pivot->model_id,
                        'permission_id' => $permId,
                    ];

                    if ($enableTenant && isset($pivot->{$legacyTeamColumn})) {
                        $data[$targetTenantColumn] = $pivot->{$legacyTeamColumn};
                    }

                    DB::table('sp_model_permissions')->updateOrInsert(
                        $data,
                        [
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );

                    $count++;
                }
            }
        });

        return $count;
    }

    protected function findNewRoleId(int $oldRoleId, ?string $legacyTeamColumn = null, ?string $targetTenantColumn = null, bool $enableTenant = false): ?object
    {
        $oldRole = DB::table('roles')->where('id', $oldRoleId)->first();

        if (!$oldRole) {
            return null;
        }

        $query = DB::table('sp_roles')->where('name', $oldRole->name);

        if ($enableTenant && $legacyTeamColumn && $targetTenantColumn && isset($oldRole->{$legacyTeamColumn})) {
            $query->where($targetTenantColumn, $oldRole->{$legacyTeamColumn});
        }

        $newRole = $query->first();

        if (!$newRole) {
            return null;
        }

        return (object) [
            'id' => $newRole->id,
            'tenantValue' => $oldRole->{$legacyTeamColumn} ?? null,
        ];
    }

    protected function findNewPermissionId(int $oldPermissionId): ?int
    {
        $oldPerm = DB::table('permissions')->where('id', $oldPermissionId)->first();

        if (!$oldPerm) {
            return null;
        }

        $newPerm = DB::table('sp_permissions')->where('name', $oldPerm->name)->first();

        return $newPerm?->id;
    }

    protected function inferGroup(string $permissionName, string $separator): ?string
    {
        $parts = explode($separator, $permissionName);

        if (count($parts) >= 2) {
            return $parts[1];
        }

        return null;
    }
}
