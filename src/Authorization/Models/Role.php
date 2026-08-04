<?php

declare(strict_types=1);

namespace Sopheak\Core\Authorization\Models;

use RuntimeException;
use Sopheak\Core\Authorization\PermissionRegistrar;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;
use Sopheak\Core\Authorization\Traits\HasConfigurableKey;
use Sopheak\Core\Services\RecordConfigService;

class Role extends Model
{
    use HasConfigurableKey;

    protected $table = 'sp_roles';

    protected $fillable = [
        'name',
        'key',
        'guard_name',
        'description',
        'is_system',
        'is_master',
        'is_default',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'is_master' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        if (RecordConfigService::enableTenantId()) {
            $tenantColumn = RecordConfigService::tenantColumn();
            if (!in_array($tenantColumn, $this->fillable, true)) {
                $this->fillable[] = $tenantColumn;
            }
        }
    }

    protected static function booted(): void
    {
        static::creating(function (self $role): void {
            if ($role->guard_name === null) {
                $role->guard_name = RecordConfigService::authGuard();
            }

            if ($role->key === null) {
                $slug = Str::slug($role->name);
                $baseSlug = $slug;
                $suffix = 1;

                $query = static::query()->where('key', $slug);
                if (RecordConfigService::enableTenantId()) {
                    $tenantColumn = RecordConfigService::tenantColumn();
                    if ($role->{$tenantColumn} !== null) {
                        $query->where($tenantColumn, $role->{$tenantColumn});
                    }
                }

                while ($query->exists()) {
                    $slug = $baseSlug . '-' . $suffix++;
                    $query = static::query()->where('key', $slug);
                    if (RecordConfigService::enableTenantId()) {
                        $tenantColumn = RecordConfigService::tenantColumn();
                        if ($role->{$tenantColumn} !== null) {
                            $query->where($tenantColumn, $role->{$tenantColumn});
                        }
                    }
                }

                $role->key = $slug;
            }
        });

        static::deleting(function (self $role): void {
            if ($role->is_system) {
                throw new RuntimeException('Cannot delete system role: ' . $role->name);
            }
        });

        static::saved(function (): void {
            app(PermissionRegistrar::class)->forgetAllCachedPermissions();
        });

        static::deleted(function (): void {
            app(PermissionRegistrar::class)->forgetAllCachedPermissions();
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
