<?php

declare(strict_types=1);

namespace Sopheak\Core\Services;

use Illuminate\Http\Request;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\RecordUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class RecordCacheService
{
    public function isCacheableRequest(Request $request, string $table): bool
    {
        if (!RecordConfigService::cacheEnabled()) {
            return false;
        }

        $perTableCache = RecordConfigService::cachePerTable();
        $schema = SchemaRegistryUtils::get();
        $tableSchema = $schema[$table] ?? null;

        $schemaTableName = null;
        if (is_object($tableSchema)) {
            $schemaTableName = $tableSchema->table ?? null;
        } elseif (is_array($tableSchema)) {
            $schemaTableName = $tableSchema['table'] ?? null;
        }

        if ((isset($perTableCache[$table]) && false === $perTableCache[$table]) || (null !== $schemaTableName && isset($perTableCache[$schemaTableName]) && false === $perTableCache[$schemaTableName])) {
            return false;
        }

        $disableCache = false;
        if (is_object($tableSchema)) {
            $disableCache = (bool) ($tableSchema->disableCache ?? false);
        } elseif (is_array($tableSchema)) {
            $disableCache = (bool) ($tableSchema['disableCache'] ?? false);
        }

        if ($disableCache) {
            return false;
        }

        if ('GET' !== $request->method()) {
            return false;
        }

        return !$request->has(['search', 'filter', 'where']);
    }

    public function generateOptimizedCacheKey(string $table, array $filters, array $includes, int $page, int $limit, bool $tenantEnabled): string
    {
        $tenantColumn = RecordConfigService::tenantColumn();
        $tenantKey = $this->resolveTenantCacheKey(tenantId: $filters[$tenantColumn] ?? null, tenantEnabled: $tenantEnabled);
        $this->recursiveKsort($filters);
        $this->recursiveKsort($includes);
        $keyData = [
            'filters' => $filters,
            'includes' => $includes,
            'page' => $page,
            'limit' => $limit,
            'tenant_enabled' => $tenantEnabled,
        ];

        return sprintf('record_index:table:%s:tenant:%s:hash:%s', $table, $tenantKey, md5(serialize($keyData)));
    }

    public function generateCursorCacheKey(string $table, array $filters, array $includes, string $cursor, string $direction, string $cursorColumn, int $limit, bool $tenantEnabled): string
    {
        $tenantColumn = RecordConfigService::tenantColumn();
        $tenantKey = $this->resolveTenantCacheKey(tenantId: $filters[$tenantColumn] ?? null, tenantEnabled: $tenantEnabled);
        $this->recursiveKsort($filters);
        $this->recursiveKsort($includes);
        $keyData = [
            'filters' => $filters,
            'includes' => $includes,
            'cursor' => $cursor,
            'direction' => $direction,
            'cursor_column' => $cursorColumn,
            'limit' => $limit,
            'tenant_enabled' => $tenantEnabled,
        ];

        return sprintf('record_cursor:table:%s:tenant:%s:hash:%s', $table, $tenantKey, md5(serialize($keyData)));
    }

    public function generateRecordCacheKey(string $table, mixed $id, mixed $tenantId, mixed $select, bool $tenantEnabled): string
    {
        $tenantKey = $this->resolveTenantCacheKey(tenantId: $tenantId, tenantEnabled: $tenantEnabled);
        if (is_array($select)) {
            $this->recursiveKsort($select);
        }
        $keyData = [
            'id' => $id,
            'select' => $select,
            'tenant_enabled' => $tenantEnabled,
        ];

        return sprintf('record_show:table:%s:id:%s:tenant:%s:select:%s', $table, $id, $tenantKey, md5(serialize($keyData)));
    }

    public function generateTableFunctionCacheKey(string $table, string $functionName, array $queryParams, mixed $tenantId, bool $tenantEnabled): string
    {
        $tenantKey = $this->resolveTenantCacheKey(tenantId: $tenantId, tenantEnabled: $tenantEnabled);
        $this->recursiveKsort($queryParams);
        $keyData = [
            'function' => $functionName,
            'query' => $queryParams,
            'tenant_enabled' => $tenantEnabled,
        ];

        return sprintf('record_func:table:%s:function:%s:tenant:%s:hash:%s', $table, $functionName, $tenantKey, md5(serialize($keyData)));
    }

    public function generateGlobalFunctionCacheKey(string $functionName, array $queryParams, mixed $tenantId, bool $tenantEnabled): string
    {
        $tenantKey = $this->resolveTenantCacheKey(tenantId: $tenantId, tenantEnabled: $tenantEnabled);
        $this->recursiveKsort($queryParams);
        $keyData = [
            'function' => $functionName,
            'query' => $queryParams,
            'tenant_enabled' => $tenantEnabled,
        ];

        return sprintf('record_func_global:function:%s:tenant:%s:hash:%s', $functionName, $tenantKey, md5(serialize($keyData)));
    }

    public function invalidateTableCache(string $table, mixed $tenantId, bool $tenantEnabled): void
    {
        $tenantKey = $this->resolveTenantCacheKey(tenantId: $tenantId, tenantEnabled: $tenantEnabled);
        QueryCacheService::invalidateTableForTenant(table: $table, tenantKey: $tenantKey);
    }

    public function clearTableCache(string $table, mixed $tenantId = null): void
    {
        $tableSchema = SchemaRegistryUtils::getTable($table);
        $tenantEnabled = $tableSchema instanceof RecordTableType ? RecordUtils::shouldApplyTenantId($tableSchema) : RecordConfigService::enableTenantId();
        $cacheTenantId = $tenantEnabled ? RecordUtils::normalizeTenantId($tenantId) : null;
        $this->invalidateTableCache($table, $cacheTenantId, $tenantEnabled);
    }

    public function clearCacheForTables(array|string|null $tables, mixed $tenantId = null): void
    {
        if (null === $tables || [] === $tables || '' === $tables) {
            return;
        }

        $tableList = is_array($tables) ? $tables : array_filter(array_map(trim(...), explode(',', $tables)));
        foreach ($tableList as $table) {
            if (!is_string($table)) {
                continue;
            }

            if ('' === $table) {
                continue;
            }

            $this->clearTableCache($table, $tenantId);
        }
    }

    public function invalidateTableFunctionCache(string $table, string $functionName, mixed $tenantId, bool $tenantEnabled): void
    {
        $tenantKey = $this->resolveTenantCacheKey(tenantId: $tenantId, tenantEnabled: $tenantEnabled);
        QueryCacheService::invalidateTableFunctionForTenant(table: $table, functionName: $functionName, tenantKey: $tenantKey);
    }

    public function invalidateGlobalFunctionCache(string $functionName, mixed $tenantId, bool $tenantEnabled): void
    {
        $tenantKey = $this->resolveTenantCacheKey(tenantId: $tenantId, tenantEnabled: $tenantEnabled);
        QueryCacheService::invalidateGlobalFunctionForTenant(functionName: $functionName, tenantKey: $tenantKey);
    }

    public function invalidateRecordCache(string $table, mixed $id, mixed $tenantId, bool $tenantEnabled): void
    {
        $tenantKey = $this->resolveTenantCacheKey(tenantId: $tenantId, tenantEnabled: $tenantEnabled);
        QueryCacheService::invalidateRecordForTenant(table: $table, id: $id, tenantKey: $tenantKey);
    }

    public function calculateOptimalCacheTTL(string $table, int $recordCount, bool $hasRelationships): int
    {
        $baseTTL = RecordConfigService::cacheDefaultTtl();
        if ($recordCount > 100) {
            $baseTTL = (int) ($baseTTL * 0.5);
        }

        if ($hasRelationships) {
            $baseTTL = (int) ($baseTTL * 0.7);
        }

        $perTableTTL = RecordConfigService::cachePerTableTtl();
        if (isset($perTableTTL[$table])) {
            $baseTTL = $perTableTTL[$table];
        }

        return max($baseTTL, 300);
    }

    private function resolveTenantCacheKey(mixed $tenantId, bool $tenantEnabled): string
    {
        if (!$tenantEnabled) {
            return 'disabled';
        }

        if (null === $tenantId || '' === (string) $tenantId) {
            return 'missing';
        }

        return (string) $tenantId;
    }

    private function recursiveKsort(array &$array): void
    {
        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->recursiveKsort($value);
            }
        }

        ksort($array);
    }
}
