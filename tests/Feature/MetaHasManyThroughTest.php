<?php

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordMetaHasManyThroughType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;

class MetaHasManyThroughTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('packages', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('modules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('meta', function (Blueprint $table): void {
            $table->id();
            $table->string('owner');
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('target_id');
            $table->timestamps();
        });

        $packageId1 = DB::table('packages')->insertGetId(['name' => 'Package 1']);
        $packageId2 = DB::table('packages')->insertGetId(['name' => 'Package 2']);

        $moduleId1 = DB::table('modules')->insertGetId(['name' => 'Module 1']);
        $moduleId2 = DB::table('modules')->insertGetId(['name' => 'Module 2']);

        DB::table('meta')->insert([
            'owner' => 'package',
            'owner_id' => $packageId1,
            'target_id' => $moduleId1,
        ]);

        DB::table('meta')->insert([
            'owner' => 'package',
            'owner_id' => $packageId1,
            'target_id' => $moduleId2,
        ]);

        DB::table('meta')->insert([
            'owner' => 'package',
            'owner_id' => $packageId2,
            'target_id' => $moduleId2,
        ]);

        Config::set('record.tables', [
            'packages' => new RecordTableType(
                table: 'packages',
                pmsName: 'packages',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [
                    'modules' => new RecordMetaHasManyThroughType(
                        table: 'modules',
                        through: 'meta',
                        firstKey: 'owner_id',
                        secondLocalKey: 'target_id',
                        ownerColumn: 'owner',
                        owner: 'package',
                    ),
                ],
            ),
            'modules' => new RecordTableType(
                table: 'modules',
                pmsName: 'modules',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
            ),
        ]);

        SchemaRegistryUtils::refresh();
    }

    public function test_meta_has_many_through_relationship_loads_modules_for_packages(): void
    {
        $request = Request::create('/api/v1/packages', 'GET', [
            'select' => '*,modules(*)',
        ]);

        $schema = SchemaRegistryUtils::get();
        $config = $schema['packages'];

        $result = RecordService::applyRequestFilters($request, $config, '', true);
        $data = $result['data'];

        $this->assertIsArray($data);
        $this->assertCount(2, $data);

        $package1 = collect($data)->firstWhere('id', 1);
        $package2 = collect($data)->firstWhere('id', 2);

        $this->assertNotNull($package1);
        $this->assertNotNull($package2);

        $this->assertIsArray($package1->modules);
        $this->assertCount(2, $package1->modules);
        $moduleNames1 = collect($package1->modules)->pluck('name')->all();
        sort($moduleNames1);
        $this->assertSame(['Module 1', 'Module 2'], $moduleNames1);

        $this->assertCount(1, $package2->modules);
        $this->assertSame('Module 2', $package2->modules[0]->name);
    }
}
