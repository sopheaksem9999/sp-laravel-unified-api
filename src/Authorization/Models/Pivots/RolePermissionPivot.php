<?php

declare(strict_types=1);

namespace Sopheak\Core\Authorization\Models\Pivots;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Sopheak\Core\Authorization\Traits\HasConfigurableKey;

/**
 * Pivot for sp_role_permissions.
 *
 * Registered via ->using() on Role::permissions() and Permission::roles() so
 * that attach()/sync() route through Eloquent's model lifecycle instead of a
 * raw insert — the only way HasConfigurableKey's `creating` hook can generate
 * a uuid for this table's own surrogate `id`.
 */
class RolePermissionPivot extends Pivot
{
    use HasConfigurableKey;

    protected $table = 'sp_role_permissions';
}
