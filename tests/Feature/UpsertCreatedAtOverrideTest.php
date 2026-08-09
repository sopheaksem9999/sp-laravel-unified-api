<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

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
 * With overrideTimestamps enabled, sanitizePayload() keeps client-provided
 * created_at, and the upsert created_at fill must not overwrite it — the
 * generated value only applies when the column is missing from the payload.
 *
 * @internal
 */
class UpsertCreatedAtOverrideTest extends TestCase
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
                overrideTimestamps: true,
            ),
        ]);

        SchemaRegistryUtils::refresh();
    }

    /** @test */
    public function upsert_respects_a_client_provided_created_at_with_override_timestamps(): void
    {
        $clientCreatedAt = '2023-05-20 08:30:00';
        $this->postJson('/api/products/upsert?match_on=sku', [
            'sku' => 'SKU-1',
            'name' => 'Imported',
            'price' => 10,
            'created_at' => $clientCreatedAt,
        ])->assertStatus(200);

        $this->assertSame($clientCreatedAt, DB::table('products')->where('sku', 'SKU-1')->value('created_at'));
    }

    /** @test */
    public function upsert_fills_created_at_when_omitted_even_with_override_timestamps(): void
    {
        $this->postJson('/api/products/upsert?match_on=sku', [
            'sku' => 'SKU-2',
            'name' => 'No Timestamp',
            'price' => 20,
        ])->assertStatus(200);

        $this->assertNotNull(DB::table('products')->where('sku', 'SKU-2')->value('created_at'));
    }

    /** @test */
    public function bulk_upsert_respects_client_created_at_with_override_timestamps(): void
    {
        $clientCreatedAt = '2022-11-11 11:11:00';
        $this->postJson('/api/products/bulk/upsert?match_on=sku', [
            ['sku' => 'SKU-3', 'name' => 'Bulk Import', 'price' => 30, 'created_at' => $clientCreatedAt],
        ])->assertStatus(200);

        $this->assertSame($clientCreatedAt, DB::table('products')->where('sku', 'SKU-3')->value('created_at'));
    }
}
