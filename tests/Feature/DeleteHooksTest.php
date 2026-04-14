<?php

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableTriggerType;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

// ---------------------------------------------------------------------------
// Static spy — state is reset in setUp()
// ---------------------------------------------------------------------------

class DeleteHookSpy
{
    public static array $calls = [];

    public static ?object $capturedRecord = null;

    public static ?array $capturedContext = null;

    public static function reset(): void
    {
        self::$calls         = [];
        self::$capturedRecord = null;
        self::$capturedContext = null;
    }

    public static function beforeDelete(Request $request, string $table, array $context): ?Request
    {
        self::$calls[]        = 'beforeDelete';
        self::$capturedContext = $context;
        self::$capturedRecord  = $context['record'] ?? null;
        return null;
    }

    public static function beforeDeletePassThrough(Request $request, string $table, array $context): Request
    {
        self::$calls[] = 'beforeDelete';
        return $request;
    }

    public static function afterDelete(Request $request, string $table, array $context): void
    {
        self::$calls[]        = 'afterDelete';
        self::$capturedContext = $context;
    }
}

// ---------------------------------------------------------------------------

class DeleteHooksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DeleteHookSpy::reset();

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('ref');
            $table->integer('amount')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    private function registerTable(array $triggers = []): void
    {
        Config::set('record.tables', [
            'orders' => new RecordTableType(
                table: 'orders',
                softDeletes: true,
                public: new RecordTablePublic(read: true, write: true),
                beforeDelete: $triggers['beforeDelete'] ?? null,
                afterDelete: $triggers['afterDelete'] ?? null,
            ),
        ]);
        SchemaRegistryUtils::refresh();
    }

    private function seedOrder(): int
    {
        return DB::table('orders')->insertGetId([
            'ref'        => 'ORD-001',
            'amount'     => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // -----------------------------------------------------------------------
    // beforeDelete receives full record object
    // -----------------------------------------------------------------------

    /** @test */
    public function before_delete_receives_full_record_object(): void
    {
        $this->registerTable([
            'beforeDelete' => new RecordTableTriggerType(
                class: DeleteHookSpy::class,
                functionName: 'beforeDelete',
            ),
        ]);

        $id = $this->seedOrder();

        $this->deleteJson('/api/orders/' . $id)->assertStatus(200);

        $this->assertContains('beforeDelete', DeleteHookSpy::$calls);
        $record = DeleteHookSpy::$capturedRecord;
        $this->assertNotNull($record);
        $this->assertIsObject($record);
        $this->assertSame($id, (int) $record->id);
        $this->assertSame('ORD-001', $record->ref);
        $this->assertSame(100, (int) $record->amount);
    }

    // -----------------------------------------------------------------------
    // afterDelete receives full record object
    // -----------------------------------------------------------------------

    /** @test */
    public function after_delete_receives_full_record_object(): void
    {
        $this->registerTable([
            'afterDelete' => new RecordTableTriggerType(
                class: DeleteHookSpy::class,
                functionName: 'afterDelete',
            ),
        ]);

        $id = $this->seedOrder();

        $this->deleteJson('/api/orders/' . $id)->assertStatus(200);

        $this->assertContains('afterDelete', DeleteHookSpy::$calls);
        $ctx = DeleteHookSpy::$capturedContext;
        $this->assertNotNull($ctx);
        $this->assertArrayHasKey('record', $ctx);
        $this->assertIsObject($ctx['record']);
        $this->assertSame($id, (int) $ctx['record']->id);
    }

    // -----------------------------------------------------------------------
    // beforeDelete can return a Request (pass-through)
    // -----------------------------------------------------------------------

    /** @test */
    public function before_delete_can_return_request(): void
    {
        $this->registerTable([
            'beforeDelete' => new RecordTableTriggerType(
                class: DeleteHookSpy::class,
                functionName: 'beforeDeletePassThrough',
            ),
        ]);

        $id = $this->seedOrder();

        $this->deleteJson('/api/orders/' . $id)->assertStatus(200);

        $this->assertContains('beforeDelete', DeleteHookSpy::$calls);
        $this->assertSoftDeleted('orders', ['id' => $id]);
    }

    // -----------------------------------------------------------------------
    // forceDelete fires beforeDelete hook with force_delete flag
    // -----------------------------------------------------------------------

    /** @test */
    public function force_delete_fires_before_delete_hook_with_force_delete_flag(): void
    {
        $this->registerTable([
            'beforeDelete' => new RecordTableTriggerType(
                class: DeleteHookSpy::class,
                functionName: 'beforeDelete',
            ),
        ]);

        $id = $this->seedOrder();

        $this->deleteJson(sprintf('/api/orders/%d/force', $id))->assertStatus(200);

        $this->assertContains('beforeDelete', DeleteHookSpy::$calls);
        $ctx = DeleteHookSpy::$capturedContext;
        $this->assertNotNull($ctx);
        $this->assertArrayHasKey('force_delete', $ctx);
        $this->assertTrue($ctx['force_delete']);
        $this->assertArrayHasKey('record', $ctx);
        $this->assertIsObject($ctx['record']);
        $this->assertSame($id, (int) $ctx['record']->id);
    }

    // -----------------------------------------------------------------------
    // forceDelete permanently removes the record
    // -----------------------------------------------------------------------

    /** @test */
    public function force_delete_permanently_removes_record(): void
    {
        $this->registerTable();
        $id = $this->seedOrder();

        $this->deleteJson(sprintf('/api/orders/%d/force', $id))->assertStatus(200);

        $this->assertDatabaseMissing('orders', ['id' => $id]);
    }

    // -----------------------------------------------------------------------
    // afterDelete hook fires after soft delete
    // -----------------------------------------------------------------------

    /** @test */
    public function after_delete_hook_fires_after_soft_delete(): void
    {
        $this->registerTable([
            'afterDelete' => new RecordTableTriggerType(
                class: DeleteHookSpy::class,
                functionName: 'afterDelete',
            ),
        ]);

        $id = $this->seedOrder();
        $this->deleteJson('/api/orders/' . $id)->assertStatus(200);

        $this->assertContains('afterDelete', DeleteHookSpy::$calls);
        $this->assertSoftDeleted('orders', ['id' => $id]);
    }
}
