<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Database\MigrationIdHelper;
use Sopheak\Core\Tests\TestCase;

class MigrationIdHelperTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function primary_creates_an_autoincrement_integer_by_default(): void
    {
        $this->app['config']->set('record.id_type', 'integer');

        Schema::create('helper_probe', function (Blueprint $table): void {
            MigrationIdHelper::primary($table);
        });

        $this->assertColumnType('helper_probe', 'id', 'integer');

        // NOTE: DB::table(...)->insert([]) is a no-op in Laravel — Builder::insert()
        // short-circuits and returns true without executing any SQL when given an
        // empty array. insertGetId([]) has no such shortcut and compiles to
        // "insert into ... default values", which genuinely inserts a row.
        DB::table('helper_probe')->insertGetId([]);

        $this->assertSame(1, (int) DB::table('helper_probe')->value('id'));
    }

    /** @test */
    public function primary_creates_a_uuid_key_when_configured(): void
    {
        $this->app['config']->set('record.id_type', 'uuid');

        Schema::create('helper_probe', function (Blueprint $table): void {
            MigrationIdHelper::primary($table);
        });

        $this->assertColumnType('helper_probe', 'id', 'varchar');

        $uuid = '3f2504e0-4f89-11d3-9a0c-0305e82c3301';
        DB::table('helper_probe')->insert(['id' => $uuid]);

        $this->assertSame($uuid, DB::table('helper_probe')->value('id'));
    }

    /** @test */
    public function foreign_accepts_an_integer_by_default(): void
    {
        $this->app['config']->set('record.id_type', 'integer');

        Schema::create('helper_probe', function (Blueprint $table): void {
            MigrationIdHelper::foreign($table, 'role_id')->index();
        });

        $this->assertColumnType('helper_probe', 'role_id', 'integer');

        DB::table('helper_probe')->insert(['role_id' => 42]);

        $this->assertSame(42, (int) DB::table('helper_probe')->value('role_id'));
    }

    /** @test */
    public function foreign_accepts_a_uuid_when_configured(): void
    {
        $this->app['config']->set('record.id_type', 'uuid');

        Schema::create('helper_probe', function (Blueprint $table): void {
            MigrationIdHelper::foreign($table, 'role_id')->index();
        });

        // Column-type assertion, not just round-trip: on SQLite an
        // unsignedBigInteger column happily stores a uuid string verbatim
        // (type affinity is advisory), so an insert-based assertion alone
        // cannot tell a correct uuid column from a mistakenly integer one.
        $this->assertColumnType('helper_probe', 'role_id', 'varchar');

        $uuid = '3f2504e0-4f89-11d3-9a0c-0305e82c3301';
        DB::table('helper_probe')->insert(['role_id' => $uuid]);

        $this->assertSame($uuid, DB::table('helper_probe')->value('role_id'));
    }

    /** @test */
    public function morph_holds_both_key_shapes_regardless_of_setting(): void
    {
        foreach (['integer', 'uuid'] as $idType) {
            $this->app['config']->set('record.id_type', $idType);

            Schema::dropIfExists('helper_probe');
            Schema::create('helper_probe', function (Blueprint $table): void {
                MigrationIdHelper::morph($table, 'model_id')->index();
            });

            // The column-type assertion is the actual regression guard here.
            // SQLite type affinity is advisory, so a varchar column and an
            // integer column both happily store either key shape verbatim —
            // the insert round-trip below cannot distinguish a correct
            // unconditional morph() from one that was mistakenly wired to
            // id_type. Asserting the reported column type is 'varchar'
            // under BOTH settings is what actually catches that bug.
            $this->assertColumnType(
                'helper_probe',
                'model_id',
                'varchar',
                'morph() must always render a varchar column, even under id_type=' . $idType
            );

            DB::table('helper_probe')->insert(['model_id' => '42']);
            DB::table('helper_probe')->insert([
                'model_id' => '3f2504e0-4f89-11d3-9a0c-0305e82c3301',
            ]);

            $this->assertSame(
                2,
                DB::table('helper_probe')->count(),
                'morph() should accept both key shapes under id_type=' . $idType
            );
        }
    }

    /** @test */
    public function morph_is_bounded_to_an_index_safe_length(): void
    {
        $definition = null;

        Schema::create('helper_probe', function (Blueprint $table) use (&$definition): void {
            $definition = MigrationIdHelper::morph($table, 'model_id');
        });

        // The length cannot be asserted from the schema here: SQLite's grammar
        // renders every string column as a bare 'varchar' with no length, so
        // Schema::getColumns() reports the same thing for 191 and for 255. The
        // column definition is what the MySQL and PostgreSQL grammars turn into
        // varchar(N), so that is what this asserts.
        //
        // It matters because these columns sit in composite unique indexes.
        // Under utf8mb4 an unbounded varchar(255) costs 1020 bytes, and
        // sp_model_has_roles_unique holds four columns: at 255 with a uuid
        // role_id and a tenant column that is 3204 bytes against MySQL's
        // 3072-byte InnoDB limit, and the table cannot be created at all.
        $this->assertSame(191, MigrationIdHelper::INDEX_SAFE_LENGTH);
        $this->assertSame(191, $definition->get('length'));
    }

    /** @test */
    public function morph_length_can_be_overridden_for_an_unindexed_column(): void
    {
        $definition = null;

        Schema::create('helper_probe', function (Blueprint $table) use (&$definition): void {
            $definition = MigrationIdHelper::morph($table, 'model_id', null);
        });

        // null means "no explicit bound", which Blueprint::string() resolves to
        // Laravel's default string length.
        $this->assertSame(255, $definition->get('length'));
    }

    private function assertColumnType(string $table, string $column, string $expected, string $message = ''): void
    {
        $columns = Schema::getColumns($table);

        $match = null;
        foreach ($columns as $candidate) {
            if ($candidate['name'] === $column) {
                $match = $candidate;
                break;
            }
        }

        $this->assertNotNull($match, sprintf("Column '%s' not found on table '%s'.", $column, $table));
        $this->assertSame($expected, $match['type'], $message !== '' ? $message : sprintf("Column '%s' on '%s' should report type '%s'.", $column, $table, $expected));
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('helper_probe');

        parent::tearDown();
    }
}
