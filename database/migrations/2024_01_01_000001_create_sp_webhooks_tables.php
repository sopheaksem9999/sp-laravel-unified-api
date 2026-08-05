<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Database\MigrationIdHelper;
use Sopheak\Core\Services\RecordConfigService;

return new class extends Migration {
    public function up(): void
    {
        $tenantColumn = RecordConfigService::tenantColumn();
        $enableTenantId = RecordConfigService::enableTenantId();

        Schema::create('sp_webhook_endpoints', function (Blueprint $table) use ($tenantColumn, $enableTenantId) {
            MigrationIdHelper::primary($table);
            if ($enableTenantId) {
                $table->string($tenantColumn)->nullable()->index();
            }
            $table->string('name');
            $table->string('url');
            $table->string('secret');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('sp_webhook_subscriptions', function (Blueprint $table) use ($tenantColumn, $enableTenantId) {
            MigrationIdHelper::primary($table);
            if ($enableTenantId) {
                $table->string($tenantColumn)->nullable()->index();
            }
            MigrationIdHelper::foreign($table, 'endpoint_id')->index();
            $table->string('table_name')->index();
            $table->string('event')->index();
            $table->timestamps();
        });

        Schema::create('sp_webhook_deliveries', function (Blueprint $table) use ($tenantColumn, $enableTenantId) {
            MigrationIdHelper::primary($table);
            if ($enableTenantId) {
                $table->string($tenantColumn)->nullable()->index();
            }
            MigrationIdHelper::foreign($table, 'endpoint_id')->index();
            $table->string('event')->index();
            $table->json('payload');
            $table->integer('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->string('status')->default('pending')->index(); // pending, success, failed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sp_webhook_deliveries');
        Schema::dropIfExists('sp_webhook_subscriptions');
        Schema::dropIfExists('sp_webhook_endpoints');
    }
};
