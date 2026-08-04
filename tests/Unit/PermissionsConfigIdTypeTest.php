<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use Sopheak\Core\Tests\TestCase;

/**
 * config/permissions.php derives sp_permissions.id and sp_roles.id column
 * types from RecordConfigService::idType() at the moment the file is
 * evaluated (config/permissions.php:148,181).
 *
 * This CANNOT be tested via config('permissions.tables...'): Testbench's
 * RegisterProviders::bootstrap() runs CoreSpLaravelApiProvider::register()
 * -- which merges this file via mergeConfigFrom() -- BEFORE
 * getEnvironmentSetUp() runs (confirmed by reading
 * vendor/orchestra/testbench-core/src/Concerns/CreatesApplication.php:
 * resolveApplicationBootstrappers() calls RegisterProviders::bootstrap()
 * and only afterwards invokes getEnvironmentSetUp()). So by the time any
 * test method runs, config('permissions.tables') already reflects whatever
 * record.id_type was set to at boot, and setting record.id_type from a test
 * has no effect on that already-merged array. This was verified directly:
 * setting record.id_type = 'uuid' in getEnvironmentSetUp() and then reading
 * config('permissions.tables.sp_roles')->columns['id']['type'] still
 * reports 'bigIncrements'.
 *
 * This is a test-harness ordering artifact, not a production bug: in a real
 * app, config/record.php is loaded by Illuminate's LoadConfiguration
 * bootstrapper, which runs before RegisterProviders, so record.id_type is
 * already set when this provider's register() evaluates the ternary.
 *
 * To actually exercise the derivation, re-require config/permissions.php
 * directly after setting record.id_type ourselves -- this runs the exact
 * same ternary the provider's mergeConfigFrom() runs, just at a time we
 * control, bypassing the provider merge entirely. Do not "simplify" this
 * back to config('permissions.tables...'); that reads the provider's
 * already-merged, stale copy and will not detect a regression here.
 */
class PermissionsConfigIdTypeTest extends TestCase
{
    /** @test */
    public function permissions_id_columns_are_uuid_when_record_id_type_is_uuid(): void
    {
        config()->set('record.id_type', 'uuid');

        $permissions = require __DIR__ . '/../../config/permissions.php';

        $this->assertSame('uuid', $permissions['tables']['sp_permissions']->columns['id']['type']);
        $this->assertSame('uuid', $permissions['tables']['sp_roles']->columns['id']['type']);
    }

    /** @test */
    public function permissions_id_columns_are_big_increments_when_record_id_type_is_integer(): void
    {
        config()->set('record.id_type', 'integer');

        $permissions = require __DIR__ . '/../../config/permissions.php';

        $this->assertSame('bigIncrements', $permissions['tables']['sp_permissions']->columns['id']['type']);
        $this->assertSame('bigIncrements', $permissions['tables']['sp_roles']->columns['id']['type']);
    }
}
