<?php

namespace Sopheak\Core\Tests\Feature;

use Sopheak\Core\Types\RecordTablePublic;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Utilities\RelationshipResolverUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Tests\Fixtures\LegacyFunction;

class LegacyConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('legacy_items', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function test_legacy_array_table_config_is_normalized(): void
    {
        // Define a table using a legacy array configuration
        Config::set('record.tables', [
            'legacy_items' => [
                'pms_name' => 'legacy_items',
                'table' => 'legacy_items',
                'soft_deletes' => false,
                'public' => ['read' => true, 'write' => true], // Array for public
                'can_write' => false, // Legacy permission
                'can_create' => true, // Granular permission override
                'functions' => [
                    'legacy_func' => [
                        'method' => ['GET'],
                        'class' => LegacyFunction::class,
                        'function_method' => 'handle',
                        'description' => 'Legacy function',
                    ]
                ]
            ],
        ]);

        SchemaRegistryUtils::refresh();

        $schema = SchemaRegistryUtils::get();
        $tableConfig = $schema['legacy_items'];

        // Assert it was converted to RecordTableType
        $this->assertInstanceOf(RecordTableType::class, $tableConfig);
        
        // Assert public property was converted to RecordTablePublic
        $this->assertInstanceOf(RecordTablePublic::class, $tableConfig->public);
        $this->assertTrue($tableConfig->public->read);
        $this->assertTrue($tableConfig->public->write);

        // Assert granular permission override worked
        $this->assertTrue($tableConfig->can_create);
        
        // Assert fallback worked (if not overridden)
        // can_write was false, so can_update and can_delete should be false (since they fallback to can_write if null)
        $this->assertFalse($tableConfig->can_update);
        $this->assertFalse($tableConfig->can_delete);
        
        // Check functions
        $this->assertIsArray($tableConfig->functions);
        $this->assertArrayHasKey('legacy_func', $tableConfig->functions);
        $this->assertIsArray($tableConfig->functions['legacy_func']);
        
        // Test executing table function with array config
        $service = new RecordService();
        $request = Request::create('/api/v1/legacy_items/rpc/legacy_func', 'GET');
        $response = $service->executeTableFunction($request, 'legacy_items', 'legacy_func');
        
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Legacy function executed', $response->getData()->data->message);
    }

    public function test_legacy_global_function_array_config(): void
    {
        // Define global function using array
        Config::set('record.global_functions', [
            'legacy_global' => [
                'method' => ['GET'],
                'class' => LegacyFunction::class,
                'function_method' => 'handle',
                'description' => 'Legacy global function',
            ]
        ]);

        $service = new RecordService();
        $request = Request::create('/api/v1/rpc/legacy_global', 'GET');
        
        $response = $service->executeGlobalFunction($request, 'legacy_global');
        
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Legacy function executed', $response->getData()->data->message);
    }

    public function test_legacy_relationship_array_config(): void
    {
        // Mock schema with legacy relationship array
        Config::set('record.tables', [
            'legacy_parent' => [
                'pms_name' => 'legacy_parent',
                'table' => 'legacy_parent',
                'relationships' => [
                    'children' => [
                        'type' => 'hasMany',
                        'table' => 'legacy_child',
                        'foreign_key' => 'parent_id',
                        'local_key' => 'id',
                        'allow_create' => true,
                        'allow_update' => true,
                        'allow_delete' => true,
                    ]
                ]
            ],
            'legacy_child' => [
                'pms_name' => 'legacy_child',
                'table' => 'legacy_child',
            ]
        ]);
        
        SchemaRegistryUtils::refresh();
        RelationshipResolverUtils::clearSchemaCache();
        
        // Resolve relationship
        $rel = RelationshipResolverUtils::resolveRelationship('legacy_parent', 'children');
        
        $this->assertIsArray($rel);
        $this->assertEquals('hasMany', $rel['type']);
        $this->assertEquals('legacy_child', $rel['table']);
    }
}
