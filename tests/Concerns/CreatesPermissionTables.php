<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal tables for tests that run the built-in permission module
 * (`permissions.enabled`): roles, permissions, their pivots and a users table.
 * Same schema SuperAdminBypassTest builds.
 */
trait CreatesPermissionTables
{
    protected function createPermissionTables(): void
    {
        if (!Schema::hasTable('sp_permissions')) {
            Schema::create('sp_permissions', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('name')->unique();
                $table->string('group')->nullable();
                $table->string('guard_name');
                $table->text('description')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('sp_roles')) {
            Schema::create('sp_roles', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('name');
                $table->string('key')->nullable();
                $table->string('guard_name');
                $table->text('description')->nullable();
                $table->boolean('is_system')->default(false);
                $table->boolean('is_master')->default(false);
                $table->boolean('is_default')->default(false);
                $table->unique('key', 'sp_roles_key_unique');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('sp_role_permissions')) {
            Schema::create('sp_role_permissions', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('role_id');
                $table->unsignedBigInteger('permission_id');
                $table->timestamps();
                $table->unique(['role_id', 'permission_id']);
            });
        }

        if (!Schema::hasTable('sp_model_has_roles')) {
            Schema::create('sp_model_has_roles', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('model_type');
                $table->string('model_id');
                $table->unsignedBigInteger('role_id');
                $table->string('tenant_id')->nullable();
                $table->timestamps();
                $table->index(['model_type', 'model_id']);
            });
        }

        if (!Schema::hasTable('sp_model_permissions')) {
            Schema::create('sp_model_permissions', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('model_type');
                $table->string('model_id');
                $table->unsignedBigInteger('permission_id');
                $table->string('tenant_id')->nullable();
                $table->timestamps();
                $table->index(['model_type', 'model_id']);
            });
        }
    }

    protected function createUsersTable(): void
    {
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->boolean('is_admin')->default(false);
            });
        }
    }
}
