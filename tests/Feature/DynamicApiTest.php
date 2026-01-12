<?php

namespace Sopheak\Core\Tests\Feature;

use Sopheak\Core\Support\SchemaRegistry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Tests\TestCase;
use Illuminate\Support\Facades\Config;
use Illuminate\Http\Request;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\Validator;
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

        // Configure a test table for dynamic API
        Config::set('record.tables', [
            'users' => new RecordTableType(
                pms_name: 'users',
                table: 'users',
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
