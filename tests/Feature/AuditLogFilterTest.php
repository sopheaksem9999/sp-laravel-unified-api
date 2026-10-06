<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Sopheak\Core\Events\RecordMutated;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Sopheak\Core\Enums\AuditLogEventEnum as Event;
use Sopheak\Core\Jobs\AuditLogJob;
use Sopheak\Core\Services\AuditLogService;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class AuditLogFilterTest extends TestCase
{
    // Config values are serializable data, not first-class closures.
    private const DENY_FILTER = [FilterProbe::class, 'deny'];

    private const ACTOR_FILTER = [FilterProbe::class, 'byActor'];

    private const SECOND_FILTER = [FilterProbe::class, 'secondOnly'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit.enabled' => true]);
        FilterProbe::$calls = [];
    }

    public function test_rejection_preserves_triggers_and_broadcast(): void
    {
        \Illuminate\Support\Facades\Event::fake([RecordMutated::class]);
        config(['audit.filter' => self::DENY_FILTER, 'record.broadcast_events' => true,
            'record.tables' => ['widgets' => new RecordTableType(table: 'widgets', afterCreate: [['class' => FilterProbe::class, 'functionName' => 'trigger']])],
            'record.global_triggers.afterCreate' => [['class' => FilterProbe::class, 'functionName' => 'trigger']],
        ]);
        SchemaRegistryUtils::refresh();
        FilterProbe::$triggers = 0;
        app(RecordService::class)->processPostWriteLogic(Request::create('/'), 'widgets', 'create', ['id' => 1]);
        $this->assertSame(2, FilterProbe::$triggers);
        \Illuminate\Support\Facades\Event::assertDispatched(RecordMutated::class);
    }

    public function test_mixed_bulk_upserts_make_independent_decisions(): void
    {
        Schema::create('widgets', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        DB::table('widgets')->insert([['id' => 1, 'name' => 'one'], ['id' => 2, 'name' => 'two']]);
        config(['audit.filter' => self::SECOND_FILTER, 'audit.queue_enabled' => true,
            'record.tables' => ['widgets' => new RecordTableType(
                table: 'widgets',
                hasTenantId: false,
                softDeletes: false,
                columns: ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']]
            )],
        ]);
        SchemaRegistryUtils::refresh();
        Queue::fake();
        $request = Request::create('/?match_on=id', 'POST', ['items' => [
            ['operation' => 'upsert', 'id' => 1, 'name' => 'changed one'],
            ['operation' => 'upsert', 'id' => 2, 'name' => 'changed two'],
        ]]);
        app(RecordService::class)->bulkRecord($request, 'widgets', null);
        // Existing bulk upsert submits once in its inner update and once in its wrapper.
        $this->assertCount(4, FilterProbe::$calls);
        $this->assertSame(['update', 'upsert', 'update', 'upsert'], array_map(fn(array $call) => $call[4]['operation'], FilterProbe::$calls));
        Queue::assertPushed(AuditLogJob::class, 2);
        $this->assertSame('changed one', DB::table('widgets')->where('id', 1)->value('name'));
    }

    public function test_worker_does_not_re_evaluate_admitted_entry(): void
    {
        config(['audit.filter' => self::ACTOR_FILTER, 'audit.queue_enabled' => true]);
        Queue::fake();
        AuditLogService::insertAuditLog(Event::CREATED, 'widgets', ['id' => 1]);
        $job = Queue::pushed(AuditLogJob::class)->first();
        config(['audit.filter' => self::DENY_FILTER]);
        $job->handle();
        $this->assertCount(1, FilterProbe::$calls);
        $this->assertSame(1, DB::table('sp_audit_logs')->count());
    }

    public function test_table_disabled_and_invalid_custom_handler_keep_legacy_behavior(): void
    {
        config(['audit.filter' => self::DENY_FILTER, 'record.tables' => [
            'widgets' => new RecordTableType(table: 'widgets', disableAuditLog: true),
        ]]);
        SchemaRegistryUtils::refresh();
        app(RecordService::class)->processPostWriteLogic(Request::create('/'), 'widgets', 'create', ['id' => 1]);
        $this->assertSame([], FilterProbe::$calls);
        config(['audit.filter' => null, 'record.tables' => [
            'widgets' => new RecordTableType(table: 'widgets', customAuditLog: 'missing_audit_handler'),
        ]]);
        SchemaRegistryUtils::refresh();
        app(RecordService::class)->processPostWriteLogic(Request::create('/'), 'widgets', 'create', ['id' => 1]);
        $this->assertSame(1, DB::table('sp_audit_logs')->count());
    }

    public function test_admission_preserves_no_change_update_and_legacy_override(): void
    {
        config(['audit.filter' => self::ACTOR_FILTER]);
        AuditLogService::insertAuditLog(Event::UPDATED, 'widgets', ['id' => 1, 'old_data' => ['name' => 'same'], 'new_data' => ['name' => 'same']]);
        $this->assertSame(0, DB::table('sp_audit_logs')->count());
        LegacyAuditSubclass::insertAuditLog(Event::CREATED, 'widgets', ['id' => 1]);
        $this->assertSame(1, DB::table('sp_audit_logs')->count());
    }

    public function test_custom_job_receives_unchanged_submission_arguments(): void
    {
        Queue::fake();
        config(['audit.filter' => self::ACTOR_FILTER, 'audit.queue_enabled' => true, 'audit.audit_log_job' => CustomFilterAuditJob::class]);
        AuditLogService::insertAuditLogWithContext(Event::CREATED, 'widgets', ['id' => 1], 'subject', 'recap', 'tenant-a', ['actor' => null]);
        Queue::assertPushed(CustomFilterAuditJob::class, fn($job): bool => $job->event === Event::CREATED
            && $job->entityType === 'widgets' && $job->queryData === ['id' => 1]
            && $job->subject === 'subject' && $job->recap === 'recap' && $job->tenantId === 'tenant-a');
        $this->assertCount(1, FilterProbe::$calls);
    }

    public function test_sequential_requests_do_not_retain_actor_or_tenant(): void
    {
        config(['audit.filter' => self::ACTOR_FILTER, 'audit.queue_enabled' => true]);
        Queue::fake();
        foreach ([7, null, 9] as $id) {
            $request = Request::create('/');
            $actor = $id === null ? null : new GenericUser(['id' => $id]);
            $request->setUserResolver(fn(): ?GenericUser => $actor);
            AuditLogService::insertAuditLogWithContext(Event::CREATED, 'widgets', ['id' => 1], tenantId: $id, context: ['request' => $request]);
        }

        $this->assertSame([7, null, 9], array_map(fn(array $call) => $call[3]?->getAuthIdentifier(), FilterProbe::$calls));
        $this->assertSame([7, null, 9], array_map(fn(array $call) => $call[4]['tenant_id'], FilterProbe::$calls));
        $this->assertSame(self::ACTOR_FILTER, config('audit.filter'));
        Queue::assertPushed(AuditLogJob::class, 1);
    }

    public function test_missing_filter_preserves_sync_and_queue_behavior(): void
    {
        AuditLogService::insertAuditLog(Event::CREATED, 'widgets', ['id' => 1, 'name' => 'A']);
        $this->assertSame(1, DB::table('sp_audit_logs')->count());
        Queue::fake();
        config(['audit.queue_enabled' => true]);
        AuditLogService::insertAuditLog(Event::CREATED, 'widgets', ['id' => 2]);
        Queue::assertPushed(AuditLogJob::class, fn($job): bool => $job->queryData === ['id' => 2]);
    }

    public function test_denial_skips_history_queries_and_dispatch(): void
    {
        config(['audit.filter' => self::DENY_FILTER, 'audit.queue_enabled' => true, 'audit.store_diff_only' => true]);
        Queue::fake();
        DB::enableQueryLog();
        DB::flushQueryLog();
        AuditLogService::insertAuditLog(Event::UPDATED, 'widgets', ['id' => 1, 'name' => 'B']);
        $this->assertSame([], DB::getQueryLog());
        Queue::assertNothingPushed();
        $this->assertCount(1, FilterProbe::$calls);
    }

    public function test_denial_skips_synchronous_persistence(): void
    {
        config(['audit.filter' => self::DENY_FILTER]);
        AuditLogService::insertAuditLog(Event::CREATED, 'widgets', ['id' => 1]);
        $this->assertSame(0, DB::table('sp_audit_logs')->count());
    }

    public function test_context_actor_null_and_tenant_are_explicit_and_not_retained(): void
    {
        config(['audit.filter' => self::ACTOR_FILTER, 'audit.queue_enabled' => true]);
        Queue::fake();
        $request = Request::create('/widgets');
        $actor = new GenericUser(['id' => 7]);
        $request->setUserResolver(fn(): GenericUser => $actor);
        foreach ([$actor, null] as $user) {
            AuditLogService::insertAuditLogWithContext(Event::CREATED, 'widgets', ['id' => 1], tenantId: 'tenant-a', context: [
                'request' => $request, 'actor' => $user, 'tenant_id' => 'untrusted',
            ]);
        }

        $this->assertSame($actor, FilterProbe::$calls[0][3]);
        $this->assertNull(FilterProbe::$calls[1][3]);
        $this->assertSame('tenant-a', FilterProbe::$calls[1][4]['tenant_id']);
        Queue::assertPushed(AuditLogJob::class, 1);
    }

    public function test_record_policy_receives_operation_table_and_request_actor(): void
    {
        config(['audit.filter' => self::DENY_FILTER, 'record.tables' => [
            'widgets' => new RecordTableType(table: 'widgets', hasTenantId: false),
        ]]);
        SchemaRegistryUtils::refresh();
        $request = Request::create('/widgets');
        $actor = new GenericUser(['id' => 9]);
        $request->setUserResolver(fn(): GenericUser => $actor);
        app(RecordService::class)->processPostWriteLogic($request, 'widgets', 'restore', ['id' => 1]);
        $this->assertCount(1, FilterProbe::$calls);
        [$event, $table, $data, $user, $context] = FilterProbe::$calls[0];
        $this->assertSame(Event::UPDATED, $event);
        $this->assertSame('widgets', $table);
        $this->assertSame($actor, $user);
        $this->assertSame('restore', $context['operation']);
        $this->assertSame('record', $context['source']);
    }

    public function test_authentication_and_disabled_audit_do_not_invoke_policy(): void
    {
        config(['audit.filter' => self::DENY_FILTER, 'audit.queue_enabled' => true]);
        Queue::fake();
        AuditLogService::insertAuditLog(Event::LOGIN, 'users', ['id' => 1]);
        AuditLogService::authEvent(Event::FAILED_LOGIN, ['id' => 1]);
        config(['audit.enabled' => false]);
        AuditLogService::insertAuditLog(Event::CREATED, 'widgets', ['id' => 1]);
        $this->assertSame([], FilterProbe::$calls);
        Queue::assertPushed(AuditLogJob::class, 2);
    }

    public function test_custom_logger_return_values_still_consume_default_audit(): void
    {
        foreach (['voidHandler', 'nullHandler', 'falseHandler', 'trueHandler'] as $method) {
            config(['record.tables' => ['widgets' => new RecordTableType(table: 'widgets', customAuditLog: [FilterProbe::class, $method])]]);
            SchemaRegistryUtils::refresh();
            app(RecordService::class)->processPostWriteLogic(Request::create('/'), 'widgets', 'create', ['id' => 1]);
        }

        $this->assertSame(0, DB::table('sp_audit_logs')->count());
    }
}

class FilterProbe
{
    public static array $calls = [];

    public static int $triggers = 0;

    public static function trigger(): void
    {
        ++self::$triggers;
    }

    public static function secondOnly(...$args): bool
    {
        self::$calls[] = $args;
        return ($args[2]['id'] ?? null) === 2;
    }


    public static function deny(...$args): bool
    {
        self::$calls[] = $args;
        return false;
    }

    public static function byActor(...$args): bool
    {
        self::$calls[] = $args;
        return $args[3] === null;
    }

    public static function voidHandler(): void {}

    public static function nullHandler(): mixed
    {
        return null;
    }

    public static function falseHandler(): bool
    {
        return false;
    }

    public static function trueHandler(): bool
    {
        return true;
    }
}

class LegacyAuditSubclass extends AuditLogService
{
    public static function insertAuditLog(Event $auditLogEventEnum, string $entityClass, array|object $queryData = [], ?string $subject = '', ?string $recap = '', mixed $tenantId = null): void
    {
        parent::insertAuditLog($auditLogEventEnum, $entityClass, $queryData, $subject, $recap, $tenantId);
    }
}

class CustomFilterAuditJob extends AuditLogJob {}
