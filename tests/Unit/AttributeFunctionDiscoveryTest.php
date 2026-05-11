<?php

namespace Sopheak\Core\Tests\Unit;

use Illuminate\Support\Facades\Config;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordFunctionType;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class AttributeFunctionDiscoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('sp-laravel-api.attribute_discovery.enabled', true);
        Config::set('sp-laravel-api.attribute_discovery.paths', [__DIR__ . '/../Fixtures/AttributeDiscovery']);
        Config::set('record.tables', []);
        Config::set('record.global_functions', []);
        SchemaRegistryUtils::refresh();
    }

    protected function tearDown(): void
    {
        SchemaRegistryUtils::refresh();

        parent::tearDown();
    }

    public function test_it_discovers_table_functions_from_attributes(): void
    {
        $schema = SchemaRegistryUtils::get();

        $this->assertArrayHasKey('invoices', $schema);
        $this->assertInstanceOf(RecordTableType::class, $schema['invoices']);
        $this->assertIsArray($schema['invoices']->functions);
        $this->assertArrayHasKey('sync', $schema['invoices']->functions);
        $this->assertArrayHasKey('rebuild-index', $schema['invoices']->functions);
        $this->assertInstanceOf(RecordFunctionType::class, $schema['invoices']->functions['sync']);
        $this->assertSame('sync', $schema['invoices']->functions['sync']->functionName);
    }

    public function test_file_config_functions_override_discovered_table_functions_with_same_name(): void
    {
        Config::set('record.tables', [
            'invoices' => new RecordTableType(
                table: 'invoices',
                pmsName: 'invoices',
                hasTenantId: false,
                functions: [
                    'sync' => new RecordFunctionType(
                        httpMethod: ['POST'],
                        class: 'App\\Custom\\InvoiceRpc',
                        functionName: 'syncFromConfig',
                        pmsName: 'invoice.sync'
                    ),
                ],
            ),
        ]);

        SchemaRegistryUtils::refresh();
        $schema = SchemaRegistryUtils::get();

        $this->assertArrayHasKey('invoices', $schema);
        $this->assertArrayHasKey('sync', $schema['invoices']->functions);
        $this->assertArrayHasKey('rebuild-index', $schema['invoices']->functions);
        $this->assertSame('syncFromConfig', $schema['invoices']->functions['sync']->functionName);
    }

    public function test_it_discovers_global_functions_from_attributes_and_respects_config_precedence(): void
    {
        Config::set('record.global_functions', [
            'health' => new RecordFunctionType(
                httpMethod: ['GET'],
                class: 'App\\Custom\\GlobalRpc',
                functionName: 'healthFromConfig',
                isPublic: true
            ),
        ]);

        $functions = RecordConfigService::globalFunctions();

        $this->assertArrayHasKey('health', $functions);
        $this->assertArrayHasKey('ping', $functions);
        $this->assertInstanceOf(RecordFunctionType::class, $functions['health']);
        $this->assertSame('healthFromConfig', $functions['health']->functionName);
        $this->assertSame('ping', $functions['ping']->functionName);
    }
}
