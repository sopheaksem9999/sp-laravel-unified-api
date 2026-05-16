<?php

namespace Sopheak\Core\Services\Queries;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\QueryBuilderFiltersUtils;
use Sopheak\Core\Utilities\RelationshipResolverUtils;

class RecordQueryBuilder
{
    protected Builder $builder;

    protected ?string $primaryKey = null;

    protected ?string $softDeleteColumn = null;

    protected bool $softDeletesEnabled = false;

    public function __construct(
        protected string $table,
        protected RecordTableType $config,
        protected mixed $tenantId = null
    ) {
        $this->primaryKey = $this->config->primaryKey ?? 'id';
        $this->buildBaseQuery();
    }

    public function buildBaseQuery(array $requestedFields = []): static
    {
        $connection = RecordConfigService::readConnection();
        $this->builder = $connection
            ? DB::connection($connection)->table($this->table)
            : DB::table($this->table);

        if ($this->config->hasTenantId && $this->tenantId) {
            $tenantColumn = $this->config->tenantColumn ?? 'company_id';
            $this->builder->where($tenantColumn, $this->tenantId);
        }

        if (!empty($requestedFields)) {
            $fields = array_unique(array_merge($requestedFields, [$this->primaryKey]));
            $this->builder->select($fields);
        }

        $softDelete = $this->config->softDeletes ?? ($this->config->softDelete ?? false);
        if ($softDelete) {
            $this->softDeletesEnabled = true;
            $this->softDeleteColumn = $this->table . '.deleted_at';
            $this->builder->whereNull($this->softDeleteColumn);
        }

        return $this;
    }

    public function withTrashed(bool $withTrashed = true): static
    {
        if (!$this->softDeletesEnabled) {
            return $this;
        }

        if ($withTrashed) {
            $this->builder->whereNull($this->softDeleteColumn);
        }

        return $this;
    }

    public function onlyTrashed(): static
    {
        if ($this->softDeletesEnabled && $this->softDeleteColumn) {
            $this->builder->whereNotNull($this->softDeleteColumn);
        }

        return $this;
    }

    public function distinct(): static
    {
        $this->builder->distinct();

        return $this;
    }

    public function applyFilters(Request $request): static
    {
        QueryBuilderFiltersUtils::apply(
            $this->builder,
            $request,
            $this->table,
            $this->primaryKey
        );

        return $this;
    }

    public function applySelectFromParam(string $selectParam): static
    {
        if ($selectParam === '') {
            return $this;
        }

        $mainCols = RelationshipResolverUtils::getMainTableColumns($selectParam);
        if (!empty($mainCols)) {
            $attributeKeys = array_keys($this->config->attributes ?? []);
            $dbMainCols = $attributeKeys !== []
                ? array_values(array_diff($mainCols, $attributeKeys))
                : $mainCols;
            if (!empty($dbMainCols) && !in_array('*', $dbMainCols, true)) {
                $this->builder->addSelect($dbMainCols);
            }
        }

        return $this;
    }

    public function applyIndexHint(string $context = 'list'): static
    {
        $hints = RecordConfigService::tableIndexHints($this->table);
        $index = $hints[$context] ?? null;
        if ($index !== null) {
            $this->builder->from(DB::raw($this->builder->from . ' FORCE INDEX (' . $index . ')'));
        }

        return $this;
    }

    public function cursorPaginate(?string $cursor, string $direction = 'next', ?string $cursorColumn = null, int $perPage = 25, bool $skipTotal = false, string $sortOrder = 'desc'): array
    {
        $cursorColumn ??= RecordConfigService::cursorDefaultColumn();
        $maxPerPage = RecordConfigService::perPageMax();
        $perPage = max(1, min($perPage, $maxPerPage));

        $isUuidColumn = self::isUuidColumn($this->config, $cursorColumn);

        // Normalize cursor: cast numeric strings to int for index-friendly comparisons (skip UUID columns)
        if (!$isUuidColumn && null !== $cursor && '' !== $cursor && ctype_digit((string) $cursor)) {
            $cursor = (int) $cursor;
        }

        // Count total matching records before cursor filtering
        $total = 0;
        $firstCursor = null;
        $lastCursor = null;

        if (!$skipTotal) {
            $total = (clone $this->builder)->count();

            if ($total > $perPage && RecordConfigService::cursorBoundaryEnabled()) {
                $lastPageSize = $total % $perPage;
                $lastPageSize = 0 === $lastPageSize ? $perPage : $lastPageSize;

                // Get the cursor at the start of the last page using O(per_page) query
                $boundaryRows = (clone $this->builder)
                    ->select($cursorColumn)
                    ->reorder()
                    ->orderBy($cursorColumn, 'desc')
                    ->limit($lastPageSize + 1)
                    ->get();

                $boundaryMin = $boundaryRows->min($cursorColumn);
                $lastCursor = null !== $boundaryMin ? $boundaryMin : null;
            }
        }

        $hasCursor = null !== $cursor && '' !== $cursor && 0 !== $cursor;

        // For UUID columns, only apply cursor filter when the cursor is a valid UUID
        if ($isUuidColumn && $hasCursor) {
            if (!is_string($cursor) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $cursor)) {
                $hasCursor = false;
                $cursor = null;
            }
        }

        if ($hasCursor) {
            $this->builder->reorder();

            $sortOrder = in_array(strtolower($sortOrder), ['asc', 'desc'], true) ? strtolower($sortOrder) : 'desc';

            $cursorOperator = match (true) {
                'asc' === $sortOrder && 'next' === $direction => '>',
                'asc' === $sortOrder && 'prev' === $direction => '<',
                'desc' === $sortOrder && 'next' === $direction => '<',
                'desc' === $sortOrder && 'prev' === $direction => '>',
                default => '>',
            };

            if (RecordConfigService::cursorCompositeEnabled() && $cursorColumn !== $this->primaryKey) {
                $this->builder->where(function ($q) use ($cursorColumn, $cursor, $cursorOperator) {
                    $q->where($cursorColumn, $cursorOperator, $cursor)
                      ->orWhere(function ($q2) use ($cursorColumn, $cursor, $cursorOperator) {
                          $q2->where($cursorColumn, '=', $cursor)
                             ->where($this->primaryKey, $cursorOperator === '>' ? '>=' : '<=', $cursor);
                      });
                });
                $this->builder->orderBy($cursorColumn, $sortOrder)
                              ->orderBy($this->primaryKey, $sortOrder);
            } else {
                $this->builder->where($cursorColumn, $cursorOperator, $cursor)
                              ->orderBy($cursorColumn, $sortOrder);
            }
        }

        $data = $this->builder->limit($perPage)->get()->all();
        $nextCursor = count($data) > 0
            ? $data[count($data) - 1]->{$cursorColumn} ?? null
            : null;

        return [
            'data' => $data,
            'meta' => [
                'cursor' => $nextCursor,
                'direction' => $direction,
                'cursor_column' => $cursorColumn,
                'total' => $total,
                'first_cursor' => $firstCursor,
                'last_cursor' => $lastCursor,
            ],
            'headers' => ['X-Cursor' => (string) ($nextCursor ?? '')],
            'total' => $total,
        ];
    }

    public function offsetPaginate(int $page = 1, int $perPage = 25, bool $skipTotal = false): array
    {
        $maxPerPage = RecordConfigService::perPageMax();
        $perPage = max(1, min($perPage, $maxPerPage));
        $page = max(1, $page);

        if ($skipTotal) {
            $total = 0;
        } else {
            $countQuery = clone $this->builder;
            $total = $countQuery->count();
        }

        $data = $this->builder->forPage($page, $perPage)->get()->all();

        if ($skipTotal) {
            $headers = [];
            $meta = ['page' => $page, 'per_page' => $perPage];
        } elseif ($total > 0) {
            $headers = [
                'X-Total-Count' => (string) $total,
                'X-Page' => (string) $page,
                'X-Per-Page' => (string) $perPage,
                'X-Total-Pages' => (string) ceil($total / $perPage),
            ];
            $meta = ['page' => $page, 'per_page' => $perPage, 'total' => $total];
        } else {
            $headers = ['X-Total-Count' => '0'];
            $meta = ['total' => 0];
        }

        return ['data' => $data, 'meta' => $meta, 'headers' => $headers, 'total' => $total];
    }

    public function paginate(Request $request): array
    {
        $pKey = $this->config->primaryKey ?? 'id';
        $defaultMode = RecordConfigService::paginationDefaultMode();

        $useCursor = $request->has('cursor') || $defaultMode === 'cursor';
        $maxPerPage = RecordConfigService::perPageMax();
        $perPage = max(1, min((int) $request->input('per_page', 25), $maxPerPage));

        if ($useCursor) {
            return $this->cursorPaginate(
                cursor: $request->input('cursor'),
                direction: $request->input('direction', 'next'),
                cursorColumn: $request->input('cursor_column'),
                perPage: $perPage,
                skipTotal: $request->boolean('skip_total', RecordConfigService::skipTotalDefault()),
                sortOrder: $request->input('order', 'desc')
            );
        }

        return $this->offsetPaginate(
            page: (int) $request->input('page', 1),
            perPage: $perPage,
            skipTotal: $request->boolean('skip_total', RecordConfigService::skipTotalDefault())
        );
    }

    public function getBuilder(): Builder
    {
        return $this->builder;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getPrimaryKey(): string
    {
        return $this->primaryKey;
    }

    public function getConfig(): RecordTableType
    {
        return $this->config;
    }

    private static function isUuidColumn(?RecordTableType $config, string $column): bool
    {
        if ($config === null) {
            return false;
        }

        $colDef = $config->columns[$column] ?? null;
        if ($colDef === null) {
            return false;
        }

        return ($colDef['type'] ?? '') === 'uuid' || ($colDef['udt_name'] ?? '') === 'uuid';
    }
}
