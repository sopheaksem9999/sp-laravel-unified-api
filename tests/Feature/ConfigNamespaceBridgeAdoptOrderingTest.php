<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Foundation\Application;
use Sopheak\Core\Tests\TestCase;

/**
 * Pins Pass A to its position: FIRST statement of the provider's register(),
 * before every mergeConfigFrom().
 *
 * config/sp-permissions.php calls RecordConfigService::idType() *while it is
 * being merged*, and idType() reads config('record.id_type'). So a client who
 * publishes config/sp-record.php with 'id_type' => 'uuid' only gets uuid
 * primary keys on sp_permissions and sp_roles if adopt() has already folded
 * sp-record into the canonical `record` namespace by the time that merge runs.
 * Move adopt() below the merges and the client silently gets bigIncrements
 * instead — a wrong primary key type, with no error.
 *
 * The rest of the suite cannot see this: nothing publishes an sp-record.php
 * into Testbench's config directory, so adopt() is a no-op in every other test
 * and the ordering is unconstrained. Hence this dedicated case, which seeds
 * `sp-record` in the one window a real published file occupies — after
 * LoadConfiguration, before RegisterProviders.
 */
class ConfigNamespaceBridgeAdoptOrderingTest extends TestCase
{
    /**
     * Stand in for a client's published config/sp-record.php.
     *
     * Testbench runs LoadConfiguration in resolveApplicationConfiguration()
     * and RegisterProviders inside resolveApplicationBootstrappers(), so
     * setting the value here — before the parent call — puts it on the
     * repository exactly where LoadConfiguration would have left it, and
     * before the provider registers.
     *
     * getEnvironmentSetUp() is NOT usable for this: it runs after
     * RegisterProviders, which is far too late to influence a merge.
     *
     * @param Application $app
     */
    protected function resolveApplicationBootstrappers($app)
    {
        $app['config']->set('sp-record', ['id_type' => 'uuid']);

        parent::resolveApplicationBootstrappers($app);
    }

    /** @test */
    public function adopt_runs_before_the_merges_so_a_published_sp_record_reaches_permissions(): void
    {
        // Pass A folded the published file into the canonical namespace at all.
        $this->assertSame('uuid', config('record.id_type'));

        // The ordering-sensitive assertion: permissions resolved its id column
        // types from record.id_type during mergeConfigFrom. bigIncrements here
        // means adopt() ran too late.
        $tables = config('permissions.tables');

        $this->assertSame(
            'uuid',
            $tables['sp_permissions']->columns['id']['type'],
            'sp_permissions.id resolved before adopt() folded in the published sp-record.php'
        );
        $this->assertSame(
            'uuid',
            $tables['sp_roles']->columns['id']['type'],
            'sp_roles.id resolved before adopt() folded in the published sp-record.php'
        );
    }
}
