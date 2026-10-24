<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Services\Queries\RecordQueryBuilder;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\QueryBuilderFiltersUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * Cursor navigation beyond "Next" (bug report 2026-10-24):
 *
 * A. `select=` that leaves out the paging column or the key returned no
 *    `meta.cursor`, so a list could never leave page 1.
 * B. `direction=prev` only flipped the comparison and kept the list order, so
 *    it always returned the first page.
 * C. `meta.last_cursor` was computed as if every list were ascending, and was a
 *    bare value without the key, so rows sharing the boundary timestamp were
 *    skipped.
 *
 * Every case runs on an integer-keyed and a uuid-keyed table, ascending and
 * descending, over rows that share timestamps, against the order the database
 * itself gives for (created_at, id).
 *
 * @internal
 */
class CursorPaginationNavigationTest extends TestCase
{
    use RefreshDatabase;

    /** created_at per body; several rows share a timestamp. */
    private const STAMPS = [
        'f1' => '2020-01-01 00:00:00',
        'f2' => '2021-01-01 00:00:00',
        'f3' => '2021-01-01 00:00:00',
        'f4' => '2021-01-01 00:00:00',
        'f5' => '2022-01-01 00:00:00',
        'f6' => '2023-01-01 00:00:00',
        'f7' => '2023-01-01 00:00:00',
        'f8' => '2024-01-01 00:00:00',
    ];

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
        $app['config']->set('record.pagination.cursor.boundary_cursors', true);
        $app['config']->set('record.pagination.cursor.default_column', 'id');

        $tables = [];
        foreach (['nav_int_notes' => 'integer', 'nav_uuid_notes' => 'uuid'] as $name => $idType) {
            $tables[$name] = $this->tableType($name, $idType);
        }

        $app['config']->set('record.tables', $tables);
    }

    private function tableType(string $name, string $idType): RecordTableType
    {
        return new RecordTableType(
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

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('nav_int_notes', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('body')->nullable();
            $table->timestamps();
        });

        Schema::create('nav_uuid_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('body')->nullable();
            $table->timestamps();
        });

        foreach (['nav_int_notes' => self::INT_KEYS, 'nav_uuid_notes' => self::UUID_KEYS] as $table => $keys) {
            $rows = [];
            foreach (self::STAMPS as $body => $stamp) {
                $rows[] = ['id' => $keys[$body], 'body' => $body, 'created_at' => $stamp, 'updated_at' => $stamp];
            }

            DB::table($table)->insert($rows);
        }

        SchemaRegistryUtils::refresh();
        QueryBuilderFiltersUtils::clearColumnCache();
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function tableAndOrderProvider(): array
    {
        return [
            'integer key, newest first' => ['nav_int_notes', 'desc'],
            'integer key, oldest first' => ['nav_int_notes', 'asc'],
            'uuid key, newest first' => ['nav_uuid_notes', 'desc'],
            'uuid key, oldest first' => ['nav_uuid_notes', 'asc'],
        ];
    }

    /**
     * The list in the order the database gives for (created_at, id).
     *
     * @return list<string>
     */
    private function reference(string $table, string $order): array
    {
        return DB::table($table)->orderBy('created_at', $order)->orderBy('id', $order)->pluck('body')->all();
    }

    /**
     * Follow meta.cursor forward and return every page, as lists of bodies plus meta.
     *
     * @return list<array{bodies: list<string>, meta: array<string, mixed>, rows: list<array<string, mixed>>}>
     */
    private function pages(string $table, string $query): array
    {
        $pages = [];
        $cursor = '';

        for ($i = 0; $i < 12; ++$i) {
            $response = $this->getJson('/api/' . $table . '?' . $query . '&direction=next&cursor=' . urlencode($cursor))->assertStatus(200);
            $rows = (array) $response->json('data');
            if ([] === $rows) {
                break;
            }

            $meta = (array) $response->json('meta');
            $pages[] = ['bodies' => array_column($rows, 'body'), 'meta' => $meta, 'rows' => $rows];

            $next = $meta['cursor'] ?? null;
            if (null === $next || '' === $next || $next === $cursor) {
                break;
            }

            $cursor = (string) $next;
        }

        return $pages;
    }

    /** @return list<string> */
    private function bodies(string $url): array
    {
        return array_column((array) $this->getJson($url)->assertStatus(200)->json('data'), 'body');
    }

    /** @dataProvider tableAndOrderProvider */
    public function test_a_select_without_the_paging_column_or_the_key_still_pages_through_every_row(string $table, string $order): void
    {
        foreach (['id,body', 'body'] as $select) {
            $pages = $this->pages($table, sprintf('per_page=3&sortby=created_at&order=%s&select=%s', $order, $select));

            $this->assertSame($this->reference($table, $order), array_merge(...array_column($pages, 'bodies')), 'select=' . $select);

            foreach ($pages as $page) {
                foreach ($page['rows'] as $row) {
                    $this->assertSame(explode(',', $select), array_keys($row), 'the response keeps the columns the request asked for');
                }
            }
        }
    }

    /** @dataProvider tableAndOrderProvider */
    public function test_prev_from_a_pages_prev_cursor_returns_the_page_before_it(string $table, string $order): void
    {
        $pages = $this->pages($table, sprintf('per_page=3&sortby=created_at&order=%s', $order));
        $this->assertCount(3, $pages);
        $counter = count($pages);

        for ($n = 1; $n < $counter; ++$n) {
            $prevCursor = $pages[$n]['meta']['prev_cursor'] ?? null;
            $this->assertIsString($prevCursor);

            $this->assertSame(
                $pages[$n - 1]['bodies'],
                $this->bodies(sprintf('/api/%s?per_page=3&sortby=created_at&order=%s&direction=prev&cursor=%s', $table, $order, urlencode($prevCursor))),
                'page ' . ($n + 1) . ' → Previous'
            );
        }
    }

    /** @dataProvider tableAndOrderProvider */
    public function test_every_page_carries_both_ends_and_keeps_meta_cursor(string $table, string $order): void
    {
        foreach ($this->pages($table, sprintf('per_page=3&sortby=created_at&order=%s', $order)) as $page) {
            $this->assertStringStartsWith('c1.', (string) $page['meta']['prev_cursor']);
            $this->assertStringStartsWith('c1.', (string) $page['meta']['next_cursor']);
            $this->assertSame($page['meta']['next_cursor'], $page['meta']['cursor'], 'meta.cursor is unchanged: the end of the page');
        }
    }

    /** @dataProvider tableAndOrderProvider */
    public function test_a_prev_page_can_continue_backwards_to_the_first_page(string $table, string $order): void
    {
        $pages = $this->pages($table, sprintf('per_page=3&sortby=created_at&order=%s', $order));
        $last = $pages[count($pages) - 1];

        $seen = [];
        $cursor = (string) $last['meta']['prev_cursor'];
        for ($i = 0; $i < 5; ++$i) {
            $response = $this->getJson(sprintf('/api/%s?per_page=3&sortby=created_at&order=%s&direction=prev&cursor=%s', $table, $order, urlencode($cursor)))->assertStatus(200);
            $rows = array_column((array) $response->json('data'), 'body');
            if ([] === $rows) {
                break;
            }

            $seen = [...$rows, ...$seen];
            $cursor = (string) $response->json('meta.prev_cursor');
        }

        $this->assertSame(array_merge(...array_column(array_slice($pages, 0, -1), 'bodies')), $seen);
    }

    /** @dataProvider tableAndOrderProvider */
    public function test_last_cursor_opens_exactly_the_last_page(string $table, string $order): void
    {
        $reference = $this->reference($table, $order);

        foreach ([3 => 2, 4 => 4] as $perPage => $lastPageSize) {
            $meta = (array) $this->getJson(sprintf('/api/%s?per_page=%d&sortby=created_at&order=%s&cursor=', $table, $perPage, $order))->assertStatus(200)->json('meta');
            $lastCursor = $meta['last_cursor'] ?? null;

            $this->assertIsString($lastCursor);
            $this->assertStringStartsWith('c1.', $lastCursor, 'a composite token, so ties on the boundary are not skipped');
            $this->assertSame(
                array_slice($reference, -$lastPageSize),
                $this->bodies(sprintf('/api/%s?per_page=%d&sortby=created_at&order=%s&direction=next&cursor=%s', $table, $perPage, $order, urlencode($lastCursor))),
                'per_page=' . $perPage
            );
        }
    }

    /** @dataProvider tableAndOrderProvider */
    public function test_the_query_builder_pages_the_same_way(string $table, string $order): void
    {
        $paginate = function (array $query) use ($table): array {
            $builder = new RecordQueryBuilder($table, config('record.tables')[$table]);
            $builder->buildBaseQuery()->getBuilder()->orderBy('created_at', $query['order']);

            return $builder->paginate(Request::create('/', 'GET', $query));
        };

        $reference = $this->reference($table, $order);
        $base = ['per_page' => 3, 'cursor_column' => 'created_at', 'order' => $order];

        $seen = [];
        $pages = [];
        $cursor = '';
        for ($i = 0; $i < 5; ++$i) {
            $result = $paginate($base + ['cursor' => $cursor, 'direction' => 'next']);
            if ([] === $result['data']) {
                break;
            }

            $pages[] = $result;
            $seen = [...$seen, ...array_map(static fn(object $row): string => $row->body, $result['data'])];
            $cursor = (string) $result['meta']['cursor'];
        }

        $this->assertSame($reference, $seen, 'forward, ties included');

        $prev = $paginate($base + ['cursor' => $pages[1]['meta']['prev_cursor'], 'direction' => 'prev']);
        $this->assertSame(array_slice($reference, 0, 3), array_map(static fn(object $row): string => $row->body, $prev['data']));

        $last = $paginate($base + ['cursor' => $pages[0]['meta']['last_cursor'], 'direction' => 'next']);
        $this->assertSame(array_slice($reference, -2), array_map(static fn(object $row): string => $row->body, $last['data']));
    }
}
