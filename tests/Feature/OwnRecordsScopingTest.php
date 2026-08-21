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
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\QueryBuilderFiltersUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class OwnRecordsScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('purchases', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->uuid('created_by_id')->nullable();
            $table->timestamps();
        });

        Schema::create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('notes', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        // Domain table where the owner (subject) and the audit author differ:
        // an admin may create/approve the row on behalf of a customer.
        Schema::create('video_purchases', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->uuid('user_id')->nullable();
            $table->uuid('created_by_id')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->uuid('user_id')->nullable();
            $table->uuid('created_by_id')->nullable();
            $table->timestamps();
        });

        Schema::create('tickets', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->uuid('created_by_id')->nullable();
            $table->timestamps();
        });

        Config::set('record.tables', [
            'purchases' => new RecordTableType(
                table: 'purchases',
                pmsName: 'purchase',
                hasTenantId: false,
                columns: [
                    'id' => ['type' => 'integer', 'nullable' => false],
                    'name' => ['type' => 'string', 'nullable' => false],
                    'created_by_id' => ['type' => 'uuid', 'nullable' => true],
                    'created_at' => ['type' => 'datetime', 'nullable' => true],
                    'updated_at' => ['type' => 'datetime', 'nullable' => true],
                ],
            ),
            'tasks' => new RecordTableType(
                table: 'tasks',
                pmsName: 'task',
                hasTenantId: false,
                columns: [
                    'id' => ['type' => 'integer', 'nullable' => false],
                    'name' => ['type' => 'string', 'nullable' => false],
                    'created_by' => ['type' => 'integer', 'nullable' => true],
                    'created_at' => ['type' => 'datetime', 'nullable' => true],
                    'updated_at' => ['type' => 'datetime', 'nullable' => true],
                ],
            ),
            'notes' => new RecordTableType(
                table: 'notes',
                pmsName: 'note',
                hasTenantId: false,
                columns: [
                    'id' => ['type' => 'integer', 'nullable' => false],
                    'name' => ['type' => 'string', 'nullable' => false],
                    'created_at' => ['type' => 'datetime', 'nullable' => true],
                    'updated_at' => ['type' => 'datetime', 'nullable' => true],
                ],
            ),
            'video_purchases' => new RecordTableType(
                table: 'video_purchases',
                pmsName: 'video_purchase',
                hasTenantId: false,
                ownerColumn: 'user_id',
                columns: [
                    'id' => ['type' => 'integer', 'nullable' => false],
                    'name' => ['type' => 'string', 'nullable' => false],
                    'user_id' => ['type' => 'uuid', 'nullable' => true],
                    'created_by_id' => ['type' => 'uuid', 'nullable' => true],
                    'created_at' => ['type' => 'datetime', 'nullable' => true],
                    'updated_at' => ['type' => 'datetime', 'nullable' => true],
                ],
            ),
            // No `ownerColumn` declared, and both candidates exist — guards the
            // backward-compatible default (audit column keeps winning).
            'orders' => new RecordTableType(
                table: 'orders',
                pmsName: 'order',
                hasTenantId: false,
                columns: [
                    'id' => ['type' => 'integer', 'nullable' => false],
                    'name' => ['type' => 'string', 'nullable' => false],
                    'user_id' => ['type' => 'uuid', 'nullable' => true],
                    'created_by_id' => ['type' => 'uuid', 'nullable' => true],
                    'created_at' => ['type' => 'datetime', 'nullable' => true],
                    'updated_at' => ['type' => 'datetime', 'nullable' => true],
                ],
            ),
            // `ownerColumn` points at a column the table does not declare.
            'tickets' => new RecordTableType(
                table: 'tickets',
                pmsName: 'ticket',
                hasTenantId: false,
                ownerColumn: 'owner_uuid',
                columns: [
                    'id' => ['type' => 'integer', 'nullable' => false],
                    'name' => ['type' => 'string', 'nullable' => false],
                    'created_by_id' => ['type' => 'uuid', 'nullable' => true],
                    'created_at' => ['type' => 'datetime', 'nullable' => true],
                    'updated_at' => ['type' => 'datetime', 'nullable' => true],
                ],
            ),
        ]);

        SchemaRegistryUtils::refresh();
        QueryBuilderFiltersUtils::clearColumnCache();
    }

    private function actingAsOwner(int $id = 42): void
    {
        Auth::setUser(new GenericUser(['id' => $id]));
    }

    /** @test */
    public function view_own_scopes_on_created_by_id_when_present(): void
    {
        $this->actingAsOwner();
        Gate::define('viewOwn:purchase', fn($u): bool => (int) $u->id === 42);

        $builder = DB::table('purchases');
        $request = Request::create('/api/purchases', 'GET');

        QueryBuilderFiltersUtils::apply($builder, $request, 'purchases', 'id');

        $sql = $builder->toSql();
        $this->assertStringContainsString('created_by_id', $sql);
        $this->assertStringNotContainsString('"created_by"', $sql);

        $bindings = $builder->getBindings();
        $this->assertContains(42, $bindings);
    }

    /** @test */
    public function view_own_scopes_on_created_by_as_legacy_fallback(): void
    {
        $this->actingAsOwner();
        Gate::define('viewOwn:task', fn($u): bool => (int) $u->id === 42);

        $builder = DB::table('tasks');
        $request = Request::create('/api/tasks', 'GET');

        QueryBuilderFiltersUtils::apply($builder, $request, 'tasks', 'id');

        $sql = $builder->toSql();
        $this->assertStringContainsString('"created_by"', $sql);
        $this->assertStringNotContainsString('created_by_id', $sql);
    }

    /** @test */
    public function view_own_skips_scoping_when_neither_owner_column_exists(): void
    {
        $this->actingAsOwner();
        Gate::define('viewOwn:note', fn($u): bool => (int) $u->id === 42);

        $builder = DB::table('notes');
        $request = Request::create('/api/notes', 'GET');

        QueryBuilderFiltersUtils::apply($builder, $request, 'notes', 'id');

        $sql = $builder->toSql();
        $this->assertStringNotContainsString('created_by', $sql);
        $this->assertEmpty($builder->getBindings());
    }

    /** @test */
    public function view_own_prefers_explicit_owner_column_over_audit_columns(): void
    {
        $this->actingAsOwner();
        Gate::define('viewOwn:video_purchase', fn($u): bool => (int) $u->id === 42);

        $builder = DB::table('video_purchases');
        $request = Request::create('/api/video_purchases', 'GET');

        QueryBuilderFiltersUtils::apply($builder, $request, 'video_purchases', 'id');

        $sql = $builder->toSql();
        $this->assertStringContainsString('"user_id"', $sql);
        $this->assertStringNotContainsString('created_by_id', $sql);
        $this->assertContains(42, $builder->getBindings());
    }

    /** @test */
    public function view_own_default_order_keeps_audit_column_when_owner_column_is_not_declared(): void
    {
        $this->actingAsOwner();
        Gate::define('viewOwn:order', fn($u): bool => (int) $u->id === 42);

        $builder = DB::table('orders');
        $request = Request::create('/api/orders', 'GET');

        QueryBuilderFiltersUtils::apply($builder, $request, 'orders', 'id');

        $sql = $builder->toSql();
        $this->assertStringContainsString('created_by_id', $sql);
        $this->assertStringNotContainsString('"user_id"', $sql);
    }

    /** @test */
    public function view_own_honours_configured_owner_column_order(): void
    {
        Config::set('record.own_records_owner_columns', ['user_id', 'created_by_id', 'created_by']);

        $this->actingAsOwner();
        Gate::define('viewOwn:order', fn($u): bool => (int) $u->id === 42);

        $builder = DB::table('orders');
        $request = Request::create('/api/orders', 'GET');

        QueryBuilderFiltersUtils::apply($builder, $request, 'orders', 'id');

        $sql = $builder->toSql();
        $this->assertStringContainsString('"user_id"', $sql);
        $this->assertStringNotContainsString('created_by_id', $sql);
    }

    /** @test */
    public function explicit_owner_column_still_beats_configured_order(): void
    {
        Config::set('record.own_records_owner_columns', ['created_by_id', 'created_by']);

        $this->actingAsOwner();
        Gate::define('viewOwn:video_purchase', fn($u): bool => (int) $u->id === 42);

        $builder = DB::table('video_purchases');
        $request = Request::create('/api/video_purchases', 'GET');

        QueryBuilderFiltersUtils::apply($builder, $request, 'video_purchases', 'id');

        $this->assertStringContainsString('"user_id"', $builder->toSql());
    }

    /** @test */
    public function undeclared_owner_column_falls_back_to_audit_column(): void
    {
        $this->actingAsOwner();
        Gate::define('viewOwn:ticket', fn($u): bool => (int) $u->id === 42);

        $builder = DB::table('tickets');
        $request = Request::create('/api/tickets', 'GET');

        QueryBuilderFiltersUtils::apply($builder, $request, 'tickets', 'id');

        $sql = $builder->toSql();
        $this->assertStringNotContainsString('owner_uuid', $sql);
        $this->assertStringContainsString('created_by_id', $sql);
    }

    /** @test */
    public function owner_column_survives_config_cache_round_trip(): void
    {
        $hydrated = RecordTableType::__set_state([
            'table' => 'video_purchases',
            'pmsName' => 'video_purchase',
            'ownerColumn' => 'user_id',
        ]);

        $this->assertSame('user_id', $hydrated->ownerColumn);
    }
}
