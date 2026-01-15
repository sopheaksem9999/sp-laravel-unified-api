<?php

namespace Sopheak\Core\Support;

use Illuminate\Support\Str;

class PermissionHelper
{
    /**
     * Determine if an action on a table is public (no auth required) based on config.
     */
    public static function isPublicAction(string $table, string $action): bool
    {
        $tables = config('record.tables', []);
        if (!isset($tables[$table])) {
            return false;
        }

        $public = $tables[$table]->public ?? false;

        // Entire resource public
        if (true === $public) {
            return true;
        }

        // If public is not a RecordTablePublic object, deny access
        if (!is_object($public) || !property_exists($public, 'read') || !property_exists($public, 'write')) {
            return false;
        }

        // Grouped semantics: 'read' and 'write'
        $readActions = ['read', 'view'];
        $writeActions = ['create', 'update', 'delete', 'restore'];

        if (in_array($action, $readActions, true)) {
            return (bool) ($public->read ?? false);
        }

        if (in_array($action, $writeActions, true)) {
            return (bool) ($public->write ?? false);
        }

        // Default deny
        return false;
    }

    /**
     * Map controller action to permission string following existing naming convention.
     * Uses pms_name from config when available, falls back to table name.
     * Example: invoices + read => view_invoice; estimates (pms_name: estimateSo) + read => view_estimateSo.
     */
    public static function mapPermission(string $table, string $action): string
    {
        $permissions = self::mapPermissions($table, $action);

        return $permissions[0] ?? '';
    }

    public static function mapPermissions(string $table, string $action): array
    {
        // Get resource name from config pms_name or fallback to table name
        $tables = config('record.tables', []);
        $permissionPrefix = config('record.permission_separator', ':');
        $tableConfig = $tables[$table] ?? [];
        $resources = self::normalizeResources($tableConfig->pms_name ?? null, $table);

        // Map standard CRUD actions to permission verbs first
        switch ($action) {
            case 'read':
            case 'view':
                $verb = 'view';

                break;

            case 'create':
                $verb = 'create';

                break;

            case 'update':
            case 'edit':
            case 'write':
                $verb = 'update';

                break;

            case 'delete':
            case 'write':
                $verb = 'delete';

                break;

            default:
                // Handle special permission types that include the action in the permission name
                if (str_contains($action, (string) $permissionPrefix)) {
                    // For actions like 'viewOnlyCreateBy', 'updateStatus', etc.
                    return array_map(
                        static fn(string $resource): string => $action . $permissionPrefix . $resource,
                        $resources
                    );
                }

                $verb = $action;

                break;
        }

        return array_map(
            static fn(string $resource): string => $verb . $permissionPrefix . $resource,
            $resources
        );
    }

    private static function normalizeResources(string|array|null $pmsName, string $table): array
    {
        if (is_string($pmsName) && '' !== trim($pmsName)) {
            return [trim($pmsName)];
        }

        if (is_array($pmsName)) {
            $resources = [];
            foreach ($pmsName as $candidate) {
                if (!is_string($candidate)) {
                    continue;
                }

                $candidate = trim($candidate);
                if ('' === $candidate) {
                    continue;
                }

                $resources[] = $candidate;
            }

            if ([] !== $resources) {
                return array_values(array_unique($resources));
            }
        }

        return [Str::snake(Str::singular($table))];
    }
}
