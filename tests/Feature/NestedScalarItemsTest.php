<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Sopheak\Core\CoreSpLaravelApiProvider;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordBelongsToType;
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Types\RecordMetaBelongsToManyType;
use Sopheak\Core\Types\RecordMetaHasManyThroughType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class NestedScalarItemsTest extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [CoreSpLaravelApiProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('notes', function (Blueprint $table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->timestamps();
        });
        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
        Schema::create('note_tag', function (Blueprint $table): void {
            $table->unsignedBigInteger('note_id');
            $table->unsignedBigInteger('tag_id');
        });
        Schema::create('note_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('note_id');
            $table->string('text')->nullable();
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
        Schema::create('modules', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
        Schema::create('meta', function (Blueprint $table): void {
            $table->id();
            $table->string('owner');
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('target_id');
            $table->timestamps();
        });

        $public = new RecordTablePublic(read: true, write: true);
        Config::set('record.tables', [
            'notes' => new RecordTableType(
                table: 'notes',
                pmsName: 'notes',
                public: $public,
                relationships: [
                    'tags' => new RecordMetaBelongsToManyType(related: 'tags', table: 'note_tag', foreignPivotKey: 'note_id', relatedPivotKey: 'tag_id'),
                    'lines' => new RecordHasManyType(table: 'note_lines', foreignKey: 'note_id'),
                    'customer' => new RecordBelongsToType(table: 'customers', foreignKey: 'customer_id'),
                    'modules' => new RecordMetaHasManyThroughType(table: 'modules', through: 'meta', firstKey: 'owner_id', secondLocalKey: 'target_id', ownerColumn: 'owner', owner: 'note'),
                ],
            ),
            'customers' => new RecordTableType(table: 'customers', pmsName: 'customers', public: $public),
            'modules' => new RecordTableType(table: 'modules', pmsName: 'modules', public: $public),
            'tags' => new RecordTableType(table: 'tags', pmsName: 'tags', public: $public),
            'note_lines' => new RecordTableType(table: 'note_lines', pmsName: 'note_lines', public: $public),
        ]);
        SchemaRegistryUtils::refresh();
    }

    public function test_bare_ids_attach_in_a_many_to_many_array(): void
    {
        $noteId = DB::table('notes')->insertGetId(['title' => 'n']);
        $a = DB::table('tags')->insertGetId(['name' => 'a']);
        $b = DB::table('tags')->insertGetId(['name' => 'b']);

        $this->putJson('/api/notes/' . $noteId, ['tags' => [$a, (string) $b]])->assertSuccessful();

        $this->assertSame(
            [$a, $b],
            DB::table('note_tag')->where('note_id', $noteId)->orderBy('tag_id')->pluck('tag_id')->map(static fn($v): int => (int) $v)->all(),
        );
    }

    public function test_bare_ids_on_create_attach_too(): void
    {
        $a = DB::table('tags')->insertGetId(['name' => 'a']);

        $this->postJson('/api/notes', ['title' => 'x', 'tags' => [$a]])->assertSuccessful();

        $this->assertSame(1, DB::table('note_tag')->where('tag_id', $a)->count());
    }

    public function test_object_and_bare_forms_can_be_mixed(): void
    {
        $noteId = DB::table('notes')->insertGetId(['title' => 'n']);
        $a = DB::table('tags')->insertGetId(['name' => 'a']);
        $b = DB::table('tags')->insertGetId(['name' => 'b']);

        $this->putJson('/api/notes/' . $noteId, ['tags' => [$a, ['id' => $b]]])->assertSuccessful();

        $this->assertSame(2, DB::table('note_tag')->where('note_id', $noteId)->count());
    }

    public function test_re_sending_an_attached_id_is_a_no_op(): void
    {
        $noteId = DB::table('notes')->insertGetId(['title' => 'n']);
        $a = DB::table('tags')->insertGetId(['name' => 'a']);
        DB::table('note_tag')->insert(['note_id' => $noteId, 'tag_id' => $a]);

        $this->putJson('/api/notes/' . $noteId, ['tags' => [$a]])->assertSuccessful();

        $this->assertSame(1, DB::table('note_tag')->where('note_id', $noteId)->count());
    }

    public function test_a_belongs_to_object_echoed_back_from_a_read_is_still_ignored(): void
    {
        // GET ...?select=*,customer(*) returns the related object; sending the record
        // back with PUT must keep working: only hasMany/morphMany arrays are strict.
        $noteId = DB::table('notes')->insertGetId(['title' => 'n']);

        $this->putJson('/api/notes/' . $noteId, ['title' => 'renamed', 'customer' => ['id' => 1, 'name' => 'Acme']])->assertSuccessful();

        $this->assertSame('renamed', DB::table('notes')->where('id', $noteId)->value('title'));
        $this->assertSame(0, DB::table('customers')->count(), 'the echoed object creates nothing');
    }

    public function test_a_bare_id_in_a_has_many_array_is_refused_naming_the_relationship(): void
    {
        $noteId = DB::table('notes')->insertGetId(['title' => 'n']);

        $this->putJson('/api/notes/' . $noteId, ['lines' => [5]])
            ->assertStatus(422)
            ->assertJsonPath('message', "Relationship 'lines' on table 'notes' expects objects, got scalar 5. Send {\"id\": ...} to update a child or {...fields} to create one.");

        $this->assertSame(0, DB::table('note_lines')->count());
    }

    public function test_a_bare_id_attaches_in_a_has_many_through_array(): void
    {
        $noteId = DB::table('notes')->insertGetId(['title' => 'n']);
        $moduleId = DB::table('modules')->insertGetId(['name' => 'm']);

        $this->putJson('/api/notes/' . $noteId, ['modules' => [$moduleId]])->assertSuccessful();

        $this->assertSame(1, DB::table('meta')->where('owner_id', $noteId)->where('target_id', $moduleId)->count());
    }

    #[DataProvider('emptyScalars')]
    public function test_empty_scalars_are_refused_and_never_create_an_empty_row(mixed $value): void
    {
        $noteId = DB::table('notes')->insertGetId(['title' => 'n']);
        $before = DB::table('tags')->count();

        $this->putJson('/api/notes/' . $noteId, ['tags' => [$value]])->assertStatus(422);

        $this->assertSame($before, DB::table('tags')->count());
        $this->assertSame(0, DB::table('note_tag')->count());
    }

    /** @return array<string, array<int, mixed>> */
    public static function emptyScalars(): array
    {
        return ['zero' => [0], 'empty string' => [''], 'zero string' => ['0'], 'null' => [null], 'false' => [false]];
    }
}
