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
        Schema::create('audit_logs', function (Blueprint $blueprint): void {
            $blueprint->id();
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};