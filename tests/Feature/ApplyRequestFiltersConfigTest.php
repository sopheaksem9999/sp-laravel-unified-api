<?php

namespace Sopheak\Core\Tests\Feature;

use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Sopheak\Core\Utilities\QueryBuilderFiltersUtils;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Enums\RecordRelationshipsEnum;
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

        Schema::create('qht_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('status');
        });

        DB::table('qht_subscriptions')->insert([
            ['company_id' => 3, 'status' => 'paid'],
            ['company_id' => 4, 'status' => 'pending'],
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
            'updated_at' => ['type' => 'datetime'],
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

    public function test_query_helpers_trait_supports_select_with_prefix_syntax(): void
    {
        $request = Request::create('/api/qht_categories', 'GET', [
            'select' => 'id,name,with=items(id,name,category_id)',
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

    public function test_record_service_supports_with_as_top_level_parameter(): void
    {
        Config::set('record.enable_tenant_id', false);

        $config = new RecordTableType(
            table: 'qht_categories',
            hasTenantId: false,
            public: new RecordTablePublic(true, true),
            relationships: [
                'items' => new RecordHasManyType(
                    table: 'qht_items',
                    foreignKey: 'category_id',
                    type: RecordRelationshipsEnum::HAS_MANY,
                    localKey: 'id',
                ),
            ]
        );

        $config->columns = [
            'id' => ['type' => 'bigint'],
            'name' => ['type' => 'string'],
        ];

        $itemsConfig = new RecordTableType(
            table: 'qht_items',
            hasTenantId: false,
            public: new RecordTablePublic(true, true),
        );
        $itemsConfig->columns = [
            'id' => ['type' => 'bigint'],
            'name' => ['type' => 'string'],
            'category_id' => ['type' => 'bigint'],
        ];

        SchemaRegistryUtils::refresh();
        SchemaRegistryUtils::register('qht_categories', $config);
        SchemaRegistryUtils::register('qht_items', $itemsConfig);
        QueryBuilderFiltersUtils::clearColumnCache();

        $request = Request::create('/api/qht_categories', 'GET', [
            'with' => 'items(id,name,category_id)',
            'sortby' => 'id',
            'order' => 'asc',
        ]);

        $result = RecordService::applyRequestFilters($request, $config);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(2, $result['data']);

        // Ensure relationship is loaded
        $this->assertObjectHasProperty('items', $result['data'][0]);
        $this->assertIsArray($result['data'][0]->items);
        $this->assertCount(2, $result['data'][0]->items);
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

    public function test_tenant_column_operator_value_is_parsed_as_filter(): void
    {
        Config::set('record.enable_tenant_id', true);
        Config::set('record.tenant_column', 'company_id');

        $config = new RecordTableType(
            table: 'qht_subscriptions',
            hasTenantId: true,
            public: new RecordTablePublic(true, true),
        );

        $config->columns = [
            'id' => ['type' => 'bigint'],
            'company_id' => ['type' => 'bigint'],
            'status' => ['type' => 'string'],
        ];

        SchemaRegistryUtils::refresh();
        SchemaRegistryUtils::register('qht_subscriptions', $config);
        QueryBuilderFiltersUtils::clearColumnCache();

        $request = Request::create('/api/qht_subscriptions', 'GET', [
            'company_id' => 'eq.3',
            'sortby' => 'id',
            'order' => 'asc',
        ]);

        $result = RecordService::applyRequestFilters($request, $config);

        $this->assertCount(1, $result['data']);
        $this->assertEquals(3, $result['data'][0]->company_id);
    }

    public function test_apply_request_filters_resolves_tenant_from_resolved_tenant_id_attribute(): void
    {
        Config::set('record.enable_tenant_id', true);
        Config::set('record.tenant_column', 'company_id');

        $config = new RecordTableType(
            table: 'qht_subscriptions',
            hasTenantId: true,
            public: new RecordTablePublic(true, true),
        );

        $config->columns = [
            'id' => ['type' => 'bigint'],
            'company_id' => ['type' => 'bigint'],
            'status' => ['type' => 'string'],
        ];

        SchemaRegistryUtils::refresh();
        SchemaRegistryUtils::register('qht_subscriptions', $config);
        QueryBuilderFiltersUtils::clearColumnCache();

        $request = Request::create('/api/qht_subscriptions', 'GET', [
            'sortby' => 'id',
            'order' => 'asc',
        ]);
        $request->attributes->set('resolved_tenant_id', 3);

        $result = RecordService::applyRequestFilters($request, $config);

        $this->assertCount(1, $result['data']);
        $this->assertEquals(3, $result['data'][0]->company_id);
    }

    public function test_apply_request_filters_resolves_tenant_from_header_for_backward_compatibility(): void
    {
        Config::set('record.enable_tenant_id', true);
        Config::set('record.tenant_column', 'company_id');
        Config::set('record.tenant_header', 'X-Tenant-ID');

        $config = new RecordTableType(
            table: 'qht_subscriptions',
            hasTenantId: true,
            public: new RecordTablePublic(true, true),
        );

        $config->columns = [
            'id' => ['type' => 'bigint'],
            'company_id' => ['type' => 'bigint'],
            'status' => ['type' => 'string'],
        ];

        SchemaRegistryUtils::refresh();
        SchemaRegistryUtils::register('qht_subscriptions', $config);
        QueryBuilderFiltersUtils::clearColumnCache();

        $request = Request::create('/api/qht_subscriptions', 'GET', [
            'sortby' => 'id',
            'order' => 'asc',
        ], [], [], [
            'HTTP_X_TENANT_ID' => '4',
        ]);

        $result = RecordService::applyRequestFilters($request, $config);

        $this->assertCount(1, $result['data']);
        $this->assertEquals(4, $result['data'][0]->company_id);
    }

    public function test_query_helpers_trait_applies_tenant_filter_from_resolved_tenant_id_attribute(): void
    {
        Config::set('record.enable_tenant_id', true);
        Config::set('record.tenant_column', 'company_id');

        $request = Request::create('/api/qht_subscriptions', 'GET', [
            'sortby' => 'id',
            'order' => 'asc',
        ]);
        $request->attributes->set('resolved_tenant_id', 3);

        $subscriptions = QhtSubscription::query()
            ->applyRequestFilters($request)
            ->get();

        $this->assertCount(1, $subscriptions);
        $this->assertSame(3, (int) $subscriptions->first()->company_id);
    }

    public function test_query_helpers_trait_applies_tenant_filter_from_header_for_backward_compatibility(): void
    {
        Config::set('record.enable_tenant_id', true);
        Config::set('record.tenant_column', 'company_id');
        Config::set('record.tenant_header', 'X-Tenant-ID');

        $request = Request::create('/api/qht_subscriptions', 'GET', [
            'sortby' => 'id',
            'order' => 'asc',
        ], [], [], [
            'HTTP_X_TENANT_ID' => '4',
        ]);

        $subscriptions = QhtSubscription::query()
            ->applyRequestFilters($request)
            ->get();

        $this->assertCount(1, $subscriptions);
        $this->assertSame(4, (int) $subscriptions->first()->company_id);
    }

    public function test_query_helpers_trait_uses_table_config_has_tenant_id_when_fillable_is_empty(): void
    {
        Config::set('record.enable_tenant_id', true);
        Config::set('record.tenant_column', 'company_id');
        Config::set('record.tables.qht_subscriptions', new RecordTableType(
            table: 'qht_subscriptions',
            hasTenantId: true,
            public: new RecordTablePublic(true, true),
        ));

        $request = Request::create('/api/qht_subscriptions', 'GET', [
            'sortby' => 'id',
            'order' => 'asc',
        ]);
        $request->attributes->set('resolved_tenant_id', 3);

        $subscriptions = QhtSubscription::query()
            ->applyRequestFilters($request)
            ->get();

        $this->assertCount(1, $subscriptions);
        $this->assertSame(3, (int) $subscriptions->first()->company_id);
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

class QhtSubscription extends Model
{
    use QueryHelpersTrait;

    protected $table = 'qht_subscriptions';

    protected $guarded = [];

    public $timestamps = false;
}
