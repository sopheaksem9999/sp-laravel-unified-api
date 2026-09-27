<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * The guard is configurable through `sp-laravel-api.auth.guard`, and
 * `RecordConfigService::authGuard()` exists to read it — but the userstamp write
 * path called `auth('api')` directly. An application configuring any other guard
 * got a null user there, so `created_by_id` / `last_updated_by_id` were silently
 * left unstamped: no error, just missing audit attribution.
 *
 * @internal
 */
class ConfiguredAuthGuardUserstampsTest extends TestCase
{
    use RefreshDatabase;

    private Authenticatable $user;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // A deployment whose guard is not the package default.
        $app['config']->set('auth.guards.portal', ['driver' => 'session', 'provider' => 'users']);
        $app['config']->set('sp-laravel-api.auth.guard', 'portal');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('notes', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->unsignedBigInteger('last_updated_by_id')->nullable();
            $table->timestamps();
        });

        Config::set('record.tables', ['notes' => new RecordTableType(
            table: 'notes',
            pmsName: 'note',
            hasTenantId: false,
            columns: [
                'id' => ['type' => 'integer', 'nullable' => false],
                'title' => ['type' => 'string', 'nullable' => false],
                'created_by_id' => ['type' => 'bigInteger', 'nullable' => true],
                'last_updated_by_id' => ['type' => 'bigInteger', 'nullable' => true],
                'created_at' => ['type' => 'datetime', 'nullable' => true],
                'updated_at' => ['type' => 'datetime', 'nullable' => true],
            ],
        )]);
        SchemaRegistryUtils::refresh();

        $this->user = (new class extends Authenticatable {
            protected $table = 'users';

            public $timestamps = false;

            protected $fillable = ['id', 'name'];
        });
        $this->user->forceFill(['id' => 5, 'name' => 'Portal User'])->save();

        $this->actingAs($this->user, 'portal');
    }

    /** @test */
    public function create_stamps_the_user_from_the_configured_guard(): void
    {
        app(RecordService::class)->createRecord('notes', ['title' => 'first'], null);

        $row = DB::table('notes')->latest('id')->first();
        $this->assertSame(5, (int) $row->created_by_id);
        $this->assertSame(5, (int) $row->last_updated_by_id);
    }

    /** @test */
    public function update_stamps_the_last_writer_from_the_configured_guard(): void
    {
        DB::table('notes')->insert([
            'id' => 1, 'title' => 'x', 'created_by_id' => 9,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(RecordService::class)->updateRecord('notes', 1, ['title' => 'y'], null);

        $row = DB::table('notes')->where('id', 1)->first();
        $this->assertSame(5, (int) $row->last_updated_by_id);
        $this->assertSame(9, (int) $row->created_by_id, 'create-time stamp must not move');
    }
}
