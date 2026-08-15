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
        ]);

        SchemaRegistryUtils::refresh();
        QueryBuilderFiltersUtils::clearColumnCache();
    }

    /** @test */
    public function view_own_scopes_on_created_by_id_when_present(): void
    {
        $user = new GenericUser(['id' => 42]);
        Auth::setUser($user);
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
        $user = new GenericUser(['id' => 42]);
        Auth::setUser($user);
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
        $user = new GenericUser(['id' => 42]);
        Auth::setUser($user);
        Gate::define('viewOwn:note', fn($u): bool => (int) $u->id === 42);

        $builder = DB::table('notes');
        $request = Request::create('/api/notes', 'GET');

        QueryBuilderFiltersUtils::apply($builder, $request, 'notes', 'id');

        $sql = $builder->toSql();
        $this->assertStringNotContainsString('created_by', $sql);
        $this->assertEmpty($builder->getBindings());
    }
}
