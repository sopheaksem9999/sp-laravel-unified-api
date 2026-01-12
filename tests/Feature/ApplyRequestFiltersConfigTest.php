<?php

namespace Sopheak\Core\Tests\Feature;

use Sopheak\Core\Support\SchemaRegistry;
use Sopheak\Core\Support\QueryBuilderFilters;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Services\RecordService;

class ApplyRequestFiltersConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('custom_items', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('category');
            $table->timestamps();
        });

        DB::table('custom_items')->insert([
            ['name' => 'Item A', 'category' => 'cat1'],
            ['name' => 'Item B', 'category' => 'cat2'],
            ['name' => 'Item C', 'category' => 'cat1'],
        ]);
    }

    public function test_apply_request_filters_with_config_object(): void
    {
        $request = Request::create('/api/custom_items', 'GET', ['category' => 'eq.cat1']);

        $config = new RecordTableType(
            pms_name: 'custom_items_endpoint',
            table: 'custom_items',
            has_tenant_id: false,
            soft_deletes: false,
            public: new RecordTablePublic(true, true),
            relationships: []
        );

        // Pass config object directly
        // Ensure the config has columns defined to simulate what SchemaRegistry does
        // Because we're in a test environment, getTableColumns might fail or behave unexpectedly if not mocked
        $config->columns = [
            'id' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            'category' => ['type' => 'string'],
            'created_at' => ['type' => 'datetime'],
            'updated_at' => ['type' => 'datetime']
        ];

        // Ensure SchemaRegistry is fresh
        SchemaRegistry::refresh();
        QueryBuilderFilters::clearColumnCache();

        $result = RecordService::applyRequestFilters($request, $config);

        // Debugging failure: 
        // If result count is 3, it means filtering failed.
        // Likely allowedColumns returned empty because SchemaRegistry::register 
        // didn't populate columns correctly or QueryBuilderFilters didn't see them.

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(2, $result['data']);

        // Ensure sorting is consistent
        $this->assertEquals('Item C', $result['data'][0]->name);
        $this->assertEquals('Item A', $result['data'][1]->name);
    }
}
