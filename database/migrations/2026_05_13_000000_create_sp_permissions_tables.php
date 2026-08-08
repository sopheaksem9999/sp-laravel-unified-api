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
            // Bounded because of the unique('name') below -- the same
            // per-column index budget that governs sp_roles.key.
            //
            // Auto-registration builds these as "{verb}{separator}{pmsName}"
            // (PermissionRegistrar::ensurePermissionExists), so the package's
            // own longest is 21 characters: 'delete:sp_attachments'. A client's
            // pmsName defaults to the table name, and MySQL caps identifiers at
            // 64 characters, so the CRUD form tops out near 71. 191 is not a
            // real constraint on any name this generates.
            $table->string('name', MigrationIdHelper::INDEX_SAFE_LENGTH);
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
            // key and the tenant column are the two parts of
            // sp_roles_key_tenant_unique (and key alone of sp_roles_key_unique),
            // so both are bounded to INDEX_SAFE_LENGTH for the same reason as
            // sp_model_has_roles. Unbounded, the composite index costs
            // 1020 + 1020 = 2040 bytes under utf8mb4 -- inside InnoDB's
            // 3072-byte limit, but each part alone already exceeds the 767-byte
            // per-column cap of the COMPACT and REDUNDANT row formats. Bounded,
            // it is 764 + 764 = 1528 bytes with every part under 767.
            //
            // key holds Str::slug($role->name), so 191 characters is not a real
            // constraint on any name this package or its tests generate.
            if ($enableTenantId) {
                $table->string($tenantColumn, MigrationIdHelper::INDEX_SAFE_LENGTH)->nullable();
                $table->unique(['key', $tenantColumn], 'sp_roles_key_tenant_unique');
            } else {
                $table->unique('key', 'sp_roles_key_unique');
            }
            $table->string('name');
            $table->string('key', MigrationIdHelper::INDEX_SAFE_LENGTH)->nullable();
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
            // Surrogate key: nothing references it. Governed by record.id_type
            // via a custom Pivot class (RolePermissionPivot / ModelHasRolePivot /
            // ModelPermissionPivot) registered on the relevant relationship with
            // ->using(), since Eloquent's sync()/attach() only fire model events
            // — and thus HasConfigurableKey's uuid generation — when a custom
            // pivot class is registered.
            MigrationIdHelper::primary($table);
            MigrationIdHelper::foreign($table, 'role_id');
            MigrationIdHelper::foreign($table, 'permission_id');
            if ($enableTenantId) {
                // Part of sp_role_permissions_unique; bounded for the same
                // index-budget reason as the other tenant columns here.
                $table->string($tenantColumn, MigrationIdHelper::INDEX_SAFE_LENGTH)->nullable()->index();
            }
            $table->timestamps();

            $cols = array_filter(['role_id', 'permission_id', $enableTenantId ? $tenantColumn : null]);
            $table->unique($cols, 'sp_role_permissions_unique');
            $table->foreign('role_id')->references('id')->on('sp_roles')->onDelete('cascade');
            $table->foreign('permission_id')->references('id')->on('sp_permissions')->onDelete('cascade');
        });

        Schema::create('sp_model_has_roles', function (Blueprint $table) use ($tenantColumn, $enableTenantId) {
            // Surrogate key: nothing references it. See sp_role_permissions.id
            // above for why a custom Pivot class is required to govern it.
            MigrationIdHelper::primary($table);
            // Every string column here is part of sp_model_has_roles_unique, so
            // each is bounded to INDEX_SAFE_LENGTH. Unbounded varchar(255)
            // columns cost 1020 bytes each under utf8mb4 and the four of them
            // (with a uuid role_id and a tenant column) overrun MySQL's
            // 3072-byte InnoDB index limit: 1020 + 1020 + 144 + 1020 = 3204.
            $table->string('model_type', MigrationIdHelper::INDEX_SAFE_LENGTH);
            // Points at an arbitrary client model, whose key may be a uuid or
            // an integer. A string holds either.
            MigrationIdHelper::morph($table, 'model_id');
            MigrationIdHelper::foreign($table, 'role_id');
            if ($enableTenantId) {
                $table->string($tenantColumn, MigrationIdHelper::INDEX_SAFE_LENGTH)->nullable()->index();
            }
            $table->timestamps();

            $table->index(['model_type', 'model_id']);
            $table->index('role_id');
            $cols = array_filter(['model_type', 'model_id', 'role_id', $enableTenantId ? $tenantColumn : null]);
            $table->unique($cols, 'sp_model_has_roles_unique');
            $table->foreign('role_id')->references('id')->on('sp_roles')->onDelete('cascade');
        });

        Schema::create('sp_model_permissions', function (Blueprint $table) use ($tenantColumn, $enableTenantId) {
            // Surrogate key: nothing references it. See sp_role_permissions.id
            // above for why a custom Pivot class is required to govern it.
            MigrationIdHelper::primary($table);
            // Bounded for the same index-budget reason as sp_model_has_roles.
            $table->string('model_type', MigrationIdHelper::INDEX_SAFE_LENGTH);
            MigrationIdHelper::morph($table, 'model_id');
            MigrationIdHelper::foreign($table, 'permission_id');
            if ($enableTenantId) {
                $table->string($tenantColumn, MigrationIdHelper::INDEX_SAFE_LENGTH)->nullable()->index();
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
