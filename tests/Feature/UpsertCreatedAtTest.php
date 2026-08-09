<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Support\Str;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * upsertRecord() and bulkUpsertRecord() apply timestamps with isUpdate=true, which
 * only sets updated_at. New rows inserted by the upsert's INSERT branch (no existing
 * row matches match_on) therefore ended up with created_at = NULL on nullable
 * timestamp columns. created_at is now filled for the INSERT branch while remaining
 * excluded from the update columns, so existing rows keep their original value.
 *
 * @internal
 */
class UpsertCreatedAtTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('sku')->unique();
            $table->string('name');
            $table->integer('price');
            $table->timestamps();
        });

        Config::set('record.tables', [
            'products' => new RecordTableType(
                table: 'products',
                canUpsert: true,
                public: new RecordTablePublic(read: true, write: true),
                columns: [
                    'id' => ['type' => 'uuid', 'nullable' => false],
                    'sku' => ['type' => 'string', 'nullable' => false],
                    'name' => ['type' => 'string', 'nullable' => false],
                    'price' => ['type' => 'integer', 'nullable' => false],
                    'created_at' => ['type' => 'datetime', 'nullable' => true],
                    'updated_at' => ['type' => 'datetime', 'nullable' => true],
                ],
            ),
        ]);

        SchemaRegistryUtils::refresh();
    }

    /** @test */
    public function upsert_sets_created_at_when_inserting_a_new_record(): void
    {
        $this->postJson('/api/products/upsert?match_on=sku', [
            'sku' => 'SKU-1',
            'name' => 'Widget',
            'price' => 100,
        ])->assertStatus(200);

        $row = DB::table('products')->where('sku', 'SKU-1')->first();
        $this->assertNotNull($row);
        $this->assertNotNull($row->created_at);
    }

    /** @test */
    public function upsert_keeps_the_existing_created_at_when_updating(): void
    {
        $originalCreatedAt = '2024-01-15 10:00:00';
        DB::table('products')->insert([
            'id' => (string) Str::uuid(),
            'sku' => 'SKU-2',
            'name' => 'Old Name',
            'price' => 50,
            'created_at' => $originalCreatedAt,
            'updated_at' => $originalCreatedAt,
        ]);

        $this->postJson('/api/products/upsert?match_on=sku', [
            'sku' => 'SKU-2',
            'name' => 'New Name',
            'price' => 75,
        ])->assertStatus(200);

        $row = DB::table('products')->where('sku', 'SKU-2')->first();
        $this->assertSame($originalCreatedAt, $row->created_at);
        $this->assertNotSame($originalCreatedAt, $row->updated_at);
    }

    /** @test */
    public function upsert_ignores_client_created_at_for_system_managed_timestamps(): void
    {
        // Without overrideTimestamps, sanitizePayload() strips client timestamps —
        // the server fills created_at with now() instead of inserting NULL.
        $this->postJson('/api/products/upsert?match_on=sku', [
            'sku' => 'SKU-3',
            'name' => 'Imported',
            'price' => 10,
            'created_at' => '2023-05-20 08:30:00',
        ])->assertStatus(200);

        $row = DB::table('products')->where('sku', 'SKU-3')->first();
        $this->assertNotNull($row->created_at);
        $this->assertNotSame('2023-05-20 08:30:00', $row->created_at);
    }

    /** @test */
    public function bulk_upsert_sets_created_at_when_inserting_new_records(): void
    {
        $this->postJson('/api/products/bulk/upsert?match_on=sku', [
            ['sku' => 'SKU-4', 'name' => 'Gadget', 'price' => 200],
            ['sku' => 'SKU-5', 'name' => 'Widget Pro', 'price' => 300],
        ])->assertStatus(200);

        foreach (['SKU-4', 'SKU-5'] as $sku) {
            $this->assertNotNull(DB::table('products')->where('sku', $sku)->value('created_at'));
        }
    }

    /** @test */
    public function bulk_upsert_keeps_created_at_on_existing_rows(): void
    {
        $originalCreatedAt = '2024-02-01 09:00:00';
        DB::table('products')->insert([
            'id' => (string) Str::uuid(),
            'sku' => 'SKU-6',
            'name' => 'Old',
            'price' => 5,
            'created_at' => $originalCreatedAt,
            'updated_at' => $originalCreatedAt,
        ]);

        $this->postJson('/api/products/bulk/upsert?match_on=sku', [
            ['sku' => 'SKU-6', 'name' => 'New', 'price' => 9],
        ])->assertStatus(200);

        $row = DB::table('products')->where('sku', 'SKU-6')->first();
        $this->assertSame($originalCreatedAt, $row->created_at);
        $this->assertNotSame($originalCreatedAt, $row->updated_at);
    }
}
