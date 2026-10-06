<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * RecordService::executeCreate/Update/Delete — behind the MCP tools, the
 * attachment endpoints and any application code calling them — audit through
 * RecordCreated/Updated/Deleted and LogRecordAuditListener. That listener was
 * ShouldQueue, so on any non-sync queue connection it ran in a worker whatever
 * `audit.queue_enabled` said, and it passed only the request context as the
 * record: every row lost entity_id, user_id, ip_address, user_agent and the
 * tenant, new_data held the request context, and update and delete rows —
 * having no id — were never written.
 *
 * @internal
 */
class RecordEventAuditTest extends TestCase
{
    use RefreshDatabase;

    private const IP = '10.1.2.3';

    private const AGENT = 'ClientApp/2.4';

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('record.enable_tenant_id', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('widgets', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('tenant_id')->nullable();
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
        Config::set('audit.queue_enabled', false);
        // A real app's default connection; Laravel 11+ ships QUEUE_CONNECTION=database.
        Config::set('queue.default', 'database');
        Config::set('queue.connections.database', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);

        Config::set('record.tables', [
            'widgets' => new RecordTableType(
                table: 'widgets',
                hasTenantId: true,
                columns: [
                    'id' => ['type' => 'integer', 'nullable' => false],
                    'name' => ['type' => 'string', 'nullable' => false],
                    'tenant_id' => ['type' => 'string', 'nullable' => true],
                    'created_at' => ['type' => 'datetime', 'nullable' => true],
                    'updated_at' => ['type' => 'datetime', 'nullable' => true],
                ],
            ),
        ]);
        SchemaRegistryUtils::refresh();

        $this->app->instance('request', Request::create('/api/widgets', 'POST', [], [], [], [
            'REMOTE_ADDR' => self::IP,
            'HTTP_USER_AGENT' => self::AGENT,
        ]));
        Auth::guard('api')->setUser(new GenericUser(['id' => 7, 'name' => 'Client']));
    }

    /** @test */
    public function service_crud_is_audited_in_the_request_with_the_record_and_its_actor(): void
    {
        RecordService::executeCreate('widgets', ['name' => 'A'], [], 't1');
        RecordService::executeUpdate('widgets', 1, ['name' => 'B'], [], 't1');
        RecordService::executeDelete('widgets', 1, [], 't1');

        $this->assertSame(0, DB::table('jobs')->count(), 'audit.queue_enabled is false: nothing may be queued');

        $rows = DB::table('sp_audit_logs')->orderBy('id')->get();
        $this->assertSame(['created', 'updated', 'deleted'], $rows->pluck('event')->all());

        foreach ($rows as $row) {
            $this->assertSame('widgets', $row->entity_name);
            $this->assertSame('1', (string) $row->entity_id);
            $this->assertSame('7', (string) $row->user_id);
            $this->assertSame(self::IP, $row->ip_address);
            $this->assertSame(self::AGENT, $row->user_agent);
            $this->assertSame('t1', (string) $row->tenant_id);
        }

        $created = json_decode((string) $rows[0]->new_data, true);
        $this->assertSame('A', $created['name'] ?? null);
        $this->assertArrayNotHasKey('user_agent', $created, 'new_data must be the record, not the request context');

        $this->assertSame('A', json_decode((string) $rows[1]->old_data, true)['name'] ?? null);
        $this->assertSame('B', json_decode((string) $rows[1]->new_data, true)['name'] ?? null);
        $this->assertSame('B', json_decode((string) $rows[2]->old_data, true)['name'] ?? null);
    }

    /** @test */
    public function service_crud_is_still_queued_when_audit_queueing_is_enabled(): void
    {
        Config::set('audit.queue_enabled', true);

        RecordService::executeCreate('widgets', ['name' => 'A'], [], 't1');

        $this->assertSame(0, DB::table('sp_audit_logs')->count());
        $this->assertSame(1, DB::table('jobs')->count());
    }
}
