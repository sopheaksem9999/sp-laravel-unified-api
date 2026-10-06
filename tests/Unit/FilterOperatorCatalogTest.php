<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Utilities\FilterOperatorCatalog;
use Sopheak\Core\Utilities\QueryBuilderFiltersUtils;

class FilterOperatorCatalogTest extends TestCase
{
    public function test_the_engine_constant_is_the_catalog(): void
    {
        $engine = (new ReflectionClass(QueryBuilderFiltersUtils::class))->getReflectionConstant('FILTER_OPERATORS')->getValue();

        $this->assertSame(FilterOperatorCatalog::NAMES, $engine);
        $this->assertSame(array_values(array_unique(FilterOperatorCatalog::NAMES)), FilterOperatorCatalog::NAMES);
    }

    public function test_the_engine_has_no_private_driver_lists_left(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Utilities/QueryBuilderFiltersUtils.php');

        $this->assertSame(0, preg_match('/assertOperatorDriverSupported\([^)]*\[/', $source), 'driver lists belong in FilterOperatorCatalog');
    }

    #[DataProvider('driverRules')]
    public function test_driver_support(string $operator, string $driver, bool $expected): void
    {
        $this->assertSame($expected, FilterOperatorCatalog::supports($operator, $driver));
    }

    /** @return array<int, array{0: string, 1: string, 2: bool}> */
    public static function driverRules(): array
    {
        return [
            ['eq', 'sqlite', true],
            ['like', 'sqlite', true],
            ['regex', 'sqlite', false],
            ['regex', 'mysql', true],
            ['imatch', 'mariadb', true],
            ['not_regex', 'sqlite', false],
            ['fts', 'mysql', false],
            ['fts', 'pgsql', true],
            ['not_fts', 'pgsql', true],
            ['cs', 'mariadb', false],
            ['adj', 'pgsql', true],
        ];
    }

    public function test_every_name_is_advertised_or_a_negation_of_an_advertised_one(): void
    {
        $described = array_column(FilterOperatorCatalog::catalogue('pgsql'), 'name');

        foreach (FilterOperatorCatalog::NAMES as $name) {
            $base = str_starts_with($name, 'not_') ? substr($name, 4) : $name;
            $this->assertTrue(
                in_array($name, $described, true) || in_array($base, $described, true),
                sprintf("operator '%s' is accepted by the engine but undocumented", $name),
            );
        }
    }

    public function test_every_catalogue_row_names_a_known_operator_and_is_complete(): void
    {
        foreach (FilterOperatorCatalog::catalogue('pgsql') as $row) {
            $this->assertContains($row['name'], FilterOperatorCatalog::NAMES);
            $this->assertNotSame('', $row['syntax']);
            $this->assertNotSame('', $row['example']);
            $this->assertNotSame('', $row['summary']);
            $this->assertNotSame([], $row['families']);
        }
    }

    public function test_family_sets_follow_the_driver(): void
    {
        $this->assertContains('ilike', FilterOperatorCatalog::forFamily('text', 'sqlite'));
        $this->assertNotContains('regex', FilterOperatorCatalog::forFamily('text', 'sqlite'));
        $this->assertContains('regex', FilterOperatorCatalog::forFamily('text', 'mysql'));
        $this->assertNotContains('fts', FilterOperatorCatalog::forFamily('text', 'mysql'));
        $this->assertContains('fts', FilterOperatorCatalog::forFamily('text', 'pgsql'));
        $this->assertContains('cs', FilterOperatorCatalog::forFamily('json', 'pgsql'));
        $this->assertNotContains('cs', FilterOperatorCatalog::forFamily('json', 'sqlite'));
        $this->assertSame(['eq', 'neq', 'is', 'is_not'], FilterOperatorCatalog::forFamily('boolean', 'sqlite'));
    }

    public function test_json_and_array_columns_advertise_only_what_postgres_can_run(): void
    {
        $jsonOnPg = FilterOperatorCatalog::forFamily('json', 'pgsql');
        foreach (['eq', 'neq', 'is', 'is_not', 'cs', 'cd'] as $operator) {
            $this->assertContains($operator, $jsonOnPg, $operator);
        }

        // contains is a LIKE, which jsonb has no operator for; && exists for arrays, not jsonb.
        $this->assertNotContains('contains', $jsonOnPg);
        $this->assertNotContains('ov', $jsonOnPg);

        $this->assertContains('contains', FilterOperatorCatalog::forFamily('json', 'mysql'));
        $this->assertContains('contains', FilterOperatorCatalog::forFamily('json', 'sqlite'));
        $this->assertNotContains('cs', FilterOperatorCatalog::forFamily('json', 'mysql'));

        $arrayOnPg = FilterOperatorCatalog::forFamily('array', 'pgsql');
        foreach (['eq', 'neq', 'is', 'is_not', 'cs', 'cd', 'ov'] as $operator) {
            $this->assertContains($operator, $arrayOnPg, $operator);
        }

        $this->assertNotContains('contains', $arrayOnPg);
        $this->assertSame(['eq', 'neq', 'is', 'is_not'], FilterOperatorCatalog::forFamily('array', 'sqlite'));
    }

    public function test_the_engine_negates_through_the_catalog(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Utilities/QueryBuilderFiltersUtils.php');

        $this->assertStringNotContainsString("'between' => 'not_between'", $source, 'the negation map belongs in FilterOperatorCatalog');
        $this->assertSame('neq', FilterOperatorCatalog::negation('eq'));
        $this->assertSame('lte', FilterOperatorCatalog::negation('gt'));
        $this->assertSame('not_regex', FilterOperatorCatalog::negation('regex'));
        $this->assertNull(FilterOperatorCatalog::negation('contains'));
    }

    public function test_only_operators_with_a_negated_form_are_negatable(): void
    {
        $negatable = FilterOperatorCatalog::negatable('sqlite');

        foreach (['eq', 'in', 'like', 'ilike', 'between', 'gt', 'is', 'empty'] as $operator) {
            $this->assertContains($operator, $negatable, $operator);
        }

        foreach (['contains', 'starts_with', 'ends_with', 'date_eq', 'date_gte', 'not_like'] as $operator) {
            $this->assertNotContains($operator, $negatable, $operator . ' has no not. form');
        }

        $this->assertNotContains('regex', $negatable, 'regex is not available on sqlite');
        $this->assertContains('regex', FilterOperatorCatalog::negatable('mysql'));
        $this->assertContains('fts', FilterOperatorCatalog::negatable('pgsql'));
    }

    public function test_numbers_and_dates_get_comparisons_and_text_does_not(): void
    {
        $number = FilterOperatorCatalog::forFamily('number', 'sqlite');
        foreach (['gt', 'gte', 'lt', 'lte', 'between', 'in', 'not_in', 'eq', 'neq'] as $op) {
            $this->assertContains($op, $number);
        }

        $this->assertNotContains('like', $number);
        $this->assertContains('date_gte', FilterOperatorCatalog::forFamily('temporal', 'sqlite'));
        $this->assertNotContains('date_gte', $number);
        $this->assertNotContains('gt', FilterOperatorCatalog::forFamily('text', 'sqlite'));
    }
}
