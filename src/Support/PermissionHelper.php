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
        $readActions = ['read'];
        $writeActions = ['create', 'update', 'delete', 'restore', 'force_delete'];

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
        // Get resource name from config pms_name or fallback to table name
        $tables = config('record.tables', []);
        $tableConfig = $tables[$table] ?? [];
        $resource = $tableConfig->pms_name ?? Str::snake(Str::singular($table));

        // Map standard CRUD actions to permission verbs first
        switch ($action) {
            case 'read':
            case 'index':
            case 'show':
                $verb = 'view';

                break;

            case 'create':
            case 'store':
                $verb = 'create';

                break;

            case 'update':
            case 'edit':
            case 'restore':
                $verb = 'update';

                break;

            case 'delete':
            case 'destroy':
            case 'force_delete':
                $verb = 'delete';

                break;

            default:
                // Handle special permission types that include the action in the permission name
                if (str_contains($action, '_')) {
                    // For actions like 'viewOnlyCreateBy', 'updateStatus', etc.
                    return $action.'_'.$resource;
                }

                $verb = $action;

                break;
        }

        return $verb.'_'.$resource;
    }
}
