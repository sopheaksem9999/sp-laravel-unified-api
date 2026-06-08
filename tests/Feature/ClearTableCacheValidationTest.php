<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Sopheak\Core\Services\RecordCacheService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class ClearTableCacheValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('tenant_id')->nullable();
            $table->timestamps();
        });

        Config::set('record.enable_tenant_id', true);
        Config::set('record.tenant_column', 'tenant_id');
        Config::set('record.cache.enabled', true);
        Config::set('record.cache.prefix', 'sp_laravel_api');
        Config::set('record.cache.ttl', 3600);
        Config::set('record.tables', [
            'products' => new RecordTableType(
                table: 'products',
                pmsName: 'products',
                hasTenantId: true,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
            ),
        ]);

        SchemaRegistryUtils::refresh();
        Cache::flush();
    }

    public function test_clear_table_cache_throws_when_tenant_enabled_but_tenant_id_missing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tenant');

        app(RecordCacheService::class)->clearTableCache('products');
    }

    public function test_clear_table_cache_throws_when_tenant_enabled_but_tenant_id_empty_string(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(RecordCacheService::class)->clearTableCache('products', '');
    }

    public function test_clear_table_cache_succeeds_when_tenant_id_provided(): void
    {
        // Should not throw
        app(RecordCacheService::class)->clearTableCache('products', 'acme');
        $this->assertTrue(true);
    }
}
