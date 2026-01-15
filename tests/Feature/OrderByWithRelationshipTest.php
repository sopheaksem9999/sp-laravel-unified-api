<?php

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Support\SchemaRegistry;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordBelongsToType;

use Illuminate\Support\Facades\Schema;

class OrderByWithRelationshipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Setup tables
        if (!Schema::hasTable('users')) {
            Schema::create('users', function ($table): void {
                $table->id();
                $table->string('name');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('posts')) {
            Schema::create('posts', function ($table): void {
                $table->id();
                $table->string('title');
                $table->foreignId('user_id');
                $table->timestamps();
            });
        }
    }

    public function test_order_by_is_preserved_with_relationships(): void
    {
        // Insert data with specific timestamps to test sorting
        $userId = DB::table('users')->insertGetId(['name' => 'User 1', 'created_at' => now(), 'updated_at' => now()]);

        // Post 1: created 3 days ago (Oldest)
        DB::table('posts')->insert([
            'title' => 'Post 1',
            'user_id' => $userId,
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ]);

        // Post 2: created 1 day ago (Newest)
        DB::table('posts')->insert([
            'title' => 'Post 2',
            'user_id' => $userId,
            'created_at' => now()->subDays(1),
            'updated_at' => now()->subDays(1),
        ]);

        // Post 3: created 2 days ago (Middle)
        DB::table('posts')->insert([
            'title' => 'Post 3',
            'user_id' => $userId,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        // Config
        $config = [
            'posts' => new RecordTableType(
                table: 'posts',
                pms_name: 'post',
                has_tenant_id: false,
                public: new RecordTablePublic(read: true),
                relationships: [
                    'user' => new RecordBelongsToType(
                        table: 'users',
                        foreignKey: 'user_id'
                    )
                ]
            ),
            'users' => new RecordTableType(
                table: 'users',
                pms_name: 'user',
                has_tenant_id: false,
                public: new RecordTablePublic(read: true)
            )
        ];

        Config::set('record.tables', $config);
        SchemaRegistry::refresh();

        // Request with explicit sort and relationship (triggers subquery optimization)
        // Sort by created_at DESC -> Expected: Post 2, Post 3, Post 1
        $request = Request::create('/api/v1/posts?select=*,user(*)&sortby=created_at&order=desc', 'GET');

        $service = app(RecordService::class);
        $result = $service->listRecords($request, 'posts', null);
        $data = $result['data'];

        $this->assertCount(3, $data);

        // Convert to array if objects
        $titles = array_map(fn($item) => $item->title, $data);

        // This assertion might fail if the bug exists
        $this->assertEquals(['Post 2', 'Post 3', 'Post 1'], $titles, 'Explicit sort order failed with relationship');
    }

    public function test_default_order_by_created_at_desc(): void
    {
        // Config (Reuse setup from previous test or re-setup if needed, but schema is persistent in memory db usually)
        // We need to re-config because previous test might have modified global config state?
        // Actually TestCase refreshes app state.

        // Re-insert data
        $userId = DB::table('users')->insertGetId(['name' => 'User 1', 'created_at' => now(), 'updated_at' => now()]);

        DB::table('posts')->insert([
            'title' => 'Post 1',
            'user_id' => $userId,
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ]);

        DB::table('posts')->insert([
            'title' => 'Post 2',
            'user_id' => $userId,
            'created_at' => now()->subDays(1),
            'updated_at' => now()->subDays(1),
        ]);

        DB::table('posts')->insert([
            'title' => 'Post 3',
            'user_id' => $userId,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        $config = [
            'posts' => new RecordTableType(
                table: 'posts',
                pms_name: 'post',
                has_tenant_id: false,
                public: new RecordTablePublic(read: true),
                relationships: [
                    'user' => new RecordBelongsToType(
                        table: 'users',
                        foreignKey: 'user_id'
                    )
                ]
            ),
            'users' => new RecordTableType(
                table: 'users',
                pms_name: 'user',
                has_tenant_id: false,
                public: new RecordTablePublic(read: true)
            )
        ];

        Config::set('record.tables', $config);
        SchemaRegistry::refresh();

        // No sort parameters
        $request = Request::create('/api/v1/posts?select=*,user(*)', 'GET');

        $service = app(RecordService::class);
        $result = $service->listRecords($request, 'posts', null);
        $data = $result['data'];

        $this->assertCount(3, $data);

        $titles = array_map(fn($item) => $item->title, $data);

        // Expected: Post 2, Post 3, Post 1 (Newest first)
        $this->assertEquals(['Post 2', 'Post 3', 'Post 1'], $titles, 'Default sort order failed');
    }
}
