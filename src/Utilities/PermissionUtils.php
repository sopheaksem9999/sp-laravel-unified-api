<?php

declare(strict_types=1);

namespace Sopheak\Core\Utilities;

use Illuminate\Support\Str;
use Sopheak\Core\Constants\RecordConstants;
use Sopheak\Core\Services\RecordConfigService;

class PermissionUtils
{
    /**
     * Determine if an action on a table is public (no auth required) based on config.
     */
    public static function isPublicAction(string $table, string $action): bool
    {
        $tables = RecordConfigService::getTableConfig();
        if (!isset($tables[$table])) {
            return false;
        }

        $tableConfig = $tables[$table];

        $readActions = [RecordConstants::READ];
        $writeActions = [RecordConstants::WRITE, RecordConstants::ACTION_CREATE, RecordConstants::ACTION_UPDATE, RecordConstants::ACTION_DELETE, RecordConstants::ACTION_RESTORE, 'force_delete'];

        if (is_object($tableConfig) && in_array($action, $readActions, true) && property_exists($tableConfig, 'isAuthRead')) {
            return !(bool) $tableConfig->isAuthRead;
        }

        if (is_object($tableConfig) && in_array($action, $writeActions, true) && property_exists($tableConfig, 'isAuthWrite')) {
            return !(bool) $tableConfig->isAuthWrite;
        }

        $public = $tableConfig->public ?? false;

        // Entire resource public
        if (true === $public) {
            return true;
        }

        // If public is not a RecordTablePublic object, deny access
        if (!is_object($public) || !property_exists($public, RecordConstants::READ) || !property_exists($public, RecordConstants::WRITE)) {
            return false;
        }

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
     * Uses pmsName from config when available, falls back to table name.
     * Example: invoices + read => view_invoice; estimates (pmsName: estimateSo) + read => view_estimateSo.
     */
    public static function mapPermission(string $table, string $action): string
    {
        $permissions = self::mapPermissions($table, $action);

        return $permissions[0] ?? '';
    }

    /**
     * @return string[]
     */
    public static function mapPermissions(string $table, string $action): array
    {
        // Get resource name from config pmsName or fallback to table name
        $tables = RecordConfigService::getTableConfig();
        $permissionPrefix = RecordConfigService::permissionSeparator();
        $tableConfig = $tables[$table] ?? [];
        $resources = self::normalizeResources($tableConfig->pmsName ?? null, $table);

        // Map standard CRUD actions to permission verbs first
        switch ($action) {
            case RecordConstants::READ:
            case RecordConstants::VIEW:
            case RecordConstants::SEE:
                $verb = RecordConstants::ACTION_VIEW;

                break;

            case RecordConstants::CREATE:
                $verb = RecordConstants::ACTION_CREATE;

                break;

            case RecordConstants::UPDATE:
            case RecordConstants::EDIT:
            case RecordConstants::WRITE:
                $verb = RecordConstants::ACTION_UPDATE;

                break;

            case RecordConstants::DELETE:
            case RecordConstants::DESTROY:
                $verb = RecordConstants::ACTION_DELETE;

                break;

            default:
                // Handle special permission types that include the action in the permission name
                if (str_contains($action, $permissionPrefix)) {
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
