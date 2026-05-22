<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Enums\RecordRelationshipsEnum;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordAassociationType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class RelationshipAssociationWriteTest extends TestCase
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
            $table->string('target')->nullable(false);
            $table->unsignedBigInteger('target_id');
            $table->timestamps();
        });

        Config::set('record.tables', [
            'packages' => new RecordTableType(
                table: 'packages',
                pmsName: 'packages',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [
                    'modules' => new RecordAassociationType(
                        related: 'meta',
                        type: RecordRelationshipsEnum::HAS_MANY_THROUGH,
                        fromObjectType: 'packages',
                        fromObjectId: 'owner_id',
                        toObjectType: 'modules',
                        toObjectId: 'target_id',
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

    public function test_association_create_populates_target_column_in_meta(): void
    {
        $payload = [
            'name' => 'Package A',
            'modules' => [
                ['name' => 'Module 1'],
                ['name' => 'Module 2'],
            ],
        ];

        $service = app(RecordService::class);
        $service->createRecord('packages', $payload, null);

        $packageId = DB::table('packages')->where('name', 'Package A')->value('id');
        $this->assertNotNull($packageId);

        $metaRows = DB::table('meta')
            ->where('owner', 'packages')
            ->where('owner_id', $packageId)
            ->get();

        $this->assertCount(2, $metaRows);

        foreach ($metaRows as $row) {
            $this->assertEquals('packages', $row->owner);
            $this->assertEquals($packageId, $row->owner_id);
            $this->assertEquals('modules', $row->target);
            $this->assertNotNull($row->target_id);
        }
    }
}
