<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class PayloadFieldValidationTest extends TestCase
{
    use RefreshDatabase;

    protected int $authorId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('authors', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('internal_notes')->nullable();
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
                    'internal_notes' => ['type' => 'string', 'nullable' => true],
                    'created_at' => ['type' => 'datetime', 'nullable' => true],
                    'updated_at' => ['type' => 'datetime', 'nullable' => true],
                ],
                columnWriteDisabled: ['internal_notes'],
                relationships: [
                    'posts' => new RecordHasManyType(table: 'posts', foreignKey: 'author_id'),
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
    public function unknown_scalar_field_on_create_returns_422(): void
    {
        $response = $this->postJson('/api/authors', [
            'name' => 'Grace Hopper',
            'bogus_field' => 'x',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath(
            'message',
            "Unknown field 'bogus_field' in payload for table 'authors'. Valid columns: created_at, id, internal_notes, name, updated_at. Valid relationships: posts."
        );
        $this->assertDatabaseMissing('authors', ['name' => 'Grace Hopper']);
    }

    /** @test */
    public function unknown_relationship_shaped_field_on_create_returns_422(): void
    {
        $response = $this->postJson('/api/authors', [
            'name' => 'Grace Hopper',
            'bogus_rel' => [['title' => 'Some Post']],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath(
            'message',
            "Unknown field 'bogus_rel' in payload for table 'authors'. Valid columns: created_at, id, internal_notes, name, updated_at. Valid relationships: posts."
        );
        $this->assertDatabaseMissing('authors', ['name' => 'Grace Hopper']);
    }

    /** @test */
    public function write_disabled_column_on_create_is_silently_ignored_not_an_error(): void
    {
        // columnWriteDisabled is a known field, not an unknown one — sending it is a
        // deliberate no-op (e.g. round-tripping a GET response back as a write), so it
        // must not be rejected the way a genuinely unknown field name is.
        $response = $this->postJson('/api/authors', [
            'name' => 'Grace Hopper',
            'internal_notes' => 'should not be settable',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('authors', ['name' => 'Grace Hopper', 'internal_notes' => null]);
    }

    /** @test */
    public function valid_relationship_field_still_works_on_create(): void
    {
        $response = $this->postJson('/api/authors', [
            'name' => 'Grace Hopper',
            'posts' => [['title' => 'Compilers']],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('authors', ['name' => 'Grace Hopper']);
        $this->assertDatabaseHas('posts', ['title' => 'Compilers']);
    }

    /** @test */
    public function unknown_field_on_update_returns_422(): void
    {
        $response = $this->putJson('/api/authors/' . $this->authorId, [
            'bogus_field' => 'x',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath(
            'message',
            "Unknown field 'bogus_field' in payload for table 'authors'. Valid columns: created_at, id, internal_notes, name, updated_at. Valid relationships: posts."
        );
    }

    /** @test */
    public function bulk_create_reports_422_for_unknown_field(): void
    {
        $response = $this->postJson('/api/authors/bulk/create', [
            ['name' => 'Katherine Johnson', 'bogus_field' => 'x'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath(
            'message',
            "Unknown field 'bogus_field' in payload for table 'authors'. Valid columns: created_at, id, internal_notes, name, updated_at. Valid relationships: posts."
        );
        $this->assertDatabaseMissing('authors', ['name' => 'Katherine Johnson']);
    }

    /** @test */
    public function upsert_reports_422_for_unknown_field(): void
    {
        $response = $this->postJson('/api/authors/upsert?match_on=name', [
            'name' => 'Margaret Hamilton',
            'bogus_field' => 'x',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath(
            'message',
            "Unknown field 'bogus_field' in payload for table 'authors'. Valid columns: created_at, id, internal_notes, name, updated_at. Valid relationships: posts."
        );
        $this->assertDatabaseMissing('authors', ['name' => 'Margaret Hamilton']);
    }
}
