<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (config('audit.enabled', false)) {
            Schema::create('audit_logs', function (Blueprint $blueprint): void {
                $blueprint->id();

                if (config('record.enable_tenant_id', false)) {
                    $tenantColumn = config('record.tenant_column', 'tenant_id');
                    $blueprint->unsignedBigInteger($tenantColumn)->nullable();
                    $blueprint->index([$tenantColumn]);
                }

                $blueprint->string('entity_name')->nullable();
                $blueprint->string('entity_type')->nullable();
                $blueprint->unsignedBigInteger('entity_id')->nullable();
                $blueprint->unsignedBigInteger('user_id')->nullable();
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
        if (config('audit.enabled', false)) {
            Schema::dropIfExists('audit_logs');
        }
    }
};
