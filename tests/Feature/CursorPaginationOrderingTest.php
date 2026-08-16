<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\QueryBuilderFiltersUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * Cursor pagination has to page on the column the rows are ordered by, and its
 * tie-break has to compare like with like.
 *
 * Two defects reported 2026-08-16 (KarunaFilm), both silent:
 *
 *  - `?sortby=created_at` ordered page 1 by `created_at` while the cursor
 *    returned in `meta.cursor` was the last row's `id`, because `applySort()`
 *    and the cursor block each decided the ordering independently. Page 2 ran
 *    `WHERE id <op> <cursor>` against unrelated uuids and returned a page that
 *    was neither a continuation nor an error — rows repeated, others vanished.
 *
 *  - The composite branch compared the *primary key* against `$cursor`, which
 *    holds the *cursor column's* value, and did so inclusively (`>=`/`<=`). On
 *    a uuid-keyed table that is a lexicographic comparison of a uuid against a
 *    timestamp string; the boundary row also reappeared at the top of the next
 *    page.
 *
 * Both are exercised against an integer-keyed and a uuid-keyed table, because
 * the failure modes differ by key type: an integer key compares numerically and
 * degrades quietly, while a uuid compares lexicographically and, on Postgres,
 * can raise a cast error outright.
 */
class CursorPaginationOrderingTest extends TestCase
{
    use RefreshDatabase;

    /** Bodies in created_at order, oldest first. */
    private const ORDER = ['f1', 'f2', 'f3', 'f4', 'f5', 'f6', 'f7', 'f8'];

    /**
     * Keys deliberately uncorrelated with created_at order, so a query that
     * pages on the key returns visibly different rows from one that pages on
     * the timestamp. Fixed rather than random, so a failure reproduces.
     */
    private const UUID_KEYS = [
        'f1' => 'e0000000-0000-4000-8000-000000000001',
        'f2' => 'a0000000-0000-4000-8000-000000000002',
        'f3' => 'c0000000-0000-4000-8000-000000000003',
        'f4' => '10000000-0000-4000-8000-000000000004',
        'f5' => 'd0000000-0000-4000-8000-000000000005',
        'f6' => '30000000-0000-4000-8000-000000000006',
        'f7' => 'b0000000-0000-4000-8000-000000000007',
        'f8' => '70000000-0000-4000-8000-000000000008',
    ];

    private const INT_KEYS = [
        'f1' => 80, 'f2' => 30, 'f3' => 60, 'f4' => 10,
        'f5' => 70, 'f6' => 20, 'f7' => 50, 'f8' => 40,
    ];

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('record.cache.enabled', false);
        $app['config']->set('record.pagination.default_mode', 'cursor');
        $app['config']->set('record.pagination.cursor.composite_enabled', true);
        $app['config']->set('record.pagination.cursor.default_column', 'id');

        $tables = [];
        foreach (['cursor_int_notes' => 'integer', 'cursor_uuid_notes' => 'uuid'] as $name => $idType) {
            $tables[$name] = new RecordTableType(
                table: $name,
                pmsName: $name,
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                primaryKey: 'id',
                columns: [
                    'id' => ['type' => $idType, 'nullable' => false],
                    'body' => ['type' => 'string', 'nullable' => true],
                    'created_at' => ['type' => 'datetime', 'nullable' => true],
                    'updated_at' => ['type' => 'datetime', 'nullable' => true],
                ],
                relationships: [],
            );
        }

        $app['config']->set('record.tables', $tables);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('cursor_int_notes', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('body')->nullable();
            $table->timestamps();
        });

        Schema::create('cursor_uuid_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('body')->nullable();
            $table->timestamps();
        });

        SchemaRegistryUtils::refresh();
        QueryBuilderFiltersUtils::clearColumnCache();
    }

    /** @return array<string, array{0: string}> */
    public static function tableProvider(): array
    {
        return [
            'integer primary key' => ['cursor_int_notes'],
            'uuid primary key' => ['cursor_uuid_notes'],
        ];
    }

    /**
     * The reported case: sorting by created_at must page by created_at.
     *
     * @dataProvider tableProvider
     */
    public function test_a_sorted_list_pages_through_every_row_once_in_order(string $table): void
    {
        $this->seedRows($table);

        $walked = $this->walk($table, 'per_page=3&sortby=created_at&order=desc');

        $this->assertSame(
            array_reverse(self::ORDER),
            $walked,
            'paging a created_at-sorted list must yield every row once, newest first'
        );
    }

    /** The same, ascending, to catch an operator that only works one way. */
    public function test_ascending_order_also_pages_correctly(): void
    {
        $this->seedRows('cursor_uuid_notes');

        $this->assertSame(
            self::ORDER,
            $this->walk('cursor_uuid_notes', 'per_page=3&sortby=created_at&order=asc')
        );
    }

    /**
     * `meta.cursor_column` must name the column actually sorted on, since that
     * is what a caller inspects to know what the cursor means.
     */
    public function test_the_cursor_reports_the_column_the_list_is_sorted_by(): void
    {
        $this->seedRows('cursor_uuid_notes');

        $this->assertSame(
            'created_at',
            $this->getJson('/api/cursor_uuid_notes?per_page=3&sortby=created_at&order=desc')
                ->assertStatus(200)
                ->json('meta.cursor_column')
        );
    }

    /**
     * With no `sortby` at all the list still pages on `created_at`, because
     * that is what applySort() orders by when a table has one. Tables without
     * timestamps keep falling back to the primary key, which a flat config
     * default could not express.
     */
    public function test_created_at_is_the_default_paging_column_when_the_table_has_one(): void
    {
        $this->seedRows('cursor_uuid_notes');

        $response = $this->getJson('/api/cursor_uuid_notes?per_page=3')->assertStatus(200);

        $this->assertSame('created_at', $response->json('meta.cursor_column'));
        $this->assertSame(array_reverse(self::ORDER), $this->walk('cursor_uuid_notes', 'per_page=3'));
    }

    /**
     * The composite tie-break: rows sharing a cursor value must each appear
     * exactly once.
     *
     * This is what the inclusive `>=`/`<=` broke — and what comparing the key
     * against a timestamp made non-deterministic, since the outcome depended on
     * how a uuid happened to compare against a date string.
     *
     * @dataProvider tableProvider
     */
    public function test_rows_sharing_a_timestamp_are_each_returned_exactly_once(string $table): void
    {
        $keys = $table === 'cursor_uuid_notes' ? self::UUID_KEYS : self::INT_KEYS;

        $rows = [];
        foreach (self::ORDER as $body) {
            $rows[] = [
                'id' => $keys[$body],
                'body' => $body,
                // Every row shares one timestamp, so ordering is decided
                // entirely by the tie-break.
                'created_at' => '2025-01-01 00:00:00',
                'updated_at' => '2025-01-01 00:00:00',
            ];
        }

        DB::table($table)->insert($rows);

        $walked = $this->walk($table, 'per_page=3&sortby=created_at&order=desc');

        sort($walked);
        $this->assertSame(
            self::ORDER,
            $walked,
            'every tied row must appear exactly once — duplicates mean an inclusive tie-break, gaps mean a lost row'
        );
    }

    /**
     * An explicit `cursor_column` still wins, and page 1 is ordered by it.
     *
     * This is the workaround clients adopted for the sort bug, so it has to
     * keep working — and it has to order page 1 by the same column it pages on,
     * which is the half the old code only did from page 2 onwards.
     *
     * @dataProvider tableProvider
     */
    public function test_an_explicit_cursor_column_still_drives_both_order_and_paging(string $table): void
    {
        $this->seedRows($table);

        $response = $this->getJson('/api/' . $table . '?per_page=3&cursor_column=created_at&order=asc')
            ->assertStatus(200);

        $this->assertSame('created_at', $response->json('meta.cursor_column'));
        $this->assertSame(
            ['f1', 'f2', 'f3'],
            array_column((array) $response->json('data'), 'body')
        );
        $this->assertSame(
            self::ORDER,
            $this->walk($table, 'per_page=3&cursor_column=created_at&order=asc')
        );
    }

    /**
     * Paging on the primary key itself stays a plain scalar cursor — there is
     * no tie to break on a unique column, and clients holding such cursors must
     * not see the token format change under them.
     */
    public function test_paging_on_the_primary_key_returns_a_plain_cursor(): void
    {
        $this->seedRows('cursor_int_notes');

        $cursor = $this->getJson('/api/cursor_int_notes?per_page=3&cursor_column=id&order=asc')
            ->assertStatus(200)
            ->json('meta.cursor');

        $this->assertSame(30, $cursor, 'a primary-key cursor is the key value itself, not an encoded token');
    }

    /**
     * A cursor issued by an older version is a bare scalar. It must still page
     * forward rather than being misread as a compound token or routed into the
     * composite branch it cannot satisfy.
     */
    public function test_a_legacy_scalar_cursor_still_pages_forward(): void
    {
        $this->seedRows('cursor_uuid_notes');

        $bodies = array_column(
            (array) $this->getJson('/api/cursor_uuid_notes?per_page=3&sortby=created_at&order=asc&cursor=' . urlencode('2023-01-01 00:00:00'))
                ->assertStatus(200)
                ->json('data'),
            'body'
        );

        // f4 carries exactly this timestamp and the cursor is exclusive, so the
        // continuation starts at f5.
        $this->assertSame(['f5', 'f6', 'f7'], $bodies, 'a plain timestamp cursor must still continue the list');
    }

    /** Seed the eight rows, created_at ascending, keys deliberately scrambled. */
    private function seedRows(string $table): void
    {
        $keys = $table === 'cursor_uuid_notes' ? self::UUID_KEYS : self::INT_KEYS;

        $rows = [];
        foreach (self::ORDER as $index => $body) {
            $stamp = sprintf('%04d-01-01 00:00:00', 2020 + $index);
            $rows[] = [
                'id' => $keys[$body],
                'body' => $body,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ];
        }

        DB::table($table)->insert($rows);
    }

    /**
     * Follow `meta.cursor` to exhaustion and return every body seen, in order.
     *
     * Page count is capped so a cursor that fails to advance fails the test
     * instead of hanging it.
     *
     * @return array<int, string>
     */
    private function walk(string $table, string $query): array
    {
        $seen = [];
        $cursor = null;

        for ($page = 0; $page < 12; ++$page) {
            $url = '/api/' . $table . '?' . $query;
            if (null !== $cursor && '' !== $cursor) {
                $url .= '&cursor=' . urlencode((string) $cursor);
            }

            $response = $this->getJson($url)->assertStatus(200);
            $rows = (array) $response->json('data');

            if ([] === $rows) {
                break;
            }

            foreach ($rows as $row) {
                $seen[] = $row['body'];
            }

            $next = $response->json('meta.cursor');
            if (null === $next || $next === $cursor) {
                break;
            }

            $cursor = $next;
        }

        return $seen;
    }
}
