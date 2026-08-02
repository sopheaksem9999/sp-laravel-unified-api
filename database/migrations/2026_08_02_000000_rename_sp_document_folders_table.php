<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('sp_document_folders') && !Schema::hasTable('sp_attachment_folders') && config('database.default') !== 'sqlite') {
            Schema::rename('sp_document_folders', 'sp_attachment_folders');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('sp_attachment_folders') && !Schema::hasTable('sp_document_folders')) {
            Schema::rename('sp_attachment_folders', 'sp_document_folders');
        }
    }
};
