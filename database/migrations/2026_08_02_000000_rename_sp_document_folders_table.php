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
        // 'sqlite'` condition. That compared a connection NAME against a driver
        // name, so it never matched (this suite's connection is named
        // "testing" while the driver is sqlite) and the rename ran anyway --
        // which is the correct behaviour, so the condition is gone rather than
        // corrected.
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
