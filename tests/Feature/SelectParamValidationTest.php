<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Services\Queries\RecordQueryBuilder;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class SelectParamValidationTest extends TestCase
{
    use RefreshDatabase;

    protected int $authorId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('authors', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('author_id');
            $table->string('title');
            $table->timestamps();
        });

        $this->authorId = DB::table('authors')->insertGetId([
            'name' => 'Ada Lovelace',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('posts')->insert([
            'author_id' => $this->authorId,
            'title' => 'First Post',
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
                relationships: [
                    'posts' => new RecordHasManyType(table: 'posts', foreignKey: 'author_id'),
                ],
                attributes: [
                    'display_name' => fn($record): string => (is_array($record) ? ($record['name'] ?? '') : ($record->name ?? '')) . ' (author)',
                ],
            ),
            'posts' => new RecordTableType(
                table: 'posts',
                pmsName: 'posts',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                columns: [
                    'id' => ['type' => 'integer', 'nullable' => false],
                    'author_id' => ['type' => 'integer', 'nullable' => false],
                    'title' => ['type' => 'string', 'nullable' => false],
                    'created_at' => ['type' => 'datetime', 'nullable' => true],
                    'updated_at' => ['type' => 'datetime', 'nullable' => true],
                ],
                relationships: [],
            ),
        ]);

        SchemaRegistryUtils::refresh();
    }

    /** @test */
    public function unknown_main_table_column_returns_422(): void
    {
        $response = $this->getJson('/api/authors?select=bogus_field');

        $response->assertStatus(422);
        $response->assertJsonPath('message', "Unknown column 'bogus_field' in select for table 'authors'. Valid columns: created_at, display_name, id, name, updated_at.");
    }

    /** @test */
    public function computed_attribute_is_a_valid_main_column(): void
    {
        $response = $this->getJson('/api/authors?select=id,name,display_name');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.display_name', 'Ada Lovelace (author)');
    }

    /** @test */
    public function wildcard_select_still_works_on_main_table(): void
    {
        $response = $this->getJson('/api/authors?select=*');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.name', 'Ada Lovelace');
    }

    /** @test */
    public function record_query_builder_validates_main_columns(): void
    {
        $config = new RecordTableType(table: 'authors', pmsName: 'authors');
        $builder = new RecordQueryBuilder('authors', $config);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown column 'bogus_field' in select for table 'authors'.");

        $builder->applySelectFromParam('bogus_field');
    }

    /** @test */
    public function unknown_top_level_relationship_returns_422(): void
    {
        $response = $this->getJson('/api/authors?select=bogus_rel(*)');

        $response->assertStatus(422);
        $response->assertJsonPath('message', "Unknown relationship 'bogus_rel' in select for table 'authors'. Valid relationships: posts.");
    }

    /** @test */
    public function unknown_nested_relationship_returns_422(): void
    {
        $response = $this->getJson('/api/authors?select=posts(bogus_child(*))');

        $response->assertStatus(422);
        $response->assertJsonPath('message', "Unknown relationship 'bogus_child' in select for table 'posts'. Valid relationships: none.");
    }

    /** @test */
    public function valid_relationship_select_still_works(): void
    {
        $response = $this->getJson('/api/authors?select=id,posts(title)');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.posts.0.title', 'First Post');
    }
}
