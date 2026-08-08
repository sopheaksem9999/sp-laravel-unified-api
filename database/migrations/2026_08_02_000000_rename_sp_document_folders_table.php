<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Driver-agnostic on purpose. Schema::rename works on every driver this
        // package supports, SQLite included, and skipping it anywhere would
        // leave the physical table named sp_document_folders while
        // config/attachments.php points at sp_attachment_folders.
        //
        // A previous revision carried an extra `config('database.default') !==
        // 'sqlite'` condition, which compared a connection NAME against a
        // driver name. It fired for any install whose default connection was
        // NAMED 'sqlite' -- including stock Laravel 11/12, which ships
        // `'default' => env('DB_CONNECTION', 'sqlite')` against a connection
        // key of that name. There the rename was skipped while
        // config/attachments.php already pointed at sp_attachment_folders, so
        // every folder query failed with a missing-table error.
        //
        // It is removed rather than corrected to DB::getDriverName(): that
        // would skip SQLite for real and strand the table on every SQLite
        // install, this test suite included.
        if (Schema::hasTable('sp_document_folders') && !Schema::hasTable('sp_attachment_folders')) {
            Schema::rename('sp_document_folders', 'sp_attachment_folders');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Mirror of up(): the same two hasTable guards and no driver condition.
        if (Schema::hasTable('sp_attachment_folders') && !Schema::hasTable('sp_document_folders')) {
            Schema::rename('sp_attachment_folders', 'sp_document_folders');
        }
    }
};
