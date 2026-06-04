<?php

namespace Sopheak\Core\Tests\Unit;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
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
        $this->deleteTestConfigFile(config_path('records/test-tables-folder/customers.php'));
        $this->deleteTestConfigFile(config_path('records/global-functions/zz_test_reports.php'));
        $this->deleteTestConfigFile(config_path('records/globalFunctions/zz_test_legacy.php'));
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

    public function test_it_loads_table_configs_from_folder_pattern(): void
    {
        Config::set('record.table_config_path', 'records/test-tables-folder');
        $path = config_path('records/test-tables-folder/customers.php');
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, <<<'PHP'
            <?php

            use Sopheak\Core\Types\RecordTableType;

            return new RecordTableType(
                table: 'customers',
                pmsName: 'customers',
                hasTenantId: false,
            );
            PHP);

        SchemaRegistryUtils::refresh();
        $schema = SchemaRegistryUtils::get();

        $this->assertArrayHasKey('customers', $schema);
        $this->assertSame('customers', $schema['customers']->table);
    }

    public function test_it_loads_global_functions_from_preferred_and_legacy_folders(): void
    {
        $preferredPath = config_path('records/global-functions/zz_test_reports.php');
        $legacyPath = config_path('records/globalFunctions/zz_test_legacy.php');
        File::ensureDirectoryExists(dirname($preferredPath));
        File::ensureDirectoryExists(dirname($legacyPath));

        file_put_contents($preferredPath, <<<'PHP'
            <?php

            use Sopheak\Core\Types\RecordFunctionType;

            return [
                'summary' => new RecordFunctionType(
                    httpMethod: ['GET'],
                    class: 'App\\Reports',
                    functionName: 'summary',
                ),
            ];
            PHP);

        file_put_contents($legacyPath, <<<'PHP'
            <?php

            use Sopheak\Core\Types\RecordFunctionType;

            return [
                'ping' => new RecordFunctionType(
                    httpMethod: ['GET'],
                    class: 'App\\Legacy',
                    functionName: 'ping',
                ),
            ];
            PHP);

        $functions = RecordConfigService::globalFunctions();

        $this->assertArrayHasKey('zz_test_reports/summary', $functions);
        $this->assertArrayHasKey('zz_test_legacy/ping', $functions);
    }

    private function deleteTestConfigFile(string $path): void
    {
        if (File::exists($path)) {
            File::delete($path);
        }
    }
}
