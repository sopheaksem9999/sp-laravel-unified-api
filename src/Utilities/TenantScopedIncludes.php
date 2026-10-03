<?php

declare(strict_types=1);

namespace Sopheak\Core\Utilities;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Sopheak\Core\Enums\RecordApiJsonResponseEnum;
use Sopheak\Core\Services\RecordApiResponseService;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Types\RecordTableType;

/**
 * Which tenant-scoped relationships a read asks to include.
 *
 * A table that is not tenant-scoped can still embed rows of one that is
 * (`owners` -> `pets`). With tenancy on and no tenant resolved those rows would
 * come back for every tenant, so the HTTP controllers and the MCP / AI SDK
 * tools refuse such a request, as they refuse a tenant-scoped table itself.
 *
 * The request is read with the relationship loader's own parser and resolver
 * (parseSelectForIncludes(), resolveRelationship()), so every include the
 * loader would load is seen: aliased (`pets:x(*)`), nested at any depth
 * (`houses(*,animals(*))`), and through a tenant-scoped pivot or through-table.
 */
final class TenantScopedIncludes
{
    /**
     * The include paths of $table that $queryParams asks for and that reach a
     * tenant-scoped table — `pets`, `houses.animals` — through `select`, `with`
     * or a relationship filter (`rel.column=…`).
     *
     * @param array<string, mixed> $queryParams
     * @return list<string>
     */
    public static function requested(string $table, array $queryParams): array
    {
        $paths = [];

        $select = self::combinedSelect($queryParams);
        if ('' !== $select) {
            self::collect($table, RelationshipResolverUtils::parseSelectForIncludes($select), '', $paths);
        }

        // Relationship filters are one level deep, `alias.column`: as a parameter
        // (QueryBuilderFiltersUtils::applyRelationshipFilters()), inside an `and` / `or` group, or a
        // `searchable` column a `search` parameter expands into.
        $filterColumns = [...array_keys($queryParams), ...QueryBuilderFiltersUtils::groupedFilterColumns($queryParams, $table)];
        foreach ($filterColumns as $key) {
            if (!is_string($key)) {
                continue;
            }

            if (!str_contains($key, '.')) {
                continue;
            }

            $alias = strstr($key, '.', true);
            $config = RelationshipResolverUtils::resolveRelationship($table, (string) $alias);
            if (is_array($config) && self::reachesTenantScopedTable($config)) {
                $paths[] = (string) $alias;
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * requested() for an HTTP request, read from the sources the loader and the
     * filters use: filters from the raw query string (dotted keys intact), and
     * `select` / `with` from the query bag, where a middleware or a hook may
     * have set them without touching the raw string.
     *
     * @return list<string>
     */
    public static function requestedBy(Request $request, string $table): array
    {
        $params = QueryBuilderFiltersUtils::parseQueryStringPreservingDots((string) $request->getQueryString());
        unset($params['with']);
        $params['select'] = RecordService::getCombinedSelectParam($request);

        return self::requested($table, $params);
    }

    /**
     * The 422 a request gets when tenancy is on, no tenant resolved, and it asks
     * for tenant-scoped rows through $paths — as a tenant-scoped table answers.
     *
     * @param list<string> $paths
     */
    public static function refusal(array $paths): JsonResponse
    {
        $header = RecordConfigService::tenantHeader();

        return RecordApiResponseService::errorWrapped('Validation failed', RecordApiJsonResponseEnum::VALIDATION_ERROR->value, [
            $header => [sprintf('header %s cannot be empty: it is required to include %s', $header, implode(', ', $paths))],
        ]);
    }

    /**
     * `select` and `with` as one include list, as RecordService::getCombinedSelectParam() builds it.
     *
     * @param array<string, mixed> $queryParams
     */
    private static function combinedSelect(array $queryParams): string
    {
        $parts = [];
        foreach (['select', 'with'] as $key) {
            foreach ((array) ($queryParams[$key] ?? []) as $value) {
                if (is_string($value) && '' !== trim($value)) {
                    $parts[] = $value;
                }
            }
        }

        return implode(',', $parts);
    }

    /**
     * @param array<string, mixed> $includes parseSelectForIncludes() output
     * @param list<string> $paths
     */
    private static function collect(string $table, array $includes, string $prefix, array &$paths): void
    {
        foreach ($includes as $alias => $include) {
            $include = is_array($include) ? $include : [];
            $config = RelationshipResolverUtils::resolveRelationship($table, (string) $alias, $include['table'] ?? null);
            if (!is_array($config)) {
                continue;
            }

            $path = $prefix . $alias;
            if (self::reachesTenantScopedTable($config)) {
                $paths[] = $path;
            }

            $children = $include['children'] ?? [];
            if (is_array($children) && [] !== $children && is_string($config['table'] ?? null)) {
                self::collect($config['table'], $children, $path . '.', $paths);
            }
        }
    }

    /**
     * @param array<string, mixed> $config resolveRelationship() output
     */
    private static function reachesTenantScopedTable(array $config): bool
    {
        foreach (['table', 'pivot_table', 'through_table'] as $key) {
            $related = is_string($config[$key] ?? null) ? SchemaRegistryUtils::getTable($config[$key]) : null;
            if ($related instanceof RecordTableType && RecordUtils::shouldApplyTenantId($related)) {
                return true;
            }
        }

        return false;
    }
}
