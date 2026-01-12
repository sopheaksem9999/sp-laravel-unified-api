<?php

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Support\SchemaRegistry;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Types\RecordBelongsToType;
use Sopheak\Core\Types\RecordHasManyThroughType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Enums\RecordRelationshipsEnum;
use Sopheak\Core\Tests\TestCase;

class HiddenColumnTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Create tables
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('password');
            $table->string('email');
            $table->string('remember_token')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->string('title');
            $table->string('secret');
            $table->timestamps();
        });

        Schema::create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->foreignId('reporter_id')->nullable();
            $table->timestamps();
        });

        Schema::create('task_assignees', function (Blueprint $table): void {
            $table->foreignId('task_id');
            $table->foreignId('user_id');
        });

        // Seed data
        $u1 = DB::table('users')->insertGetId([
            'name' => 'User 1',
            'password' => 'secret_password',
            'email' => 'user1@example.com',
            'remember_token' => 'token_123',
            'email_verified_at' => now(),
        ]);

        DB::table('posts')->insert([
            ['user_id' => $u1, 'title' => 'Post A', 'secret' => 'post_secret_1'],
            ['user_id' => $u1, 'title' => 'Post B', 'secret' => 'post_secret_2']
        ]);

        $t1 = DB::table('tasks')->insertGetId([
            'title' => 'Task 1',
            'reporter_id' => $u1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('task_assignees')->insert([
            ['task_id' => $t1, 'user_id' => $u1]
        ]);

        // Register Schema for Users
        $userConfig = new RecordTableType('users');
        $userConfig->disable_cache = true;
        $userConfig->column_hiddens = ['password', 'remember_token', 'email_verified_at']; // Hide multiple columns
        $userConfig->relationships = [
            'posts' => new RecordHasManyType('posts', 'user_id'),
        ];

        // Register Schema for Posts
        $postConfig = new RecordTableType('posts');
        $postConfig->disable_cache = true;
        $postConfig->column_hiddens = ['secret']; // Hide secret
        $postConfig->relationships = [
            'user' => new RecordBelongsToType(table: 'users', foreignKey: 'user_id'),
        ];

        // Register Schema for Tasks
        $taskConfig = new RecordTableType(
            pms_name: 'task',
            public: new RecordTablePublic(read: true, write: false),
            relationships: [
                'assignees' => new RecordHasManyThroughType(
                    table: 'users',
                    through: 'task_assignees',
                    firstKey: 'user_id',
                    secondLocalKey: 'task_id',
                    type: RecordRelationshipsEnum::HAS_MANY_THROUGH
                ),
                'reporter' => new RecordBelongsToType(table: 'users', foreignKey: 'reporter_id'),
            ]
        );

        SchemaRegistry::register('users', $userConfig);
        SchemaRegistry::register('posts', $postConfig);
        SchemaRegistry::register('tasks', $taskConfig);
    }

    public function test_hidden_columns_are_removed_from_main_resource(): void
    {
        $request = Request::create('/api/v1/users', 'GET', [
            'select' => '*'
        ]);

        $result = RecordService::applyRequestFilters($request, 'users');
        $data = $result['data'];

        $this->assertCount(1, $data);
        $user = (array) $data[0];

        $this->assertArrayHasKey('name', $user);
        $this->assertArrayHasKey('email', $user);
        $this->assertArrayNotHasKey('password', $user);
        $this->assertArrayNotHasKey('remember_token', $user);
        $this->assertArrayNotHasKey('email_verified_at', $user);
    }

    public function test_hidden_columns_are_removed_from_nested_relationships(): void
    {
        // Query users with posts
        $request = Request::create('/api/v1/users', 'GET', [
            'select' => '*,posts(*)'
        ]);

        $result = RecordService::applyRequestFilters($request, 'users');
        $data = $result['data'];

        $user = (array) $data[0];

        // Check main resource hidden column
        $this->assertArrayNotHasKey('password', $user);

        // Check nested resource hidden column
        $this->assertNotEmpty($user['posts']);
        foreach ($user['posts'] as $post) {
            $postArray = (array) $post;
            $this->assertArrayHasKey('title', $postArray);
            $this->assertArrayNotHasKey('secret', $postArray);
        }
    }

    public function test_hidden_columns_are_removed_from_belongs_to_relationship(): void
    {
        // Query posts with user
        $request = Request::create('/api/v1/posts', 'GET', [
            'select' => '*,user(*)'
        ]);

        $result = RecordService::applyRequestFilters($request, 'posts');
        $data = $result['data'];

        $post = (array) $data[0];

        // Check main resource hidden column
        $this->assertArrayNotHasKey('secret', $post);

        // Check nested resource hidden column
        $this->assertNotEmpty($post['user']);
        $user = (array) $post['user'];
        $this->assertArrayHasKey('name', $user);
        $this->assertArrayNotHasKey('password', $user);
    }

    public function test_hidden_columns_are_removed_from_has_many_through_relationship(): void
    {
        // Query tasks with assignees (users) and reporter
        $request = Request::create('/api/v1/tasks', 'GET', [
            'select' => '*,assignees(*),reporter(*)'
        ]);

        $result = RecordService::applyRequestFilters($request, 'tasks');
        $data = $result['data'];

        $this->assertCount(1, $data);
        $task = (array) $data[0];

        $this->assertArrayHasKey('title', $task);

        // Check nested resource (assignees) hidden column
        $this->assertArrayHasKey('assignees', $task);
        $this->assertNotEmpty($task['assignees']);

        foreach ($task['assignees'] as $assignee) {
            $assigneeArray = (array) $assignee;
            $this->assertArrayHasKey('name', $assigneeArray);
            $this->assertArrayNotHasKey('password', $assigneeArray);
            $this->assertArrayNotHasKey('remember_token', $assigneeArray);
            $this->assertArrayNotHasKey('email_verified_at', $assigneeArray);
        }

        // Check nested resource (reporter) hidden column
        $this->assertArrayHasKey('reporter', $task);
        $reporter = (array) $task['reporter'];
        $this->assertArrayHasKey('name', $reporter);
        $this->assertArrayNotHasKey('password', $reporter);
        $this->assertArrayNotHasKey('remember_token', $reporter);
        $this->assertArrayNotHasKey('email_verified_at', $reporter);
    }
}
