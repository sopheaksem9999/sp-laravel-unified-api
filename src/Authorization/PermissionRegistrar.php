<?php

declare(strict_types=1);

namespace Sopheak\Core\Authorization;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Sopheak\Core\Authorization\Models\Permission;
use Sopheak\Core\Authorization\Traits\HasRoles;
use Sopheak\Core\Services\RecordConfigService;

class PermissionRegistrar
{
    protected string $cacheKey = 'sp_permissions';

    protected string $cacheVersionKey = 'sp_permissions_version';

    protected string $configHashKey = 'sp_permissions_config_hash';

    public function __construct(protected Gate $gate, protected CacheManager $cache) {}

    public function registerPermissions(): void
    {
        Permission::query()->pluck('name')->each(function (string $name): void {
            $this->gate->define($name, function (Model $user) use ($name) {
                if ($this->userHasTrait($user)) {
                    return $user->hasPermissionTo($name);
                }

                return false;
            });
        });
    }

    public function autoRegisterFromConfig(): void
    {
        if (!config('permissions.auto_register', true)) {
            return;
        }

        $tables = RecordConfigService::getTableConfig();
        $hash = $this->computeConfigHash($tables);
        $lastHash = $this->cache->get($this->configHashKey, '');

        if ($hash === $lastHash) {
            return;
        }

        $separator = RecordConfigService::permissionSeparator();

        foreach ($tables as $config) {
            if (is_array($config)) {
                continue;
            }

            if (!is_object($config)) {
                continue;
            }

            $pmsNames = $this->getPmsNames($config);

            foreach ($pmsNames as $pmsName) {
                $this->ensurePermissionExists($pmsName, $separator, $config);
            }
        }

        if (config('permissions.auto_register_functions', true)) {
            $this->autoRegisterFunctionPermissions($tables, $separator);
        }

        $this->cache->forever($this->configHashKey, $hash);
    }

    public function getCacheVersion(): int
    {
        return (int) $this->cache->rememberForever($this->cacheVersionKey, fn(): int => 1);
    }

    public function getPermissions(Model $user): Collection
    {
        $key = $this->getCacheKey($user);

        $cached = $this->cache->get($key);
        if ($cached instanceof Collection) {
            return $cached;
        }

        $permissions = $this->resolveUserPermissions($user);
        $this->cache->put($key, $permissions, config('permissions.cache_ttl', 3600));

        return $permissions;
    }

    protected function resolveUserPermissions(Model $user): Collection
    {
        $permissions = collect();

        $rolePermissionNames = Permission::query()
            ->whereIn('id', function ($query) use ($user): void {
                $query->select('sp_role_permissions.permission_id')
                    ->from('sp_role_permissions')
                    ->join('sp_model_has_roles', 'sp_role_permissions.role_id', '=', 'sp_model_has_roles.role_id')
                    ->where('sp_model_has_roles.model_type', $user::class)
                    ->where('sp_model_has_roles.model_id', $user->getKey());
            })
            ->pluck('name');

        $permissions = $permissions->merge($rolePermissionNames);

        $directPermissionNames = $user->permissions()->pluck('name');

        $permissions = $permissions->merge($directPermissionNames);

        return $permissions->unique()->values();
    }

    public function forgetPermissions(Model $user): void
    {
        $this->cache->forget($this->getCacheKey($user));
    }

    public function forgetAllCachedPermissions(): void
    {
        $this->getCacheVersion();

        $this->cache->increment($this->cacheVersionKey);
    }

    protected function getCacheKey(Model $user): string
    {
        $version = $this->getCacheVersion();
        $tenantId = '';

        if (config('permissions.tenant_scoped', false)) {
            $tenantId = '_' . ($user->tenant_id ?? 'global');
        }

        return $this->cacheKey . '_v' . $version . '_user_' . $user::class . '_' . $user->getKey() . $tenantId;
    }

    protected function computeConfigHash(array $tables): string
    {
        $relevant = [];

        foreach ($tables as $table => $config) {
            if (is_array($config)) {
                continue;
            }

            if (!is_object($config)) {
                continue;
            }

            $pmsNames = $this->getPmsNames($config);

            if (empty($pmsNames)) {
                continue;
            }

            $functions = $config->functions ?? [];
            $funcNames = [];

            foreach ((array) $functions as $func) {
                if (is_object($func) && isset($func->pmsName)) {
                    $funcNames[] = $func->pmsName;
                } elseif (is_array($func) && isset($func['pmsName'])) {
                    $funcNames[] = $func['pmsName'];
                }
            }

            $relevant[$table] = [
                'pmsName' => $pmsNames,
                'canRead' => $config->canRead ?? true,
                'canCreate' => $config->canCreate ?? true,
                'canUpdate' => $config->canUpdate ?? true,
                'canDelete' => $config->canDelete ?? true,
                'permissions' => $config->permissions ?? null,
                'functions' => $funcNames,
            ];
        }

        return md5(json_encode($relevant));
    }

    protected function userHasTrait(Model $user): bool
    {
        return in_array(HasRoles::class, class_uses_recursive($user), true);
    }

    /**
     * @return string[]
     */
    protected function getPmsNames(object $config): array
    {
        $pmsName = $config->pmsName ?? null;

        if (is_string($pmsName) && '' !== trim($pmsName)) {
            return [trim($pmsName)];
        }

        if (is_array($pmsName)) {
            $names = [];
            foreach ($pmsName as $name) {
                if (is_string($name) && '' !== trim($name)) {
                    $names[] = trim($name);
                }
            }

            return $names;
        }

        return [];
    }

    protected function ensurePermissionExists(string $pmsName, string $separator, object $config): void
    {
        $canRead = $config->canRead ?? true;
        $canCreate = $config->canCreate ?? true;
        $canUpdate = $config->canUpdate ?? true;
        $canDelete = $config->canDelete ?? true;

        $guardName = config('sp-laravel-api.auth.guard', 'api');

        $actions = [];

        if ($canRead) {
            $actions[] = 'view';
        }

        if ($canCreate) {
            $actions[] = 'create';
        }

        if ($canUpdate) {
            $actions[] = 'update';
        }

        if ($canDelete) {
            $actions[] = 'delete';
        }

        foreach ($actions as $action) {
            $permissionName = $action . $separator . $pmsName;

            Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                [
                    'group' => $pmsName,
                    'guard_name' => $guardName,
                    'description' => sprintf('Allow %s %s', $action, $pmsName),
                ]
            );
        }

        if (isset($config->permissions) && is_array($config->permissions)) {
            foreach ($config->permissions as $permissionNames) {
                foreach ((array) $permissionNames as $permissionName) {
                    Permission::query()->firstOrCreate(
                        ['name' => $permissionName],
                        [
                            'group' => $pmsName,
                            'guard_name' => $guardName,
                            'description' => 'Custom permission: ' . $permissionName,
                        ]
                    );
                }
            }
        }
    }

    protected function autoRegisterFunctionPermissions(array $tables, string $separator): void
    {
        foreach ($tables as $config) {
            if (is_array($config)) {
                continue;
            }

            if (!is_object($config)) {
                continue;
            }

            $functions = $config->functions ?? [];
            if (!is_array($functions)) {
                continue;
            }

            if (empty($functions)) {
                continue;
            }

            foreach ($functions as $functionConfig) {
                $pmsName = null;

                if (is_object($functionConfig)) {
                    $pmsName = $functionConfig->pmsName ?? null;
                } elseif (is_array($functionConfig)) {
                    $pmsName = $functionConfig['pmsName'] ?? null;
                }

                if (null === $pmsName) {
                    continue;
                }

                $guardName = config('sp-laravel-api.auth.guard', 'api');

                $pmsNames = is_array($pmsName) ? $pmsName : [$pmsName];

                foreach ($pmsNames as $name) {
                    if (!is_string($name)) {
                        continue;
                    }

                    if ('' === trim($name)) {
                        continue;
                    }

                    $name = trim($name);

                    Permission::query()->firstOrCreate(
                        ['name' => $name],
                        [
                            'group' => 'functions',
                            'guard_name' => $guardName,
                            'description' => 'Function permission: ' . $name,
                        ]
                    );
                }
            }
        }
    }
}
