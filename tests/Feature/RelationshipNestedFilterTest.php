<?php

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Utilities\RelationshipResolverUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Enums\RecordRelationshipsEnum;
use Sopheak\Core\Types\RecordAassociationType;
use Sopheak\Core\Types\RecordHasManyThroughType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;

class RelationshipNestedFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create tables
        Schema::create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('task_assignees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id');
            $table->foreignId('user_id');
            $table->timestamps();
        });
        Schema::create('meta', function (Blueprint $table): void {
            $table->id();
            $table->string('owner');
            $table->unsignedBigInteger('owner_id');
            $table->string('target');
            $table->unsignedBigInteger('target_id');
            $table->timestamps();
        });

        // Insert data
        $taskId1 = DB::table('tasks')->insertGetId(['title' => 'Task 1']);
        $taskId2 = DB::table('tasks')->insertGetId(['title' => 'Task 2']);

        $userId1 = DB::table('users')->insertGetId(['name' => 'User 1']);
        $userId2 = DB::table('users')->insertGetId(['name' => 'User 2']);

        // Task 1 has User 1 AND User 2
        DB::table('task_assignees')->insert(['task_id' => $taskId1, 'user_id' => $userId1]);
        DB::table('task_assignees')->insert(['task_id' => $taskId1, 'user_id' => $userId2]);

        // Task 2 has User 2 only
        DB::table('task_assignees')->insert(['task_id' => $taskId2, 'user_id' => $userId2]);

        DB::table('meta')->insert(['owner' => 'tasks', 'owner_id' => $taskId1, 'target' => 'users', 'target_id' => $userId1]);
        DB::table('meta')->insert(['owner' => 'tasks', 'owner_id' => $taskId1, 'target' => 'users', 'target_id' => $userId2]);
        DB::table('meta')->insert(['owner' => 'tasks', 'owner_id' => $taskId2, 'target' => 'users', 'target_id' => $userId2]);

        Config::set('record.tables', [
            'tasks' => new RecordTableType(
                table: 'tasks',
                pmsName: 'tasks',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [
                    'assignees' => new RecordHasManyThroughType(
                        table: 'users',
                        through: 'task_assignees',
                        firstKey: 'task_id',
                        secondKey: 'id',
                        localKey: 'id',
                        secondLocalKey: 'user_id',
                    ),
                ],
            ),
            'users' => new RecordTableType(
                table: 'users',
                pmsName: 'users',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
            ),
        ]);

        SchemaRegistryUtils::refresh();
    }

    public function test_nested_filter_filters_loaded_relationships(): void
    {
        // Scenario 1: tasks?select=*,assignees(*,name=eq.User 1)
        // Expect Task 1 (with only User 1) and Task 2 (with NO assignees, because User 2 != User 1)
        // Wait, Task 2 has User 2. Filter excludes User 2. So Task 2 should show empty assignees.

        $request = Request::create('/api/v1/tasks', 'GET', [
            'select' => '*,assignees(*,name=eq.User 1)',
        ]);

        $schema = SchemaRegistryUtils::get();
        $config = $schema['tasks'];

        $result = RecordService::applyRequestFilters($request, $config);
        $data = $result['data'];

        // Should return both tasks (because we didn't filter the tasks themselves, only the loaded assignees)
        $this->assertCount(2, $data);

        $task1 = collect($data)->firstWhere('id', 1);
        $task2 = collect($data)->firstWhere('id', 2);

        // Task 1 should have 1 assignee (User 1)
        $this->assertCount(1, $task1->assignees);
        $assignee = $task1->assignees[0];
        $this->assertEquals('User 1', $assignee->name);

        // Task 2 should have 0 assignees (User 2 filtered out)
        $this->assertCount(0, $task2->assignees);
    }

    public function test_nested_filter_supports_with_prefix_syntax(): void
    {
        $request = Request::create('/api/v1/tasks', 'GET', [
            'select' => '*,with=assignees(*,name=eq.User 1)',
        ]);

        $schema = SchemaRegistryUtils::get();
        $config = $schema['tasks'];

        $result = RecordService::applyRequestFilters($request, $config);
        $data = $result['data'];

        $this->assertCount(2, $data);

        $task1 = collect($data)->firstWhere('id', 1);
        $task2 = collect($data)->firstWhere('id', 2);

        $this->assertCount(1, $task1->assignees);
        $this->assertEquals('User 1', $task1->assignees[0]->name);
        $this->assertCount(0, $task2->assignees);
    }

    public function test_toplevel_filter_filters_tasks_but_loads_all_relationships(): void
    {
        // Scenario 2: tasks?select=*,assignees(*)&assignees.name=eq.User 1
        // Expect Task 1 ONLY (because Task 2 doesn't have User 1).
        // Expect Task 1 to have BOTH User 1 and User 2 loaded.

        $request = Request::create('/api/v1/tasks', 'GET', [
            'select' => '*,assignees(*)',
            'assignees.name' => 'eq.User 1',
        ]);

        $schema = SchemaRegistryUtils::get();
        $config = $schema['tasks'];

        $result = RecordService::applyRequestFilters($request, $config);
        $data = $result['data'];

        // Should return 1 task
        $this->assertCount(1, $data);
        $this->assertEquals(1, $data[0]->id);

        // Task 1 should have 2 assignees (User 1 and User 2)
        $this->assertCount(2, $data[0]->assignees);
    }

    public function test_toplevel_filter_with_non_existing_assignee_returns_empty(): void
    {
        $request = Request::create('/api/v1/tasks', 'GET', [
            'select' => '*,assignees(*)',
            'assignees.name' => 'eq.NonExistingUser',
        ]);

        $schema = SchemaRegistryUtils::get();
        $config = $schema['tasks'];

        $result = RecordService::applyRequestFilters($request, $config);
        $data = $result['data'];

        $this->assertCount(0, $data);
    }

    public function test_association_type_supports_has_many_through_params(): void
    {
        Config::set('record.tables', [
            'tasks' => new RecordTableType(
                table: 'tasks',
                pmsName: 'tasks',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [
                    'assignees' => new RecordAassociationType(
                        related: 'meta',
                        type: RecordRelationshipsEnum::HAS_MANY_THROUGH,
                        fromObjectType: 'tasks',
                        fromObjectId: 'owner_id',
                        toObjectType: 'users',
                        toObjectId: 'target_id',
                    ),
                ],
            ),
            'users' => new RecordTableType(
                table: 'users',
                pmsName: 'users',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
            ),
        ]);

        SchemaRegistryUtils::refresh();

        $request = Request::create('/api/v1/tasks', 'GET', [
            'select' => '*,assignees(*)',
        ]);

        $schema = SchemaRegistryUtils::get();
        $config = $schema['tasks'];

        $result = RecordService::applyRequestFilters($request, $config);
        $data = $result['data'];

        $this->assertCount(2, $data);
        $this->assertCount(2, $data[0]->assignees);
    }

    public function test_association_type_resolves_allow_flags(): void
    {
        Config::set('record.tables', [
            'tasks' => new RecordTableType(
                table: 'tasks',
                pmsName: 'tasks',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [
                    'assignees' => new RecordAassociationType(
                        related: 'meta',
                        type: RecordRelationshipsEnum::HAS_MANY_THROUGH,
                        fromObjectType: 'tasks',
                        fromObjectId: 'owner_id',
                        toObjectType: 'users',
                        toObjectId: 'target_id',
                        allowCreate: false,
                        allowUpdate: false,
                        allowDelete: true,
                    ),
                ],
            ),
            'users' => new RecordTableType(
                table: 'users',
                pmsName: 'users',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
            ),
        ]);

        SchemaRegistryUtils::refresh();

        $config = RelationshipResolverUtils::resolveRelationship('tasks', 'assignees');

        $this->assertFalse($config['allow_create']);
        $this->assertFalse($config['allow_update']);
        $this->assertTrue($config['allow_delete']);
    }
}
