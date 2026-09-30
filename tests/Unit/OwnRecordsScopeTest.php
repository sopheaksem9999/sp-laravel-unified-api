<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\OwnRecordsScope;
use Sopheak\Core\Utilities\QueryBuilderFiltersUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * @internal
 */
class OwnRecordsScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('record.tables', [
            'widgets' => new RecordTableType(
                table: 'widgets',
                pmsName: 'widget',
                hasTenantId: false,
                columns: [
                    'id' => ['type' => 'integer'],
                    'created_by_id' => ['type' => 'bigInteger'],
                ],
            ),
            'orders' => new RecordTableType(
                table: 'orders',
                pmsName: 'order',
                hasTenantId: false,
                ownerColumn: 'user_id',
                columns: [
                    'id' => ['type' => 'integer'],
                    'user_id' => ['type' => 'bigInteger'],
                    'created_by_id' => ['type' => 'bigInteger'],
                ],
            ),
            'notes' => new RecordTableType(
                table: 'notes',
                pmsName: 'note',
                hasTenantId: false,
                columns: ['id' => ['type' => 'integer']],
            ),
        ]);

        SchemaRegistryUtils::refresh();
        QueryBuilderFiltersUtils::clearColumnCache();
        Auth::setUser(new GenericUser(['id' => 42]));
    }

    /** @test */
    public function no_view_own_permission_means_no_restriction(): void
    {
        $this->assertNull(OwnRecordsScope::ownerColumn('widgets'));
        $this->assertSame('', OwnRecordsScope::cacheToken('widgets'));
    }

    /** @test */
    public function view_own_restricts_on_the_resolved_owner_column(): void
    {
        Gate::define('viewOwn:widget', fn(): bool => true);
        Gate::define('viewOwn:order', fn(): bool => true);

        $this->assertSame('created_by_id', OwnRecordsScope::ownerColumn('widgets'));
        $this->assertSame('user_id', OwnRecordsScope::ownerColumn('orders'));
        $this->assertSame('own:user_id:42', OwnRecordsScope::cacheToken('orders'));
    }

    /** @test */
    public function a_table_without_a_declared_owner_column_is_not_restricted(): void
    {
        Gate::define('viewOwn:note', fn(): bool => true);

        $this->assertNull(OwnRecordsScope::ownerColumn('notes'));
        $this->assertSame('', OwnRecordsScope::cacheToken('notes'));
    }

    /** @test */
    public function apply_qualifies_the_owner_column_with_the_given_table(): void
    {
        Gate::define('viewOwn:widget', fn(): bool => true);

        $query = DB::table('physical_widgets');
        OwnRecordsScope::apply($query, 'widgets', 'physical_widgets');

        $this->assertStringContainsString('"physical_widgets"."created_by_id"', $query->toSql());
        $this->assertContains(42, $query->getBindings());
    }

    /** @test */
    public function apply_is_a_no_op_without_a_restriction(): void
    {
        $query = DB::table('widgets');
        OwnRecordsScope::apply($query, 'widgets');

        $this->assertStringNotContainsString('where', strtolower($query->toSql()));
    }

    /** @test */
    public function a_guest_is_never_restricted(): void
    {
        Auth::forgetUser();
        Gate::define('viewOwn:widget', fn(): bool => true);

        $this->assertNull(OwnRecordsScope::ownerColumn('widgets'));
    }
}
