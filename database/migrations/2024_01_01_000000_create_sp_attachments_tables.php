<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Services\RecordConfigService;

return new class extends Migration
{
    public function up(): void
    {
        $tenantColumn = RecordConfigService::tenantColumn();
        $enableTenantId = RecordConfigService::enableTenantId();

        Schema::create('sp_document_folders', function (Blueprint $table) use ($tenantColumn, $enableTenantId) {
            $table->uuid('id')->primary();
            if ($enableTenantId) {
                $table->string($tenantColumn)->nullable()->index();
            }
            $table->string('name');
            $table->uuid('parent_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('sp_attachments', function (Blueprint $table) use ($tenantColumn, $enableTenantId) {
            $table->uuid('id')->primary();
            if ($enableTenantId) {
                $table->string($tenantColumn)->nullable()->index();
            }
            $table->uuid('folder_id')->nullable()->index();
            $table->string('title')->nullable();
            $table->text('caption')->nullable();
            $table->string('disk');
            $table->string('path');
            $table->string('filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->enum('visibility', ['private', 'public', 'temp_private', 'temp_public'])->default('private');
            $table->timestamp('temp_timeout')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('sp_attachment_links', function (Blueprint $table) use ($tenantColumn, $enableTenantId) {
            $table->id();
            if ($enableTenantId) {
                $table->string($tenantColumn)->nullable()->index();
            }
            $table->uuid('attachment_id')->index();
            $table->uuid('record_id')->index();
            $table->string('record_type')->index(); 
            $table->string('collection_name')->nullable()->index(); 
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sp_attachment_links');
        Schema::dropIfExists('sp_attachments');
        Schema::dropIfExists('sp_document_folders');
    }
};
