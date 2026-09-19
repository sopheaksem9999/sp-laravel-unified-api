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
 * The bulk endpoints are documented with a wrapped request body — `{"data": [...]}`
 * for create/update, `{"ids": [...]}` for delete, `{"items": [...]}` for the legacy
 * `/bulk` dispatcher — but the item-detection heuristic only recognised a bare
 * top-level array. A documented body was therefore treated as ONE row whose only
 * field was the envelope key, so per-row validation ran one nesting level too high
 * and failed on every required column.
 *
 * Both shapes must work, and the long-standing "a single object is one row"
 * convenience must survive.
 *
 * @internal
 */
class BulkEnvelopeShapeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('widgets', function (Blueprint $table): void {
            $table->id();
            $table->string('sku')->unique();
            $table->string('name');
            $table->timestamps();
        });

        // A table that declares a real `data` column: the envelope unwrap must not
        // hijack it, or one row would silently become N.
        Schema::create('payloads', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('data')->nullable();
            $table->timestamps();
        });

        Config::set('record.default_validation', ['enabled' => true]);

        $columns = [
            'id' => ['type' => 'integer', 'nullable' => false],
            'sku' => ['type' => 'string', 'nullable' => false],
            'name' => ['type' => 'string', 'nullable' => false],
            'created_at' => ['type' => 'datetime', 'nullable' => true],
            'updated_at' => ['type' => 'datetime', 'nullable' => true],
        ];

        Config::set('record.tables', [
            'widgets' => new RecordTableType(
                table: 'widgets',
                canUpsert: true,
                public: new RecordTablePublic(read: true, write: true),
                columns: $columns,
            ),
            'payloads' => new RecordTableType(
                table: 'payloads',
                public: new RecordTablePublic(read: true, write: true),
                columns: [
                    'id' => ['type' => 'integer', 'nullable' => false],
                    'name' => ['type' => 'string', 'nullable' => false],
                    'data' => ['type' => 'string', 'nullable' => true],
                    'created_at' => ['type' => 'datetime', 'nullable' => true],
                    'updated_at' => ['type' => 'datetime', 'nullable' => true],
                ],
            ),
        ]);

        SchemaRegistryUtils::refresh();
    }

    private function seedWidget(int $id, string $sku): void
    {
        DB::table('widgets')->insert([
            'id' => $id,
            'sku' => $sku,
            'name' => 'original',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    /** @test */
    public function bulk_create_accepts_the_documented_data_envelope(): void
    {
        $this->postJson('/api/widgets/bulk/create', [
            'data' => [
                ['sku' => 'A1', 'name' => 'First'],
                ['sku' => 'A2', 'name' => 'Second'],
            ],
        ])->assertStatus(200);

        $this->assertSame(2, DB::table('widgets')->count());
    }

    /** @test */
    public function bulk_create_still_accepts_a_bare_array(): void
    {
        $this->postJson('/api/widgets/bulk/create', [
            ['sku' => 'B1', 'name' => 'First'],
        ])->assertStatus(200);

        $this->assertSame(1, DB::table('widgets')->count());
    }

    /** @test */
    public function bulk_create_still_accepts_a_single_object_as_one_row(): void
    {
        $this->postJson('/api/widgets/bulk/create', ['sku' => 'C1', 'name' => 'Only'])
            ->assertStatus(200);

        $this->assertSame(1, DB::table('widgets')->count());
    }

    /** @test */
    public function bulk_update_accepts_the_documented_data_envelope(): void
    {
        $this->seedWidget(90, 'U1');

        $this->postJson('/api/widgets/bulk/update', [
            'data' => [['id' => 90, 'name' => 'renamed']],
        ])->assertStatus(200);

        $this->assertSame('renamed', DB::table('widgets')->where('id', 90)->value('name'));
    }

    /** @test */
    public function bulk_delete_accepts_the_documented_ids_envelope(): void
    {
        $this->seedWidget(91, 'D1');

        $this->postJson('/api/widgets/bulk/delete', ['ids' => [91]])
            ->assertStatus(200);

        $this->assertSame(0, DB::table('widgets')->where('id', 91)->count());
    }

    /** @test */
    public function bulk_delete_accepts_a_data_envelope_of_objects(): void
    {
        $this->seedWidget(92, 'D2');

        $this->postJson('/api/widgets/bulk/delete', ['data' => [['id' => 92]]])
            ->assertStatus(200);

        $this->assertSame(0, DB::table('widgets')->where('id', 92)->count());
    }

    /** @test */
    public function bulk_upsert_accepts_both_the_bare_array_and_the_data_envelope(): void
    {
        $this->postJson('/api/widgets/bulk/upsert?match_on=sku', [
            ['sku' => 'E1', 'name' => 'Bare'],
        ])->assertStatus(200);

        $this->postJson('/api/widgets/bulk/upsert?match_on=sku', [
            'data' => [['sku' => 'E2', 'name' => 'Wrapped']],
        ])->assertStatus(200);

        $this->assertSame(2, DB::table('widgets')->count());
    }

    /** @test */
    public function legacy_bulk_dispatcher_accepts_both_items_and_data_envelopes(): void
    {
        $this->postJson('/api/widgets/bulk', [
            'items' => [['sku' => 'F1', 'name' => 'Items']],
        ])->assertStatus(200);

        $this->postJson('/api/widgets/bulk', [
            'data' => [['sku' => 'F2', 'name' => 'Data']],
        ])->assertStatus(200);

        $this->assertSame(2, DB::table('widgets')->count());
    }

    /** @test */
    public function an_empty_envelope_is_rejected_as_an_item_error(): void
    {
        // Unwrapping an empty envelope yields an empty list, so the failure is
        // reported against `items` rather than against a phantom row's columns.
        $response = $this->postJson('/api/widgets/bulk/create', ['data' => []]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.items.0', 'Payload must be an array');
        $this->assertSame(0, DB::table('widgets')->count());
    }

    /** @test */
    public function a_real_data_column_is_not_treated_as_an_envelope(): void
    {
        // `payloads` declares a `data` column, so this body is ONE row whose `data`
        // field happens to be a list — not an envelope wrapping two rows. Proven by
        // the error naming the row's own missing column rather than an item error.
        $response = $this->postJson('/api/payloads/bulk/create', [
            'data' => [['name' => 'A'], ['name' => 'B']],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('payloads')->count());
        $this->assertArrayHasKey('name', (array) $response->json('errors'));
    }
}
