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

        Schema::create('sp_permissions', function (Blueprint $table) {
            MigrationIdHelper::primary($table);
            $table->string('name');
            $table->string('group')->nullable();
            $table->string('guard_name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique('name');
            $table->index('group');
            $table->index(['guard_name', 'name']);
        });

        Schema::create('sp_roles', function (Blueprint $table) use ($tenantColumn, $enableTenantId) {
            MigrationIdHelper::primary($table);
            if ($enableTenantId) {
                $table->string($tenantColumn)->nullable();
                $table->unique(['key', $tenantColumn], 'sp_roles_key_tenant_unique');
            } else {
                $table->unique('key', 'sp_roles_key_unique');
            }
            $table->string('name');
            $table->string('key')->nullable();
            $table->string('guard_name');
            $table->text('description')->nullable();
            $table->boolean('is_system')->nullable()->default(false);
            $table->boolean('is_master')->nullable()->default(false);
            $table->boolean('is_default')->nullable()->default(false);

            $table->timestamps();

            $table->index(['guard_name', 'name']);
            $table->index('name');
            $table->index('is_default');
        });

        Schema::create('sp_role_permissions', function (Blueprint $table) use ($tenantColumn, $enableTenantId) {
            // Surrogate key: nothing references it, and Eloquent's sync()
            // inserts pivot rows without an id, so it must stay auto-incrementing.
            $table->bigIncrements('id');
            MigrationIdHelper::foreign($table, 'role_id');
            MigrationIdHelper::foreign($table, 'permission_id');
            if ($enableTenantId) {
                $table->string($tenantColumn)->nullable()->index();
            }
            $table->timestamps();

            $cols = array_filter(['role_id', 'permission_id', $enableTenantId ? $tenantColumn : null]);
            $table->unique($cols, 'sp_role_permissions_unique');
            $table->foreign('role_id')->references('id')->on('sp_roles')->onDelete('cascade');
            $table->foreign('permission_id')->references('id')->on('sp_permissions')->onDelete('cascade');
        });

        Schema::create('sp_model_has_roles', function (Blueprint $table) use ($tenantColumn, $enableTenantId) {
            $table->bigIncrements('id');
            $table->string('model_type');
            // Points at an arbitrary client model, whose key may be a uuid or
            // an integer. A string holds either.
            MigrationIdHelper::morph($table, 'model_id');
            MigrationIdHelper::foreign($table, 'role_id');
            if ($enableTenantId) {
                $table->string($tenantColumn)->nullable()->index();
            }
            $table->timestamps();

            $table->index(['model_type', 'model_id']);
            $table->index('role_id');
            $cols = array_filter(['model_type', 'model_id', 'role_id', $enableTenantId ? $tenantColumn : null]);
            $table->unique($cols, 'sp_model_has_roles_unique');
            $table->foreign('role_id')->references('id')->on('sp_roles')->onDelete('cascade');
        });

        Schema::create('sp_model_permissions', function (Blueprint $table) use ($tenantColumn, $enableTenantId) {
            $table->bigIncrements('id');
            $table->string('model_type');
            MigrationIdHelper::morph($table, 'model_id');
            MigrationIdHelper::foreign($table, 'permission_id');
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
        Schema::dropIfExists('sp_model_has_roles');
        Schema::dropIfExists('sp_role_permissions');
        Schema::dropIfExists('sp_roles');
        Schema::dropIfExists('sp_permissions');
    }
};
