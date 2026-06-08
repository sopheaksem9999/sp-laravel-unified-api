<?php

declare(strict_types=1);

namespace Sopheak\Core\Authorization\Traits;

use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Request;
use Sopheak\Core\Authorization\Models\Permission;
use Sopheak\Core\Authorization\Models\Role;
use Sopheak\Core\Authorization\PermissionRegistrar;
use Sopheak\Core\Services\RecordConfigService;

trait HasRoles
{
    public function roles(): MorphToMany
    {
        $relation = $this->morphToMany(
            Role::class,
            'model',
            'sp_model_has_roles',
            'model_id',
            'role_id'
        )->withTimestamps();

        if (config('permissions.tenant_scoped', false)) {
            $relation->withPivot(RecordConfigService::tenantColumn());
        }

        return $this->applyTenantScope($relation);
    }

    public function permissions(): MorphToMany
    {
        $relation = $this->morphToMany(
            Permission::class,
            'model',
            'sp_model_permissions',
            'model_id',
            'permission_id'
        )->withTimestamps();

        if (config('permissions.tenant_scoped', false)) {
            $relation->withPivot(RecordConfigService::tenantColumn());
        }

        return $this->applyTenantScope($relation);
    }

    public function assignRole(string|array|Role ...$roles): static
    {
        $roleIds = $this->resolveRoleIds($roles);

        $this->roles()->syncWithoutDetaching($this->withPivotData($roleIds));

        app(PermissionRegistrar::class)->forgetAllCachedPermissions();

        return $this;
    }

    public function removeRole(string|array|Role ...$roles): static
    {
        $roleIds = $this->resolveRoleIds($roles);

        $this->roles()->detach($roleIds);

        app(PermissionRegistrar::class)->forgetAllCachedPermissions();

        return $this;
    }

    public function syncRoles(string|array|Role ...$roles): static
    {
        $roleIds = $this->resolveRoleIds($roles);

        $this->roles()->sync($this->withPivotData($roleIds));

        app(PermissionRegistrar::class)->forgetAllCachedPermissions();

        return $this;
    }

    public function hasRole(string|array|Role $role): bool
    {
        $roleName = $role instanceof Role ? $role->name : $role;

        if (is_array($roleName)) {
            return $this->roles()->whereIn('name', $roleName)->exists();
        }

        return $this->roles()->where('name', $roleName)->exists();
    }

    public function hasAnyRole(string|array|Role ...$roles): bool
    {
        $roleNames = $this->flattenRoles(...$roles);

        return $this->roles()->whereIn('name', $roleNames)->exists();
    }

    public function hasAllRoles(string|array|Role ...$roles): bool
    {
        $roleNames = $this->flattenRoles(...$roles);

        $userRoleNames = $this->roles()->pluck('name')->toArray();

        return empty(array_diff($roleNames, $userRoleNames));
    }

    public function givePermissionTo(string|array|Permission ...$permissions): static
    {
        $permissionIds = $this->resolvePermissionIds($permissions);

        $this->permissions()->syncWithoutDetaching($permissionIds);

        app(PermissionRegistrar::class)->forgetAllCachedPermissions();

        return $this;
    }

    public function revokePermissionTo(string|array|Permission ...$permissions): static
    {
        $permissionIds = $this->resolvePermissionIds($permissions);

        $this->permissions()->detach($permissionIds);

        app(PermissionRegistrar::class)->forgetAllCachedPermissions();

        return $this;
    }

    public function syncPermissions(string|array|Permission ...$permissions): static
    {
        $permissionIds = $this->resolvePermissionIds($permissions);

        $this->permissions()->sync($permissionIds);

        app(PermissionRegistrar::class)->forgetAllCachedPermissions();

        return $this;
    }

    public function hasPermissionTo(string|Permission $permission): bool
    {
        $permissionName = $permission instanceof Permission ? $permission->name : $permission;

        return $this->getAllPermissions()->contains($permissionName);
    }

    public function hasAnyPermission(string|array|Permission ...$permissions): bool
    {
        $permissionNames = $this->flattenPermissions(...$permissions);

        $userPermissions = $this->getAllPermissions()->toArray();

        return !empty(array_intersect($permissionNames, $userPermissions));
    }

    public function hasAllPermissions(string|array|Permission ...$permissions): bool
    {
        $permissionNames = $this->flattenPermissions(...$permissions);

        $userPermissions = $this->getAllPermissions()->toArray();

        return empty(array_diff($permissionNames, $userPermissions));
    }

    public function getAllPermissions(): Collection
    {
        $registrar = app(PermissionRegistrar::class);

        return $registrar->getPermissions($this);
    }

    private function resolveRoleIds(array $roles): array
    {
        $names = $this->flattenRoles(...$roles);

        $query = Role::query()->whereIn('name', $names);

        if (config('permissions.tenant_scoped', false) && RecordConfigService::enableTenantId()) {
            $tenantColumn = RecordConfigService::tenantColumn();
            $tenantId = $this->resolveTenantId();
            if (null !== $tenantId) {
                $query->where($tenantColumn, $tenantId);
            }
        }

        return $query->pluck('id')->toArray();
    }

    private function resolvePermissionIds(array $permissions): array
    {
        $names = $this->flattenPermissions(...$permissions);

        return Permission::query()->whereIn('name', $names)->pluck('id')->toArray();
    }

    private function withPivotData(array $ids): array
    {
        if (!config('permissions.tenant_scoped', false) || !RecordConfigService::enableTenantId()) {
            return $ids;
        }

        $tenantId = $this->resolveTenantId();
        if (null === $tenantId) {
            return $ids;
        }

        $tenantColumn = RecordConfigService::tenantColumn();
        $result = [];
        foreach ($ids as $id) {
            $result[$id] = [$tenantColumn => $tenantId];
        }

        return $result;
    }

    private function flattenRoles(...$roles): array
    {
        $names = [];

        foreach ($roles as $role) {
            if ($role instanceof Role) {
                $names[] = $role->name;
            } elseif (is_array($role)) {
                $names = array_merge($names, $role);
            } elseif (is_string($role)) {
                $names[] = $role;
            }
        }

        return array_unique($names);
    }

    private function flattenPermissions(...$permissions): array
    {
        $names = [];

        foreach ($permissions as $permission) {
            if ($permission instanceof Permission) {
                $names[] = $permission->name;
            } elseif (is_array($permission)) {
                $names = array_merge($names, $permission);
            } elseif (is_string($permission)) {
                $names[] = $permission;
            }
        }

        return array_unique($names);
    }

    private function applyTenantScope(MorphToMany $relation): MorphToMany
    {
        if (!config('permissions.tenant_scoped', false)) {
            return $relation;
        }

        $tenantColumn = RecordConfigService::tenantColumn();
        $tenantId = $this->resolveTenantId();

        if (null !== $tenantId) {
            $relation->wherePivot($tenantColumn, $tenantId);
        }

        return $relation;
    }

    private function resolveTenantId(): mixed
    {
        if (property_exists($this, 'tenant_id') && null !== $this->tenant_id) {
            return $this->tenant_id;
        }

        $request = Request::instance();

        $tenantId = $request->attributes->get('resolved_tenant_id');

        if (null === $tenantId) {
            $context = $request->attributes->get('record_context', []);
            $tenantId = $context['tenant_id'] ?? null;
        }

        if (null === $tenantId) {
            $tenantHeader = RecordConfigService::tenantHeader();
            $tenantId = $request->header($tenantHeader);
        }

        return $tenantId;
    }
}
