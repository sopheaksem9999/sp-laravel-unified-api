<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Support\Facades\Config;
use Sopheak\Core\Services\QueryCacheService;
use Sopheak\Core\Tests\TestCase;

class CacheStatusCommandTest extends TestCase
{
    public function test_cache_status_command_shows_namespace_version_keys(): void
    {
        Config::set('record.cache.enabled', true);
        Config::set('record.cache.prefix', 'sp_laravel_api');

        // Pre-populate a version key for the products table
        QueryCacheService::invalidateTable('products');

        $this->artisan('sp-laravel-api:cache:status', ['table' => 'products'])
            ->expectsOutputToContain('products')
            ->assertExitCode(0);
    }

    public function test_cache_status_command_lists_all_tables_when_no_argument(): void
    {
        $this->artisan('sp-laravel-api:cache:status')
            ->assertExitCode(0);
    }

    public function test_cache_status_command_warns_when_cache_disabled(): void
    {
        Config::set('record.cache.enabled', false);

        $this->artisan('sp-laravel-api:cache:status', ['table' => 'products'])
            ->expectsOutputToContain('cache is disabled')
            ->assertExitCode(0);
    }
}
