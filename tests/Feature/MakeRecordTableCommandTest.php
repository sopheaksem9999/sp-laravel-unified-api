<?php

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Support\Facades\Config;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;

class MakeRecordTableCommandTest extends TestCase
{
    public function test_it_creates_record_table_config_file(): void
    {
        Config::set('record.table_config_path', 'records/tables');

        $dir = config_path(RecordConfigService::tableConfigPath());
        $file = $dir . DIRECTORY_SEPARATOR . 'customers.php';

        if (is_file($file)) {
            unlink($file);
        }

        $this->artisan('sp-laravel-api:make-record-table', [
            'name' => 'customers',
            '--table' => 'customers',
            '--pms-name' => 'customer',
        ])->assertExitCode(0);

        $this->assertFileExists($file);

        $config = require $file;
        $this->assertInstanceOf(RecordTableType::class, $config);
        $this->assertSame('customers', $config->table);
        $this->assertSame('customer', $config->pmsName);
        $this->assertFalse($config->hasTenantId);
        $this->assertFalse($config->softDeletes);

        unlink($file);
    }
}

