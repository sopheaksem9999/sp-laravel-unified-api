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

    public function cursorPaginate(?string $cursor, string $direction = 'next', ?string $cursorColumn = null, int $perPage = 25): array
    {
        $cursorColumn ??= RecordConfigService::cursorDefaultColumn();
        $cursorOperator = $direction === 'next' ? '>' : '<';
        $sortOrder = $direction === 'next' ? 'asc' : 'desc';
        $maxPerPage = RecordConfigService::perPageMax();
        $perPage = max(1, min($perPage, $maxPerPage));

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
            ],
            'headers' => ['X-Cursor' => (string) ($nextCursor ?? '')],
            'total' => 0,
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
        $actualCount = count($data);

        $headers = ['X-Total-Count' => (string) ($total > 0 ? $total : $actualCount)];
        if ($total > 0) {
            $headers['X-Page'] = (string) $page;
            $headers['X-Per-Page'] = (string) $perPage;
            $headers['X-Total-Pages'] = (string) ceil($total / $perPage);
        }

        $meta = $total > 0
            ? ['page' => $page, 'per_page' => $perPage, 'total' => $total]
            : ['total' => $actualCount];

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
                perPage: $perPage
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
}
