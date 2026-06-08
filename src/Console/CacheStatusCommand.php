<?php

declare(strict_types=1);

namespace Sopheak\Core\Console;

use Illuminate\Console\Command;
use Sopheak\Core\Services\QueryCacheService;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * Show current cache namespace version state for one table or all tables.
 * Useful for debugging "stale cache" reports: confirms whether the
 * namespace-bump invalidation actually hit the right scope.
 *
 * Usage:
 *   php artisan sp-laravel-api:cache:status
 *   php artisan sp-laravel-api:cache:status products
 */
class CacheStatusCommand extends Command
{
    protected $signature = 'sp-laravel-api:cache:status
                            {table? : Optional table name to inspect}';

    protected $description = 'Show current cache namespace version state for the records API.';

    public function handle(): int
    {
        if (!RecordConfigService::cacheEnabled()) {
            $this->warn('Records API cache is disabled (record.cache.enabled = false). Nothing to inspect.');

            return self::SUCCESS;
        }

        $table = $this->argument('table');

        if (null !== $table) {
            return $this->inspectTable((string) $table);
        }

        $registry = SchemaRegistryUtils::get();

        if ([] === $registry) {
            $this->info('No tables registered.');

            return self::SUCCESS;
        }

        foreach (array_keys($registry) as $name) {
            $this->inspectTable((string) $name);
            $this->newLine();
        }

        return self::SUCCESS;
    }

    private function inspectTable(string $table): int
    {
        $globalVersion = QueryCacheService::inspectNamespaceVersion(scope: 'table', name: $table);
        $tenantKey = RecordConfigService::enableTenantId() ? 'acme' : null;

        $this->line(sprintf('<info>Table: %s</info>', $table));
        $this->line(sprintf('  Global namespace:       sp_laravel_api:ns:table:%s = v%d', $table, $globalVersion));

        if (null !== $tenantKey) {
            $tenantVersion = QueryCacheService::inspectNamespaceVersion(scope: 'table', name: $table, tenantKey: $tenantKey);
            $this->line(sprintf('  Tenant namespace:       sp_laravel_api:ns:table:%s:tenant:%s = v%d', $table, $tenantKey, $tenantVersion));
        }

        return self::SUCCESS;
    }
}
