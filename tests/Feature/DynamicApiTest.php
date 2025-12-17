<?php

namespace Sopheak\Core\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Sopheak\Core\Tests\TestCase;
use Illuminate\Support\Facades\Config;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Types\RecordTablePublic;

class DynamicApiTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;
    protected function setUp(): void
    {
        parent::setUp();
        
        // Configure a test table for dynamic API
        Config::set('record.tables', [
            'users' => new RecordTableType(
                pms_name: 'users',
                table: 'users',
                public: new RecordTablePublic(
                    read: true,
                    write: true
                ),
                relationships: [],
                soft_deletes: false,
                has_tenant_id: false
            ),
        ]);
    }

    /** @test */
    public function it_can_list_records_via_dynamic_api(): void
    {
        // Create test users
        User::factory(3)->create();

        $testResponse = $this->getJson('/api/test_users');

        $testResponse->assertStatus(200)
                ->assertJsonStructure([
                    'data' => [
                        '*' => [
                            'id',
                            'name',
                            'email',
                            'created_at',
                            'updated_at'
                        ]
                    ],
                    'meta' => [
                        'current_page',
                        'per_page',
                        'total'
                    ]
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

        $testResponse = $this->postJson('/api/test_users', $userData);

        $testResponse->assertStatus(201)
                ->assertJsonStructure([
                    'data' => [
                        'id',
                        'name',
                        'email',
                        'created_at',
                        'updated_at'
                    ]
                ]);

        $this->assertDatabaseHas('users', [
            'name' => $userData['name'],
            'email' => $userData['email']
        ]);
    }

    /** @test */
    public function it_can_show_single_record_via_dynamic_api(): void
    {
        $user = User::factory()->create();

        $testResponse = $this->getJson('/api/test_users/' . $user->id);

        $testResponse->assertStatus(200)
                ->assertJsonStructure([
                    'data' => [
                        'id',
                        'name',
                        'email',
                        'created_at',
                        'updated_at'
                    ]
                ])
                ->assertJson([
                    'data' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email
                    ]
                ]);
    }

    /** @test */
    public function it_can_update_record_via_dynamic_api(): void
    {
        $user = User::factory()->create();
        $updateData = [
            'name' => 'Updated Name'
        ];

        $testResponse = $this->putJson('/api/test_users/' . $user->id, $updateData);

        $testResponse->assertStatus(200)
                ->assertJsonStructure([
                    'data' => [
                        'id',
                        'name',
                        'email',
                        'created_at',
                        'updated_at'
                    ]
                ])
                ->assertJson([
                    'data' => [
                        'name' => 'Updated Name'
                    ]
                ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Name'
        ]);
    }

    /** @test */
    public function it_can_delete_record_via_dynamic_api(): void
    {
        $user = User::factory()->create();

        $testResponse = $this->deleteJson('/api/test_users/' . $user->id);

        $testResponse->assertStatus(200)
                ->assertJson([
                    'message' => 'Record deleted successfully'
                ]);

        $this->assertDatabaseMissing('users', [
            'id' => $user->id
        ]);
    }

    /** @test */
    public function it_supports_query_filters(): void
    {
        $users = User::factory(5)->create();
        $targetUser = $users->first();

        $testResponse = $this->getJson('/api/test_users?filter[name]=' . $targetUser->name);

        $testResponse->assertStatus(200)
                ->assertJsonCount(1, 'data')
                ->assertJson([
                    'data' => [
                        [
                            'id' => $targetUser->id,
                            'name' => $targetUser->name
                        ]
                    ]
                ]);
    }

    /** @test */
    public function it_supports_sorting(): void
    {
        User::factory(3)->create();

        $testResponse = $this->getJson('/api/test_users?sort=-created_at');

        $testResponse->assertStatus(200);
        
        $data = $testResponse->json('data');
        $this->assertTrue(
            strtotime((string) $data[0]['created_at']) >= strtotime((string) $data[1]['created_at'])
        );
    }

    /** @test */
    public function it_supports_field_selection(): void
    {
        User::factory()->create();

        $testResponse = $this->getJson('/api/test_users?fields=id,name');

        $testResponse->assertStatus(200)
                ->assertJsonStructure([
                    'data' => [
                        '*' => [
                            'id',
                            'name'
                        ]
                    ]
                ]);

        // Ensure email is not included
        $data = $testResponse->json('data');
        $this->assertArrayNotHasKey('email', $data[0]);
    }

    /** @test */
    public function it_returns_404_for_non_existent_record(): void
    {
        $testResponse = $this->getJson('/api/test_users/999999');

        $testResponse->assertStatus(404)
                ->assertJson([
                    'message' => 'Record not found'
                ]);
    }

    /** @test */
    public function it_validates_required_fields_on_create(): void
    {
        $testResponse = $this->postJson('/api/test_users', [
            'name' => '', // Invalid: empty name
            'email' => 'invalid-email' // Invalid: not a valid email
        ]);

        $testResponse->assertStatus(422)
                ->assertJsonValidationErrors(['name', 'email']);
    }
}