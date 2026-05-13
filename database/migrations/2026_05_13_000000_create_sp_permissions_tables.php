<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Services\RecordConfigService;

return new class extends Migration {
    public function up(): void
    {
        $tenantColumn = RecordConfigService::tenantColumn();
        $enableTenantId = RecordConfigService::enableTenantId();

        Schema::create('sp_permissions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->unique();
            $table->string('group')->nullable()->index();
            $table->string('guard_name')->index();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('sp_roles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->unique();
            $table->string('guard_name')->index();
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('sp_role_permissions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->timestamps();

            $table->unique(['role_id', 'permission_id']);
            $table->foreign('role_id')->references('id')->on('sp_roles')->onDelete('cascade');
            $table->foreign('permission_id')->references('id')->on('sp_permissions')->onDelete('cascade');
        });

        Schema::create('sp_model_roles', function (Blueprint $table) use ($tenantColumn, $enableTenantId) {
            $table->bigIncrements('id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->unsignedBigInteger('role_id');
            if ($enableTenantId) {
                $table->string($tenantColumn)->nullable()->index();
            }
            $table->timestamps();

            $table->index(['model_type', 'model_id']);
            $cols = array_filter(['model_type', 'model_id', 'role_id', $enableTenantId ? $tenantColumn : null]);
            $table->unique($cols, 'sp_model_roles_unique');
            $table->foreign('role_id')->references('id')->on('sp_roles')->onDelete('cascade');
        });

        Schema::create('sp_model_permissions', function (Blueprint $table) use ($tenantColumn, $enableTenantId) {
            $table->bigIncrements('id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->unsignedBigInteger('permission_id');
            if ($enableTenantId) {
                $table->string($tenantColumn)->nullable()->index();
            }
            $table->timestamps();

            $table->index(['model_type', 'model_id']);
            $cols = array_filter(['model_type', 'model_id', 'permission_id', $enableTenantId ? $tenantColumn : null]);
            $table->unique($cols, 'sp_model_permissions_unique');
            $table->foreign('permission_id')->references('id')->on('sp_permissions')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sp_model_permissions');
        Schema::dropIfExists('sp_model_roles');
        Schema::dropIfExists('sp_role_permissions');
        Schema::dropIfExists('sp_roles');
        Schema::dropIfExists('sp_permissions');
    }
};
