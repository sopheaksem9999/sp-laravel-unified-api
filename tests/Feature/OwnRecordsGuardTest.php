<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Jobs\ProcessBulkOperationJob;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\OwnRecordsScope;
use Sopheak\Core\Utilities\QueryBuilderFiltersUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Throwable;

/**
 * Permission checks read the user from `sp-laravel-api.auth.guard`; viewOwn
 * read the default guard. When the two differ — a route without auth
 * middleware, or the queued bulk job, which restores the user on the
 * configured guard only — a user passed the permission check but was not
 * restricted to their own rows.
 *
 * @internal
 */
class OwnRecordsGuardTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = 42;

    private const OTHER = 99;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        });
        DB::table('users')->insert([['id' => self::OWNER, 'name' => 'owner'], ['id' => self::OTHER, 'name' => 'other']]);

        Schema::create('widgets', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->unsignedBigInteger('created_by_id')->nullable();
            $t->timestamps();
        });
        DB::table('widgets')->insert([
            ['id' => 1, 'name' => 'MINE', 'created_by_id' => self::OWNER],
            ['id' => 2, 'name' => 'THEIRS', 'created_by_id' => self::OTHER],
        ]);

        Config::set('auth.defaults.guard', 'web');
        Config::set('auth.guards.web', ['driver' => 'session', 'provider' => 'users']);
        Config::set('record.tables', [
            'widgets' => new RecordTableType(
                table: 'widgets',
                pmsName: 'widget',
                columns: [
                    'id' => ['type' => 'integer', 'nullable' => false],
                    'name' => ['type' => 'string', 'nullable' => false],
                    'created_by_id' => ['type' => 'bigInteger', 'nullable' => true],
                    'created_at' => ['type' => 'datetime', 'nullable' => true],
                    'updated_at' => ['type' => 'datetime', 'nullable' => true],
                ],
            ),
        ]);
        SchemaRegistryUtils::refresh();
        QueryBuilderFiltersUtils::clearColumnCache();

        // Everyone may do everything; only OWNER is restricted to own rows.
        Gate::before(fn($user, string $ability): bool => !str_starts_with($ability, 'viewOwn:') || self::OWNER === (int) $user->id);
    }

    private function user(int $id): GenericUser
    {
        return new GenericUser(['id' => $id, 'name' => 'user ' . $id]);
    }

    /** @return list<string> */
    private function listedNames(): array
    {
        return collect(RecordService::executeGetByFilter('widgets')['data'])->pluck('name')->sort()->values()->all();
    }

    public function test_a_user_on_the_configured_guard_only_is_restricted(): void
    {
        auth('api')->setUser($this->user(self::OWNER));

        $this->assertFalse(auth('web')->check(), 'precondition: the default guard has no user');
        $this->assertSame('created_by_id', OwnRecordsScope::ownerColumn('widgets'));
        $this->assertSame(['MINE'], $this->listedNames());
    }

    public function test_the_configured_guard_user_is_the_one_restricted_when_the_guards_disagree(): void
    {
        auth('api')->setUser($this->user(self::OWNER));
        auth('web')->setUser($this->user(self::OTHER));

        $this->assertSame(['MINE'], $this->listedNames());
    }

    public function test_a_user_on_the_default_guard_only_stays_restricted(): void
    {
        auth('web')->setUser($this->user(self::OWNER));

        $this->assertSame('created_by_id', OwnRecordsScope::ownerColumn('widgets'));
    }

    public function test_an_undefined_configured_guard_falls_back_to_the_default_guard(): void
    {
        Config::set('sp-laravel-api.auth.guard', 'no-such-guard');
        auth('web')->setUser($this->user(self::OWNER));

        $this->assertSame('created_by_id', OwnRecordsScope::ownerColumn('widgets'));
    }

    public function test_the_async_bulk_job_restricts_the_restored_user_to_their_own_rows(): void
    {
        $job = new ProcessBulkOperationJob(
            'update',
            'widgets',
            [['id' => 2, 'name' => 'HACKED']],
            null,
            ['guard' => 'api', 'headers' => [], 'server' => [], 'user_id' => self::OWNER],
        );

        try {
            app()->call($job->handle(...));
        } catch (Throwable) {
            // Refused: the row is outside the restored user's own records.
        }

        $this->assertSame('THEIRS', DB::table('widgets')->where('id', 2)->value('name'));
    }
}
