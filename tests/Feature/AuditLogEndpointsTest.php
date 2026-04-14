<?php

namespace Sopheak\Core\Tests\Feature;

use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Auth\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Tests\TestCase;

class AuditLogEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('audit.enabled', true);
        config()->set('record.enabled', true);
        SchemaRegistryUtils::refresh();

        // Register the dynamic API routes
        $this->artisan('route:clear');
        require __DIR__ . '/../../routes/api.php';

        // Ensure the sp_audit_logs table exists to avoid runtime errors in tests
        if (!Schema::hasTable('sp_audit_logs')) {
            Schema::create('sp_audit_logs', function (Blueprint $table): void {
                $table->id();
                $table->string('entity_type')->nullable();
                $table->string('entity_id')->nullable();
                $table->string('entity_name')->nullable();
                $table->string('event')->nullable();
                $table->string('title')->nullable();
                $table->string('subject')->nullable();
                $table->text('recap')->nullable();
                $table->json('old_data')->nullable();
                $table->json('new_data')->nullable();
                $table->string('user_id')->nullable();
                $table->string('tenant_id')->nullable();
                $table->json('metadata')->nullable();
                $table->string('ip_address')->nullable();
                $table->string('user_agent')->nullable();
                $table->string('request_id')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password')->nullable();
                $table->rememberToken();
                $table->timestamps();
            });
        }

        // Create a test user
        $this->user = new User();
        $this->user->id = 1;
        $this->user->name = 'Admin User';
        $this->user->email = 'admin@example.com';

        DB::table('users')->insert([
            'id' => $this->user->id,
            'name' => $this->user->name,
            'email' => $this->user->email,
            'password' => 'password123',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Define the gate for testing
        Gate::define('manage-audit-logs', fn($user): bool => $user->id === 1);
        Gate::define('view:audit_log', fn($user): bool => $user->id === 1);
        Gate::define('create:audit_log', fn($user): bool => $user->id === 1);
        Gate::define('update:audit_log', fn($user): bool => $user->id === 1);
        Gate::define('delete:audit_log', fn($user): bool => $user->id === 1);
    }

    /** @test */
    public function it_requires_authentication_and_permission(): void
    {
        $response = $this->getJson('/api/sp_audit_logs');
        $response->assertStatus(401);

        $unauthorizedUser = new User();
        $unauthorizedUser->id = 2;
        $unauthorizedUser->name = 'Normal User';
        $unauthorizedUser->email = 'normal@example.com';

        DB::table('users')->insert([
            'id' => $unauthorizedUser->id,
            'name' => $unauthorizedUser->name,
            'email' => $unauthorizedUser->email,
            'password' => 'password123',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($unauthorizedUser, 'api')->getJson('/api/sp_audit_logs');
        $response->assertStatus(403);
    }

    /** @test */
    public function it_can_get_audit_logs(): void
    {
        // Insert some audit logs
        DB::table('sp_audit_logs')->insert([
            'entity_type' => 'invoices',
            'entity_id' => '100',
            'event' => 'created',
            'title' => 'Invoice Created',
            'user_id' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'api')->getJson('/api/sp_audit_logs?filter[entity_type]=invoices');
        $response->assertStatus(200);
        $this->assertEquals('invoices', $response->json('data.0.entity_type'));
    }

    /** @test */
    public function it_can_get_audit_stats(): void
    {
        DB::table('sp_audit_logs')->insert([
            ['entity_type' => 'invoices', 'entity_id' => '1', 'event' => 'created', 'created_at' => now(), 'updated_at' => now()],
            ['entity_type' => 'invoices', 'entity_id' => '2', 'event' => 'updated', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $response = $this->actingAs($this->user, 'api')->getJson('/api/sp_audit_logs/rpc/stats');

        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('data.total'));
        $this->assertEquals(1, $response->json('data.by_event.created'));
        $this->assertEquals(1, $response->json('data.by_event.updated'));
    }

    /** @test */
    public function it_can_get_field_timeline(): void
    {
        DB::table('sp_audit_logs')->insert([
            [
                'entity_type' => 'invoices',
                'entity_id' => '1',
                'event' => 'updated',
                'old_data' => json_encode(['status' => 'draft']),
                'new_data' => json_encode(['status' => 'paid']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->actingAs($this->user, 'api')->getJson('/api/sp_audit_logs/rpc/field-timeline/invoices/1/status');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('draft', $response->json('data.0.old_value'));
        $this->assertEquals('paid', $response->json('data.0.new_value'));
    }

    /** @test */
    public function it_can_get_field_stats(): void
    {
        DB::table('sp_audit_logs')->insert([
            [
                'entity_type' => 'invoices',
                'entity_id' => '1',
                'event' => 'updated',
                'old_data' => json_encode(['status' => 'draft']),
                'new_data' => json_encode(['status' => 'paid']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'entity_type' => 'invoices',
                'entity_id' => '1',
                'event' => 'updated',
                'old_data' => json_encode(['status' => 'paid']),
                'new_data' => json_encode(['status' => 'void']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->actingAs($this->user, 'api')->getJson('/api/sp_audit_logs/rpc/field-stats/invoices/1/status');

        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('data.total_changes'));
    }
}
