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

            DB::table('helper_probe')->insert(['model_id' => '42']);
            DB::table('helper_probe')->insert([
                'model_id' => '3f2504e0-4f89-11d3-9a0c-0305e82c3301',
            ]);

            $this->assertSame(
                2,
                DB::table('helper_probe')->count(),
                "morph() should accept both key shapes under id_type={$idType}"
            );
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('helper_probe');

        parent::tearDown();
    }
}
