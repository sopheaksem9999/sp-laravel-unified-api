<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * RecordService::createRecord is what POST /api/{table} calls. It has to
 * supply a primary key itself for uuid-keyed tables: the column has no
 * database default and does not auto-increment, so an insert that omits it
 * violates the not-null constraint (PostgreSQL 23502, MySQL 1364).
 */
class RecordServiceUuidPrimaryKeyTest extends TestCase
{
    use RefreshDatabase;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Set here, not in setUp(): RefreshDatabase migrates before setUp()'s
        // body runs, so this is what sp_roles.id is actually built from.
        $app['config']->set('record.id_type', 'uuid');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Testbench runs RegisterProviders (which merges config/permissions.php)
        // BEFORE getEnvironmentSetUp(), so the merged copy derived its id column
        // types while record.id_type was still the default. Re-require the file
        // now that the setting is in place — the same evaluation a real app does
        // at boot, where LoadConfiguration runs first. See
        // tests/Unit/PermissionsConfigIdTypeTest.php for the full explanation.
        $permissions = require __DIR__ . '/../../config/permissions.php';
        config()->set('permissions.tables', $permissions['tables']);

        // A table with a uuid primary key that has nothing to do with
        // permissions: the fix must hold for any such table, not just sp_roles.
        Schema::create('uuid_widgets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('int_widgets', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->timestamps();
        });

        // A natural string key, registered below WITHOUT declared columns so
        // its types come from database introspection. SQLite's PRAGMA reports
        // this as a bare 'varchar' — exactly what it reports for a uuid()
        // column — so any heuristic that reads introspected types would call
        // it a uuid and overwrite the client's key.
        Schema::create('sku_widgets', function (Blueprint $table): void {
            $table->string('sku')->primary();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        config()->set('record.tables', [
            'uuid_widgets' => new RecordTableType(
                table: 'uuid_widgets',
                hasTenantId: false,
                primaryKey: 'id',
                columns: [
                    'id' => ['type' => 'uuid', 'nullable' => false],
                    'name' => ['type' => 'string', 'nullable' => true],
                ],
            ),
            'int_widgets' => new RecordTableType(
                table: 'int_widgets',
                hasTenantId: false,
                primaryKey: 'id',
                columns: [
                    'id' => ['type' => 'bigIncrements', 'nullable' => false],
                    'name' => ['type' => 'string', 'nullable' => true],
                ],
            ),
            // No columns: on purpose. SchemaRegistryUtils falls back to
            // introspection for this one.
            'sku_widgets' => new RecordTableType(
                table: 'sku_widgets',
                hasTenantId: false,
                primaryKey: 'sku',
            ),
        ]);

        SchemaRegistryUtils::refresh();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('uuid_widgets');
        Schema::dropIfExists('int_widgets');
        Schema::dropIfExists('sku_widgets');

        parent::tearDown();
    }

    /** @test */
    public function a_role_can_be_created_without_a_client_supplied_id(): void
    {
        $result = $this->service()->createRecord('sp_roles', [
            'name' => 'Admin',
            'guard_name' => 'api',
        ], null);

        $this->assertTrue(
            Str::isUuid((string) $result['id']),
            'createRecord must generate a uuid for a uuid-keyed table, got: ' . var_export($result['id'], true)
        );
        $this->assertSame('Admin', DB::table('sp_roles')->where('id', $result['id'])->value('name'));
    }

    /** @test */
    public function any_uuid_keyed_table_gets_a_generated_key(): void
    {
        $result = $this->service()->createRecord('uuid_widgets', ['name' => 'Widget'], null);

        $this->assertTrue(Str::isUuid((string) $result['id']));
        $this->assertSame('Widget', DB::table('uuid_widgets')->where('id', $result['id'])->value('name'));
    }

    /** @test */
    public function a_client_supplied_uuid_is_preserved(): void
    {
        $uuid = (string) Str::uuid();

        $result = $this->service()->createRecord('uuid_widgets', [
            'id' => $uuid,
            'name' => 'Widget',
        ], null);

        $this->assertSame($uuid, $result['id']);
        $this->assertSame(1, DB::table('uuid_widgets')->count());
    }

    /** @test */
    public function an_integer_keyed_table_still_uses_the_auto_increment(): void
    {
        // Guard against over-generating: a uuid must never be written into an
        // auto-incrementing key, which would break the sequence on every driver
        // that actually enforces the column type.
        $result = $this->service()->createRecord('int_widgets', ['name' => 'Widget'], null);

        $this->assertSame(1, (int) $result['id']);
        $this->assertFalse(Str::isUuid((string) $result['id']));
    }

    /** @test */
    public function a_client_supplied_natural_string_key_is_never_replaced(): void
    {
        $result = $this->service()->createRecord('sku_widgets', [
            'sku' => 'WIDGET-001',
            'name' => 'Widget',
        ], null);

        // createRecord reports the key it actually inserted under 'id',
        // whatever the primaryKey is named.
        $this->assertSame('WIDGET-001', $result['id']);
        $this->assertSame('WIDGET-001', DB::table('sku_widgets')->value('sku'));
    }

    /** @test */
    public function a_natural_string_key_is_not_invented(): void
    {
        // The column is a not-null natural key with no default, so omitting it
        // has to fail loudly. The regression this guards is the opposite: an
        // introspection-driven heuristic quietly writing a uuid into 'sku',
        // producing a row whose business key is meaningless.
        try {
            $this->service()->createRecord('sku_widgets', ['name' => 'Widget'], null);
            $this->fail('createRecord should not have inserted a row without a natural key');
        } catch (QueryException) {
            // expected
        }

        $this->assertSame(0, DB::table('sku_widgets')->count());
    }

    private function service(): RecordService
    {
        return app(RecordService::class);
    }
}
