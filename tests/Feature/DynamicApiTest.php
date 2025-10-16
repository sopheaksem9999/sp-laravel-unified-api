<?php

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Sopheak\Core\Tests\TestCase;
use Illuminate\Support\Facades\Config;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Types\RecordTablePublic;

class DynamicApiTest extends TestCase
{
    use RefreshDatabase, WithFaker;

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
    public function it_can_list_records_via_dynamic_api()
    {
        // Create test users
        $users = \App\Models\User::factory(3)->create();

        $response = $this->getJson('/api/test_users');

        $response->assertStatus(200)
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
    public function it_can_create_record_via_dynamic_api()
    {
        $userData = [
            'name' => $this->faker->name,
            'email' => $this->faker->unique()->safeEmail,
            'password' => 'password123'
        ];

        $response = $this->postJson('/api/test_users', $userData);

        $response->assertStatus(201)
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
    public function it_can_show_single_record_via_dynamic_api()
    {
        $user = \App\Models\User::factory()->create();

        $response = $this->getJson("/api/test_users/{$user->id}");

        $response->assertStatus(200)
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
    public function it_can_update_record_via_dynamic_api()
    {
        $user = \App\Models\User::factory()->create();
        $updateData = [
            'name' => 'Updated Name'
        ];

        $response = $this->putJson("/api/test_users/{$user->id}", $updateData);

        $response->assertStatus(200)
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
    public function it_can_delete_record_via_dynamic_api()
    {
        $user = \App\Models\User::factory()->create();

        $response = $this->deleteJson("/api/test_users/{$user->id}");

        $response->assertStatus(200)
                ->assertJson([
                    'message' => 'Record deleted successfully'
                ]);

        $this->assertDatabaseMissing('users', [
            'id' => $user->id
        ]);
    }

    /** @test */
    public function it_supports_query_filters()
    {
        $users = \App\Models\User::factory(5)->create();
        $targetUser = $users->first();

        $response = $this->getJson("/api/test_users?filter[name]={$targetUser->name}");

        $response->assertStatus(200)
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
    public function it_supports_sorting()
    {
        \App\Models\User::factory(3)->create();

        $response = $this->getJson('/api/test_users?sort=-created_at');

        $response->assertStatus(200);
        
        $data = $response->json('data');
        $this->assertTrue(
            strtotime($data[0]['created_at']) >= strtotime($data[1]['created_at'])
        );
    }

    /** @test */
    public function it_supports_field_selection()
    {
        \App\Models\User::factory()->create();

        $response = $this->getJson('/api/test_users?fields=id,name');

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'data' => [
                        '*' => [
                            'id',
                            'name'
                        ]
                    ]
                ]);

        // Ensure email is not included
        $data = $response->json('data');
        $this->assertArrayNotHasKey('email', $data[0]);
    }

    /** @test */
    public function it_returns_404_for_non_existent_record()
    {
        $response = $this->getJson('/api/test_users/999999');

        $response->assertStatus(404)
                ->assertJson([
                    'message' => 'Record not found'
                ]);
    }

    /** @test */
    public function it_validates_required_fields_on_create()
    {
        $response = $this->postJson('/api/test_users', [
            'name' => '', // Invalid: empty name
            'email' => 'invalid-email' // Invalid: not a valid email
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['name', 'email']);
    }
}