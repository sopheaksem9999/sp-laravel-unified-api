<?php

declare(strict_types=1);

namespace Sopheak\Core\Authorization\Models;

use Sopheak\Core\Authorization\Models\Pivots\RolePermissionPivot;
use Sopheak\Core\Authorization\PermissionRegistrar;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Sopheak\Core\Authorization\Traits\HasConfigurableKey;

class Permission extends Model
{
    use HasConfigurableKey;

    protected $table = 'sp_permissions';

    protected $fillable = [
        'name',
        'group',
        'guard_name',
        'description',
    ];

    protected static function booted(): void
    {
        static::saved(function (): void {
            app(PermissionRegistrar::class)->forgetAllCachedPermissions();
        });

        static::deleted(function (): void {
            app(PermissionRegistrar::class)->forgetAllCachedPermissions();
        });
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'sp_role_permissions',
            'permission_id',
            'role_id'
        )->using(RolePermissionPivot::class);
    }
}
