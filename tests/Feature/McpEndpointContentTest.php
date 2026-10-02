<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Mcp\SchemaTools;
use Sopheak\Core\Tests\Concerns\BuildsGuidanceFixture;
use Sopheak\Core\Tests\Concerns\ParsesPayloadHints;
use Sopheak\Core\Tests\Concerns\ResolvesRefs;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * What sp_api_get_endpoint tells an agent about an endpoint (spec §6.6: W1,
 * W2, W4, W5, W6, M4, C1, C9). The endpoint is read straight from SchemaTools.
 */
class McpEndpointContentTest extends TestCase
{
    use BuildsGuidanceFixture;
    use ParsesPayloadHints;
    use RefreshDatabase;
    use ResolvesRefs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildGuidanceFixture();
    }

    /** @return array<string, mixed> */
    private function endpoint(string $name = 'invoices'): array
    {
        return $this->resolveRefs(app(SchemaTools::class)->getEndpoint(['endpoint' => $name]));
    }

    /** @return array<string, mixed> */
    private function include(string $alias): array
    {
        foreach ($this->endpoint()['includes'] as $include) {
            if ($include['name'] === $alias) {
                return $include;
            }
        }

        $this->fail('No include named ' . $alias);
    }

    public function test_laravel_integer_types_are_integers_in_schemas(): void
    {
        Schema::create('typed_rows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('qty');
            $table->dateTime('seen_at')->nullable();
            $table->date('due')->nullable();
            $table->uuid('ref')->nullable();
        });
        Config::set('record.tables', [
            'typed_rows' => new RecordTableType(
                table: 'typed_rows',
                pmsName: 'typed_rows',
                public: new RecordTablePublic(read: true, write: true),
                columns: [
                    'id' => ['type' => 'bigInteger', 'nullable' => false],
                    'qty' => ['type' => 'unsignedBigInteger', 'nullable' => false],
                    'seen_at' => ['type' => 'timestamp', 'nullable' => true],
                    'due' => ['type' => 'date', 'nullable' => true],
                    'ref' => ['type' => 'uuid', 'nullable' => true],
                ],
            ),
        ]);
        SchemaRegistryUtils::refresh();

        $endpoint = $this->endpoint('typed_rows');
        $read = $endpoint['actions']['read']['response']['dataSchema']['properties'];
        $create = $endpoint['actions']['create']['request']['payload']['properties'];

        $this->assertSame('integer', $read['id']['type']);
        $this->assertSame('integer', $create['qty']['type']);
        $this->assertSame(['type' => 'string', 'format' => 'date-time'], $read['seen_at']);
        $this->assertSame(['type' => 'string', 'format' => 'date'], $create['due']);
        $this->assertSame(['type' => 'string', 'format' => 'uuid'], $create['ref']);
    }

    public function test_date_columns_carry_a_format(): void
    {
        $endpoint = $this->endpoint();

        $this->assertSame('date', $endpoint['actions']['create']['request']['payload']['properties']['issued_at']['format']);
    }

    public function test_create_payload_lists_aliases_and_excludes_system_columns(): void
    {
        $properties = $this->endpoint()['actions']['create']['request']['payload']['properties'];

        foreach (['ref_number', 'customer_id', 'status', 'total_amount', 'issued_at', 'items', 'tags'] as $name) {
            $this->assertArrayHasKey($name, $properties, $name);
        }

        foreach (['id', 'created_at', 'updated_at', 'deleted_at', 'created_by_id'] as $name) {
            $this->assertArrayNotHasKey($name, $properties, $name);
        }

        $this->assertSame('array', $properties['items']['type']);
        $this->assertSame('array', $properties['tags']['type']);
        $this->assertSame(['type' => 'object'], $properties['items']['items']);
        $this->assertContains('integer', $properties['tags']['items']['type']);
    }

    public function test_upsert_payloads_do_not_offer_nested_aliases_the_endpoints_ignore(): void
    {
        $actions = $this->endpoint()['actions'];

        foreach (['upsert' => $actions['upsert']['request']['payload'], 'bulkUpsert' => $actions['bulkUpsert']['request']['payload']['items']] as $name => $schema) {
            $this->assertArrayNotHasKey('items', $schema['properties'], $name . ' does not write nested children');
            $this->assertArrayNotHasKey('tags', $schema['properties'], $name);
            $this->assertArrayHasKey('ref_number', $schema['properties'], $name);
        }

        foreach (['create' => $actions['create']['request']['payload'], 'update' => $actions['update']['request']['payload'], 'bulkCreate' => $actions['bulkCreate']['request']['payload']['items'], 'bulkUpdate' => $actions['bulkUpdate']['request']['payload']['items']] as $name => $schema) {
            $this->assertArrayHasKey('items', $schema['properties'], $name . ' writes nested children');
        }

        $this->assertStringContainsString('upsert', implode(' ', app(SchemaTools::class)->apiGuidance()['nestedWrites']['rules']));
    }

    public function test_read_schemas_still_show_system_columns(): void
    {
        $properties = $this->endpoint()['actions']['read']['response']['dataSchema']['properties'];

        foreach (['id', 'created_at', 'updated_at', 'deleted_at'] as $name) {
            $this->assertArrayHasKey($name, $properties, $name);
        }
    }

    public function test_create_payload_marks_required_columns(): void
    {
        $actions = $this->endpoint()['actions'];

        $this->assertSame(['ref_number', 'customer_id'], $actions['create']['request']['payload']['required']);
        $this->assertSame(['ref_number', 'customer_id'], $actions['bulkCreate']['request']['payload']['items']['required']);
        $this->assertArrayNotHasKey('required', $actions['update']['request']['payload']);
    }

    public function test_tenant_column_is_never_writable(): void
    {
        $this->tearDownFixtureTables();
        $this->buildGuidanceFixture(tenant: true);

        $endpoint = $this->endpoint();

        $this->assertArrayNotHasKey('tenant_id', $endpoint['actions']['create']['request']['payload']['properties']);
        $this->assertArrayHasKey('tenant_id', $endpoint['actions']['read']['response']['dataSchema']['properties']);
    }

    public function test_a_uuid_primary_key_is_writable_on_create_only(): void
    {
        Schema::create('uuid_rows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
        });
        Config::set('record.tables', [
            'uuid_rows' => new RecordTableType(
                table: 'uuid_rows',
                pmsName: 'uuid_rows',
                public: new RecordTablePublic(read: true, write: true),
                columns: [
                    'id' => ['type' => 'uuid', 'nullable' => false, 'key' => 'PRI'],
                    'name' => ['type' => 'string', 'nullable' => true],
                ],
            ),
        ]);
        SchemaRegistryUtils::refresh();

        $actions = $this->endpoint('uuid_rows')['actions'];

        $this->assertArrayHasKey('id', $actions['create']['request']['payload']['properties']);
        $this->assertArrayHasKey('id', $actions['upsert']['request']['payload']['properties']);
        $this->assertArrayNotHasKey('id', $actions['update']['request']['payload']['properties']);
        $this->assertNotContains('id', $actions['create']['request']['payload']['required'] ?? [], 'the API generates a missing uuid');
    }

    public function test_bulk_actions_that_address_rows_carry_the_primary_key(): void
    {
        $actions = $this->endpoint()['actions'];

        $this->assertSame(['id'], array_keys($actions['bulkDelete']['request']['payload']['items']['properties']));
        $this->assertSame(['id'], $actions['bulkDelete']['request']['payload']['items']['required']);
        $this->assertArrayHasKey('id', $actions['bulkUpdate']['request']['payload']['items']['properties']);
        $this->assertSame(['id'], $actions['bulkUpdate']['request']['payload']['items']['required']);
        $this->assertArrayHasKey('id', $actions['bulkMixed']['request']['payload']['items']['properties']);
        $this->assertSame(['create', 'update', 'delete', 'upsert'], $actions['bulkMixed']['request']['payload']['items']['properties']['operation']['enum']);
        $this->assertArrayNotHasKey('id', $actions['bulkCreate']['request']['payload']['items']['properties']);
    }

    public function test_belongs_to_many_include_reports_the_related_table(): void
    {
        $tags = $this->include('tags');

        $this->assertSame('tags', $tags['table']);
        $this->assertSame('invoice_tag', $tags['pivotTable']);
        $this->assertSame('tag_id', $tags['relatedPivotKey']);
        $this->assertSame(['note'], $tags['pivotFields']);
        $this->assertSame('tags', $this->endpoint('tags')['table'], 'the reported table resolves as an endpoint');
    }

    public function test_has_many_and_belongs_to_includes_keep_their_table(): void
    {
        $this->assertSame('invoice_items', $this->include('items')['table']);
        $this->assertSame('customers', $this->include('customer')['table']);
        $this->assertArrayNotHasKey('payloadExample', $this->include('customer'));
    }

    public function test_hints_use_shapes_that_write_and_never_say_sync(): void
    {
        $items = $this->hintExample($this->include('items'));
        $tags = $this->hintExample($this->include('tags'));

        $this->assertCount(3, $items);
        $this->assertArrayNotHasKey('id', $items[0]);
        $this->assertSame(2, $items[1]['id']);
        $this->assertSame(['id' => 5, '_delete' => true], $items[2]);

        $this->assertSame(['id' => 1], $tags[0]);
        $this->assertSame(['id' => 2, 'note' => 'example'], $tags[1]);
        $this->assertSame(['id' => 5, '_delete' => true], $tags[3]);

        $this->assertArrayNotHasKey('payloadExample', $this->include('items'), 'the hint carries the example once');

        $strings = [];
        $endpoint = $this->endpoint();
        $guidance = app(SchemaTools::class)->apiGuidance();
        $collect = static function (mixed $v) use (&$strings): void {
            if (is_string($v)) {
                $strings[] = $v;
            }
        };
        array_walk_recursive($endpoint, $collect);
        array_walk_recursive($guidance, $collect);

        foreach ($strings as $string) {
            $this->assertDoesNotMatchRegularExpression('/\bsync\b/i', $string);
        }
    }

    public function test_hints_respect_the_relationship_flags(): void
    {
        $tables = Config::get('record.tables');
        $tables['invoices']->relationships['items']->allowCreate = false;
        $tables['invoices']->relationships['items']->allowDelete = false;
        Config::set('record.tables', $tables);
        SchemaRegistryUtils::refresh();

        $example = $this->hintExample($this->include('items'));

        $this->assertCount(1, $example, 'only the update item remains');
        $this->assertSame(2, $example[0]['id']);
    }

    public function test_the_child_table_of_a_writable_include_exposes_its_permissions(): void
    {
        foreach (['items', 'tags'] as $alias) {
            $child = $this->endpoint($this->include($alias)['table']);

            foreach (['create', 'update', 'delete'] as $action) {
                $this->assertNotEmpty($child['permissions'][$action], $alias . ' ' . $action);
            }
        }

        $this->assertSame(['create:invoice_items'], $this->endpoint('invoice_items')['permissions']['create']);
    }

    public function test_fields_carry_required_and_the_defaults_flag(): void
    {
        $endpoint = $this->endpoint();
        $fields = array_column($endpoint['fields'], null, 'name');

        $this->assertTrue($fields['ref_number']['required']);
        $this->assertTrue($fields['customer_id']['required']);
        $this->assertArrayNotHasKey('required', $fields['status'], 'a column with a default is not required');
        $this->assertArrayNotHasKey('required', $fields['total_amount']);

        $this->assertFalse($endpoint['validation']['defaults']['enabled']);

        Config::set('record.default_validation.enabled', true);
        $this->assertTrue($this->endpoint()['validation']['defaults']['enabled']);
    }

    public function test_each_field_lists_only_the_operators_of_its_family(): void
    {
        $filters = array_column($this->endpoint()['filters'], 'operators', 'field');

        $this->assertContains('gte', $filters['total_amount']);
        $this->assertContains('between', $filters['total_amount']);
        $this->assertNotContains('like', $filters['total_amount']);
        $this->assertContains('starts_with', $filters['ref_number']);
        $this->assertNotContains('gte', $filters['ref_number']);
        $this->assertContains('date_gte', $filters['issued_at']);
    }

    private function tearDownFixtureTables(): void
    {
        foreach (['invoice_tag', 'tags', 'invoice_items', 'invoices', 'customers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
