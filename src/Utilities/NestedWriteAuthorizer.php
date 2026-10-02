<?php

declare(strict_types=1);

namespace Sopheak\Core\Utilities;

use Closure;
use InvalidArgumentException;
use Sopheak\Core\Exceptions\NestedWriteRefusedException;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Types\RecordTableType;

/**
 * Authorises nested child writes as direct requests on the child table.
 *
 * A nested write used to authorise only the parent: a user allowed to update
 * an invoice could create, edit and delete its items with no invoice_item
 * permission, even on a table configured canCreate/canDelete false.
 *
 * Enforcement applies inside enforce() only. Untrusted entry points — the
 * HTTP CRUD and bulk endpoints, the async bulk job and the Data MCP — open
 * that scope. Trusted callers — app code and commands calling RecordService
 * directly, and the app's triggers, post-write hooks and record event
 * listeners (run through trusted()) — never authorise the parent either, so
 * they keep today's behaviour.
 */
final class NestedWriteAuthorizer
{
    private static int $depth = 0;

    public static function enforce(Closure $callback): mixed
    {
        ++self::$depth;

        try {
            return $callback();
        } finally {
            --self::$depth;
        }
    }

    /**
     * Run trusted app code — table and global triggers, post-write logic,
     * record event listeners — outside any enforcement scope. That code is
     * the app's own, like a direct RecordService call, so its nested writes
     * are not authorised against the requesting user. Data a before-trigger
     * merges into the request is still checked: the request's own write runs
     * after the trigger returns, back inside the scope.
     */
    public static function trusted(Closure $callback): mixed
    {
        $depth = self::$depth;
        self::$depth = 0;

        try {
            return $callback();
        } finally {
            self::$depth = $depth;
        }
    }

    public static function isEnforcing(): bool
    {
        return self::$depth > 0;
    }

    /**
     * @param 'create'|'delete'|'update' $operation
     */
    public static function authorizeChild(string $parentTable, string $relationship, string $childTable, string $operation): void
    {
        if (!self::isEnforcing()) {
            return;
        }

        $childSchema = SchemaRegistryUtils::getTable($childTable);
        if ($childSchema instanceof RecordTableType) {
            $enabled = match ($operation) {
                'create' => $childSchema->canCreate,
                'update' => $childSchema->canUpdate,
                'delete' => $childSchema->canDelete,
            };

            if (!$enabled) {
                throw new InvalidArgumentException(sprintf(
                    "Cannot %s item in relationship '%s' for table '%s': can%s is disabled on table '%s'.",
                    $operation,
                    $relationship,
                    $parentTable,
                    ucfirst($operation),
                    $childTable
                ));
            }
        }

        $decision = PermissionUtils::actionDecision(auth(RecordConfigService::authGuard())->user(), $childTable, $operation);
        if (PermissionUtils::DECISION_ALLOWED !== $decision) {
            throw new NestedWriteRefusedException($decision);
        }
    }
}
