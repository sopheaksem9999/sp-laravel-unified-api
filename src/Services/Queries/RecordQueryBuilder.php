<?php

namespace Sopheak\Core\Services\Queries;

use Illuminate\Support\Facades\DB;
use Sopheak\Core\Types\RecordTableType;

class RecordQueryBuilder
{
    public function __construct(
        protected string $table,
        protected RecordTableType $config,
        protected ?string $tenantId = null
    ) {
    }

    public function buildBaseQuery(array $requestedFields = []): \Illuminate\Database\Query\Builder
    {
        $query = DB::table($this->table);

        if ($this->config->hasTenantId && $this->tenantId) {
            $tenantColumn = $this->config->tenantColumn ?? 'company_id';
            $query->where($tenantColumn, $this->tenantId);
        }

        if (!empty($requestedFields)) {
            $primaryKey = $this->config->primaryKey ?? 'id';
            $fields = array_unique(array_merge($requestedFields, [$primaryKey]));
            $query->select($fields);
        }

        // Support softDeletes from RecordTableType or softDelete from prompt
        $softDelete = $this->config->softDeletes ?? ($this->config->softDelete ?? false);
        if ($softDelete) {
            $query->whereNull($this->table . '.deleted_at');
        }

        return $query;
    }
}
