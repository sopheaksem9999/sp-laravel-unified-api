<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Services\QueryCacheService;
use Sopheak\Core\Services\RecordCacheService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class CacheInvalidationCorrectnessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('tenant_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Config::set('record.enable_tenant_id', true);
        Config::set('record.tenant_column', 'tenant_id');
        Config::set('record.tenant_header', 'X-Tenant-ID');
        Config::set('record.cache.enabled', true);
        Config::set('record.cache.prefix', 'sp_laravel_api');
        Config::set('record.cache.ttl', 3600);
        Config::set('record.tables', [
            'products' => new RecordTableType(
                table: 'products',
                pmsName: 'products',
                hasTenantId: true,
                softDeletes: true,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
            ),
        ]);

        SchemaRegistryUtils::refresh();
        Cache::flush();
    }

    public function test_table_clear_invalidates_cursor_paginated_list_cache(): void
    {
        $cursorKey = 'record_cursor:table:products:tenant:acme:hash:abc';

        QueryCacheService::put($cursorKey, ['data' => ['stale']], 3600);
        $this->assertNotNull(QueryCacheService::get($cursorKey));

        QueryCacheService::invalidateTableForTenant('products', 'acme');

        $this->assertNull(
            QueryCacheService::get($cursorKey),
            'A table-level clear must reach record_cursor: keys'
        );
    }
}
