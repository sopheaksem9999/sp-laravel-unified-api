<?php

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Types\RecordBelongsToType;
use Sopheak\Core\Types\RecordHasManyThroughType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Enums\RecordRelationshipsEnum;
use Sopheak\Core\Tests\TestCase;

class HiddenColumnTest extends TestCase
{
    protected int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        // Create tables
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('password');
            $table->string('email')->nullable();
            $table->string('remember_token')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->string('title');
            $table->string('secret')->nullable();
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
        $this->userId = DB::table('users')->insertGetId([
            'name' => 'User 1',
            'password' => 'secret_password',
            'email' => 'user1@example.com',
            'remember_token' => 'token_123',
            'email_verified_at' => now(),
        ]);

        DB::table('posts')->insert([
            ['user_id' => $this->userId, 'title' => 'Post A', 'secret' => 'post_secret_1'],
            ['user_id' => $this->userId, 'title' => 'Post B', 'secret' => 'post_secret_2']
        ]);

        $t1 = DB::table('tasks')->insertGetId([
            'title' => 'Task 1',
            'reporter_id' => $this->userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('task_assignees')->insert([
            ['task_id' => $t1, 'user_id' => $this->userId]
        ]);

        // Register Schema for Users
        $userConfig = new RecordTableType('users');
        $userConfig->disableCache = true;
        $userConfig->columnHiddens = ['password', 'remember_token', 'email_verified_at']; // Hide multiple columns
        $userConfig->columnWriteDisabled = ['email'];
        $userConfig->relationships = [
            'posts' => new RecordHasManyType('posts', 'user_id'),
        ];

        // Register Schema for Posts
        $postConfig = new RecordTableType('posts');
        $postConfig->disableCache = true;
        $postConfig->columnHiddens = ['secret']; // Hide secret
        $postConfig->columnWriteDisabled = ['secret'];
        $postConfig->relationships = [
            'user' => new RecordBelongsToType(table: 'users', foreignKey: 'user_id'),
        ];

        // Register Schema for Tasks
        $taskConfig = new RecordTableType(
            table: 'tasks',
            pmsName: 'task',
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

        SchemaRegistryUtils::register('users', $userConfig);
        SchemaRegistryUtils::register('posts', $postConfig);
        SchemaRegistryUtils::register('tasks', $taskConfig);
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

    public function test_column_writes_are_filtered_on_create(): void
    {
        $service = new RecordService();

        $result = $service->createRecord('users', [
            'name' => 'Created User',
            'password' => 'plain_password',
            'email' => 'should_be_ignored@example.com',
        ], null);

        $id = $result['id'];
        $row = (array) DB::table('users')->where('id', $id)->first();

        $this->assertSame('Created User', $row['name']);
        $this->assertSame('plain_password', $row['password']);
        $this->assertNull($row['email']);
    }

    public function test_column_writes_are_filtered_on_update(): void
    {
        $service = new RecordService();

        $service->updateRecord('users', $this->userId, [
            'email' => 'updated_should_be_ignored@example.com',
        ], null);

        $row = (array) DB::table('users')->where('id', $this->userId)->first();
        $this->assertSame('user1@example.com', $row['email']);
    }

    public function test_column_writes_are_respected_for_nested_has_many_create(): void
    {
        $service = new RecordService();

        $result = $service->createRecord('users', [
            'name' => 'Nested User',
            'password' => 'nested_password',
            'email' => 'nested@example.com',
            'posts' => [
                ['title' => 'Nested Post', 'secret' => 'should_be_ignored'],
            ],
        ], null);

        $userId = $result['id'];
        $post = (array) DB::table('posts')->where('user_id', $userId)->first();

        $this->assertSame('Nested Post', $post['title']);
        $this->assertNull($post['secret']);
    }
}
