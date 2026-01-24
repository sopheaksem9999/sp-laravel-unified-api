<?php

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordHasManyThroughType;
use Sopheak\Core\Types\RecordMetaBelongsToManyType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;

class RelationshipPermissionsTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Setup Database Schema
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
            $table->timestamps();
        });

        Schema::create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });

        // "Through" table acting as a link table for this test
        Schema::create('project_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id');
            $table->foreignId('task_id');
            $table->timestamps();
        });

        // 2. Configure Record Types
        Config::set('record.tables', [
            'projects' => new RecordTableType(
                table: 'projects',
                pmsName: 'projects',
                public: new RecordTablePublic(read: true, write: true),
                relationships: [
                    // BelongsToMany: Tags
                    // Allowed: Create (Attach/Create), Delete (Detach)
                    // Denied: Update (not testing pivot update here, but the flag is set)
                    'tags' => new RecordMetaBelongsToManyType(
                        related: 'tags',
                        table: 'project_tags',
                        foreignPivotKey: 'project_id',
                        relatedPivotKey: 'tag_id',
                        allowCreate: true,
                        allowUpdate: false,
                        allowDelete: true // Allowed to detach
                    ),
                    // HasManyThrough: Tasks
                    // Allowed: Create (Attach/Create)
                    // Denied: Delete (Detach)
                    'tasks' => new RecordHasManyThroughType(
                        table: 'tasks',
                        through: 'project_tasks',
                        firstKey: 'project_id',
                        secondLocalKey: 'task_id',
                        allowCreate: true,
                        allowUpdate: true,
                        allowDelete: false // Denied to detach
                    )
                ]
            ),
            'tags' => new RecordTableType(
                table: 'tags',
                pmsName: 'tags',
                public: new RecordTablePublic(read: true, write: true),
                relationships: []
            ),
            'tasks' => new RecordTableType(
                table: 'tasks',
                pmsName: 'tasks',
                public: new RecordTablePublic(read: true, write: true),
                relationships: []
            )
        ]);

        SchemaRegistryUtils::refresh();
    }

    /** @test */
    public function it_can_create_and_detach_belongs_to_many_with_permissions(): void
    {
        // 1. Create Project with a new Tag
        $payload = [
            'name' => 'Project Alpha',
            'tags' => [
                ['name' => 'Urgent']
            ]
        ];

        $response = $this->postJson('/api/projects', $payload);
        $response->assertStatus(200);

        $projectId = $response->json('data.id');
        $tagId = DB::table('tags')->where('name', 'Urgent')->value('id');

        $this->assertNotNull($tagId);
        $this->assertDatabaseHas('project_tags', [
            'project_id' => $projectId,
            'tag_id' => $tagId
        ]);

        // 2. Detach Tag (Delete) - Should be ALLOWED
        $updatePayload = [
            'tags' => [
                ['id' => $tagId, '_delete' => true]
            ]
        ];

        $response = $this->putJson('/api/projects/' . $projectId, $updatePayload);
        $response->assertStatus(200);

        $this->assertDatabaseMissing('project_tags', [
            'project_id' => $projectId,
            'tag_id' => $tagId
        ]);
    }

    /** @test */
    public function it_respects_delete_restriction_on_has_many_through(): void
    {
        // 1. Create Project with a new Task
        $payload = [
            'name' => 'Project Beta',
            'tasks' => [
                ['title' => 'Initial Task']
            ]
        ];

        $response = $this->postJson('/api/projects', $payload);
        $response->assertStatus(200);

        $projectId = $response->json('data.id');
        $taskId = DB::table('tasks')->where('title', 'Initial Task')->value('id');

        $this->assertNotNull($taskId);
        $this->assertDatabaseHas('project_tasks', [
            'project_id' => $projectId,
            'task_id' => $taskId
        ]);

        // 2. Try to Detach Task (Delete) - Should be BLOCKED/IGNORED
        $updatePayload = [
            'tasks' => [
                ['id' => $taskId, '_delete' => true]
            ]
        ];

        $response = $this->putJson('/api/projects/' . $projectId, $updatePayload);
        $response->assertStatus(200);

        // The link should STILL exist because allowDelete = false
        $this->assertDatabaseHas('project_tasks', [
            'project_id' => $projectId,
            'task_id' => $taskId
        ]);
    }
}
