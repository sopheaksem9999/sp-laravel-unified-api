<?php

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Config;
use Illuminate\Http\Request;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\Validator;
use Sopheak\Core\Support\QueryBuilderFilters;
use Sopheak\Core\Support\SchemaRegistry;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Enums\RecordApiJsonResponseEnum;

class DynamicApiTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password')->nullable();
            $table->timestamps();
        });

        Schema::create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('status')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        // Configure a test table for dynamic API
        Config::set('record.tables', [
            'users' => new RecordTableType(
                table: 'users',
                pms_name: 'users',
                has_tenant_id: false,
                soft_deletes: false,
                public: new RecordTablePublic(
                    read: true,
                    write: true
                ),
                relationships: [],
                createValidator: fn(Request $request, ?int $id = null): ValidatorContract => Validator::make($request->all(), [
                    'name' => 'required|string|max:255',
                    'email' => 'required|email',
                ]),
                updateValidator: fn(Request $request, ?int $id = null): ValidatorContract => Validator::make($request->all(), [
                    'name' => 'sometimes|required|string|max:255',
                    'email' => 'sometimes|required|email',
                ]),
            ),
            'tasks' => new RecordTableType(
                table: 'tasks',
                pms_name: 'tasks',
                has_tenant_id: false,
                soft_deletes: true,
                public: new RecordTablePublic(
                    read: true,
                    write: true
                ),
                relationships: [],
            ),
        ]);

        // Refresh SchemaRegistry to pick up the new config
        SchemaRegistry::refresh();
    }

    /** @test */
    public function it_can_list_records_via_dynamic_api(): void
    {
        // Create test users
        for ($i = 0; $i < 3; ++$i) {
            DB::table('users')->insert([
                'name' => $this->faker->name,
                'email' => $this->faker->unique()->safeEmail,
                'password' => 'password123',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $testResponse = $this->getJson('/api/users?per_page=15');

        $testResponse->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'email',
                        'created_at',
                        'updated_at'
                    ]
                ],
                'meta'
            ]);
    }

    /** @test */
    public function it_can_create_record_via_dynamic_api(): void
    {
        $userData = [
            'name' => $this->faker->name,
            'email' => $this->faker->unique()->safeEmail,
            'password' => 'password123'
        ];

        $testResponse = $this->postJson('/api/users', $userData);

        $testResponse->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'name',
                    'email',
                    'created_at',
                    'updated_at'
                ],
                'meta'
            ]);

        $this->assertDatabaseHas('users', [
            'name' => $userData['name'],
            'email' => $userData['email']
        ]);
    }

    /** @test */
    public function it_can_show_single_record_via_dynamic_api(): void
    {
        $userId = (int) DB::table('users')->insertGetId([
            'name' => $this->faker->name,
            'email' => $this->faker->unique()->safeEmail,
            'password' => 'password123',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $testResponse = $this->getJson('/api/users/' . $userId);

        $testResponse->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'name',
                    'email',
                    'created_at',
                    'updated_at'
                ],
                'meta'
            ])
            ->assertJson([
                'data' => [
                    'id' => $userId,
                ]
            ]);
    }

    /** @test */
    public function it_can_update_record_via_dynamic_api(): void
    {
        $userId = (int) DB::table('users')->insertGetId([
            'name' => $this->faker->name,
            'email' => $this->faker->unique()->safeEmail,
            'password' => 'password123',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $updateData = [
            'name' => 'Updated Name'
        ];

        $testResponse = $this->putJson('/api/users/' . $userId, $updateData);

        $testResponse->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'name',
                    'email',
                    'created_at',
                    'updated_at'
                ],
                'meta'
            ])
            ->assertJson([
                'data' => [
                    'name' => 'Updated Name'
                ]
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'name' => 'Updated Name'
        ]);
    }

    /** @test */
    public function it_can_delete_record_via_dynamic_api(): void
    {
        $userId = (int) DB::table('users')->insertGetId([
            'name' => $this->faker->name,
            'email' => $this->faker->unique()->safeEmail,
            'password' => 'password123',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $testResponse = $this->deleteJson('/api/users/' . $userId);

        $testResponse->assertStatus(200)
            ->assertJsonStructure(['success', 'data', 'meta']);

        $this->assertDatabaseMissing('users', [
            'id' => $userId
        ]);
    }

    /** @test */
    public function it_supports_query_filters(): void
    {
        $targetUser = null;
        for ($i = 0; $i < 5; ++$i) {
            $name = $this->faker->name;
            $email = $this->faker->unique()->safeEmail;
            $id = (int) DB::table('users')->insertGetId([
                'name' => $name,
                'email' => $email,
                'password' => 'password123',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $targetUser ??= ['id' => $id, 'name' => $name];
        }

        $testResponse = $this->getJson('/api/users?name=eq.' . urlencode($targetUser['name']));

        $testResponse->assertStatus(200)
            ->assertJsonStructure(['success', 'data', 'meta'])
            ->assertJsonCount(1, 'data')
            ->assertJson([
                'data' => [
                    [
                        'id' => $targetUser['id'],
                        'name' => $targetUser['name'],
                    ],
                ],
            ]);
    }

    /** @test */
    public function it_supports_sorting(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            DB::table('users')->insert([
                'name' => $this->faker->name,
                'email' => $this->faker->unique()->safeEmail,
                'password' => 'password123',
                'created_at' => now()->subSeconds(10 - $i),
                'updated_at' => now()->subSeconds(10 - $i),
            ]);
        }

        $testResponse = $this->getJson('/api/users?sort=-created_at');

        $testResponse->assertStatus(200);

        $data = $testResponse->json('data');
        $this->assertTrue(
            strtotime((string) $data[0]['created_at']) >= strtotime((string) $data[1]['created_at'])
        );
    }

    /** @test */
    public function it_supports_field_selection(): void
    {
        DB::table('users')->insert([
            'name' => $this->faker->name,
            'email' => $this->faker->unique()->safeEmail,
            'password' => 'password123',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $testResponse = $this->getJson('/api/users?select=id,name');

        $testResponse->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'name'
                    ]
                ],
                'meta'
            ]);

        // Ensure email is not included
        $data = $testResponse->json('data');
        $this->assertArrayNotHasKey('email', $data[0]);
    }

    /** @test */
    public function it_supports_group_by_and_aggregate(): void
    {
        DB::table('tasks')->insert([
            'name' => 'Task A',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tasks')->insert([
            'name' => 'Task B',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tasks')->insert([
            'name' => 'Task C',
            'status' => 'closed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $testResponse = $this->getJson('/api/tasks?group_by=status&aggregate=count:id');

        $testResponse->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'status',
                        'count_id',
                    ],
                ],
                'meta' => [
                    'total',
                    'group_by',
                    'aggregate',
                ],
            ]);

        $data = collect($testResponse->json('data'))->keyBy('status');
        $this->assertEquals(2, $data['open']['count_id']);
        $this->assertEquals(1, $data['closed']['count_id']);
    }

    /** @test */
    public function it_clears_ordering_for_simple_count_aggregate(): void
    {
        DB::table('tasks')->insert([
            'name' => 'Task A',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $request = Request::create('/api/tasks', 'GET', [
            'aggregate' => 'count',
        ]);

        $builder = DB::table('tasks');

        QueryBuilderFilters::apply($builder, $request, 'tasks', 'id');

        $this->assertStringContainsStringIgnoringCase('order by', $builder->toSql());

        QueryBuilderFilters::applyAggregateAndGroupBy($builder, $request, 'tasks');

        $this->assertStringNotContainsStringIgnoringCase('order by', $builder->toSql());
    }

    /** @test */
    public function it_ignores_previous_select_for_simple_count_aggregate(): void
    {
        DB::table('tasks')->insert([
            'name' => 'Task A',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $request = Request::create('/api/tasks', 'GET', [
            'aggregate' => 'count',
            'select' => '*',
        ]);

        $builder = DB::table('tasks');

        QueryBuilderFilters::apply($builder, $request, 'tasks', 'id');

        QueryBuilderFilters::applyAggregateAndGroupBy($builder, $request, 'tasks');

        $sql = $builder->toSql();

        $this->assertStringContainsStringIgnoringCase('count(*) as count', $sql);
        $this->assertStringNotContainsStringIgnoringCase('\"tasks\".*', $sql);
    }

    /** @test */
    public function it_supports_only_trashed_for_soft_deleted_tables(): void
    {
        $activeId = (int) DB::table('tasks')->insertGetId([
            'name' => 'Active Task',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $deletedId = (int) DB::table('tasks')->insertGetId([
            'name' => 'Deleted Task',
            'status' => 'open',
            'deleted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $allResponse = $this->getJson('/api/tasks');
        $allResponse->assertStatus(200);

        $allIds = collect($allResponse->json('data'))->pluck('id')->all();
        $this->assertContains($activeId, $allIds);
        $this->assertNotContains($deletedId, $allIds);

        $trashedResponse = $this->getJson('/api/tasks?only_trashed=1');
        $trashedResponse->assertStatus(200);

        $trashedIds = collect($trashedResponse->json('data'))->pluck('id')->all();
        $this->assertContains($deletedId, $trashedIds);
        $this->assertNotContains($activeId, $trashedIds);
    }

    /** @test */
    public function it_returns_404_for_non_existent_record(): void
    {
        $testResponse = $this->getJson('/api/users/999999');

        $testResponse->assertStatus(404)
            ->assertJson([
                'message' => 'Not found'
            ]);
    }

    /** @test */
    public function it_validates_required_fields_on_create(): void
    {
        $testResponse = $this->postJson('/api/users', [
            'name' => '', // Invalid: empty name
            'email' => 'invalid-email' // Invalid: not a valid email
        ]);

        $testResponse->assertStatus((int) RecordApiJsonResponseEnum::VALIDATION_ERROR->value)
            ->assertJson([
                'success' => false,
                'message' => 'Validation failed',
            ])
            ->assertJsonStructure([
                'errors' => ['name', 'email'],
            ]);
    }
}
