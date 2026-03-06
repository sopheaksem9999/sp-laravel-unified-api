<?php

namespace Sopheak\Core\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableTriggerType;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class GlobalTriggerHooksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        GlobalTriggerHandler::reset();
    }

    /** @test */
    public function it_executes_global_trigger(): void
    {
        Config::set('record.global_triggers', [
            'beforeCreate' => new RecordTableTriggerType(
                class: GlobalTriggerHandler::class,
                functionName: 'beforeCreate'
            ),
        ]);

        $service = new RecordService();
        $request = Request::create('/api/invoices', 'POST');

        $service->executeGlobalTrigger('beforeCreate', [$request, 'invoices', []]);

        $this->assertSame(1, GlobalTriggerHandler::$calls['beforeCreate'] ?? 0);
    }

    /** @test */
    public function it_executes_global_after_triggers_in_post_write_logic(): void
    {
        Config::set('record.tables', [
            'invoices' => new RecordTableType(
                table: 'invoices',
                disableAuditLog: true,
                afterCreate: new RecordTableTriggerType(
                    class: GlobalTriggerHandler::class,
                    functionName: 'afterCreateTable'
                )
            ),
        ]);
        Config::set('record.global_triggers', [
            'afterCreate' => new RecordTableTriggerType(
                class: GlobalTriggerHandler::class,
                functionName: 'afterCreateGlobal'
            ),
        ]);

        SchemaRegistryUtils::refresh();

        $service = new RecordService();
        $request = Request::create('/api/invoices', 'POST');

        $service->processPostWriteLogic(
            request: $request,
            table: 'invoices',
            operation: 'create',
            recordContext: ['id' => 1, 'payload' => []]
        );

        $this->assertSame(1, GlobalTriggerHandler::$calls['afterCreateTable'] ?? 0);
        $this->assertSame(1, GlobalTriggerHandler::$calls['afterCreateGlobal'] ?? 0);
    }
}

class GlobalTriggerHandler
{
    public static array $calls = [];

    public static function reset(): void
    {
        self::$calls = [];
    }

    public static function beforeCreate(Request $request): void
    {
        self::$calls['beforeCreate'] = (self::$calls['beforeCreate'] ?? 0) + 1;
    }

    public static function afterCreateTable(Request $request): void
    {
        self::$calls['afterCreateTable'] = (self::$calls['afterCreateTable'] ?? 0) + 1;
    }

    public static function afterCreateGlobal(Request $request): void
    {
        self::$calls['afterCreateGlobal'] = (self::$calls['afterCreateGlobal'] ?? 0) + 1;
    }
}
