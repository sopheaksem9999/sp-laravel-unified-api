<?php

namespace Sopheak\Core\Console;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Services\RecordConfigService;

class MigrateFromSpatieCommand extends Command
{
    protected $signature = 'sp-laravel-api:migrate-from-spatie
                            {--force : Skip confirmation prompt}';

    protected $description = 'Migrate data from spatie/laravel-permission tables to the built-in sp_* permission tables';

    public function handle(): int
    {
        if (!config('permission.migrate_from_spatie', false) && !$this->option('force')) {
            $this->warn('Migration from Spatie is not enabled in config/permission.php.');
            $this->warn('Set SP_PERMISSION_MIGRATE_FROM_SPATIE=true or migrate_from_spatie to true.');
            $this->warn('Use --force to skip this check.');

            if (!$this->confirm('Do you want to proceed anyway?')) {
                return Command::FAILURE;
            }
        }

        $spatieTables = ['permissions', 'roles', 'model_has_permissions', 'model_has_roles', 'role_has_permissions'];
        $existing = [];

        foreach ($spatieTables as $table) {
            if (Schema::hasTable($table)) {
                $existing[] = $table;
            }
        }

        if (empty($existing)) {
            $this->warn('No Spatie permission tables found. Nothing to migrate.');

            return Command::SUCCESS;
        }

        $this->info('Found Spatie tables: ' . implode(', ', $existing));

        if (!Schema::hasTable('sp_permissions')) {
            $this->error('sp_permissions table does not exist. Run php artisan migrate first.');

            return Command::FAILURE;
        }

        $spatieTeamColumn = config('permission.column_names.team_foreign_key', 'team_id');
        $targetTenantColumn = RecordConfigService::tenantColumn();
        $enableTenant = RecordConfigService::enableTenantId();

        if ($enableTenant) {
            $this->info("Mapping Spatie {$spatieTeamColumn} → sp_* {$targetTenantColumn}");
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
                $stats['roles'] = $this->migrateRoles();
            }

            if (in_array('role_has_permissions', $existing, true)) {
                $stats['role_permissions'] = $this->migrateRolePermissions();
            }

            if (in_array('model_has_roles', $existing, true)) {
                $stats['model_roles'] = $this->migrateModelRoles($spatieTeamColumn, $targetTenantColumn, $enableTenant);
            }

            if (in_array('model_has_permissions', $existing, true)) {
                $stats['model_permissions'] = $this->migrateModelPermissions($spatieTeamColumn, $targetTenantColumn, $enableTenant);
            }

            DB::commit();

            $this->info('Migration complete!');
            $this->table(
                ['Table', 'Records Migrated'],
                [
                    ['sp_permissions', $stats['permissions']],
                    ['sp_roles', $stats['roles']],
                    ['sp_role_permissions', $stats['role_permissions']],
                    ['sp_model_roles', $stats['model_roles']],
                    ['sp_model_permissions', $stats['model_permissions']],
                ]
            );

            $this->warn('Old Spatie tables have been left untouched for safety.');
            $this->warn('You can drop them manually after verifying the migration.');
            $this->warn('  - permissions, roles, role_has_permissions, model_has_roles, model_has_permissions');

            return Command::SUCCESS;
        } catch (Exception $e) {
            DB::rollBack();

            $this->error('Migration failed: ' . $e->getMessage());

            return Command::FAILURE;
        }
    }

    protected function migratePermissions(): int
    {
        $count = 0;
        $guardName = config('sp-laravel-api.auth.guard', 'api');
        $separator = config('record.permission_separator', ':');

        DB::table('permissions')->orderBy('id')->chunk(200, function ($permissions) use (&$count, $guardName, $separator) {
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

    protected function migrateRoles(): int
    {
        $count = 0;
        $guardName = config('sp-laravel-api.auth.guard', 'api');

        DB::table('roles')->orderBy('id')->chunk(200, function ($roles) use (&$count, $guardName) {
            foreach ($roles as $role) {
                DB::table('sp_roles')->updateOrInsert(
                    ['name' => $role->name],
                    [
                        'guard_name' => $role->guard_name ?? $guardName,
                        'description' => null,
                        'is_system' => false,
                        'created_at' => $role->created_at ?? now(),
                        'updated_at' => $role->updated_at ?? now(),
                    ]
                );

                $count++;
            }
        });

        return $count;
    }

    protected function migrateRolePermissions(): int
    {
        $count = 0;

        DB::table('role_has_permissions')->orderBy('permission_id')->chunk(200, function ($pivots) use (&$count) {
            foreach ($pivots as $pivot) {
                $roleId = $this->findNewRoleId($pivot->role_id);
                $permId = $this->findNewPermissionId($pivot->permission_id);

                if ($roleId && $permId) {
                    DB::table('sp_role_permissions')->updateOrInsert(
                        [
                            'role_id' => $roleId,
                            'permission_id' => $permId,
                        ],
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

    protected function migrateModelRoles(string $spatieTeamColumn, string $targetTenantColumn, bool $enableTenant): int
    {
        $count = 0;

        DB::table('model_has_roles')->orderBy('role_id')->chunk(200, function ($pivots) use (&$count, $spatieTeamColumn, $targetTenantColumn, $enableTenant) {
            foreach ($pivots as $pivot) {
                $roleId = $this->findNewRoleId($pivot->role_id);

                if ($roleId) {
                    $data = [
                        'model_type' => $pivot->model_type,
                        'model_id' => $pivot->model_id,
                        'role_id' => $roleId,
                    ];

                    if ($enableTenant && isset($pivot->{$spatieTeamColumn})) {
                        $data[$targetTenantColumn] = $pivot->{$spatieTeamColumn};
                    }

                    DB::table('sp_model_roles')->updateOrInsert(
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

    protected function migrateModelPermissions(string $spatieTeamColumn, string $targetTenantColumn, bool $enableTenant): int
    {
        $count = 0;

        DB::table('model_has_permissions')->orderBy('permission_id')->chunk(200, function ($pivots) use (&$count, $spatieTeamColumn, $targetTenantColumn, $enableTenant) {
            foreach ($pivots as $pivot) {
                $permId = $this->findNewPermissionId($pivot->permission_id);

                if ($permId) {
                    $data = [
                        'model_type' => $pivot->model_type,
                        'model_id' => $pivot->model_id,
                        'permission_id' => $permId,
                    ];

                    if ($enableTenant && isset($pivot->{$spatieTeamColumn})) {
                        $data[$targetTenantColumn] = $pivot->{$spatieTeamColumn};
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

    protected function findNewRoleId(int $oldRoleId): ?int
    {
        $oldRole = DB::table('roles')->where('id', $oldRoleId)->first();

        if (!$oldRole) {
            return null;
        }

        $newRole = DB::table('sp_roles')->where('name', $oldRole->name)->first();

        return $newRole?->id;
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
