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

class FilterParamValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('authors', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        DB::table('authors')->insert([
            'name' => 'Ada Lovelace',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Config::set('record.tables', [
            'authors' => new RecordTableType(
                table: 'authors',
                pmsName: 'authors',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                columns: [
                    'id' => ['type' => 'integer', 'nullable' => false],
                    'name' => ['type' => 'string', 'nullable' => false],
                    'created_at' => ['type' => 'datetime', 'nullable' => true],
                    'updated_at' => ['type' => 'datetime', 'nullable' => true],
                ],
                relationships: [],
            ),
        ]);

        SchemaRegistryUtils::refresh();
    }

    /** @test */
    public function bracket_nested_range_filter_returns_422_with_corrected_syntax(): void
    {
        $response = $this->getJson(
            '/api/authors?' . http_build_query([
                'filter' => [
                    'created_at' => [
                        '>=' => '2026-08-01T00:00:00.000',
                        '<=' => '2026-08-31T00:00:00.000',
                    ],
                ],
            ])
        );

        $response->assertStatus(422);
        $response->assertJsonPath(
            'message',
            "Invalid filter for 'filter': bracket-style filters (e.g. filter[operator]=value) are not supported. "
            . 'Use and=(created_at.gte.2026-08-01T00:00:00.000,created_at.lte.2026-08-31T00:00:00.000) instead.'
        );
    }

    /** @test */
    public function bracket_nested_single_operator_filter_returns_422_with_corrected_syntax(): void
    {
        $response = $this->getJson(
            '/api/authors?' . http_build_query([
                'filter' => [
                    'name' => ['eq' => 'Ada Lovelace'],
                ],
            ])
        );

        $response->assertStatus(422);
        $response->assertJsonPath(
            'message',
            "Invalid filter for 'filter': bracket-style filters (e.g. filter[operator]=value) are not supported. Use name=eq.Ada Lovelace instead."
        );
    }

    /** @test */
    public function valid_dot_expression_filter_still_works(): void
    {
        $response = $this->getJson('/api/authors?created_at=gte.2020-01-01T00:00:00.000');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.name', 'Ada Lovelace');
    }
}
