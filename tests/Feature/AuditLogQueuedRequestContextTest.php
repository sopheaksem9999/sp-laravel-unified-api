<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Enums\AuditLogEventEnum;
use Sopheak\Core\Services\AuditLogService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class QueuedAuditContextUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}

/**
 * With `audit.queue_enabled`, the audit row is written by a queue worker, and
 * createAuditLogEntry() read the actor, IP address and user agent there — from
 * a worker that has no HTTP request and no authenticated user. Every queued
 * CRUD audit row therefore stored user_id NULL and the worker's own address
 * and agent instead of the client's.
 *
 * @internal
 */
class AuditLogQueuedRequestContextTest extends TestCase
{
    use RefreshDatabase;

    private const USER = 7;

    private const IP = '10.1.2.3';

    private const AGENT = 'ClientApp/2.4';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $t): void {
            $t->id();
        });
        DB::table('users')->insert(['id' => self::USER]);

        Schema::create('widgets', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });

        Schema::create('jobs', function (Blueprint $t): void {
            $t->id();
            $t->string('queue')->index();
            $t->longText('payload');
            $t->unsignedTinyInteger('attempts');
            $t->unsignedInteger('reserved_at')->nullable();
            $t->unsignedInteger('available_at');
            $t->unsignedInteger('created_at');
        });

        Config::set('audit.enabled', true);
        Config::set('audit.queue_enabled', true);
        Config::set('queue.default', 'database');
        Config::set('queue.connections.database', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);

        Config::set('record.tables', [
            'widgets' => new RecordTableType(
                table: 'widgets',
                pmsName: 'widget',
                hasTenantId: false,
                columns: [
                    'id' => ['type' => 'integer', 'nullable' => false],
                    'name' => ['type' => 'string', 'nullable' => false],
                    'created_at' => ['type' => 'datetime', 'nullable' => true],
                    'updated_at' => ['type' => 'datetime', 'nullable' => true],
                ],
            ),
        ]);
        SchemaRegistryUtils::refresh();

        Gate::before(fn(): bool => true);
    }

    /**
     * Run every queued job the way a worker does: in a process with no client
     * request, no authenticated user, and none of the request's context.
     */
    private function runWorker(): void
    {
        Context::flush();
        Auth::forgetUser();
        $this->app->instance('request', Request::create('/'));

        // The worker runs inside this PHPUnit process; by the time this test runs
        // late in a long suite the process is past queue:work's 128 MB default and
        // the worker would stop with exit code 12 (EXIT_MEMORY_LIMIT) before
        // working a single job.
        $this->artisan('queue:work', ['connection' => 'database', '--once' => false, '--stop-when-empty' => true, '--memory' => 2048])->assertExitCode(0);
    }

    private function asClient(): static
    {
        return $this->actingAs(QueuedAuditContextUser::query()->findOrFail(self::USER), 'api')
            ->withServerVariables(['REMOTE_ADDR' => self::IP])
            ->withHeaders(['User-Agent' => self::AGENT, 'X-Request-ID' => 'req-abc']);
    }

    /** @test */
    public function queued_crud_audit_rows_keep_the_clients_actor_address_and_agent(): void
    {
        $this->asClient()->postJson('/api/widgets', ['name' => 'A'])->assertSuccessful();
        $this->asClient()->putJson('/api/widgets/1', ['name' => 'B'])->assertSuccessful();
        $this->asClient()->deleteJson('/api/widgets/1')->assertSuccessful();

        $this->assertSame(0, DB::table('sp_audit_logs')->count(), 'audit rows must be written by the worker');

        $this->runWorker();

        $rows = DB::table('sp_audit_logs')->orderBy('id')->get();
        $this->assertSame(['created', 'updated', 'deleted'], $rows->pluck('event')->all());

        foreach ($rows as $row) {
            $this->assertSame('widgets', $row->entity_name);
            $this->assertSame((string) self::USER, (string) $row->user_id);
            $this->assertSame(self::IP, $row->ip_address);
            $this->assertSame(self::AGENT, $row->user_agent);
            $this->assertSame('req-abc', $row->request_id);
        }
    }

    /** @test */
    public function a_queued_auth_event_keeps_the_clients_address_and_agent(): void
    {
        $this->asClient()->getJson('/api/widgets');
        AuditLogService::authEvent(AuditLogEventEnum::LOGIN, ['id' => self::USER]);

        $this->runWorker();

        $row = DB::table('sp_audit_logs')->where('event', 'login')->first();
        $this->assertNotNull($row);
        $this->assertSame(self::IP, $row->ip_address);
        $this->assertSame(self::AGENT, $row->user_agent);
    }

    /** @test */
    public function synchronous_audit_rows_are_unchanged(): void
    {
        Config::set('audit.queue_enabled', false);

        $this->asClient()->postJson('/api/widgets', ['name' => 'A'])->assertSuccessful();

        $row = DB::table('sp_audit_logs')->first();
        $this->assertSame('widgets', $row->entity_name);
        $this->assertSame((string) self::USER, (string) $row->user_id);
        $this->assertSame(self::IP, $row->ip_address);
        $this->assertSame(self::AGENT, $row->user_agent);
    }
}
