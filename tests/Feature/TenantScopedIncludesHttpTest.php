<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use Sopheak\Core\Http\Controllers\CoreRecordController;
use PHPUnit\Framework\Attributes\DataProvider;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Types\RecordMetaBelongsToManyType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableTriggerType;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class TenantIncludeHooks
{
    public static function addsGroupedPetFilter(Request $request, string $table, array $context): Request
    {
        $request->query->set('or', '(pets.name.eq.THEIRS,name.eq.Nobody)');

        return $request;
    }

    public static function addsPetInclude(Request $request, string $table, array $context): Request
    {
        $request->query->set('select', '*,pets(*)');

        return $request;
    }
}

/**
 * Over HTTP a table that is not tenant-scoped (`owners`) embedded rows of a
 * tenant-scoped one (`pets`) for every tenant when no tenant resolved — the
 * hole the MCP tools already refuse. A tenant-scoped table itself answers 422
 * without a tenant; so does an include of one.
 *
 * @internal
 */
class TenantScopedIncludesHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('owners', function (Blueprint $t): void {
            $t->id();
            $t->string('name')->nullable();
            $t->timestamps();
        });
        Schema::create('pets', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('owner_id');
            $t->string('tenant_id')->nullable();
            $t->string('name')->nullable();
            $t->timestamps();
        });
        DB::table('owners')->insert(['id' => 1, 'name' => 'Pat']);
        DB::table('pets')->insert([
            ['id' => 1, 'owner_id' => 1, 'tenant_id' => 't1', 'name' => 'MINE'],
            ['id' => 2, 'owner_id' => 1, 'tenant_id' => 't2', 'name' => 'THEIRS'],
        ]);

        $public = new RecordTablePublic(read: true, write: true);
        Config::set('record.enable_tenant_id', true);
        Config::set('record.tables', [
            'owners' => new RecordTableType(
                table: 'owners',
                pmsName: 'owners',
                public: $public,
                relationships: ['pets' => new RecordHasManyType(table: 'pets', foreignKey: 'owner_id')],
            ),
            'pets' => new RecordTableType(table: 'pets', pmsName: 'pets', hasTenantId: true, public: $public),
        ]);
        SchemaRegistryUtils::refresh();
    }

    /** @return array<string, array{0: string}> */
    public static function includeQueries(): array
    {
        return [
            'select with an include' => ['/api/owners?select=*,pets(*)'],
            'with' => ['/api/owners?with=pets(*)'],
            'a relationship filter' => ['/api/owners?pets.name=eq.THEIRS'],
            'a tenant_id the client picked' => ['/api/owners?select=*,pets(*)&tenant_id=t2'],
            'a single record' => ['/api/owners/1?select=*,pets(*)'],
            'an aliased include' => ['/api/owners?select=*,pets:anything(*)'],
            'an aliased with' => ['/api/owners?with=pets:anything(*)'],
        ];
    }

    #[DataProvider('includeQueries')]
    public function test_without_a_tenant_an_include_of_tenant_scoped_rows_is_refused(string $uri): void
    {
        $response = $this->getJson($uri);

        $response->assertStatus(422);
        $this->assertStringContainsString('pets', (string) $response->getContent());
        $this->assertStringNotContainsString('THEIRS', (string) $response->getContent());
    }

    public function test_without_a_tenant_the_table_itself_still_reads(): void
    {
        $this->getJson('/api/owners')->assertOk()->assertJsonPath('data.0.name', 'Pat');
    }

    public function test_with_a_tenant_header_includes_are_scoped_to_it(): void
    {
        $response = $this->getJson('/api/owners?select=*,pets(*)', ['X-Tenant-ID' => 't1'])->assertOk();

        $this->assertSame(['MINE'], array_column($response->json('data.0.pets'), 'name'));
    }

    public function test_with_tenancy_off_nothing_changes(): void
    {
        Config::set('record.enable_tenant_id', false);
        SchemaRegistryUtils::refresh();

        $response = $this->getJson('/api/owners?select=*,pets(*)')->assertOk();

        $this->assertCount(2, $response->json('data.0.pets'));
    }

    /**
     * A tenant-scoped table two levels down (`owners -> houses -> animals`) and a
     * tenant-scoped pivot under a table that is not (`owners <-> tags` through
     * `owner_tag`) are embedded too.
     */
    private function registerDeeperRelationships(): void
    {
        Schema::create('houses', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('owner_id');
        });
        Schema::create('animals', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('house_id');
            $t->string('tenant_id')->nullable();
            $t->string('name')->nullable();
        });
        Schema::create('tags', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        });
        Schema::create('owner_tag', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('owner_id');
            $t->unsignedBigInteger('tag_id');
            $t->string('tenant_id')->nullable();
        });
        DB::table('houses')->insert(['id' => 1, 'owner_id' => 1]);
        DB::table('animals')->insert([
            ['id' => 1, 'house_id' => 1, 'tenant_id' => 't1', 'name' => 'MINE'],
            ['id' => 2, 'house_id' => 1, 'tenant_id' => 't2', 'name' => 'THEIRS'],
        ]);
        DB::table('tags')->insert([['id' => 1, 'name' => 'MINE'], ['id' => 2, 'name' => 'THEIRS']]);
        DB::table('owner_tag')->insert([
            ['owner_id' => 1, 'tag_id' => 1, 'tenant_id' => 't1'],
            ['owner_id' => 1, 'tag_id' => 2, 'tenant_id' => 't2'],
        ]);

        $public = new RecordTablePublic(read: true, write: true);
        $tables = config('record.tables');
        $tables['owners'] = new RecordTableType(
            table: 'owners',
            pmsName: 'owners',
            public: $public,
            relationships: [
                'pets' => new RecordHasManyType(table: 'pets', foreignKey: 'owner_id'),
                'houses' => new RecordHasManyType(table: 'houses', foreignKey: 'owner_id'),
                'tags' => new RecordMetaBelongsToManyType(related: 'tags', table: 'owner_tag', foreignPivotKey: 'owner_id', relatedPivotKey: 'tag_id'),
            ],
        );
        $tables['houses'] = new RecordTableType(
            table: 'houses',
            public: $public,
            relationships: ['animals' => new RecordHasManyType(table: 'animals', foreignKey: 'house_id')],
        );
        $tables['animals'] = new RecordTableType(table: 'animals', hasTenantId: true, public: $public);
        $tables['tags'] = new RecordTableType(table: 'tags', public: $public);
        $tables['owner_tag'] = new RecordTableType(table: 'owner_tag', hasTenantId: true, public: $public);
        Config::set('record.tables', $tables);
        SchemaRegistryUtils::refresh();
    }

    public function test_without_a_tenant_a_nested_tenant_scoped_include_is_refused(): void
    {
        $this->registerDeeperRelationships();

        $response = $this->getJson('/api/owners?select=*,houses(*,animals(*))');

        $response->assertStatus(422);
        $this->assertStringContainsString('animals', (string) $response->getContent());
    }

    public function test_without_a_tenant_an_include_through_a_tenant_scoped_pivot_is_refused(): void
    {
        $this->registerDeeperRelationships();

        $response = $this->getJson('/api/owners?select=*,tags(*)');

        $response->assertStatus(422);
        $this->assertStringContainsString('tags', (string) $response->getContent());
    }

    public function test_a_value_that_merely_mentions_a_relationship_is_not_refused(): void
    {
        $this->getJson('/api/owners?or=(name.eq.pets.com,name.eq.Pat)')->assertOk();
    }

    public function test_a_controller_action_on_a_route_with_another_parameter_name_still_refuses(): void
    {
        Route::get('/custom/owners-feed', fn(Request $request) => app(CoreRecordController::class)->listRecords($request, 'owners'));

        $this->getJson('/custom/owners-feed?select=*,pets(*)')->assertStatus(422);
    }

    /** @return list<string>
     * @param array<string, string> $headers */
    private function ownerNames(string $uri, array $headers = []): array
    {
        return array_column((array) $this->getJson($uri, $headers)->assertOk()->json('data'), 'name');
    }

    public function test_a_grouped_condition_on_a_relationship_column_matches(): void
    {
        Config::set('record.enable_tenant_id', false);
        SchemaRegistryUtils::refresh();

        $this->assertSame(['Pat'], $this->ownerNames('/api/owners?or=(pets.name.eq.THEIRS,name.eq.Nobody)'));
        $this->assertSame([], $this->ownerNames('/api/owners?or=(pets.name.eq.NONE,name.eq.Nobody)'));
    }

    public function test_without_a_tenant_a_grouped_relationship_condition_is_refused(): void
    {
        $response = $this->getJson('/api/owners?or=(pets.name.eq.THEIRS,name.eq.Nobody)');

        $response->assertStatus(422);
        $this->assertStringContainsString('pets', (string) $response->getContent());
    }

    public function test_with_a_tenant_a_grouped_relationship_condition_sees_only_that_tenant(): void
    {
        $this->assertSame([], $this->ownerNames('/api/owners?or=(pets.name.eq.THEIRS,name.eq.Nobody)', ['X-Tenant-ID' => 't1']));
        $this->assertSame(['Pat'], $this->ownerNames('/api/owners?or=(pets.name.eq.MINE,name.eq.Nobody)', ['X-Tenant-ID' => 't1']));
    }

    public function test_with_a_tenant_a_relationship_filter_sees_only_that_tenant(): void
    {
        // THEIRS is tenant t2's pet: a t1 caller must not learn that Pat has it.
        $this->assertSame([], $this->ownerNames('/api/owners?pets.name=eq.THEIRS', ['X-Tenant-ID' => 't1']));
        $this->assertSame(['Pat'], $this->ownerNames('/api/owners?pets.name=eq.MINE', ['X-Tenant-ID' => 't1']));
    }

    public function test_a_cached_include_or_relationship_filter_is_not_shared_across_tenants(): void
    {
        Config::set('record.cache.enabled', true);
        Config::set('cache.default', 'array');
        $tables = config('record.tables');
        $tables['owners'] = new RecordTableType(
            table: 'owners',
            pmsName: 'owners',
            disableCache: false,
            public: new RecordTablePublic(read: true, write: true),
            relationships: ['pets' => new RecordHasManyType(table: 'pets', foreignKey: 'owner_id')],
        );
        Config::set('record.tables', $tables);
        SchemaRegistryUtils::refresh();

        $pets = fn(string $tenant): array => array_column((array) $this->getJson('/api/owners?select=*,pets(*)', ['X-Tenant-ID' => $tenant])->json('data.0.pets'), 'name');

        $this->assertSame(['MINE'], $pets('t1'));
        $this->assertSame(['THEIRS'], $pets('t2'), 't2 must not get the response cached for t1');
        $this->assertSame([], $this->ownerNames('/api/owners?pets.name=eq.THEIRS', ['X-Tenant-ID' => 't1']));
        $this->assertSame(['Pat'], $this->ownerNames('/api/owners?pets.name=eq.THEIRS', ['X-Tenant-ID' => 't2']));
    }

    public function test_a_configured_search_over_a_tenant_scoped_relationship_needs_a_tenant_and_is_bound_to_it(): void
    {
        $tables = config('record.tables');
        $tables['owners'] = new RecordTableType(
            table: 'owners',
            pmsName: 'owners',
            public: new RecordTablePublic(read: true, write: true),
            searchable: ['name', 'pets.name'],
            relationships: ['pets' => new RecordHasManyType(table: 'pets', foreignKey: 'owner_id')],
        );
        Config::set('record.tables', $tables);
        SchemaRegistryUtils::refresh();

        $this->getJson('/api/owners?search=THEIRS')->assertStatus(422);
        $this->assertSame([], $this->ownerNames('/api/owners?search=THEIRS', ['X-Tenant-ID' => 't1']));
        $this->assertSame(['Pat'], $this->ownerNames('/api/owners?search=THEIRS', ['X-Tenant-ID' => 't2']));
    }

    private function withOwnersHook(string $method, bool $cached = false): void
    {
        $tables = config('record.tables');
        $tables['owners'] = new RecordTableType(
            table: 'owners',
            pmsName: 'owners',
            disableCache: !$cached,
            public: new RecordTablePublic(read: true, write: true),
            relationships: ['pets' => new RecordHasManyType(table: 'pets', foreignKey: 'owner_id')],
            beforeRead: '' === $method ? null : new RecordTableTriggerType(TenantIncludeHooks::class, $method),
        );
        Config::set('record.tables', $tables);
        SchemaRegistryUtils::refresh();
    }

    public function test_without_a_tenant_a_relationship_filter_a_hook_adds_is_refused(): void
    {
        $this->withOwnersHook('addsGroupedPetFilter');

        $this->getJson('/api/owners')->assertStatus(422);
    }

    public function test_without_a_tenant_an_include_a_hook_adds_is_refused(): void
    {
        $this->withOwnersHook('addsPetInclude');

        $this->getJson('/api/owners')->assertStatus(422);
        $this->getJson('/api/owners/1')->assertStatus(422);
    }

    public function test_an_include_set_on_the_request_before_the_controller_is_checked_and_cached_per_tenant(): void
    {
        Config::set('record.cache.enabled', true);
        Config::set('cache.default', 'array');
        $this->withOwnersHook('', cached: true);
        // Like a middleware that sets a default select on the request.
        Route::get('/custom/owners-with-pets', function (Request $request) {
            $request->query->set('select', '*,pets(*)');

            return app(CoreRecordController::class)->listRecords($request, 'owners');
        });

        $this->getJson('/custom/owners-with-pets')->assertStatus(422);
        $pets = fn(string $tenant): array => array_column((array) $this->getJson('/custom/owners-with-pets', ['X-Tenant-ID' => $tenant])->json('data.0.pets'), 'name');
        $this->assertSame(['MINE'], $pets('t1'));
        $this->assertSame(['THEIRS'], $pets('t2'), 't2 must not get the response cached for t1');
    }

    public function test_a_malformed_nested_filter_key_is_a_validation_error(): void
    {
        Config::set('record.enable_tenant_id', false);
        SchemaRegistryUtils::refresh();

        $this->getJson('/api/owners?0[a][b]=1')->assertStatus(422);
        $this->getJson('/api/owners?lazy=true&0[a][b]=1')->assertStatus(422);
    }

    public function test_with_a_tenant_a_relationship_filter_through_a_tenant_scoped_pivot_sees_only_that_tenant(): void
    {
        $this->registerDeeperRelationships();

        // Tag THEIRS is linked to Pat by a t2 pivot row only.
        $this->assertSame([], $this->ownerNames('/api/owners?tags.name=eq.THEIRS', ['X-Tenant-ID' => 't1']));
        $this->assertSame(['Pat'], $this->ownerNames('/api/owners?tags.name=eq.MINE', ['X-Tenant-ID' => 't1']));
    }

    public function test_a_lazy_relationship_filter_filters_the_relationship_not_the_table(): void
    {
        Config::set('record.enable_tenant_id', false);
        SchemaRegistryUtils::refresh();

        // No pet is named Pat; the owner is. The filter is on pets.name.
        $this->assertSame([], $this->ownerNames('/api/owners?lazy=true&pets.name=eq.Pat'));
        $this->assertSame(['Pat'], $this->ownerNames('/api/owners?lazy=true&pets.name=eq.MINE'));
    }
}
