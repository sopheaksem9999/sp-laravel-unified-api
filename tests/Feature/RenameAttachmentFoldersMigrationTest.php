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
 * condition that compared a connection NAME against a driver name. It never
 * matched here (this suite's connection is named "testing" while the driver is
 * sqlite), which is why the rename ran and the suite passed. Removing it makes
 * the intent match the behaviour; these tests pin that behaviour down so the
 * condition cannot come back in either form.
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
