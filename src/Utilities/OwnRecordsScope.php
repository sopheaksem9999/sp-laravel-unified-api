<?php

declare(strict_types=1);

namespace Sopheak\Core\Utilities;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Throwable;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Enums\RecordApiJsonResponseEnum;
use Sopheak\Core\Services\RecordApiResponseService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Types\RecordTableType;

/**
 * The single place that decides whether the current user is restricted to
 * their own rows on a table, and on which column.
 *
 * `viewOwn:{pmsName}` used to be enforced only inside
 * QueryBuilderFiltersUtils::apply(), which only list reads reach. By-id
 * reads, update, delete, restore and force-delete build their own queries, so
 * a viewOwn user saw only their own rows in a list yet could read, edit or
 * delete anyone's row by id. Every one of those paths now calls apply() here,
 * directly after applyTenantFilter().
 */
final class OwnRecordsScope
{
    /**
     * The action a custom `record.authorization` handler receives for the
     * viewOwn question. Distinct from 'read' so a handler that decides on the
     * action cannot mistake it for the real read check.
     */
    private const VIEW_OWN_ACTION = 'view_own';

    /**
     * The owner column the current user is restricted to on $table, or null
     * when no own-records restriction applies — no authenticated user, no
     * viewOwn permission for any pmsName alias, or no owner column declared.
     */
    public static function ownerColumn(string $table): ?string
    {
        $recordConfig = RecordConfigService::table($table);
        $pmsName = $recordConfig->pmsName ?? null;

        if (!$pmsName || !Auth::check() || '' === RecordConfigService::ownRecordsPermissionPrefix()) {
            return null;
        }

        $user = Auth::user();

        // A super admin is never restricted to their own rows, however the
        // app's Gate or authorization handler answers viewOwn:*.
        if (self::isSuperAdminSafely($user)) {
            return null;
        }

        $prefix = RecordConfigService::ownRecordsPermissionPrefix();
        $separator = RecordConfigService::permissionSeparator();

        $permissions = [];
        foreach (is_array($pmsName) ? $pmsName : [$pmsName] as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }

            if ('' === trim($candidate)) {
                continue;
            }

            $permissions[] = $prefix . $separator . trim($candidate);
        }

        if (!self::holdsViewOwn($user, $permissions, $table)) {
            return null;
        }

        return self::resolveOwnerColumn($recordConfig, QueryBuilderFiltersUtils::getAllowedColumns($table));
    }

    /**
     * Restrict $query to the current user's own rows when a restriction
     * applies. $qualifiedTable is the name the column is prefixed with in
     * SQL — the physical table — and defaults to $table.
     */
    public static function apply(Builder $query, string $table, ?string $qualifiedTable = null): void
    {
        $ownerColumn = self::ownerColumn($table);
        if (null === $ownerColumn) {
            return;
        }

        $query->where(($qualifiedTable ?? $table) . '.' . $ownerColumn, Auth::user()->id);
    }

    /**
     * The own-records restriction as a raw-SQL fragment for correlated
     * subqueries that cannot take a query builder.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    public static function sqlCondition(string $table, string $qualifier): array
    {
        $ownerColumn = self::ownerColumn($table);
        if (null === $ownerColumn) {
            return ['', []];
        }

        return [sprintf(' AND %s.%s = ?', $qualifier, $ownerColumn), [Auth::user()->id]];
    }

    /**
     * A cache-key component that is empty when no restriction applies, so
     * unrestricted callers keep exactly the keys they have today, and
     * otherwise names the column and the user the rows are restricted to.
     *
     * $select is the request's combined select/with parameter. Every table it
     * embeds is scoped too, so a cached response on an unrestricted table that
     * includes a restricted one is not shared across users either.
     */
    public static function cacheToken(string $table, string $select = ''): string
    {
        $parts = [];

        $ownerColumn = self::ownerColumn($table);
        if (null !== $ownerColumn) {
            $parts[] = $ownerColumn;
        }

        if ('' !== $select) {
            self::collectIncludeTokens($table, RelationshipResolverUtils::parseSelectForIncludes($select), $parts);
        }

        return [] === $parts ? '' : 'own:' . implode(',', $parts) . ':' . Auth::user()->id;
    }

    /**
     * @param array<string, array{table?: ?string, children?: array<string, mixed>}> $includes
     * @param array<int, string> $parts
     */
    private static function collectIncludeTokens(string $parentTable, array $includes, array &$parts): void
    {
        foreach ($includes as $alias => $include) {
            $relationship = RelationshipResolverUtils::resolveRelationship($parentTable, (string) $alias, $include['table'] ?? null);
            $relatedTable = is_array($relationship) ? ($relationship['table'] ?? null) : null;
            if (!is_string($relatedTable)) {
                continue;
            }

            if ('' === $relatedTable) {
                continue;
            }

            $ownerColumn = self::ownerColumn($relatedTable);
            if (null !== $ownerColumn && !in_array($relatedTable . '.' . $ownerColumn, $parts, true)) {
                $parts[] = $relatedTable . '.' . $ownerColumn;
            }

            if (!empty($include['children']) && is_array($include['children'])) {
                self::collectIncludeTokens($relatedTable, $include['children'], $parts);
            }
        }
    }

    /**
     * Refuse an upsert that would overwrite a row the current user does not own.
     *
     * An upsert's ON CONFLICT … DO UPDATE cannot carry a WHERE, so the owner
     * filter that guards every other write cannot ride on the statement.
     *
     * Probing only `match_on` is not enough. MySQL's INSERT … ON DUPLICATE KEY
     * UPDATE ignores `match_on` and fires on ANY unique key — including the
     * primary key — so an item naming a foreign row's id, or any other unique
     * value it holds, would take that row over. Every unique key the item fully
     * specifies is therefore probed: `match_on`, the primary key, and each
     * unique index on the table.
     *
     * A key with a missing or NULL value is skipped: unique constraints never
     * treat NULLs as conflicting, so it cannot cause an overwrite, and probing
     * it as `IS NULL` would refuse legitimate inserts. A row with a NULL owner
     * is not the caller's either. The probe is deliberately not tenant-filtered:
     * upsert itself has no tenant guard, so a foreign-tenant collision must fail
     * closed too.
     *
     * @param array<int, array<string, mixed>> $items
     * @param array<int, string> $matchOn
     */
    public static function assertNoForeignMatches(string $table, string $qualifiedTable, array $items, array $matchOn): void
    {
        $ownerColumn = self::ownerColumn($table);
        if (null === $ownerColumn || [] === $items) {
            return;
        }

        $keySets = self::uniqueKeySets($table, $qualifiedTable, $matchOn);
        $userId = Auth::user()->id;
        $owner = $qualifiedTable . '.' . $ownerColumn;

        $tuples = [];
        foreach ($items as $item) {
            foreach ($keySets as $columns) {
                $tuple = [];
                foreach ($columns as $column) {
                    if (!array_key_exists($column, $item)) {
                        continue 2;
                    }

                    if (null === $item[$column]) {
                        continue 2;
                    }

                    $tuple[$column] = $item[$column];
                }

                $tuples[] = $tuple;
            }
        }

        if ([] === $tuples) {
            return;
        }

        $foreign = DB::table($qualifiedTable)
            ->where(function (Builder $any) use ($tuples, $qualifiedTable): void {
                foreach ($tuples as $tuple) {
                    $any->orWhere(function (Builder $match) use ($tuple, $qualifiedTable): void {
                        foreach ($tuple as $column => $value) {
                            $match->where($qualifiedTable . '.' . $column, $value);
                        }
                    });
                }
            })
            ->where(function (Builder $notMine) use ($owner, $userId): void {
                $notMine->where($owner, '!=', $userId)->orWhereNull($owner);
            })
            ->exists();

        if ($foreign) {
            throw new HttpResponseException(
                RecordApiResponseService::errorWrapped('Forbidden', RecordApiJsonResponseEnum::FORBIDDEN->value)
            );
        }
    }

    /**
     * Every column set that can trigger an upsert conflict on the table:
     * `match_on`, the primary key, and each unique index. Index introspection
     * failing is not allowed to disable the guard — it falls back to the two
     * sets that are always known.
     *
     * @param array<int, string> $matchOn
     * @return array<int, array<int, string>>
     */
    private static function uniqueKeySets(string $table, string $qualifiedTable, array $matchOn): array
    {
        $primaryKey = SchemaRegistryUtils::getTable($table)?->primaryKey ?? 'id';

        $sets = [];
        if ([] !== $matchOn) {
            $sets[] = array_values($matchOn);
        }

        $sets[] = [$primaryKey];

        try {
            foreach (Schema::getIndexes($qualifiedTable) as $index) {
                if (($index['unique'] ?? false) || ($index['primary'] ?? false)) {
                    $sets[] = array_values((array) ($index['columns'] ?? []));
                }
            }
        } catch (Throwable) {
            // Keep match_on + primary key.
        }

        $unique = [];
        foreach ($sets as $set) {
            if ([] === $set) {
                continue;
            }

            $unique[implode("\0", $set)] = $set;
        }

        return array_values($unique);
    }

    /**
     * Whether $user holds viewOwn for the table.
     *
     * viewOwn is restrictive — holding it narrows access — so the user is
     * restricted when EITHER the application's configured authorization mode
     * (custom handler, built-in module, or Gate) OR Laravel's Gate grants it.
     * Before the decision was shared, Gate was the only thing that could grant
     * viewOwn in every mode, so an app on a custom handler or the built-in
     * module that granted it through Gate::define must stay restricted. OR-ing
     * Gate in can only ever narrow access, never widen it.
     *
     * A mode that cannot answer — a handler that throws for a permission it
     * does not know, as Spatie's hasPermissionTo() does — is treated as not
     * granting it, which is exactly what it did before it was consulted at
     * all; the exception is reported, not swallowed, and Gate still decides.
     *
     * Without a custom handler the configured mode already is Gate, so Gate is
     * asked once — one viewOwn entry in Telescope, not two.
     *
     * @param array<int, string> $permissions
     */
    private static function holdsViewOwn(mixed $user, array $permissions, string $table): bool
    {
        if ([] === $permissions) {
            return false;
        }

        $gateAsked = false;

        try {
            if (PermissionUtils::userHasAnyPermission($user, $permissions, $table, self::VIEW_OWN_ACTION)) {
                return true;
            }

            $gateAsked = null === config('record.authorization');
        } catch (Throwable $throwable) {
            report($throwable);
        }

        return !$gateAsked && Gate::forUser($user)->any($permissions);
    }

    /**
     * authorizeAction() never runs `super_admin_callback` for a public action,
     * so this can be the first place it runs. A callback that throws must not
     * turn every signed-in read of a public table into a 500; it is reported
     * and the user is treated as not a super admin.
     */
    private static function isSuperAdminSafely(mixed $user): bool
    {
        try {
            return PermissionUtils::isSuperAdmin($user);
        } catch (Throwable $throwable) {
            report($throwable);

            return false;
        }
    }

    /**
     * Resolution order: the table's explicit `ownerColumn`, then each entry of
     * `record.own_records_owner_columns`. The first candidate the table
     * actually declares wins; none declared means no restriction.
     *
     * @param array<int, string> $allowedCols
     */
    private static function resolveOwnerColumn(mixed $recordConfig, array $allowedCols): ?string
    {
        $candidates = [];

        if ($recordConfig instanceof RecordTableType) {
            $candidates[] = $recordConfig->ownerColumn;
        } elseif (is_array($recordConfig)) {
            $candidates[] = $recordConfig['ownerColumn'] ?? ($recordConfig['owner_column'] ?? null);
        }

        foreach (RecordConfigService::ownRecordsOwnerColumns() as $fallback) {
            $candidates[] = $fallback;
        }

        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }

            $candidate = trim($candidate);
            if ('' !== $candidate && in_array($candidate, $allowedCols, true)) {
                return $candidate;
            }
        }

        return null;
    }
}
