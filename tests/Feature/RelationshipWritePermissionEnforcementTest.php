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
use Sopheak\Core\Types\RecordMetaBelongsToManyType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * A disallowed nested relationship write (allowCreate/allowUpdate/allowDelete: false)
 * used to be silently dropped, returning 200 as if the request had fully succeeded.
 * RelationshipResolverUtils now rejects it with a 422 naming the relationship and the
 * disabled flag, across all three nested-write code paths: plain hasMany/morphMany,
 * belongsToMany/morphToMany (pivot), and hasManyThrough.
 *
 * @internal
 */
class RelationshipWritePermissionEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('project_tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id');
            $table->foreignId('tag_id');
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Config::set('record.tables', [
            'invoice_items' => new RecordTableType(
                table: 'invoice_items',
                pmsName: 'invoice_items',
                hasTenantId: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
            ),
            'tags' => new RecordTableType(
                table: 'tags',
                pmsName: 'tags',
                hasTenantId: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
            ),
        ]);
    }

    private function configureInvoices(bool $allowCreate, bool $allowUpdate, bool $allowDelete): void
    {
        $tables = Config::get('record.tables');
        $tables['invoices'] = new RecordTableType(
            table: 'invoices',
            pmsName: 'invoices',
            hasTenantId: false,
            public: new RecordTablePublic(read: true, write: true),
            relationships: [
                'items' => new RecordHasManyType(
                    table: 'invoice_items',
                    foreignKey: 'invoice_id',
                    allowCreate: $allowCreate,
                    allowUpdate: $allowUpdate,
                    allowDelete: $allowDelete,
                ),
            ],
        );
        Config::set('record.tables', $tables);
        SchemaRegistryUtils::refresh();
    }

    private function configureProjects(bool $allowCreate, bool $allowUpdate, bool $allowDelete): void
    {
        $tables = Config::get('record.tables');
        $tables['projects'] = new RecordTableType(
            table: 'projects',
            pmsName: 'projects',
            hasTenantId: false,
            public: new RecordTablePublic(read: true, write: true),
            relationships: [
                'tags' => new RecordMetaBelongsToManyType(
                    related: 'tags',
                    table: 'project_tags',
                    foreignPivotKey: 'project_id',
                    relatedPivotKey: 'tag_id',
                    withPivot: ['note'],
                    allowCreate: $allowCreate,
                    allowUpdate: $allowUpdate,
                    allowDelete: $allowDelete,
                ),
            ],
        );
        Config::set('record.tables', $tables);
        SchemaRegistryUtils::refresh();
    }

    /** @test */
    public function has_many_update_is_rejected_when_allow_update_is_false(): void
    {
        $this->configureInvoices(allowCreate: true, allowUpdate: false, allowDelete: true);

        $invoiceId = DB::table('invoices')->insertGetId(['title' => 'Invoice 1']);
        $itemId = DB::table('invoice_items')->insertGetId(['invoice_id' => $invoiceId, 'name' => 'original']);

        $response = $this->putJson('/api/invoices/' . $invoiceId, [
            'items' => [['id' => $itemId, 'name' => 'changed']],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath(
            'message',
            "Cannot update item in relationship 'items' for table 'invoices': allowUpdate is disabled for this relationship."
        );
        $this->assertSame('original', DB::table('invoice_items')->where('id', $itemId)->value('name'));
    }

    /** @test */
    public function has_many_delete_is_rejected_when_allow_delete_is_false(): void
    {
        $this->configureInvoices(allowCreate: true, allowUpdate: true, allowDelete: false);

        $invoiceId = DB::table('invoices')->insertGetId(['title' => 'Invoice 1']);
        $itemId = DB::table('invoice_items')->insertGetId(['invoice_id' => $invoiceId, 'name' => 'original']);

        $response = $this->putJson('/api/invoices/' . $invoiceId, [
            'items' => [['id' => $itemId, '_delete' => true]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath(
            'message',
            "Cannot delete item in relationship 'items' for table 'invoices': allowDelete is disabled for this relationship."
        );
        $this->assertDatabaseHas('invoice_items', ['id' => $itemId]);
    }

    /** @test */
    public function has_many_delete_without_id_is_rejected_as_ambiguous(): void
    {
        $this->configureInvoices(allowCreate: true, allowUpdate: true, allowDelete: true);

        $invoiceId = DB::table('invoices')->insertGetId(['title' => 'Invoice 1']);

        $response = $this->putJson('/api/invoices/' . $invoiceId, [
            'items' => [['_delete' => true]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath(
            'message',
            "Cannot delete item in relationship 'items' for table 'invoices': '_delete' requires the related 'id' primary key."
        );
    }

    /** @test */
    public function belongs_to_many_attach_is_rejected_when_allow_create_is_false(): void
    {
        $this->configureProjects(allowCreate: false, allowUpdate: true, allowDelete: true);

        $projectId = DB::table('projects')->insertGetId(['name' => 'Project 1']);
        $tagId = DB::table('tags')->insertGetId(['name' => 'Urgent']);

        $response = $this->putJson('/api/projects/' . $projectId, [
            'tags' => [['id' => $tagId]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath(
            'message',
            "Cannot create item in relationship 'tags' for table 'projects': allowCreate is disabled for this relationship."
        );
        $this->assertDatabaseMissing('project_tags', ['project_id' => $projectId, 'tag_id' => $tagId]);
    }

    /** @test */
    public function belongs_to_many_pivot_field_update_is_rejected_when_allow_update_is_false(): void
    {
        $this->configureProjects(allowCreate: true, allowUpdate: false, allowDelete: true);

        $projectId = DB::table('projects')->insertGetId(['name' => 'Project 1']);
        $tagId = DB::table('tags')->insertGetId(['name' => 'Urgent']);
        DB::table('project_tags')->insert(['project_id' => $projectId, 'tag_id' => $tagId, 'note' => 'original']);

        $response = $this->putJson('/api/projects/' . $projectId, [
            'tags' => [['id' => $tagId, 'note' => 'changed']],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath(
            'message',
            "Cannot update item in relationship 'tags' for table 'projects': allowUpdate is disabled for this relationship."
        );
        $this->assertSame('original', DB::table('project_tags')->where('project_id', $projectId)->value('note'));
    }

    /** @test */
    public function belongs_to_many_reattaching_with_no_pivot_changes_is_not_an_error(): void
    {
        // Already linked, no pivot fields to change — a no-op, not an error, regardless
        // of allowUpdate/allowCreate.
        $this->configureProjects(allowCreate: false, allowUpdate: false, allowDelete: true);

        $projectId = DB::table('projects')->insertGetId(['name' => 'Project 1']);
        $tagId = DB::table('tags')->insertGetId(['name' => 'Urgent']);
        DB::table('project_tags')->insert(['project_id' => $projectId, 'tag_id' => $tagId, 'note' => 'original']);

        $response = $this->putJson('/api/projects/' . $projectId, [
            'tags' => [['id' => $tagId]],
        ]);

        $response->assertStatus(200);
        $this->assertSame('original', DB::table('project_tags')->where('project_id', $projectId)->value('note'));
    }
}
