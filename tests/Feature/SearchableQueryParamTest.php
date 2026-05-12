<?php

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Types\RecordMetaBelongsToManyType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class SearchableQueryParamTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->string('title');
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

        $user1 = DB::table('users')->insertGetId(['name' => 'User 1']);
        $user2 = DB::table('users')->insertGetId(['name' => 'User 2']);
        $adminRole = DB::table('roles')->insertGetId(['name' => 'admin']);
        $editorRole = DB::table('roles')->insertGetId(['name' => 'editor']);

        DB::table('user_roles')->insert([
            ['user_id' => $user1, 'role_id' => $adminRole],
            ['user_id' => $user2, 'role_id' => $editorRole],
        ]);

        DB::table('posts')->insert([
            ['user_id' => $user1, 'title' => 'Post A'],
            ['user_id' => $user2, 'title' => 'Post B'],
        ]);

        $config = new RecordTableType(
            table: 'users',
            pmsName: 'users',
            hasTenantId: false,
            softDeletes: false,
            public: new RecordTablePublic(read: true, write: true),
            searchable: ['name', 'posts.title', 'roles.name'],
            relationships: [
                'posts' => new RecordHasManyType(table: 'posts', foreignKey: 'user_id'),
                'roles' => new RecordMetaBelongsToManyType(
                    related: 'roles',
                    table: 'user_roles',
                    foreignPivotKey: 'user_id',
                    relatedPivotKey: 'role_id'
                ),
            ]
        );

        SchemaRegistryUtils::register('users', $config);
        SchemaRegistryUtils::register('posts', new RecordTableType(table: 'posts'));
        SchemaRegistryUtils::register('roles', new RecordTableType(table: 'roles'));
    }

    public function test_search_param_uses_configured_root_and_relationship_fields(): void
    {
        $rootRequest = Request::create('/api/v1/users', 'GET', [
            'search' => 'User 2',
        ]);

        $rootResult = RecordService::applyRequestFilters($rootRequest, 'users');
        $rootData = $rootResult['data'];

        $this->assertCount(1, $rootData);
        $this->assertEquals('User 2', $rootData[0]->name);

        $relationRequest = Request::create('/api/v1/users', 'GET', [
            'search' => 'admin',
        ]);

        $relationResult = RecordService::applyRequestFilters($relationRequest, 'users');
        $relationData = $relationResult['data'];

        $this->assertCount(1, $relationData);
        $this->assertEquals('User 1', $relationData[0]->name);
    }
}
