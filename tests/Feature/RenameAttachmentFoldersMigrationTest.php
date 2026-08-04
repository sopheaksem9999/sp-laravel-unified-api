<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Tests\TestCase;

/**
 * The sp_document_folders -> sp_attachment_folders rename.
 *
 * The migration used to carry a `config('database.default') !== 'sqlite'`
 * condition that compared a connection NAME against a driver name. Two
 * different mistakes hide inside that one line, and a test has to provoke each
 * separately:
 *
 * - as written, it fired whenever the DEFAULT CONNECTION was NAMED 'sqlite'.
 *   Stock Laravel 11/12 config/database.php ships
 *   `'default' => env('DB_CONNECTION', 'sqlite')` against a connection key
 *   named `sqlite`, so that is the common local setup, and there the rename
 *   was skipped while config/attachments.php pointed at the new name — every
 *   folder query 500s. This suite's connection is named "testing", so the
 *   default-path tests below CANNOT see that; renameIsNotConditionalOnThe
 *   ConnectionName covers it by switching the default to a connection named
 *   'sqlite'.
 * - rewritten to DB::getDriverName(), it would skip SQLite for real, which the
 *   remaining tests catch because this suite runs on SQLite.
 *
 * Together the two shapes are pinned. Neither alone is sufficient.
 */
class RenameAttachmentFoldersMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function the_rename_has_already_run_on_this_driver(): void
    {
        // Whatever the driver, the migrated schema exposes the canonical name.
        $this->assertTrue(Schema::hasTable('sp_attachment_folders'));
        $this->assertFalse(Schema::hasTable('sp_document_folders'));
    }

    /** @test */
    public function it_renames_the_legacy_table_regardless_of_driver(): void
    {
        $this->rebuildLegacySchema();

        $this->migration()->up();

        $this->assertTrue(Schema::hasTable('sp_attachment_folders'));
        $this->assertFalse(Schema::hasTable('sp_document_folders'));
    }

    /** @test */
    public function it_carries_the_rows_across(): void
    {
        $this->rebuildLegacySchema();

        DB::table('sp_document_folders')->insert([
            'id' => 'folder-1',
            'name' => 'Invoices',
        ]);

        $this->migration()->up();

        $this->assertSame('Invoices', DB::table('sp_attachment_folders')->where('id', 'folder-1')->value('name'));
    }

    /** @test */
    public function it_is_a_no_op_when_the_rename_already_happened(): void
    {
        // The canonical table exists and the legacy one does not: the guards
        // must leave the migrated schema untouched.
        $before = collect(Schema::getColumns('sp_attachment_folders'))->keyBy('name')->toArray();

        $this->migration()->up();

        $this->assertTrue(Schema::hasTable('sp_attachment_folders'));
        $this->assertEquals($before, collect(Schema::getColumns('sp_attachment_folders'))->keyBy('name')->toArray());
    }

    /** @test */
    public function it_leaves_both_tables_alone_when_both_exist(): void
    {
        // A hand-renamed install can end up with both. Clobbering the canonical
        // table with the stale one would lose data, so neither is touched.
        Schema::create('sp_document_folders', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
        });

        $this->migration()->up();

        $this->assertTrue(Schema::hasTable('sp_document_folders'));
        $this->assertTrue(Schema::hasTable('sp_attachment_folders'));
    }

    /** @test */
    public function down_reverses_the_rename(): void
    {
        $this->migration()->down();

        $this->assertTrue(Schema::hasTable('sp_document_folders'));
        $this->assertFalse(Schema::hasTable('sp_attachment_folders'));

        // And back again, so the pair really is symmetric.
        $this->migration()->up();

        $this->assertTrue(Schema::hasTable('sp_attachment_folders'));
        $this->assertFalse(Schema::hasTable('sp_document_folders'));
    }

    /** @test */
    public function the_rename_is_not_conditional_on_the_connection_name(): void
    {
        // The regression the removed line actually caused. Stock Laravel 11/12
        // names its default connection 'sqlite', and the old guard read
        // config('database.default') -- a connection NAME -- so it fired there
        // and left the table behind. This suite's connection is named
        // "testing", which is exactly why every other test here stayed green
        // with the broken line in place.
        $this->rebuildLegacySchema();
        $this->useConnectionNamedSqlite();

        $this->assertSame('sqlite', config('database.default'));

        $this->migration()->up();

        $this->assertTrue(
            Schema::hasTable('sp_attachment_folders'),
            'the rename must run even when the default connection is NAMED sqlite'
        );
        $this->assertFalse(Schema::hasTable('sp_document_folders'));
    }

    /**
     * Point the default connection at a key literally named 'sqlite', backed by
     * the same in-memory database so the schema built above is still visible.
     */
    private function useConnectionNamedSqlite(): void
    {
        $pdo = DB::connection()->getPdo();

        config()->set('database.connections.sqlite', config('database.connections.' . config('database.default')));
        config()->set('database.default', 'sqlite');

        DB::purge('sqlite');
        // Without this the new connection would open its own, empty :memory:
        // database. Sharing the PDO changes only the connection's NAME, which
        // is the single variable under test.
        DB::connection('sqlite')->setPdo($pdo);
    }

    private function migration(): Migration
    {
        return require __DIR__ . '/../../database/migrations/2026_08_02_000000_rename_sp_document_folders_table.php';
    }

    /**
     * Put the schema back the way a pre-rename install looks.
     */
    private function rebuildLegacySchema(): void
    {
        Schema::dropIfExists('sp_attachment_folders');

        Schema::create('sp_document_folders', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
        });
    }
}
