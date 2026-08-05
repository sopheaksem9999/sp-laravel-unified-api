<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Database\MigrationIdHelper;
use Sopheak\Core\Services\RecordConfigService;

return new class extends Migration {
    public function up(): void
    {
        if (!(bool) config('attachments.enabled', true)) {
            return;
        }

        $tenantColumn = RecordConfigService::tenantColumn();
        $enableTenantId = RecordConfigService::enableTenantId();

        Schema::create('sp_document_folders', function (Blueprint $table) use ($tenantColumn, $enableTenantId): void {
            MigrationIdHelper::primary($table);
            if ($enableTenantId) {
                $table->string($tenantColumn)->nullable()->index();
            }

            $table->string('name');
            MigrationIdHelper::foreign($table, 'parent_id')->nullable()->index();
            $table->string('scope')->default('internal')->index();
            $table->string('visibility')->default('private')->index();
            $table->string('owner_type')->nullable()->index();
            $table->string('owner_id')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('sp_attachments', function (Blueprint $table) use ($tenantColumn, $enableTenantId): void {
            MigrationIdHelper::primary($table);
            if ($enableTenantId) {
                $table->string($tenantColumn)->nullable()->index();
            }

            MigrationIdHelper::foreign($table, 'folder_id')->nullable()->index();
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

        Schema::create('sp_attachment_links', function (Blueprint $table) use ($tenantColumn, $enableTenantId): void {
            $table->id();
            if ($enableTenantId) {
                $table->string($tenantColumn)->nullable()->index();
            }

            MigrationIdHelper::foreign($table, 'attachment_id')->index();
            $table->string('record_id')->index();
            $table->string('record_type')->index();
            $table->string('collection_name')->nullable()->index();
            $table->timestamps();

            $table->index(['record_type', 'record_id', 'collection_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sp_attachment_links');
        Schema::dropIfExists('sp_attachments');
        Schema::dropIfExists('sp_document_folders');
    }
};
