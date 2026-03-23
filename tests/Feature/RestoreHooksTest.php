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

class RestoreHookSpy
{
    public static array $calls = [];

    public static ?array $capturedBeforeCtx = null;

    public static ?array $capturedAfterCtx  = null;

    public static function reset(): void
    {
        self::$calls            = [];
        self::$capturedBeforeCtx = null;
        self::$capturedAfterCtx  = null;
    }

    public static function beforeRestore(Request $request, string $table, array $context): ?Request
    {
        self::$calls[]           = 'beforeRestore';
        self::$capturedBeforeCtx = $context;
        return null;
    }

    public static function beforeRestorePassThrough(Request $request, string $table, array $context): Request
    {
        self::$calls[] = 'beforeRestore';
        return $request;
    }

    public static function afterRestore(Request $request, string $table, array $context): void
    {
        self::$calls[]          = 'afterRestore';
        self::$capturedAfterCtx = $context;
    }

    public static function afterUpdate(Request $request, string $table, array $context): void
    {
        self::$calls[] = 'afterUpdate';
    }
}

// ---------------------------------------------------------------------------

class RestoreHooksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RestoreHookSpy::reset();

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->integer('total')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    private function registerTable(array $triggers = []): void
    {
        Config::set('record.tables', [
            'invoices' => new RecordTableType(
                table: 'invoices',
                softDeletes: true,
                public: new RecordTablePublic(read: true, write: true),
                afterUpdate: $triggers['afterUpdate'] ?? null,
                beforeRestore: $triggers['beforeRestore'] ?? null,
                afterRestore: $triggers['afterRestore'] ?? null,
            ),
        ]);
        SchemaRegistryUtils::refresh();
    }

    private function seedDeletedInvoice(): int
    {
        $id = DB::table('invoices')->insertGetId([
            'code'       => 'INV-001',
            'total'      => 500,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('invoices')->where('id', $id)->update(['deleted_at' => now()]);
        return $id;
    }

    // -----------------------------------------------------------------------
    // beforeRestore receives full record object (the deleted row)
    // -----------------------------------------------------------------------

    /** @test */
    public function before_restore_receives_full_record_object(): void
    {
        $this->registerTable([
            'beforeRestore' => new RecordTableTriggerType(
                class: RestoreHookSpy::class,
                functionName: 'beforeRestore',
            ),
        ]);

        $id = $this->seedDeletedInvoice();

        $this->postJson(sprintf('/api/invoices/%d/restore', $id))->assertStatus(200);

        $this->assertContains('beforeRestore', RestoreHookSpy::$calls);
        $ctx = RestoreHookSpy::$capturedBeforeCtx;
        $this->assertNotNull($ctx);
        $this->assertArrayHasKey('record', $ctx);
        $record = $ctx['record'];
        $this->assertIsObject($record);
        $this->assertSame($id, (int) $record->id);
        $this->assertSame('INV-001', $record->code);
        $this->assertSame(500, (int) $record->total);
    }

    // -----------------------------------------------------------------------
    // afterRestore receives full record object
    // -----------------------------------------------------------------------

    /** @test */
    public function after_restore_receives_full_record_object(): void
    {
        $this->registerTable([
            'afterRestore' => new RecordTableTriggerType(
                class: RestoreHookSpy::class,
                functionName: 'afterRestore',
            ),
        ]);

        $id = $this->seedDeletedInvoice();

        $this->postJson(sprintf('/api/invoices/%d/restore', $id))->assertStatus(200);

        $this->assertContains('afterRestore', RestoreHookSpy::$calls);
        $ctx = RestoreHookSpy::$capturedAfterCtx;
        $this->assertNotNull($ctx);
        $this->assertArrayHasKey('record', $ctx);
        $this->assertIsObject($ctx['record']);
        $this->assertSame($id, (int) $ctx['record']->id);
        $this->assertArrayHasKey('restored', $ctx);
        $this->assertSame(1, (int) $ctx['restored']);
    }

    // -----------------------------------------------------------------------
    // beforeRestore can return Request (pass-through)
    // -----------------------------------------------------------------------

    /** @test */
    public function before_restore_can_return_request(): void
    {
        $this->registerTable([
            'beforeRestore' => new RecordTableTriggerType(
                class: RestoreHookSpy::class,
                functionName: 'beforeRestorePassThrough',
            ),
        ]);

        $id = $this->seedDeletedInvoice();

        $this->postJson(sprintf('/api/invoices/%d/restore', $id))->assertStatus(200);

        $this->assertContains('beforeRestore', RestoreHookSpy::$calls);
        $row = DB::table('invoices')->find($id);
        $this->assertNotNull($row);
        $this->assertNull($row->deleted_at);
    }

    // -----------------------------------------------------------------------
    // Both hooks fire in correct order
    // -----------------------------------------------------------------------

    /** @test */
    public function both_restore_hooks_fire_in_correct_order(): void
    {
        $this->registerTable([
            'beforeRestore' => new RecordTableTriggerType(
                class: RestoreHookSpy::class,
                functionName: 'beforeRestore',
            ),
            'afterRestore' => new RecordTableTriggerType(
                class: RestoreHookSpy::class,
                functionName: 'afterRestore',
            ),
        ]);

        $id = $this->seedDeletedInvoice();

        $this->postJson(sprintf('/api/invoices/%d/restore', $id))->assertStatus(200);

        $this->assertSame(['beforeRestore', 'afterRestore'], RestoreHookSpy::$calls);
    }

    // -----------------------------------------------------------------------
    // Restore operation fires afterRestore, NOT afterUpdate
    // -----------------------------------------------------------------------

    /** @test */
    public function restore_fires_after_restore_not_after_update(): void
    {
        $this->registerTable([
            'afterUpdate' => new RecordTableTriggerType(
                class: RestoreHookSpy::class,
                functionName: 'afterUpdate',
            ),
            'afterRestore' => new RecordTableTriggerType(
                class: RestoreHookSpy::class,
                functionName: 'afterRestore',
            ),
        ]);

        $id = $this->seedDeletedInvoice();

        $this->postJson(sprintf('/api/invoices/%d/restore', $id))->assertStatus(200);

        $this->assertNotContains('afterUpdate', RestoreHookSpy::$calls, 'afterUpdate should NOT be called on restore');
        $this->assertContains('afterRestore', RestoreHookSpy::$calls, 'afterRestore should be called on restore');
    }

    // -----------------------------------------------------------------------
    // beforeRestore context has deleted_at set (raw deleted row)
    // -----------------------------------------------------------------------

    /** @test */
    public function before_restore_context_record_has_deleted_at_set(): void
    {
        $this->registerTable([
            'beforeRestore' => new RecordTableTriggerType(
                class: RestoreHookSpy::class,
                functionName: 'beforeRestore',
            ),
        ]);

        $id = $this->seedDeletedInvoice();

        $this->postJson(sprintf('/api/invoices/%d/restore', $id))->assertStatus(200);

        $ctx = RestoreHookSpy::$capturedBeforeCtx;
        $this->assertNotNull($ctx);
        $record = $ctx['record'] ?? null;
        $this->assertIsObject($record);
        // fetchRawRecord uses DB::table() so deleted_at is available on the raw row
        $this->assertNotNull($record->deleted_at, 'The raw fetched record should have deleted_at set before restore');
    }

    // -----------------------------------------------------------------------
    // restoreRecord actually restores the row
    // -----------------------------------------------------------------------

    /** @test */
    public function restore_actually_recovers_the_record(): void
    {
        $this->registerTable();
        $id = $this->seedDeletedInvoice();

        $this->postJson(sprintf('/api/invoices/%d/restore', $id))->assertStatus(200);

        $row = DB::table('invoices')->find($id);
        $this->assertNotNull($row);
        $this->assertNull($row->deleted_at);
    }
}


