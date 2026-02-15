<?php

namespace Sopheak\Core\Tests\Feature;

use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Sopheak\Core\Utilities\QueryBuilderFiltersUtils;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Traits\QueryHelpersTrait;

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

        Schema::create('qht_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('qht_items', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('category_id');
        });

        $categoryA = DB::table('qht_categories')->insertGetId(['name' => 'Cat A']);
        $categoryB = DB::table('qht_categories')->insertGetId(['name' => 'Cat B']);

        DB::table('qht_items')->insert([
            ['name' => 'Item A1', 'category_id' => $categoryA],
            ['name' => 'Item A2', 'category_id' => $categoryA],
            ['name' => 'Item B1', 'category_id' => $categoryB],
        ]);
    }

    public function test_apply_request_filters_with_config_object(): void
    {
        $request = Request::create('/api/custom_items', 'GET', [
            'category' => 'eq.cat1',
            'sortby' => 'id',
            'order' => 'desc',
        ]);

        $config = new RecordTableType(
            table: 'custom_items',
            pmsName: 'custom_items_endpoint',
            hasTenantId: false,
            softDeletes: false,
            public: new RecordTablePublic(true, true),
            relationships: []
        );

        // Pass config object directly
        // Ensure the config has columns defined to simulate what SchemaRegistryUtils does
        // Because we're in a test environment, getTableColumns might fail or behave unexpectedly if not mocked
        $config->columns = [
            'id' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            'category' => ['type' => 'string'],
            'created_at' => ['type' => 'datetime'],
            'updated_at' => ['type' => 'datetime']
        ];

        // Ensure SchemaRegistryUtils is fresh
        SchemaRegistryUtils::refresh();
        QueryBuilderFiltersUtils::clearColumnCache();

        $result = RecordService::applyRequestFilters($request, $config);

        // Debugging failure: 
        // If result count is 3, it means filtering failed.
        // Likely allowedColumns returned empty because SchemaRegistryUtils::register 
        // didn't populate columns correctly or QueryBuilderFiltersUtils didn't see them.

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(2, $result['data']);

        // Ensure sorting is consistent
        $this->assertEquals('Item C', $result['data'][0]->name);
        $this->assertEquals('Item A', $result['data'][1]->name);
    }

    public function test_query_helpers_trait_supports_select_with_and_filters(): void
    {
        $request = Request::create('/api/qht_categories', 'GET', [
            'select' => 'id,name,items(id,name,category_id)',
            'name' => 'starts_with.Cat',
            'sortby' => 'id',
            'order' => 'asc',
        ]);

        $categories = QhtCategory::query()
            ->applyRequestFilters($request)
            ->get();

        $this->assertCount(2, $categories);
        $this->assertTrue($categories->first()->relationLoaded('items'));
        $this->assertCount(2, $categories->first()->items);
    }

    public function test_query_helpers_trait_supports_search_alias(): void
    {
        $request = Request::create('/api/qht_categories', 'GET', [
            'search' => 'Cat B',
        ]);

        $categories = QhtCategory::query()
            ->applyRequestFilters($request)
            ->get();

        $this->assertCount(1, $categories);
        $this->assertSame('Cat B', $categories->first()->name);
    }

    public function test_query_helpers_trait_supports_with_parameter(): void
    {
        $request = Request::create('/api/qht_categories', 'GET', [
            'with' => 'items',
            'sortby' => 'id',
            'order' => 'asc',
        ]);

        $categories = QhtCategory::query()
            ->applyRequestFilters($request)
            ->get();

        $this->assertCount(2, $categories);
        $this->assertTrue($categories->first()->relationLoaded('items'));
        $this->assertCount(2, $categories->first()->items);
    }
}

class QhtCategory extends Model
{
    use QueryHelpersTrait;

    protected $table = 'qht_categories';
    protected $guarded = [];
    public $timestamps = false;

    public function items()
    {
        return $this->hasMany(QhtItem::class, 'category_id');
    }
}

class QhtItem extends Model
{
    protected $table = 'qht_items';
    protected $guarded = [];
    public $timestamps = false;
}
