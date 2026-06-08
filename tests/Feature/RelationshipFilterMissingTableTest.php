<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordHasManyThroughType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;

class RelationshipFilterMissingTableTest extends TestCase
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

        // Insert data
        $taskId1 = DB::table('tasks')->insertGetId(['title' => 'Task 1']);
        $taskId2 = DB::table('tasks')->insertGetId(['title' => 'Task 2']);

        $userId1 = DB::table('users')->insertGetId(['name' => 'User 1']);
        $userId2 = DB::table('users')->insertGetId(['name' => 'User 2']);

        // Task 1 has User 1
        DB::table('task_assignees')->insert(['task_id' => $taskId1, 'user_id' => $userId1]);
        // Task 2 has User 2
        DB::table('task_assignees')->insert(['task_id' => $taskId2, 'user_id' => $userId2]);
    }

    public function test_filter_relationship_missing_from_schema_registry_is_ignored(): void
    {
        // Configure ONLY tasks table, not users
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
        ]);

        SchemaRegistryUtils::refresh();

        // Filter by assignees.name = User 1
        // Expectation: Only Task 1 should be returned.
        // Bug: If users table is not in schema registry, filter is ignored and both tasks returned.
        $request = Request::create('/api/v1/tasks', 'GET', [
            'assignees.name' => 'eq.User 1',
        ]);

        // We need to resolve the config manually since we are calling RecordService directly
        $schema = SchemaRegistryUtils::get();
        $config = $schema['tasks'];

        $result = RecordService::applyRequestFilters($request, $config);

        // With the bug, we expect 2 records (filter ignored)
        // Once fixed, we expect 1 record.
        // For now, I assert 2 to confirm reproduction, or I can assert 1 and expect failure.
        // Let's assert 1 and see it fail.
        $this->assertCount(1, $result['data'], 'Filter was ignored! expected 1 record but got ' . count($result['data']));
        $this->assertEquals('Task 1', $result['data'][0]->title);
    }
}
