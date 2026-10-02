<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Sopheak\Core\Mcp\SchemaTools;
use Sopheak\Core\Tests\Concerns\BuildsGuidanceFixture;
use Sopheak\Core\Tests\Concerns\CreatesPermissionTables;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * The error table in sp_api_get_api_guidance is only useful if it matches what
 * the API really answers. Each row is checked against a real response.
 */
class McpGuidanceErrorsTest extends TestCase
{
    use BuildsGuidanceFixture;
    use CreatesPermissionTables;
    use RefreshDatabase;

    private Authenticatable $user;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('permissions.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPermissionTables();
        $this->createUsersTable();
        $this->buildGuidanceFixture(tenant: true);

        $tables = Config::get('record.tables');
        $tables['invoices']->isAuthRead = true;
        $tables['invoices']->isAuthWrite = true;
        Config::set('record.tables', $tables);
        Config::set('record.default_validation.enabled', true);
        SchemaRegistryUtils::refresh();

        $this->user = new class extends Authenticatable {
            protected $table = 'users';

            public $timestamps = false;

            protected $fillable = ['id', 'name', 'is_admin'];
        };
        $this->user->forceFill(['id' => 1, 'name' => 'Test', 'is_admin' => false]);
        $this->user->save();
    }

    /** @return array<string, mixed> */
    private function row(int $status, string $name): array
    {
        foreach (app(SchemaTools::class)->apiGuidance()['errors']['http'] as $row) {
            if ($row['status'] === $status && $row['name'] === $name) {
                return $row;
            }
        }

        $this->fail(sprintf('No %d %s row in the guidance error table', $status, $name));
    }

    private function superAdmin(): void
    {
        Config::set('permissions.super_admin_callback', fn($user): true => true);
    }

    public function test_401_matches_the_table(): void
    {
        $row = $this->row(401, 'INVALID_ACCESS');

        $this->getJson('/api/invoices', ['X-Tenant-ID' => '1'])
            ->assertStatus($row['status'])
            ->assertJsonPath('error_code', $row['error_code']);
    }

    public function test_403_matches_the_table(): void
    {
        $row = $this->row(403, 'PERMISSION_DENIED');

        $this->actingAs($this->user, 'api')
            ->getJson('/api/invoices', ['X-Tenant-ID' => '1'])
            ->assertStatus($row['status'])
            ->assertJsonPath('error_code', $row['error_code']);
    }

    public function test_404_matches_the_table(): void
    {
        $this->superAdmin();
        $row = $this->row(404, 'RESOURCE_NOT_FOUND');

        $this->actingAs($this->user, 'api')
            ->getJson('/api/invoices/999999', ['X-Tenant-ID' => '1'])
            ->assertStatus($row['status'])
            ->assertJsonPath('error_code', $row['error_code']);
    }

    public function test_a_row_in_another_tenant_is_a_404_not_a_403(): void
    {
        $this->superAdmin();
        DB::table('invoices')->insert(['id' => 7, 'tenant_id' => '2', 'ref_number' => 'X', 'customer_id' => 1]);
        $row = $this->row(404, 'RESOURCE_NOT_FOUND');

        $this->actingAs($this->user, 'api')
            ->getJson('/api/invoices/7', ['X-Tenant-ID' => '1'])
            ->assertStatus($row['status'])
            ->assertJsonPath('error_code', $row['error_code']);
    }

    public function test_422_validation_matches_the_table_and_carries_errors(): void
    {
        $this->superAdmin();
        $row = $this->row(422, 'INVALID_REQUEST');

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/invoices', [], ['X-Tenant-ID' => '1'])
            ->assertStatus($row['status'])
            ->assertJsonPath('error_code', $row['error_code']);

        $this->assertIsArray($response->json('errors.ref_number'), 'errors is {field: [messages]}');
    }

    public function test_422_missing_tenant_header_matches_the_table(): void
    {
        $this->superAdmin();
        $row = $this->row(422, 'TENANT_HEADER_MISSING');

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/invoices')
            ->assertStatus($row['status'])
            ->assertJsonPath('error_code', $row['error_code']);

        $this->assertIsArray($response->json('errors.X-Tenant-ID'), 'errors names the header the table row mentions');
        $this->assertStringContainsString('X-Tenant-ID', $row['when']);
    }

    public function test_429_matches_the_table_and_sends_retry_after(): void
    {
        $this->superAdmin();
        RateLimiter::for('api-reads', fn() => Limit::perMinute(1)->by('errors-test'));
        $row = $this->row(429, 'THROTTLED');

        $this->actingAs($this->user, 'api')->getJson('/api/invoices', ['X-Tenant-ID' => '1'])->assertSuccessful();
        $response = $this->actingAs($this->user, 'api')->getJson('/api/invoices', ['X-Tenant-ID' => '1']);

        $response->assertStatus($row['status']);
        $this->assertNotNull($response->headers->get('Retry-After'));
    }

    public function test_the_tenant_row_is_absent_without_tenancy(): void
    {
        Config::set('record.enable_tenant_id', false);

        $names = array_column(app(SchemaTools::class)->apiGuidance()['errors']['http'], 'name');

        $this->assertNotContains('TENANT_HEADER_MISSING', $names);
        $this->assertContains('THROTTLED', $names);
    }

    public function test_the_mcp_codes_are_listed(): void
    {
        $mcp = app(SchemaTools::class)->apiGuidance()['errors']['mcp'];

        $this->assertSame(['-32001', '-32002', '-32601'], array_map(strval(...), array_keys($mcp)));
    }
}
