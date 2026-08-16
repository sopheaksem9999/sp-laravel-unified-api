<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\QueryBuilderFiltersUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * A table config that declares fewer columns than its migration creates used to
 * degrade to *silent misordering*.
 *
 * Reported 2026-08-16 (KarunaFilm): `GET /sp_attachments?sortby=created_at` came
 * back in primary-key order, which on uuid keys reads as unsorted. No error, no
 * warning — `getAllowedColumns()` is the allow-list for sorting, filtering,
 * `select` and `group_by`, and every shipped package config omitted
 * `created_at`/`updated_at`, so both the explicit sort and applySort()'s own
 * "prefer created_at" fallback missed and the query sorted by `id` instead.
 *
 * Two layers are covered here, because either alone leaves installs broken:
 *  - the shipped configs now declare the columns their migrations create; and
 *  - getAllowedColumns() recovers `created_at`/`updated_at` from the physical
 *    table when a config omits them, which is what reaches apps that already
 *    published their own copy of the config (mergeConfigFrom lets a published
 *    `tables` key replace the package's wholesale).
 */
class TimestampColumnDriftTest extends TestCase
{
    use RefreshDatabase;

    protected $user;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        foreach ([
            'sp-attachments' => 'attachments',
            'sp-webhooks' => 'webhooks',
            'sp-audit' => 'audit',
            'sp-permissions' => 'permissions',
        ] as $file => $key) {
            $shipped = require __DIR__ . '/../../config/' . $file . '.php';
            $app['config']->set($key . '.tables', $shipped['tables'] ?? []);

            // permissions.tables is merged into the registry unconditionally,
            // while permissions.enabled additionally activates the per-table
            // permission gate — which is not what these tests are exercising.
            if ($key !== 'permissions') {
                $app['config']->set($key . '.enabled', true);
            }
        }

        // The reported install uses uuid keys, where "sorted by id" is visibly
        // unsorted rather than merely wrong.
        $app['config']->set('record.id_type', 'uuid');
        $app['config']->set('record.cache.enabled', false);

        // A table whose config deliberately omits both a timestamp and a
        // sensitive column, used to pin down exactly what the recovery does and
        // does not re-admit.
        $app['config']->set('record.tables', [
            'drifted_notes' => new RecordTableType(
                table: 'drifted_notes',
                pmsName: 'drifted_notes',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                columns: [
                    'id' => ['type' => 'integer', 'nullable' => false],
                    'body' => ['type' => 'string', 'nullable' => true],
                    // created_at, updated_at and api_secret all exist in the
                    // database but are intentionally left undeclared.
                ],
                relationships: [],
            ),
            // The corrected shape: timestamps declared, as the shipped package
            // configs now do.
            'stamped_notes' => new RecordTableType(
                table: 'stamped_notes',
                pmsName: 'stamped_notes',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                columns: [
                    'id' => ['type' => 'integer', 'nullable' => false],
                    'body' => ['type' => 'string', 'nullable' => true],
                    'created_at' => ['type' => 'datetime', 'nullable' => true],
                    'updated_at' => ['type' => 'datetime', 'nullable' => true],
                ],
                relationships: [],
            ),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

        Schema::create('drifted_notes', function (Blueprint $table): void {
            $table->id();
            $table->string('body')->nullable();
            $table->string('api_secret')->nullable();
            $table->timestamps();
        });

        Schema::create('stamped_notes', function (Blueprint $table): void {
            $table->id();
            $table->string('body')->nullable();
            $table->timestamps();
        });

        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password')->nullable();
                $table->timestamps();
            });
        }

        DB::table('users')->insert([
            'id' => 1, 'name' => 'Admin', 'email' => 'admin@example.com',
            'password' => 'x', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->user = new User();
        $this->user->id = 1;
        $this->user->name = 'Admin';
        $this->user->email = 'admin@example.com';

        Gate::before(fn(): bool => true);

        SchemaRegistryUtils::refresh();
        QueryBuilderFiltersUtils::clearColumnCache();
    }

    /**
     * The reported case, end to end, against the shipped attachment config.
     *
     * Rows are seeded so that primary-key order is the exact reverse of
     * created_at order — a query that ignores `sortby` returns the reverse of
     * the expected list rather than something coincidentally similar.
     */
    public function test_attachments_can_be_sorted_by_created_at(): void
    {
        $this->seedAttachments();

        $ids = array_column(
            (array) $this->actingAs($this->user, 'api')
                ->getJson('/api/sp_attachments?sortby=created_at&order=desc')
                ->assertStatus(200)
                ->json('data'),
            'id'
        );

        $this->assertSame(
            ['newest', 'middle', 'oldest'],
            $ids,
            'sortby=created_at must order by created_at; ["oldest","newest","middle"] means it silently fell back to id order'
        );
    }

    /** The same column must be usable as a filter, not just as a sort. */
    public function test_attachments_can_be_filtered_by_created_at(): void
    {
        $this->seedAttachments();

        $ids = array_column(
            (array) $this->actingAs($this->user, 'api')
                ->getJson('/api/sp_attachments?created_at=gte.2024-01-01 00:00:00')
                ->assertStatus(200)
                ->json('data'),
            'id'
        );

        sort($ids);
        $this->assertSame(['middle', 'newest'], $ids, 'a created_at filter must be applied, not silently dropped');
    }

    /**
     * Every column a package migration creates must be declared in the package
     * config for that table.
     *
     * This is the check that would have caught the report: the drift was not in
     * one config but in all nine shipped tables at once.
     */
    public function test_every_shipped_package_config_declares_the_columns_its_migration_creates(): void
    {
        $tenantColumn = RecordConfigService::tenantColumn();
        $drift = [];

        foreach (SchemaRegistryUtils::get() as $table => $config) {
            if (!str_starts_with((string) $table, 'sp_')) {
                continue;
            }

            if (!Schema::hasTable((string) $table)) {
                continue;
            }

            $declared = array_keys($config->columns ?? []);
            $missing = array_diff(Schema::getColumnListing((string) $table), $declared);

            // Tenancy is resolved server-side and the tenant column is
            // deliberately never client-filterable, so it is not expected here.
            unset($missing[array_search($tenantColumn, $missing, true)]);

            if ([] !== $missing) {
                $drift[] = $table . ' is missing ' . implode(', ', $missing);
            }
        }

        $this->assertSame(
            [],
            $drift,
            "Package table configs have drifted from their migrations.\n"
                . "An undeclared column is silently unsortable and unfilterable, so declare it in the config:\n  "
                . implode("\n  ", $drift)
        );
    }

    /**
     * The recovery layer: a config that omits created_at still sorts correctly,
     * because the column physically exists.
     *
     * This is what reaches installs that published their own config copy, where
     * the corrected package config never applies.
     */
    public function test_a_config_that_omits_created_at_still_sorts_by_it(): void
    {
        DB::table('drifted_notes')->insert([
            ['id' => 1, 'body' => 'oldest', 'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00'],
            ['id' => 2, 'body' => 'newest', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'],
            ['id' => 3, 'body' => 'middle', 'created_at' => '2023-01-01 00:00:00', 'updated_at' => '2023-01-01 00:00:00'],
        ]);

        $bodies = array_column(
            (array) $this->getJson('/api/drifted_notes?sortby=created_at&order=desc')
                ->assertStatus(200)
                ->json('data'),
            'body'
        );

        $this->assertSame(
            ['newest', 'middle', 'oldest'],
            $bodies,
            'created_at exists on the table, so it must be sortable even though the config omits it'
        );
    }

    /**
     * The recovery is limited to timestamps and must not become a general
     * "fall back to the database schema" rule.
     *
     * The config column list is an allow-list: a table that omits `api_secret`
     * is relying on it to keep that column un-filterable. Recovering arbitrary
     * columns would turn every such column into a blind enumeration oracle via
     * `?api_secret=starts_with.a`.
     */
    public function test_recovery_does_not_re_admit_arbitrary_undeclared_columns(): void
    {
        $allowed = QueryBuilderFiltersUtils::getAllowedColumns('drifted_notes');

        $this->assertContains('created_at', $allowed);
        $this->assertContains('updated_at', $allowed);
        $this->assertNotContains(
            'api_secret',
            $allowed,
            'only created_at/updated_at are recovered; an undeclared sensitive column must stay unfilterable'
        );
    }

    /** A column left out of the config stays unfilterable end to end. */
    public function test_an_undeclared_sensitive_column_cannot_be_used_as_a_filter(): void
    {
        DB::table('drifted_notes')->insert([
            ['id' => 1, 'body' => 'a', 'api_secret' => 'alpha', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'body' => 'b', 'api_secret' => 'bravo', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $rows = (array) $this->getJson('/api/drifted_notes?api_secret=eq.alpha')
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(2, $rows, 'the filter must be ignored, not applied — otherwise it is an enumeration oracle');
    }

    /**
     * Declaring the timestamps must not make them client-writable.
     *
     * overrideTimestamps is false for every package table, so
     * RecordService::sanitizePayload() strips them and the server sets its own.
     */
    public function test_declaring_timestamps_does_not_let_a_client_forge_them(): void
    {
        $this->postJson('/api/stamped_notes', [
            'body' => 'forged',
            'created_at' => '1999-01-01 00:00:00',
            'updated_at' => '1999-01-01 00:00:00',
        ])->assertSuccessful();

        $stored = DB::table('stamped_notes')->where('body', 'forged')->first();

        $this->assertNotNull($stored);
        $this->assertStringNotContainsString(
            '1999',
            (string) $stored->created_at,
            'the server must set created_at itself and ignore a client-supplied value'
        );
    }

    /** Seed rows whose id order is the exact reverse of their created_at order. */
    private function seedAttachments(): void
    {
        $base = [
            'disk' => 'local', 'mime_type' => 'text/plain',
            'size' => 1, 'visibility' => 'private',
        ];

        DB::table('sp_attachments')->insert([
            ['id' => 'oldest', 'path' => 'a', 'filename' => 'a', 'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00'] + $base,
            ['id' => 'newest', 'path' => 'b', 'filename' => 'b', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'] + $base,
            ['id' => 'middle', 'path' => 'c', 'filename' => 'c', 'created_at' => '2024-06-01 00:00:00', 'updated_at' => '2024-06-01 00:00:00'] + $base,
        ]);
    }
}
