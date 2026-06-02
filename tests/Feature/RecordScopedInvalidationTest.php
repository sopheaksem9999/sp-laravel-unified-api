<?php

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Services\QueryCacheService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class RecordScopedInvalidationTest extends TestCase
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

    public function test_invalidate_record_for_tenant_only_busts_that_records_show_cache(): void
    {
        $keyRecord5 = 'record_show:table:products:id:5:tenant:acme:select:abc';
        $keyRecord7 = 'record_show:table:products:id:7:tenant:acme:select:abc';

        QueryCacheService::put($keyRecord5, ['id' => 5, 'name' => 'Widget5'], 3600);
        QueryCacheService::put($keyRecord7, ['id' => 7, 'name' => 'Widget7'], 3600);

        $this->assertNotNull(QueryCacheService::get($keyRecord5));
        $this->assertNotNull(QueryCacheService::get($keyRecord7));

        QueryCacheService::invalidateRecordForTenant('products', 5, 'acme');

        $this->assertNull(
            QueryCacheService::get($keyRecord5),
            'Record 5 cache should be invalidated after invalidateRecordForTenant'
        );
        $this->assertNotNull(
            QueryCacheService::get($keyRecord7),
            'Record 7 cache should NOT be invalidated by an invalidateRecordForTenant call for record 5'
        );
    }
}
