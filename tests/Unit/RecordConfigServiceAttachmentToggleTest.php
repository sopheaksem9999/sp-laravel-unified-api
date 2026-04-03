<?php

namespace Sopheak\Core\Tests\Unit;

use Illuminate\Support\Facades\Config;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;

class RecordConfigServiceAttachmentToggleTest extends TestCase
{
    /** @test */
    public function it_excludes_attachment_tables_when_attachment_module_is_disabled(): void
    {
        Config::set('record.tables', [
            'orders' => new RecordTableType(
                table: 'orders',
                pmsName: 'order',
                columns: [
                    'id' => ['type' => 'integer', 'nullable' => false],
                ]
            ),
        ]);

        Config::set('attachments.tables', [
            'sp_attachments' => new RecordTableType(
                table: 'sp_attachments',
                pmsName: 'attachment',
                columns: [
                    'id' => ['type' => 'string', 'nullable' => false],
                ]
            ),
        ]);

        Config::set('attachments.enabled', false);

        $tables = RecordConfigService::getTableConfig();

        $this->assertArrayHasKey('orders', $tables);
        $this->assertArrayNotHasKey('sp_attachments', $tables);
        $this->assertSame([], RecordConfigService::table('sp_attachments'));
    }
}
