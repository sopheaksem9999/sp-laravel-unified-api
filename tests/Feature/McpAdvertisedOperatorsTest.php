<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Mcp\Guidance\ColumnTypes;
use Sopheak\Core\Mcp\SchemaTools;
use Sopheak\Core\Tests\Concerns\ResolvesRefs;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * Advertised = accepted (spec §9 "Operators (C1)"): every operator the schema
 * tool lists for a field must work in a real list request on this driver.
 */
class McpAdvertisedOperatorsTest extends TestCase
{
    use RefreshDatabase;
    use ResolvesRefs;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('ks_rows', function (Blueprint $table): void {
            $table->id();
            $table->string('str_col')->nullable();
            $table->text('txt_col')->nullable();
            $table->integer('int_col')->nullable();
            $table->decimal('dec_col', 8, 2)->nullable();
            $table->boolean('flag_col')->nullable();
            $table->date('day_col')->nullable();
            $table->timestamp('stamp_col')->nullable();
            $table->uuid('uid_col')->nullable();
            $table->json('doc_col')->nullable();
        });
        DB::table('ks_rows')->insert([
            ['str_col' => 'alpha', 'txt_col' => 'one two', 'int_col' => 5, 'dec_col' => 9.5, 'flag_col' => true, 'day_col' => '2026-01-01', 'stamp_col' => '2026-01-01 10:00:00', 'uid_col' => '3f2504e0-4f89-11d3-9a0c-0305e82c3301', 'doc_col' => '{"a":1}'],
            ['str_col' => 'beta', 'txt_col' => 'three', 'int_col' => 7, 'dec_col' => 1.5, 'flag_col' => false, 'day_col' => '2026-02-01', 'stamp_col' => null, 'uid_col' => null, 'doc_col' => null],
        ]);
        Config::set('record.tables', [
            'ks_rows' => new RecordTableType(table: 'ks_rows', pmsName: 'ks_rows', public: new RecordTablePublic(read: true, write: true)),
        ]);
        SchemaRegistryUtils::refresh();
    }

    /** @return array<string, array<int, string>> */
    private function advertised(): array
    {
        return array_column($this->resolveRefs(app(SchemaTools::class)->getEndpoint(['endpoint' => 'ks_rows']))['filters'], 'operators', 'field');
    }

    public function test_every_advertised_operator_works_in_a_real_request(): void
    {
        $columns = SchemaRegistryUtils::getTable('ks_rows')->columns;
        $checked = 0;

        foreach ($this->advertised() as $field => $operators) {
            $sample = (string) ColumnTypes::sample((string) $columns[$field]['type']);
            if ('true' === $sample) {
                $sample = '1';
            }

            foreach ($operators as $operator) {
                $value = match ($operator) {
                    'is', 'is_not', 'empty', 'not_empty' => 'null',
                    'between', 'not_between' => $sample . ',' . $sample,
                    default => $sample,
                };

                $response = $this->getJson('/api/ks_rows?' . http_build_query([$field => $operator . '.' . $value]));
                $this->assertSame(200, $response->getStatusCode(), sprintf('%s=%s.%s -> %s', $field, $operator, $value, $response->getContent()));
                ++$checked;
            }
        }

        $this->assertGreaterThan(60, $checked);
    }

    public function test_sets_differ_by_family_and_exclude_other_drivers_operators(): void
    {
        $advertised = $this->advertised();

        $this->assertSame(['eq', 'neq', 'is', 'is_not'], $advertised['flag_col']);
        $this->assertContains('between', $advertised['int_col']);
        $this->assertContains('date_eq', $advertised['day_col']);
        $this->assertContains('starts_with', $advertised['str_col']);
        $this->assertNotContains('starts_with', $advertised['int_col']);

        foreach ($advertised as $field => $operators) {
            foreach (['regex', 'match', 'imatch', 'fts', 'plfts', 'cs', 'cd', 'ov'] as $foreign) {
                $this->assertNotContains($foreign, $operators, $field . ' must not advertise ' . $foreign . ' on sqlite');
            }
        }
    }

    public function test_every_negatable_operator_the_guidance_lists_really_negates(): void
    {
        $negatable = app(SchemaTools::class)->apiGuidance()['operators']['negatable'];
        $cases = [
            'str_col' => ['eq' => 'alpha', 'neq' => 'alpha', 'in' => 'alpha', 'not_in' => 'alpha', 'like' => 'alph', 'ilike' => 'ALPH', 'is' => 'null', 'is_not' => 'null', 'empty' => 'null', 'not_empty' => 'null'],
            'int_col' => ['gt' => '5', 'gte' => '7', 'lt' => '7', 'lte' => '5', 'between' => '1,5', 'not_between' => '1,5'],
        ];
        $ids = fn(string $query): array => collect($this->getJson('/api/ks_rows?' . $query)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $tested = [];

        foreach ($cases as $column => $operators) {
            foreach ($operators as $operator => $value) {
                if (!in_array($operator, $negatable, true)) {
                    continue;
                }

                $positive = $ids($column . '=' . $operator . '.' . $value);
                $negated = $ids($column . '=not.' . $operator . '.' . $value);
                $this->assertSame([1, 2], collect([...$positive, ...$negated])->unique()->sort()->values()->all(), $operator . ' and not.' . $operator . ' together cover every row');
                $this->assertSame([], array_values(array_intersect($positive, $negated)), 'not.' . $operator . ' must be the complement of ' . $operator);
                $tested[] = $operator;
            }
        }

        $this->assertGreaterThan(10, count($tested));
        $this->assertNotContains('contains', $negatable);
    }

    public function test_an_operator_the_driver_cannot_run_is_rejected(): void
    {
        $this->getJson('/api/ks_rows?str_col=regex.al')->assertStatus(422);
    }
}
