<?php

declare(strict_types=1);

namespace Sopheak\Core\Authorization;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Sopheak\Core\Authorization\Models\Permission;
use Sopheak\Core\Authorization\Models\Role;
use Sopheak\Core\Authorization\Traits\HasRoles;

class PermissionService
{
    public function userHasTrait(Model $user): bool
    {
        return in_array(HasRoles::class, class_uses_recursive($user), true);
    }

    public function userHasPermission(Model $user, string $permission): bool
    {
        if (!$this->userHasTrait($user)) {
            return false;
        }

        return $user->hasPermissionTo($permission);
    }

    public function userHasAnyPermission(Model $user, array $permissions): bool
    {
        if (!$this->userHasTrait($user)) {
            return false;
        }

        return $user->hasAnyPermission($permissions);
    }

    public function userHasAllPermissions(Model $user, array $permissions): bool
    {
        if (!$this->userHasTrait($user)) {
            return false;
        }

        return $user->hasAllPermissions($permissions);
    }

    public function userHasRole(Model $user, string $role): bool
    {
        if (!$this->userHasTrait($user)) {
            return false;
        }

        return $user->hasRole($role);
    }

    public function getAllPermissionsForUser(Model $user): Collection
    {
        if (!$this->userHasTrait($user)) {
            return collect();
        }

        return $user->getAllPermissions();
    }

    public function createRole(string $name, string $guardName = 'api', ?string $description = null, bool $isSystem = false): Role
    {
        return Role::query()->create([
            'name' => $name,
            'guard_name' => $guardName,
            'description' => $description,
            'is_system' => $isSystem,
        ]);
    }

    public function createPermission(string $name, ?string $group = null, string $guardName = 'api', ?string $description = null): Permission
    {
        return Permission::query()->create([
            'name' => $name,
            'group' => $group,
            'guard_name' => $guardName,
            'description' => $description,
        ]);
    }

    public function findOrCreatePermission(string $name, ?string $group = null, string $guardName = 'api', ?string $description = null): Permission
    {
        return Permission::query()->firstOrCreate(
            ['name' => $name],
            [
                'group' => $group,
                'guard_name' => $guardName,
                'description' => $description,
            ]
        );
    }

    public function findOrCreateRole(string $name, string $guardName = 'api', ?string $description = null): Role
    {
        return Role::query()->firstOrCreate(
            ['name' => $name],
            [
                'guard_name' => $guardName,
                'description' => $description,
            ]
        );
    }

    public function assignRoleToUser(Model $user, string $roleName): bool
    {
        if (!$this->userHasTrait($user)) {
            return false;
        }

        if (!Role::query()->where('name', $roleName)->exists()) {
            return false;
        }

        $user->assignRole($roleName);

        return true;
    }

    public function givePermissionToUser(Model $user, string $permissionName): bool
    {
        if (!$this->userHasTrait($user)) {
            return false;
        }

        if (!Permission::query()->where('name', $permissionName)->exists()) {
            return false;
        }

        $user->givePermissionTo($permissionName);

        return true;
    }

    public function syncUserRoles(Model $user, array $roleNames): bool
    {
        if (!$this->userHasTrait($user)) {
            return false;
        }

        $user->syncRoles($roleNames);

        return true;
    }

    public function syncUserPermissions(Model $user, array $permissionNames): bool
    {
        if (!$this->userHasTrait($user)) {
            return false;
        }

        $user->syncPermissions($permissionNames);

        return true;
    }

    public function isBuiltInPermissionEnabled(): bool
    {
        return (bool) config('permissions.enabled', false);
    }
}
