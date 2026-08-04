<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Database\MigrationIdHelper;
use Sopheak\Core\Services\RecordConfigService;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (RecordConfigService::auditEnabled()) {
            Schema::create('sp_audit_logs', function (Blueprint $blueprint): void {
                $blueprint->id();

                if (RecordConfigService::enableTenantId()) {
                    $tenantColumn = RecordConfigService::tenantColumn();
                    // String, matching every other package migration. A string
                    // holds either an integer or a uuid tenant id, the same
                    // reasoning as the client-reference columns below.
                    $blueprint->string($tenantColumn)->nullable();
                    $blueprint->index([$tenantColumn]);
                }

                $blueprint->string('entity_name')->nullable();
                $blueprint->string('entity_type')->nullable();
                // Both reference client-owned records whose key may be a uuid
                // or an integer, so they must be strings.
                MigrationIdHelper::morph($blueprint, 'entity_id')->nullable();
                MigrationIdHelper::morph($blueprint, 'user_id')->nullable();
                $blueprint->string('event')->nullable();
                $blueprint->string('title')->nullable();
                $blueprint->string('subject')->nullable();
                $blueprint->text('recap')->nullable();
                $blueprint->json('old_data')->nullable();
                $blueprint->json('new_data')->nullable();
                $blueprint->json('metadata')->nullable();
                $blueprint->timestamps();

                $blueprint->index(['entity_type', 'entity_id']);
                $blueprint->index(['user_id']);
                $blueprint->index(['event']);
                $blueprint->index(['created_at']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (RecordConfigService::auditEnabled()) {
            Schema::dropIfExists('sp_audit_logs');
            Schema::dropIfExists('audit_logs');
        }
    }
};
