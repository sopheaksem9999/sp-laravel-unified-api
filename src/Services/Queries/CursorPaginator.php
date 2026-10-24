<?php

declare(strict_types=1);

namespace Sopheak\Core\Services\Queries;

use Illuminate\Database\Query\Builder;
use Sopheak\Core\Services\RecordConfigService;

/**
 * Keyset (cursor) pagination, shared by the CRUD list endpoint
 * (RecordService) and RecordQueryBuilder so both page the same way.
 *
 * A page is defined by the list order — the cursor column, then the primary key
 * as a tie-break when the cursor column is not unique — and a cursor is a
 * position in that order: the row a page ends at (`next_cursor`, also
 * `meta.cursor`) or starts at (`prev_cursor`).
 *
 * - `direction=next` returns the rows after the cursor, in list order.
 * - `direction=prev` returns the rows before it: the query runs in the reverse
 *   order (so LIMIT takes the rows closest to the cursor) and the rows are
 *   reversed back into list order.
 * - `last_cursor` is the row just before the last page, so
 *   `cursor=<last_cursor>&direction=next` opens exactly the last page.
 */
final class CursorPaginator
{
    /**
     * Marks a cursor that carries both a sort value and a tie-breaking key.
     * Versioned so the encoding can change without misreading tokens clients hold.
     */
    public const COMPOUND_PREFIX = 'c1.';

    public const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * @param bool $keepExistingOrder the builder is already ordered by $cursorColumn
     *                                in $sortOrder (the list endpoint's applySort()),
     *                                so the first page need not reorder it
     * @return array{0: list<object>, 1: array<string, mixed>, 2: array<string, string>, 3: int}
     */
    public static function paginate(
        Builder $builder,
        mixed $rawCursor,
        mixed $direction,
        string $cursorColumn,
        string $primaryKey,
        mixed $sortOrder,
        int $perPage,
        bool $skipTotal,
        bool $isUuidColumn,
        bool $keepExistingOrder = false,
    ): array {
        $direction = 'prev' === $direction ? 'prev' : 'next';
        $sortOrder = in_array(strtolower((string) $sortOrder), ['asc', 'desc'], true) ? strtolower((string) $sortOrder) : 'desc';
        $reverseOrder = 'asc' === $sortOrder ? 'desc' : 'asc';

        // A composite cursor only means anything when the paging column is not
        // already unique — on the primary key the simple comparison is exact.
        $useComposite = RecordConfigService::cursorCompositeEnabled() && $cursorColumn !== $primaryKey;

        // A cursor issued for a composite page carries both components; a plain
        // scalar (a legacy or hand-written token) yields a null key and falls
        // back to the simple comparison.
        [$cursor, $cursorKey] = self::decode($rawCursor);

        // Cast numeric strings to int so PostgreSQL uses index-friendly comparisons (not for UUID columns).
        if (!$isUuidColumn && null !== $cursor && '' !== $cursor && ctype_digit((string) $cursor)) {
            $cursor = (int) $cursor;
        }

        $hasCursor = null !== $cursor && '' !== $cursor && 0 !== $cursor;

        // On a UUID column only a valid UUID is a cursor.
        if ($isUuidColumn && $hasCursor && (!is_string($cursor) || 1 !== preg_match(self::UUID_PATTERN, $cursor))) {
            $hasCursor = false;
            $cursor = null;
            $cursorKey = null;
        }

        $total = 0;
        $lastCursor = null;

        if (!$skipTotal) {
            $total = (clone $builder)->count();

            if ($total > $perPage && RecordConfigService::cursorBoundaryEnabled()) {
                $lastPageSize = $total % $perPage;
                $lastPageSize = 0 === $lastPageSize ? $perPage : $lastPageSize;

                // Read the list from its end: the last page's rows, then the row just
                // before them — the position `cursor=<last_cursor>&direction=next`
                // continues from.
                $boundary = (clone $builder)
                    ->select(self::qualified($builder, array_values(array_unique([$cursorColumn, $primaryKey]))))
                    ->reorder()
                    ->orderBy($cursorColumn, $reverseOrder)
                    ->when($useComposite, fn(Builder $q): Builder => $q->orderBy($primaryKey, $reverseOrder))
                    ->limit($lastPageSize + 1)
                    ->get();

                $row = $boundary->last();
                $lastCursor = is_object($row) ? self::cursorFor($row, $cursorColumn, $primaryKey, $useComposite) : null;
            }
        }

        // The next and previous cursors are read from the rows themselves, so the
        // paging column and the key must be loaded even when `select=` left them
        // out; they are removed again before the rows are returned.
        $addedColumns = self::ensureSelected($builder, [$cursorColumn, $primaryKey]);

        // `prev` reads backwards from the cursor so LIMIT keeps the rows nearest to it.
        $readBackwards = $hasCursor && 'prev' === $direction;
        $queryOrder = $readBackwards ? $reverseOrder : $sortOrder;

        if ($hasCursor || !$keepExistingOrder) {
            $builder->reorder()->orderBy($cursorColumn, $queryOrder);
        }

        if ($hasCursor) {
            $operator = 'asc' === $queryOrder ? '>' : '<';

            if ($useComposite && null !== $cursorKey) {
                // The tie-break compares the key against the *key* component of the
                // cursor, strictly: an inclusive comparison re-serves the cursor row.
                $builder->where(function (Builder $q) use ($cursorColumn, $primaryKey, $cursor, $cursorKey, $operator): void {
                    $q->where($cursorColumn, $operator, $cursor)
                        ->orWhere(function (Builder $q2) use ($cursorColumn, $primaryKey, $cursor, $cursorKey, $operator): void {
                            $q2->where($cursorColumn, '=', $cursor)
                                ->where($primaryKey, $operator, $cursorKey);
                        });
                });
            } else {
                $builder->where($cursorColumn, $operator, $cursor);
            }
        }

        // Keyset paging needs a total order: without the key as a tie-break, rows
        // sharing a cursor value come back in an arbitrary order and can be
        // skipped or repeated across pages.
        if ($useComposite) {
            $builder->orderBy($primaryKey, $queryOrder);
        }

        $data = array_values($builder->limit($perPage)->get()->all());
        if ($readBackwards) {
            $data = array_reverse($data);
        }

        $nextCursor = null;
        $prevCursor = null;
        if ([] !== $data) {
            $nextCursor = self::cursorFor($data[count($data) - 1], $cursorColumn, $primaryKey, $useComposite);
            $prevCursor = self::cursorFor($data[0], $cursorColumn, $primaryKey, $useComposite);
        }

        foreach ($data as $row) {
            foreach ($addedColumns as $column) {
                unset($row->{$column});
            }
        }

        $meta = [
            'cursor' => $nextCursor,
            'next_cursor' => $nextCursor,
            'prev_cursor' => $prevCursor,
            'direction' => $direction,
            'cursor_column' => $cursorColumn,
            'total' => $total,
            'first_cursor' => null,
            'last_cursor' => $lastCursor,
        ];

        return [$data, $meta, ['X-Cursor' => (string) ($nextCursor ?? '')], $total];
    }

    /**
     * Encode a cursor value together with its tie-breaking key.
     */
    public static function encode(mixed $cursorValue, mixed $keyValue): string
    {
        $json = json_encode(['v' => $cursorValue, 'k' => $keyValue]);
        if (!is_string($json)) {
            return (string) $cursorValue;
        }

        return self::COMPOUND_PREFIX . rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * Split a cursor into its value and key. Anything that is not a compound
     * token comes back with a null key.
     *
     * @return array{0: mixed, 1: mixed}
     */
    public static function decode(mixed $cursor): array
    {
        if (!is_string($cursor) || !str_starts_with($cursor, self::COMPOUND_PREFIX)) {
            return [$cursor, null];
        }

        $binary = base64_decode(strtr(substr($cursor, strlen(self::COMPOUND_PREFIX)), '-_', '+/'), true);
        if (false === $binary) {
            return [$cursor, null];
        }

        $decoded = json_decode($binary, true);
        if (!is_array($decoded) || !array_key_exists('v', $decoded) || !array_key_exists('k', $decoded)) {
            return [$cursor, null];
        }

        return [$decoded['v'], $decoded['k']];
    }

    private static function cursorFor(object $row, string $cursorColumn, string $primaryKey, bool $useComposite): mixed
    {
        $value = $row->{$cursorColumn} ?? null;
        $key = $row->{$primaryKey} ?? null;

        return $useComposite && null !== $value && null !== $key ? self::encode($value, $key) : $value;
    }

    /**
     * Add $columns to an explicit column list that lacks them, and return the
     * ones added. A query selecting `*` (or no list) already loads them; a
     * DISTINCT query is left alone, because an extra column changes its rows.
     *
     * @param list<string> $columns
     * @return list<string>
     */
    private static function ensureSelected(Builder $builder, array $columns): array
    {
        $selected = $builder->columns;
        if (empty($selected) || $builder->distinct) {
            return [];
        }

        $loaded = [];
        foreach ($selected as $column) {
            // A raw expression is not a string and names no column.
            if (!is_string($column)) {
                continue;
            }

            $column = trim($column);
            if ('*' === $column || str_ends_with($column, '.*')) {
                return [];
            }

            $parts = preg_split('/\s+as\s+/i', $column);
            $name = trim((string) end($parts));
            $loaded[] = str_contains($name, '.') ? substr($name, strrpos($name, '.') + 1) : $name;
        }

        $added = [];
        foreach (array_unique($columns) as $column) {
            if (!in_array($column, $loaded, true)) {
                $added[] = $column;
            }
        }

        if ([] !== $added) {
            $builder->addSelect(self::qualified($builder, $added));
        }

        return $added;
    }

    /**
     * @param list<string> $columns
     * @return list<string>
     */
    private static function qualified(Builder $builder, array $columns): array
    {
        $table = is_string($builder->from) && !str_contains(trim($builder->from), ' ') ? $builder->from : null;

        return array_map(static fn(string $column): string => null === $table || str_contains($column, '.') ? $column : $table . '.' . $column, $columns);
    }
}
