<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature\Ai;

use Error;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Responses\Data\ToolCall;
use RuntimeException;
use Sopheak\Core\Ai\RecordTools;
use Sopheak\Core\Tests\Concerns\BuildsGuidanceFixture;
use Sopheak\Core\Tests\Concerns\UsesLaravelAi;
use Sopheak\Core\Tests\Support\AiRecordAgent;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * A queued or background agent has no request and no user. The tool set
 * carries who and which tenant it runs as, installs them for one call and
 * puts the process back (spec §7.4, §12.2, §12.4).
 *
 * @internal
 */
class AgentContextTest extends TestCase
{
    use BuildsGuidanceFixture;
    use RefreshDatabase;
    use UsesLaravelAi;

    protected function setUp(): void
    {
        $this->requireLaravelAi();
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('password')->nullable();
        });
        DB::table('users')->insert(['id' => 7, 'name' => 'Seven', 'password' => 'SECRET-HASH']);

        $this->buildGuidanceFixture(tenant: true);
        $tables = Config::get('record.tables');
        $tables['invoices']->isAuthRead = true;
        $tables['invoices']->isAuthWrite = true;
        Config::set('record.tables', $tables);
        SchemaRegistryUtils::refresh();
    }

    private function user(): GenericUser
    {
        return new GenericUser(['id' => 7, 'name' => 'Seven', 'password' => 'SECRET-HASH']);
    }

    private function createCall(): ToolCall
    {
        return new ToolCall('call_1', 'create_invoices', ['payload' => ['ref_number' => 'Q-1', 'customer_id' => 1]]);
    }

    public function test_a_queued_style_agent_writes_as_its_user_in_its_tenant(): void
    {
        Gate::before(static fn($user, string $ability): ?bool => 'create:invoices' === $ability ? true : null);
        $this->scriptedGateway([$this->createCall(), 'done']);
        $tools = RecordTools::for('invoices')->only(['create'])->withoutApproval()->actingAs($this->user())->forTenant('t1');

        $this->assertFalse(auth('api')->hasUser(), 'precondition: there is no user, as in a queue worker');
        (new AiRecordAgent($tools))->prompt('queue it');

        $row = DB::table('invoices')->where('ref_number', 'Q-1')->first();
        $this->assertSame('t1', $row->tenant_id);
        $this->assertSame(7, (int) $row->created_by_id);
    }

    public function test_the_process_is_restored_after_the_run(): void
    {
        Gate::before(static fn($user, string $ability): ?bool => 'create:invoices' === $ability ? true : null);
        $this->scriptedGateway([$this->createCall(), 'done']);
        $tools = RecordTools::for('invoices')->only(['create'])->withoutApproval()->actingAs($this->user())->forTenant('t1');

        (new AiRecordAgent($tools))->prompt('queue it');

        $this->assertFalse(auth('api')->hasUser());
        $this->assertFalse(auth()->guard()->hasUser());
        $this->assertFalse(request()->attributes->has('resolved_tenant_id'));
    }

    public function test_the_process_is_restored_when_a_call_throws(): void
    {
        Gate::before(static function (): never {
            throw new Error('permission hook blew up');
        });
        $this->scriptedGateway([$this->createCall(), 'done']);
        $tools = RecordTools::for('invoices')->only(['create'])->withoutApproval()->actingAs($this->user())->forTenant('t1');

        try {
            (new AiRecordAgent($tools))->prompt('queue it');
            $this->fail('the failure must reach the caller');
        } catch (RuntimeException $runtimeException) {
            $this->assertInstanceOf(Error::class, $runtimeException->getPrevious());
            $this->assertSame('permission hook blew up', $runtimeException->getPrevious()->getMessage());
        }

        $this->assertFalse(auth('api')->hasUser());
        $this->assertFalse(request()->attributes->has('resolved_tenant_id'));
    }

    public function test_no_context_and_no_user_is_unauthenticated_not_anonymous(): void
    {
        $this->scriptedGateway([new ToolCall('call_1', 'list_invoices', []), 'done']);

        $response = (new AiRecordAgent(RecordTools::for('invoices')->only(['list'])))->prompt('go');

        $seen = json_decode((string) $response->toolResults->first()->result, true);
        $this->assertSame(-32001, $seen['error']['code']);
        $this->assertSame('Unauthenticated', $seen['error']['message']);
    }

    public function test_a_serialised_agent_carries_no_user_and_still_runs_as_that_user(): void
    {
        Gate::before(static fn($user, string $ability): ?bool => 'create:invoices' === $ability ? true : null);
        $tools = RecordTools::for('invoices')->only(['create'])->withoutApproval()->actingAs($this->user())->forTenant('t1')->toArray();
        $agent = new AiRecordAgent($tools);

        $serialized = serialize($agent);

        $this->assertStringNotContainsString('SECRET-HASH', $serialized);
        // The class name is stored as text (like SerializesModels), never the user object.
        $this->assertStringNotContainsString('O:27:"Illuminate\\Auth\\GenericUser"', $serialized);
        $this->assertStringNotContainsString('Seven', $serialized, 'no attribute of the user travels with the agent');

        $restored = unserialize($serialized);
        $this->scriptedGateway([$this->createCall(), 'done']);
        $restored->prompt('queue it');

        $this->assertSame(7, (int) DB::table('invoices')->where('ref_number', 'Q-1')->value('created_by_id'));
    }
}
