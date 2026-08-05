<?php

declare(strict_types=1);

namespace Sopheak\Core\Authorization\Models\Pivots;

use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Sopheak\Core\Authorization\Traits\HasConfigurableKey;

/**
 * Pivot for sp_model_permissions.
 *
 * Registered via ->using() on HasRoles::permissions(). See
 * RolePermissionPivot for why a custom pivot class is required at all.
 */
class ModelPermissionPivot extends MorphPivot
{
    use HasConfigurableKey;

    protected $table = 'sp_model_permissions';
}
