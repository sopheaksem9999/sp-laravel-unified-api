<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Rename the table if the old one exists and the new one doesn't
        if (Schema::hasTable('audit_logs') && !Schema::hasTable('sp_audit_logs')) {
            Schema::rename('audit_logs', 'sp_audit_logs');
        }

        // 2. Add the new traceability columns
        if (Schema::hasTable('sp_audit_logs')) {
            Schema::table('sp_audit_logs', function (Blueprint $table) {
                if (!Schema::hasColumn('sp_audit_logs', 'ip_address')) {
                    $table->string('ip_address')->nullable()->after('metadata');
                }
                if (!Schema::hasColumn('sp_audit_logs', 'user_agent')) {
                    $table->string('user_agent')->nullable()->after('ip_address');
                }
                if (!Schema::hasColumn('sp_audit_logs', 'request_id')) {
                    $table->string('request_id')->nullable()->after('user_agent');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 1. Drop the traceability columns
        if (Schema::hasTable('sp_audit_logs')) {
            Schema::table('sp_audit_logs', function (Blueprint $table) {
                if (Schema::hasColumn('sp_audit_logs', 'ip_address')) {
                    $table->dropColumn('ip_address');
                }
                if (Schema::hasColumn('sp_audit_logs', 'user_agent')) {
                    $table->dropColumn('user_agent');
                }
                if (Schema::hasColumn('sp_audit_logs', 'request_id')) {
                    $table->dropColumn('request_id');
                }
            });
        }

        // 2. Rename the table back to the old name
        if (Schema::hasTable('sp_audit_logs') && !Schema::hasTable('audit_logs')) {
            Schema::rename('sp_audit_logs', 'audit_logs');
        }
    }
};
