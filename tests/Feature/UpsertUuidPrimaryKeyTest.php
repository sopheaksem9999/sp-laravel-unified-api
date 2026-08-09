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
 * A uuid('id')->primary() column has no database default and does not auto-increment, so
 * upsertRecord()'s INSERT branch (no existing row matches match_on) needs the id generated
 * server-side — exactly like createRecord() already does. sanitizePayload() strips 'id'
 * unconditionally, and upsertRecord() never restored or generated it, so inserting via
 * upsert on a uuid-keyed table violated the primary key's NOT NULL constraint.
 *
 * @internal
 */
class UpsertUuidPrimaryKeyTest extends TestCase
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
    public function upsert_generates_a_uuid_when_inserting_a_new_record(): void
    {
        $response = $this->postJson('/api/products/upsert?match_on=sku', [
            'sku' => 'SKU-1',
            'name' => 'Widget',
            'price' => 100,
        ]);

        $response->assertStatus(200);

        $row = DB::table('products')->where('sku', 'SKU-1')->first();
        $this->assertNotNull($row);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $row->id
        );
        $this->assertSame($row->id, $response->json('data.id'));
    }

    /** @test */
    public function upsert_updates_the_existing_row_and_keeps_its_original_id(): void
    {
        $existingId = (string) \Illuminate\Support\Str::uuid();
        DB::table('products')->insert([
            'id' => $existingId,
            'sku' => 'SKU-2',
            'name' => 'Old Name',
            'price' => 50,
        ]);

        $response = $this->postJson('/api/products/upsert?match_on=sku', [
            'sku' => 'SKU-2',
            'name' => 'New Name',
            'price' => 75,
        ]);

        $response->assertStatus(200);

        $row = DB::table('products')->where('sku', 'SKU-2')->first();
        $this->assertSame($existingId, $row->id);
        $this->assertSame('New Name', $row->name);
        $this->assertSame(75, $row->price);
        $this->assertSame(1, DB::table('products')->where('sku', 'SKU-2')->count());
    }

    /** @test */
    public function bulk_upsert_generates_a_uuid_when_inserting_a_new_record(): void
    {
        $response = $this->postJson('/api/products/bulk/upsert?match_on=sku', [
            ['sku' => 'SKU-3', 'name' => 'Gadget', 'price' => 200],
        ]);

        $response->assertStatus(200);

        $row = DB::table('products')->where('sku', 'SKU-3')->first();
        $this->assertNotNull($row);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $row->id
        );
    }
}
