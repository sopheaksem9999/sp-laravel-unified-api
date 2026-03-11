<?php

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;

class GenerateRecordTablesFromDatabaseCommandTest extends TestCase
{
    public function test_it_creates_config_for_database_tables_and_ignores_pivot_tables(): void
    {
        Config::set('record.table_config_path', 'records/tables-generated');

        $dir = config_path(RecordConfigService::tableConfigPath());

        if (is_dir($dir)) {
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*.php') as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        if (!Schema::hasTable('customers')) {
            Schema::create('customers', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('model_has_roles')) {
            Schema::create('model_has_roles', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('model_id');
                $table->unsignedBigInteger('role_id');
                $table->timestamps();
            });
        }

        $this->artisan('sp-laravel-api:generate-record-tables-from-db')->assertExitCode(0);

        $customersFile = $dir . DIRECTORY_SEPARATOR . 'customers.php';
        $pivotFile = $dir . DIRECTORY_SEPARATOR . 'model_has_roles.php';

        $this->assertFileExists($customersFile);
        $this->assertFileDoesNotExist($pivotFile);

        $config = require $customersFile;
        $this->assertInstanceOf(RecordTableType::class, $config);
        $this->assertSame('customers', $config->table);
        $this->assertTrue($config->isAuthRead);
        $this->assertTrue($config->isAuthWrite);

        unlink($customersFile);
    }
}
