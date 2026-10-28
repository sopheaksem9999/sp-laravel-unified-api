<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class UpsertRecordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('sku')->unique();
            $table->string('name');
            $table->integer('price');
            $table->timestamps();
        });

        Config::set('record.tables', [
            'products' => new RecordTableType(
                table: 'products',
                canUpsert: true,
                public: new RecordTablePublic(
                    read: true,
                    write: true
                ),
            ),
        ]);

        SchemaRegistryUtils::refresh();
    }

    /** @test */
    public function it_can_upsert_single_record_create(): void
    {
        $payload = [
            'sku' => 'SKU-001',
            'name' => 'Test Product',
            'price' => 100,
        ];

        $response = $this->postJson('/api/products/upsert?match_on=sku', $payload);

        $response->assertStatus(200);
        $this->assertDatabaseHas('products', ['sku' => 'SKU-001', 'name' => 'Test Product']);
    }

    /** @test */
    public function it_can_upsert_single_record_update(): void
    {
        DB::table('products')->insert([
            'sku' => 'SKU-001',
            'name' => 'Old Name',
            'price' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'sku' => 'SKU-001',
            'name' => 'New Name',
            'price' => 100,
        ];

        $response = $this->postJson('/api/products/upsert?match_on=sku', $payload);

        $response->assertStatus(200);
        $this->assertDatabaseHas('products', ['sku' => 'SKU-001', 'name' => 'New Name', 'price' => 100]);
        $this->assertDatabaseCount('products', 1);
    }

    /** @test */
    public function it_fails_upsert_without_match_on(): void
    {
        $payload = [
            'sku' => 'SKU-001',
            'name' => 'Test',
            'price' => 100,
        ];

        $response = $this->postJson('/api/products/upsert', $payload);

        $response->assertStatus(422); // Validation error
    }

    /** @test */
    public function it_can_bulk_upsert_via_bulk_endpoint_mixed_operations(): void
    {
        // Pre-existing
        DB::table('products')->insert([
            'sku' => 'SKU-EXISTING',
            'name' => 'Existing',
            'price' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'items' => [
                [
                    'operation' => 'upsert',
                    'sku' => 'SKU-EXISTING',
                    'name' => 'Updated Existing',
                    'price' => 75,
                ],
                [
                    'operation' => 'upsert',
                    'sku' => 'SKU-NEW',
                    'name' => 'New Product',
                    'price' => 150,
                ],
            ],
        ];

        $response = $this->postJson('/api/products/bulk?match_on=sku', $payload);

        $response->assertStatus(200);

        $this->assertDatabaseHas('products', ['sku' => 'SKU-EXISTING', 'name' => 'Updated Existing', 'price' => 75]);
        $this->assertDatabaseHas('products', ['sku' => 'SKU-NEW', 'name' => 'New Product']);
        $this->assertDatabaseCount('products', 2);
    }

    /** @test */
    public function it_can_bulk_upsert_via_dedicated_endpoint(): void
    {
        // Pre-existing
        DB::table('products')->insert([
            'sku' => 'SKU-EXISTING',
            'name' => 'Existing',
            'price' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            [
                'sku' => 'SKU-EXISTING',
                'name' => 'Updated via Bulk',
                'price' => 80,
            ],
            [
                'sku' => 'SKU-NEW-BULK',
                'name' => 'New Bulk Product',
                'price' => 200,
            ],
        ];

        $response = $this->postJson('/api/products/bulk/upsert?match_on=sku', $payload);

        $response->assertStatus(200);

        $this->assertDatabaseHas('products', ['sku' => 'SKU-EXISTING', 'name' => 'Updated via Bulk']);
        $this->assertDatabaseHas('products', ['sku' => 'SKU-NEW-BULK', 'name' => 'New Bulk Product']);
        $this->assertDatabaseCount('products', 2);
    }

    /** @test */
    public function upsert_on_tenant_scoped_table_forces_tenant_column_into_match_on(): void
    {
        Config::set('record.enable_tenant_id', true);

        Schema::create('tenant_products', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('sku');
            $table->string('name');
            $table->integer('price');
            $table->timestamps();
            $table->unique(['tenant_id', 'sku']);
        });

        Config::set('record.tables.tenant_products', new RecordTableType(
            table: 'tenant_products',
            hasTenantId: true,
            canUpsert: true,
            public: new RecordTablePublic(
                read: true,
                write: true
            ),
        ));

        SchemaRegistryUtils::refresh();

        // Tenant 1 has SKU-001
        DB::table('tenant_products')->insert([
            'tenant_id' => 1,
            'sku' => 'SKU-001',
            'name' => 'Tenant 1 Product',
            'price' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Tenant 2 upserts SKU-001 with match_on=sku (omitting tenant_id in match_on)
        $payload = [
            'sku' => 'SKU-001',
            'name' => 'Tenant 2 Product',
            'price' => 200,
        ];

        // Send request as Tenant 2
        $response = $this->withHeaders(['X-Tenant-Id' => '2'])
            ->postJson('/api/tenant_products/upsert?match_on=sku', $payload);

        $response->assertStatus(200);

        // Tenant 1 record must be untouched
        $this->assertDatabaseHas('tenant_products', [
            'tenant_id' => 1,
            'sku' => 'SKU-001',
            'name' => 'Tenant 1 Product',
            'price' => 100,
        ]);

        // Tenant 2 record must be created
        $this->assertDatabaseHas('tenant_products', [
            'tenant_id' => 2,
            'sku' => 'SKU-001',
            'name' => 'Tenant 2 Product',
            'price' => 200,
        ]);

        $this->assertDatabaseCount('tenant_products', 2);
    }

    /** @test */
    public function bulk_upsert_on_tenant_scoped_table_forces_tenant_column_into_match_on(): void
    {
        Config::set('record.enable_tenant_id', true);

        Schema::create('tenant_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('sku');
            $table->string('name');
            $table->timestamps();
            $table->unique(['tenant_id', 'sku']);
        });

        Config::set('record.tables.tenant_items', new RecordTableType(
            table: 'tenant_items',
            hasTenantId: true,
            canUpsert: true,
            public: new RecordTablePublic(
                read: true,
                write: true
            ),
        ));

        SchemaRegistryUtils::refresh();

        DB::table('tenant_items')->insert([
            'tenant_id' => 1,
            'sku' => 'SKU-EXISTING',
            'name' => 'Tenant 1 Existing',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            [
                'sku' => 'SKU-EXISTING',
                'name' => 'Tenant 2 Existing',
            ],
            [
                'sku' => 'SKU-NEW',
                'name' => 'Tenant 2 New',
            ],
        ];

        $response = $this->withHeaders(['X-Tenant-Id' => '2'])
            ->postJson('/api/tenant_items/bulk/upsert?match_on=sku', $payload);

        $response->assertStatus(200);

        // Tenant 1 record is untouched
        $this->assertDatabaseHas('tenant_items', [
            'tenant_id' => 1,
            'sku' => 'SKU-EXISTING',
            'name' => 'Tenant 1 Existing',
        ]);

        // Tenant 2 records created
        $this->assertDatabaseHas('tenant_items', [
            'tenant_id' => 2,
            'sku' => 'SKU-EXISTING',
            'name' => 'Tenant 2 Existing',
        ]);
        $this->assertDatabaseHas('tenant_items', [
            'tenant_id' => 2,
            'sku' => 'SKU-NEW',
            'name' => 'Tenant 2 New',
        ]);

        $this->assertDatabaseCount('tenant_items', 3);
    }
}
