<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordBelongsToType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class ReadQueryPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private ?string $readDatabasePath = null;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('record.cache.enabled', false);
        Config::set('record.pagination.default_mode', 'offset');
        Config::set('record.pagination.skip_total_default', false);
        Config::set('record.database.read_connection');
        Config::set('record.index_hints', []);
    }

    protected function tearDown(): void
    {
        DB::purge('read_sqlite');

        if ($this->readDatabasePath !== null && file_exists($this->readDatabasePath)) {
            @unlink($this->readDatabasePath);
        }

        parent::tearDown();
    }

    public function test_cursor_default_mode_uses_cursor_pagination_without_cursor_parameter(): void
    {
        $this->createTasksTable();
        $this->configureTasks();
        Config::set('record.pagination.default_mode', 'cursor');

        foreach (range(1, 3) as $index) {
            DB::table('tasks')->insert([
                'name' => 'Task ' . $index,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $result = app(RecordService::class)->listRecords(
            Request::create('/api/tasks?per_page=2&skip_total=true', 'GET'),
            'tasks',
            null
        );

        $this->assertArrayHasKey('cursor', $result['meta']);
        $this->assertArrayHasKey('X-Cursor', $result['headers']);
        $this->assertSame(2, count($result['data']));
    }

    public function test_skip_total_avoids_count_queries_for_offset_and_cursor_pagination(): void
    {
        $this->createTasksTable();
        $this->configureTasks();

        foreach (range(1, 5) as $index) {
            DB::table('tasks')->insert([
                'name' => 'Task ' . $index,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $queries = [];
        DB::listen(static function ($event) use (&$queries): void {
            $queries[] = strtolower((string) $event->sql);
        });

        app(RecordService::class)->listRecords(
            Request::create('/api/tasks?per_page=2&skip_total=true', 'GET'),
            'tasks',
            null
        );

        app(RecordService::class)->listRecords(
            Request::create('/api/tasks?cursor=2&per_page=2&skip_total=true', 'GET'),
            'tasks',
            null
        );

        $countQueries = array_filter($queries, static fn(string $sql): bool => str_contains($sql, 'count('));

        $this->assertSame([], array_values($countQueries));
    }

    public function test_list_show_and_optimized_relationship_reads_use_configured_read_connection(): void
    {
        $this->createUsersAndPostsTables();
        $this->configureUsersAndPosts();
        $this->configureReadConnection();

        DB::table('users')->insert(['id' => 1, 'name' => 'default-user', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('posts')->insert(['id' => 1, 'title' => 'default-post', 'user_id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        DB::connection('read_sqlite')->table('users')->insert(['id' => 1, 'name' => 'read-user', 'created_at' => now(), 'updated_at' => now()]);
        DB::connection('read_sqlite')->table('posts')->insert(['id' => 1, 'title' => 'read-post', 'user_id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $service = app(RecordService::class);

        $list = $service->listRecords(Request::create('/api/posts?per_page=1', 'GET'), 'posts', null);
        $this->assertSame('read-post', $list['data'][0]->title);

        $show = $service->getRecord(Request::create('/api/posts/1', 'GET'), 'posts', 1, null);
        $this->assertSame('read-post', $show['data']->title);

        $withRelation = $service->listRecords(Request::create('/api/posts?select=*,user(*)&per_page=1', 'GET'), 'posts', null);
        $this->assertSame('read-post', data_get($withRelation, 'data.0.title'));
        $this->assertSame('read-user', data_get($withRelation, 'data.0.user.name'));
    }

    public function test_index_hints_are_ignored_on_non_mysql_drivers(): void
    {
        $this->createTasksTable();
        $this->configureTasks();
        Config::set('record.index_hints', [
            'tasks' => ['list' => 'idx_tasks_name'],
        ]);

        DB::table('tasks')->insert([
            'name' => 'Task 1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $queries = [];
        DB::listen(static function ($event) use (&$queries): void {
            $queries[] = strtolower((string) $event->sql);
        });

        $result = app(RecordService::class)->listRecords(Request::create('/api/tasks?per_page=1', 'GET'), 'tasks', null);

        $this->assertCount(1, $result['data']);
        $this->assertFalse(collect($queries)->contains(static fn(string $sql): bool => str_contains($sql, 'force index')));
    }

    private function createTasksTable(): void
    {
        Schema::create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    private function createUsersAndPostsTables(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
        });
    }

    private function configureTasks(): void
    {
        Config::set('record.tables', [
            'tasks' => new RecordTableType(
                table: 'tasks',
                pmsName: 'tasks',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
            ),
        ]);

        SchemaRegistryUtils::refresh();
    }

    private function configureUsersAndPosts(): void
    {
        Config::set('record.tables', [
            'posts' => new RecordTableType(
                table: 'posts',
                pmsName: 'posts',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [
                    'user' => new RecordBelongsToType(table: 'users', foreignKey: 'user_id'),
                ],
            ),
            'users' => new RecordTableType(
                table: 'users',
                pmsName: 'users',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
            ),
        ]);

        SchemaRegistryUtils::refresh();
    }

    private function configureReadConnection(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sp_read_');
        if (false === $path) {
            throw new RuntimeException('Unable to create a temporary read database.');
        }

        $this->readDatabasePath = $path;

        Config::set('database.connections.read_sqlite', [
            'driver' => 'sqlite',
            'database' => $this->readDatabasePath,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        Config::set('record.database.read_connection', 'read_sqlite');

        DB::purge('read_sqlite');

        DB::connection('read_sqlite')->getSchemaBuilder()->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        DB::connection('read_sqlite')->getSchemaBuilder()->create('posts', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
        });
    }
}
