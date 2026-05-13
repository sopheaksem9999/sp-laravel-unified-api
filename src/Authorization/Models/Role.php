<?php

namespace Sopheak\Core\Authorization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $table = 'sp_roles';

    protected $fillable = [
        'name',
        'guard_name',
        'description',
        'is_system',
    ];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::deleting(function (self $role) {
            if ($role->is_system) {
                throw new \RuntimeException('Cannot delete system role: ' . $role->name);
            }
        });

        static::saved(function () {
            app(\Sopheak\Core\Authorization\PermissionRegistrar::class)->forgetAllCachedPermissions();
        });

        static::deleted(function () {
            app(\Sopheak\Core\Authorization\PermissionRegistrar::class)->forgetAllCachedPermissions();
        });
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'sp_role_permissions',
            'role_id',
            'permission_id'
        );
    }

    public function hasPermissionTo(string $permission): bool
    {
        return $this->permissions()->where('name', $permission)->exists();
    }

    public function givePermissionTo(string|array $permissions): static
    {
        $permissionIds = Permission::query()
            ->whereIn('name', (array) $permissions)
            ->pluck('id')
            ->toArray();

        $this->permissions()->syncWithoutDetaching($permissionIds);

        return $this;
    }

    public function revokePermissionTo(string|array $permissions): static
    {
        $permissionIds = Permission::query()
            ->whereIn('name', (array) $permissions)
            ->pluck('id')
            ->toArray();

        $this->permissions()->detach($permissionIds);

        return $this;
    }

    public function syncPermissions(array $permissions): static
    {
        $permissionIds = Permission::query()
            ->whereIn('name', $permissions)
            ->pluck('id')
            ->toArray();

        $this->permissions()->sync($permissionIds);

        return $this;
    }
}
