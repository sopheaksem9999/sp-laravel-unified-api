<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\QueryBuilderFiltersUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * Exact matching of values with commas, `not.` on every substring/date operator,
 * 422 for unusable aggregate input, the documented default page size, and the
 * error envelope for unknown tables.
 */
class FilterQuotingNegationAndDefaultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('fq_rows', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->integer('amount')->nullable();
            $table->date('day')->nullable();
        });
        DB::table('fq_rows')->insert([
            ['name' => 'Acme, Inc.', 'amount' => 1, 'day' => '2026-01-01'],
            ['name' => 'Acme', 'amount' => 2, 'day' => '2026-02-01'],
            ['name' => 'Inc.', 'amount' => 3, 'day' => '2026-03-01'],
            ['name' => '(draft)', 'amount' => 4, 'day' => '2026-04-01'],
            ['name' => 'beta', 'amount' => 5, 'day' => '2026-05-01'],
        ]);
        Config::set('record.tables', [
            'fq_rows' => new RecordTableType(table: 'fq_rows', pmsName: 'fq_rows', public: new RecordTablePublic(read: true, write: true)),
        ]);
        SchemaRegistryUtils::refresh();
    }

    /** @return int[] */
    private function ids(string $query): array
    {
        return collect($this->getJson('/api/fq_rows?' . $query)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    }

    public function test_split_list_honours_quotes_and_escapes(): void
    {
        $this->assertSame(['Acme, Inc.', 'B'], QueryBuilderFiltersUtils::splitList('"Acme, Inc.",B'));
        $this->assertSame(['a', 'b', 'c'], QueryBuilderFiltersUtils::splitList('a, b ,c'));
        $this->assertSame(['say "hi"', 'a\\b'], QueryBuilderFiltersUtils::splitList('"say \"hi\"","a\\\\b"'));
        $this->assertSame(['(x)', 'y'], QueryBuilderFiltersUtils::splitList('"(x)",y'));
    }

    public function test_eq_with_a_comma_matches_the_whole_value(): void
    {
        $this->assertSame([1], $this->ids('name=' . rawurlencode('eq.Acme, Inc.')));
        $this->assertSame([2, 3, 4, 5], $this->ids('name=' . rawurlencode('neq.Acme, Inc.')));
    }

    public function test_an_explicit_parenthesised_list_still_means_in(): void
    {
        $this->assertSame([2, 3], $this->ids('name=' . rawurlencode('eq.(Acme,Inc.)')));
        $this->assertSame([1, 4, 5], $this->ids('name=' . rawurlencode('neq.(Acme,Inc.)')));
    }

    public function test_quoted_items_keep_commas_and_parentheses(): void
    {
        $this->assertSame([1, 5], $this->ids('name=' . rawurlencode('in."Acme, Inc.",beta')));
        $this->assertSame([1, 5], $this->ids('name=' . rawurlencode('in.("Acme, Inc.",beta)')));
        $this->assertSame([2, 3, 4], $this->ids('name=' . rawurlencode('not_in.("Acme, Inc.",beta)')));
        $this->assertSame([4], $this->ids('name=' . rawurlencode('eq."(draft)"')));
        $this->assertSame([4, 5], $this->ids('name=' . rawurlencode('in.("(draft)",beta)')));
    }

    public function test_quoted_items_work_in_grouped_or_filters(): void
    {
        $this->assertSame([1, 5], $this->ids('or=' . rawurlencode('(name.eq."Acme, Inc.",amount.eq.5)')));
        $this->assertSame([1, 2], $this->ids('or=' . rawurlencode('(name.in.("Acme, Inc.",Acme),amount.eq.99)')));
    }

    public function test_not_on_substring_and_date_operators_is_the_complement(): void
    {
        $cases = [
            'name=contains.Acme' => 'name=not.contains.Acme',
            'name=starts_with.Acme' => 'name=not.starts_with.Acme',
            'name=ends_with.Inc.' => 'name=not.ends_with.Inc.',
            'day=date_eq.2026-02-01' => 'day=not.date_eq.2026-02-01',
            'day=date_gt.2026-02-01' => 'day=not.date_gt.2026-02-01',
            'day=date_gte.2026-02-01' => 'day=not.date_gte.2026-02-01',
            'day=date_lt.2026-02-01' => 'day=not.date_lt.2026-02-01',
            'day=date_lte.2026-02-01' => 'day=not.date_lte.2026-02-01',
        ];

        foreach ($cases as $positive => $negated) {
            $in = $this->ids($positive);
            $out = $this->ids($negated);
            $this->assertNotSame([], $in, $positive);
            $this->assertNotSame([], $out, $negated);
            $this->assertSame([1, 2, 3, 4, 5], collect([...$in, ...$out])->unique()->sort()->values()->all(), $negated . ' covers the rest');
            $this->assertSame([], array_values(array_intersect($in, $out)), $negated . ' excludes the matches');
        }

        $this->assertSame([3, 4, 5], $this->ids('name=not.contains.Acme'));
    }

    public function test_not_on_an_operator_without_a_negation_is_rejected(): void
    {
        $this->getJson('/api/fq_rows?name=not.not_like.Acme')->assertStatus(422);
    }

    public function test_unknown_aggregate_parts_are_rejected(): void
    {
        $this->getJson('/api/fq_rows?aggregate=median:amount')->assertStatus(422);
        $this->getJson('/api/fq_rows?aggregate=sum:no_such_col')->assertStatus(422);
        $this->getJson('/api/fq_rows?aggregate=sum')->assertStatus(422);
        $this->getJson('/api/fq_rows?aggregate=sum:amount')->assertOk();
    }

    public function test_per_page_defaults_to_the_documented_page_size(): void
    {
        DB::table('fq_rows')->insert(array_map(static fn(int $i): array => ['name' => 'row' . $i, 'amount' => $i, 'day' => '2026-06-01'], range(1, 30)));

        $this->assertCount(25, $this->getJson('/api/fq_rows?page=1')->assertOk()->json('data'));
        $this->assertCount(25, $this->getJson('/api/fq_rows?cursor=0&direction=next')->assertOk()->json('data'));
        $this->assertCount(7, $this->getJson('/api/fq_rows?page=1&per_page=7')->assertOk()->json('data'));

        Config::set('record.default_per_page', 10);
        $this->assertCount(10, $this->getJson('/api/fq_rows?page=1')->assertOk()->json('data'));
    }

    public function test_unknown_table_uses_the_error_envelope(): void
    {
        $response = $this->getJson('/api/no_such_table')->assertNotFound();

        $this->assertFalse($response->json('success'));
        $this->assertNotNull($response->json('error_code'));
        $this->assertNotEmpty($response->json('message'));
        $this->assertArrayHasKey('request_id', $response->json('meta'));
        $this->assertStringContainsString('"errors":{}', (string) $response->getContent());
    }
}
