<?php

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

class GroupedLogicFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('bills', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('vendor_id');
            $table->decimal('balance_due', 14, 2)->default(0);
            $table->timestamps();
        });

        Config::set('record.tables', [
            'bills' => new RecordTableType(
                table: 'bills',
                pmsName: 'bills',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
            ),
        ]);

        SchemaRegistryUtils::refresh();

        DB::table('bills')->insert([
            ['id' => 1, 'vendor_id' => 27, 'balance_due' => 20, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 5, 'vendor_id' => 27, 'balance_due' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 6, 'vendor_id' => 27, 'balance_due' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 7, 'vendor_id' => 99, 'balance_due' => 30, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /** @test */
    public function it_supports_postgrest_grouped_or_logic_with_existing_and_filters(): void
    {
        $response = $this->getJson('/api/bills?vendor_id=eq.27&or=(balance_due.gt.0,id.eq.5)&sortby=id&order=asc');

        $response->assertStatus(200);

        $ids = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $response->json('data'));
        $this->assertSame([1, 5], $ids);
    }

    /** @test */
    public function it_supports_postgrest_parenthesized_in_syntax_without_breaking_existing_behavior(): void
    {
        $response = $this->getJson('/api/bills?id=in.%285%2C6%29&sortby=id&order=asc');

        $response->assertStatus(200);

        $ids = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $response->json('data'));
        $this->assertSame([5, 6], $ids);
    }

    /** @test */
    public function it_returns_validation_error_when_operator_not_supported_for_current_driver(): void
    {
        $response = $this->getJson('/api/bills?balance_due=fts.invoice');

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonFragment([
            'message' => "Operator 'fts' is not supported on current driver 'sqlite'.",
        ]);
    }
}
