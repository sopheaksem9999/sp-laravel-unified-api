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
use Sopheak\Core\Types\RecordMetaBelongsToManyType;
use Sopheak\Core\Tests\TestCase;

class RelationshipMixedFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Create tables
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->string('title');
            $table->string('status');
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('user_roles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->foreignId('role_id');
            $table->timestamps();
        });

        // Seed data
        // User 1: Admin & User, Has Published & Draft posts
        $u1 = DB::table('users')->insertGetId(['name' => 'User 1']);
        $rAdmin = DB::table('roles')->insertGetId(['name' => 'admin']);
        $rUser = DB::table('roles')->insertGetId(['name' => 'user']);

        DB::table('user_roles')->insert([
            ['user_id' => $u1, 'role_id' => $rAdmin],
            ['user_id' => $u1, 'role_id' => $rUser]
        ]);

        DB::table('posts')->insert([
            ['user_id' => $u1, 'title' => 'Post A', 'status' => 'published'],
            ['user_id' => $u1, 'title' => 'Post B', 'status' => 'draft']
        ]);

        // User 2: User only, Has Published post
        $u2 = DB::table('users')->insertGetId(['name' => 'User 2']);
        DB::table('user_roles')->insert([
            ['user_id' => $u2, 'role_id' => $rUser]
        ]);
        DB::table('posts')->insert([
            ['user_id' => $u2, 'title' => 'Post C', 'status' => 'published']
        ]);

        // User 3: Editor, Has Draft post
        $u3 = DB::table('users')->insertGetId(['name' => 'User 3']);
        $rEditor = DB::table('roles')->insertGetId(['name' => 'editor']);
        DB::table('user_roles')->insert([
            ['user_id' => $u3, 'role_id' => $rEditor]
        ]);
        DB::table('posts')->insert([
            ['user_id' => $u3, 'title' => 'Post D', 'status' => 'draft']
        ]);

        // Register Schema
        $config = new RecordTableType('users');
        $config->disableCache = true;
        $config->relationships = [
            'posts' => new RecordHasManyType('posts', 'user_id'),
            'roles' => new RecordMetaBelongsToManyType(
                related: 'roles',
                table: 'user_roles',
                foreignPivotKey: 'user_id',
                relatedPivotKey: 'role_id'
            )
        ];
        // Must update config because RelationshipResolverUtils reads from config('record.tables')
        config(['record.tables.users' => $config]);

        SchemaRegistryUtils::register('users', $config);

        // Also register related tables
        SchemaRegistryUtils::register('posts', new RecordTableType('posts'));
        SchemaRegistryUtils::register('roles', new RecordTableType('roles'));
    }

    public function test_mixed_nested_and_top_level_filtering(): void
    {
        // Query: users?select=*,posts(*,status=eq.published),roles(*)&roles.name=eq.admin
        // Expects:
        // - Filter users by roles.name=admin (User 1 only)
        // - Load posts with status=published (Post A only)
        // - Load all roles (Admin & User)

        $request = Request::create('/api/v1/users', 'GET', [
            'select' => '*,posts(*,status=eq.published),roles(*)',
            'roles.name' => 'eq.admin'
        ]);

        $result = RecordService::applyRequestFilters($request, 'users');
        $data = $result['data'];

        // Should only return User 1
        $this->assertCount(1, $data);
        $user = $data[0];
        $this->assertEquals('User 1', $user->name);

        // Check loaded posts (filtered)
        $this->assertCount(1, $user->posts);
        $this->assertEquals('Post A', $user->posts[0]->title);

        // Check loaded roles (unfiltered in select)
        $this->assertCount(2, $user->roles);
        $roleNames = array_map(fn($r) => $r->name, $user->roles);
        $this->assertContains('admin', $roleNames);
        $this->assertContains('user', $roleNames);
    }

    public function test_builder_macro_only_select_and_object_flags(): void
    {
        $request = Request::create('/api/v1/users', 'GET', [
            'select' => '*,posts(*),roles(*)',
            'name' => 'eq.User 2',
        ]);

        $result = DB::table('users')
            ->where('id', 1)
            ->applyRequestFilters($request, true);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(1, $result['data']);
        $this->assertIsObject($result['data'][0]);
        $this->assertEquals('User 1', $result['data'][0]->name);
    }
}
