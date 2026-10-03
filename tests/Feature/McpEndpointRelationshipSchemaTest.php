<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Sopheak\Core\CoreSpLaravelApiProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordBelongsToType;
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class McpEndpointRelationshipSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            CoreSpLaravelApiProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('record.mcp.enabled', true);
        $app['config']->set('record.mcp.read_only', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('mcp_customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('mcp_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id');
            $table->string('ref_number');
            $table->timestamps();
        });

        Config::set('record.tables', [
            'mcp_customers' => new RecordTableType(
                table: 'mcp_customers',
                pmsName: 'mcp_customers',
                hasTenantId: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [
                    'orders' => new RecordHasManyType(table: 'mcp_orders', foreignKey: 'customer_id'),
                ],
            ),
            'mcp_orders' => new RecordTableType(
                table: 'mcp_orders',
                pmsName: 'mcp_orders',
                hasTenantId: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [
                    'customer' => new RecordBelongsToType(table: 'mcp_customers', foreignKey: 'customer_id'),
                ],
            ),
        ]);

        Config::set('record.mcp.enabled', true);
        Config::set('record.mcp.read_only', false);

        SchemaRegistryUtils::refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function getEndpointSchema(string $endpoint): array
    {
        $response = $this->postJson('/api/mcp/message', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'sp_api_get_endpoint',
                'arguments' => ['endpoint' => $endpoint],
            ],
        ]);

        $response->assertStatus(200);

        return json_decode((string) $response->json('result.content.0.text'), true);
    }

    /** @test */
    public function has_many_relationship_is_reported_as_writable_with_a_payload_hint(): void
    {
        $schema = $this->getEndpointSchema('mcp_customers');

        $orders = collect($schema['includes'])->firstWhere('name', 'orders');

        $this->assertNotNull($orders);
        $this->assertSame('hasMany', $orders['type']);
        $this->assertTrue($orders['writable']);
        $this->assertTrue($orders['allowCreate']);
        $this->assertTrue($orders['allowUpdate']);
        $this->assertTrue($orders['allowDelete']);
        $this->assertStringContainsString('"orders"', $orders['payloadHint']);
        $this->assertStringContainsString('_delete', $orders['payloadHint']);
    }

    /** @test */
    public function belongs_to_relationship_is_reported_as_not_writable_with_fk_guidance(): void
    {
        $schema = $this->getEndpointSchema('mcp_orders');

        $customer = collect($schema['includes'])->firstWhere('name', 'customer');

        $this->assertNotNull($customer);
        $this->assertSame('belongsTo', $customer['type']);
        $this->assertFalse($customer['writable']);
        $this->assertArrayNotHasKey('allowCreate', $customer);
        $this->assertStringContainsString('customer_id', $customer['payloadHint']);
    }

    /** @test */
    public function create_tool_description_hints_at_nested_relationship_writes(): void
    {
        $response = $this->postJson('/api/mcp/message', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => [],
        ]);

        $tools = collect($response->json('result.tools'));
        $createCustomers = $tools->firstWhere('name', 'create_mcp_customers');

        $this->assertNotNull($createCustomers);
        $this->assertStringContainsString('sp_api_get_endpoint', $createCustomers['description']);
        $this->assertStringContainsString('single call', $createCustomers['description']);
    }
}
