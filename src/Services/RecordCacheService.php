<?php

declare(strict_types=1);

namespace Sopheak\Core\Services;

use Illuminate\Http\Request;
use InvalidArgumentException;
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
            $candidateTableName = $tableSchema->table ?? null;
            $schemaTableName = is_string($candidateTableName) && '' !== $candidateTableName ? $candidateTableName : null;
        } elseif (is_array($tableSchema)) {
            $candidateTableName = $tableSchema['table'] ?? null;
            $schemaTableName = is_string($candidateTableName) && '' !== $candidateTableName ? $candidateTableName : null;
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

        // hasAny(), not has(): has() with an array is ALL-of, so it only ever
        // rejected a request carrying all three params at once.
        if ($request->hasAny(['search', 'filter', 'where'])) {
            return false;
        }

        return $this->passesAdmissionRules($request, $table, $schemaTableName);
    }

    /**
     * Table-less counterpart of isCacheableRequest() for global functions.
     *
     * Global functions have no table, so only_tables/except_tables cannot apply,
     * but the enable flag, method check, dynamic-query guard, action rules and
     * skip_query_params all must -- RecordService used to re-implement a partial
     * version of this inline and silently bypassed the last three.
     */
    public function isCacheableGlobalRequest(Request $request): bool
    {
        if (!RecordConfigService::cacheEnabled()) {
            return false;
        }

        if ('GET' !== $request->method()) {
            return false;
        }

        if ($request->hasAny(['search', 'filter', 'where'])) {
            return false;
        }

        if (!RecordConfigService::cacheAdmissionEnabled()) {
            return true;
        }

        $action = $this->resolveCacheAction($request);

        $onlyActions = array_filter(array_map(strval(...), RecordConfigService::cacheAdmissionOnlyActions()));
        if ([] !== $onlyActions && (null === $action || !in_array($action, $onlyActions, true))) {
            return false;
        }

        $exceptActions = array_filter(array_map(strval(...), RecordConfigService::cacheAdmissionExceptActions()));
        if (null !== $action && [] !== $exceptActions && in_array($action, $exceptActions, true)) {
            return false;
        }

        foreach (RecordConfigService::cacheAdmissionSkipQueryParams() as $param) {
            if (!is_string($param) && !is_int($param)) {
                continue;
            }

            if ($request->query->has((string) $param)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Stable fingerprint of the full request query.
     *
     * The consumers that actually shape a read -- with_trashed, only_trashed,
     * add_total, and anything added later -- read through input()/boolean(),
     * which sources from the parsed JSON body instead of the query string
     * whenever Content-Type: application/json is set (see
     * Request::getInputSource()). A fingerprint built from query() alone is
     * blind to that path: a JSON-body request and a plain one can carry an
     * empty query() while behaving completely differently. So this folds in
     * the JSON body too. Parameters are opt-out, not opt-in: forgetting to
     * list one can only split a cache entry, never merge two that should
     * differ.
     */
    public function queryFingerprint(Request $request): string
    {
        $query = $request->json()->all() + $request->query();
        $this->recursiveKsort($query);

        return md5(serialize($query));
    }

    private function passesAdmissionRules(Request $request, string $table, ?string $schemaTableName): bool
    {
        if (!RecordConfigService::cacheAdmissionEnabled()) {
            return true;
        }

        $tableNames = array_values(array_filter([$table, $schemaTableName], static fn(?string $value): bool => null !== $value && '' !== $value));

        $onlyTables = array_filter(array_map(strval(...), RecordConfigService::cacheAdmissionOnlyTables()));
        if ([] !== $onlyTables && [] === array_intersect($tableNames, $onlyTables)) {
            return false;
        }

        $exceptTables = array_filter(array_map(strval(...), RecordConfigService::cacheAdmissionExceptTables()));
        if ([] !== $exceptTables && [] !== array_intersect($tableNames, $exceptTables)) {
            return false;
        }

        $action = $this->resolveCacheAction($request);
        $onlyActions = array_filter(array_map(strval(...), RecordConfigService::cacheAdmissionOnlyActions()));
        if ([] !== $onlyActions && (null === $action || !in_array($action, $onlyActions, true))) {
            return false;
        }

        $exceptActions = array_filter(array_map(strval(...), RecordConfigService::cacheAdmissionExceptActions()));
        if (null !== $action && [] !== $exceptActions && in_array($action, $exceptActions, true)) {
            return false;
        }

        foreach (RecordConfigService::cacheAdmissionSkipQueryParams() as $param) {
            if (!is_string($param) && !is_int($param)) {
                continue;
            }

            if ($request->query->has((string) $param)) {
                return false;
            }
        }

        return true;
    }

    private function resolveCacheAction(Request $request): ?string
    {
        $attributeAction = $request->attributes->get('record_cache_action');
        if (is_string($attributeAction) && '' !== $attributeAction) {
            return $attributeAction;
        }

        $route = $request->route();
        if (!is_object($route) || !method_exists($route, 'getActionMethod')) {
            return null;
        }

        $actionMethod = $route->getActionMethod();
        if (!is_string($actionMethod)) {
            return null;
        }

        return match ($actionMethod) {
            'listRecords' => 'list',
            'getRecordById' => 'show',
            'executeTableFunction', 'executeTableFunctionWithId' => 'table_function',
            'executeGlobalFunction' => 'global_function',
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function generateOptimizedCacheKey(string $table, array $filters, array $includes, int $page, int $limit, bool $tenantEnabled, string $queryFingerprint = ''): string
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
            'query' => $queryFingerprint,
        ];

        return sprintf('record_index:table:%s:tenant:%s:hash:%s', $table, $tenantKey, md5(serialize($keyData)));
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function generateCursorCacheKey(string $table, array $filters, array $includes, string $cursor, string $direction, string $cursorColumn, int $limit, bool $tenantEnabled, string $queryFingerprint = ''): string
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
            'query' => $queryFingerprint,
        ];

        return sprintf('record_cursor:table:%s:tenant:%s:hash:%s', $table, $tenantKey, md5(serialize($keyData)));
    }

    public function generateRecordCacheKey(string $table, mixed $id, mixed $tenantId, mixed $select, bool $tenantEnabled, string $queryFingerprint = ''): string
    {
        $tenantKey = $this->resolveTenantCacheKey(tenantId: $tenantId, tenantEnabled: $tenantEnabled);
        if (is_array($select)) {
            $this->recursiveKsort($select);
        }

        $keyData = [
            'id' => $id,
            'select' => $select,
            'tenant_enabled' => $tenantEnabled,
            'query' => $queryFingerprint,
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

        if ($tenantEnabled && (null === $cacheTenantId || '' === $cacheTenantId)) {
            throw new InvalidArgumentException(sprintf(
                'Cannot clear cache for table [%s]: tenant is enabled but no tenantId was provided. '
                . 'Pass the resolved tenant id (e.g. from the request via RecordUtils::resolveTenantIdFromRequest).',
                $table
            ));
        }

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

    /**
     * Turn a function's declared clearCacheTables into a cache dependency list.
     *
     * clearCacheTables already tells us which tables a function touches on write;
     * the same list is what its cached result depends on for reads. Declaring it
     * both ways is what makes a CRUD write to one of those tables invalidate the
     * function's cached output.
     *
     * @return array<int, array<string, string|null>>
     */
    public function functionCacheDependencies(array|string|null $tables, mixed $tenantId, bool $tenantEnabled): array
    {
        if (null === $tables || [] === $tables || '' === $tables) {
            return [];
        }

        $tableList = is_array($tables) ? $tables : array_filter(array_map(trim(...), explode(',', $tables)));
        $tenantKey = $this->resolveTenantCacheKey(tenantId: $tenantId, tenantEnabled: $tenantEnabled);

        $dependencies = [];
        foreach ($tableList as $table) {
            if (!is_string($table) || '' === $table) {
                continue;
            }

            $dependencies[] = ['scope' => 'table', 'name' => $table, 'tenant' => $tenantKey];
        }

        return $dependencies;
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
        $baseTTL = RecordConfigService::cacheTtl();
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

    /**
     * @param array<string, mixed> $array
     */
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
