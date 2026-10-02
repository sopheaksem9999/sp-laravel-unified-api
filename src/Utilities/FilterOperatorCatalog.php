<?php

declare(strict_types=1);

namespace Sopheak\Core\Utilities;

use Illuminate\Support\Facades\DB;

/**
 * The one map of filter operators: which tokens the filter engine understands,
 * which database drivers each needs, and which column families it applies to.
 *
 * QueryBuilderFiltersUtils reads {@see self::NAMES} and gates driver-specific
 * operators through {@see self::supports()}; the MCP schema tools advertise
 * {@see self::forFamily()} / {@see self::catalogue()}. Add an operator here and
 * both follow, so what is advertised cannot drift from what is accepted.
 */
final class FilterOperatorCatalog
{
    /** Every operator token the engine understands, negated forms included. */
    public const NAMES = [
        'is', 'eq', 'neq', 'like', 'ilike', 'gt', 'lt', 'gte', 'lte', 'in', 'contains',
        'between', 'not_between', 'starts_with', 'ends_with', 'not_like', 'not_in', 'is_not',
        'regex', 'not_regex', 'match', 'not_match', 'imatch', 'not_imatch', 'not_ilike',
        'date_eq', 'date_gt', 'date_lt', 'date_gte', 'date_lte', 'empty', 'not_empty',
        'fts', 'not_fts', 'plfts', 'not_plfts', 'phfts', 'not_phfts', 'wfts', 'not_wfts',
        'cs', 'not_cs', 'cd', 'not_cd', 'ov', 'not_ov', 'sl', 'not_sl', 'sr', 'not_sr',
        'nxl', 'not_nxl', 'nxr', 'not_nxr', 'adj', 'not_adj',
    ];

    private const REGEX_DRIVERS = ['mysql', 'mariadb', 'pgsql'];

    private const PGSQL_ONLY = ['pgsql'];

    /** Operators that need specific drivers; every other operator runs everywhere. */
    private const DRIVER_LIMITED = [
        'regex' => self::REGEX_DRIVERS,
        'not_regex' => self::REGEX_DRIVERS,
        'match' => self::REGEX_DRIVERS,
        'not_match' => self::REGEX_DRIVERS,
        'imatch' => self::REGEX_DRIVERS,
        'not_imatch' => self::REGEX_DRIVERS,
        'fts' => self::PGSQL_ONLY, 'not_fts' => self::PGSQL_ONLY,
        'plfts' => self::PGSQL_ONLY, 'not_plfts' => self::PGSQL_ONLY,
        'phfts' => self::PGSQL_ONLY, 'not_phfts' => self::PGSQL_ONLY,
        'wfts' => self::PGSQL_ONLY, 'not_wfts' => self::PGSQL_ONLY,
        'cs' => self::PGSQL_ONLY, 'not_cs' => self::PGSQL_ONLY,
        'cd' => self::PGSQL_ONLY, 'not_cd' => self::PGSQL_ONLY,
        'ov' => self::PGSQL_ONLY, 'not_ov' => self::PGSQL_ONLY,
        'sl' => self::PGSQL_ONLY, 'not_sl' => self::PGSQL_ONLY,
        'sr' => self::PGSQL_ONLY, 'not_sr' => self::PGSQL_ONLY,
        'nxl' => self::PGSQL_ONLY, 'not_nxl' => self::PGSQL_ONLY,
        'nxr' => self::PGSQL_ONLY, 'not_nxr' => self::PGSQL_ONLY,
        'adj' => self::PGSQL_ONLY, 'not_adj' => self::PGSQL_ONLY,
    ];

    /**
     * What `not.{operator}.{value}` turns an operator into. An operator with no
     * entry has no negated form: the engine drops a `not.` filter on it.
     */
    private const NEGATIONS = [
        'eq' => 'neq',
        'neq' => 'eq',
        'in' => 'not_in',
        'not_in' => 'in',
        'like' => 'not_like',
        'ilike' => 'not_ilike',
        'is' => 'is_not',
        'is_not' => 'is',
        'gt' => 'lte',
        'gte' => 'lt',
        'lt' => 'gte',
        'lte' => 'gt',
        'between' => 'not_between',
        'not_between' => 'between',
        'empty' => 'not_empty',
        'not_empty' => 'empty',
        'regex' => 'not_regex',
        'match' => 'not_match',
        'imatch' => 'not_imatch',
        'fts' => 'not_fts',
        'plfts' => 'not_plfts',
        'phfts' => 'not_phfts',
        'wfts' => 'not_wfts',
        'cs' => 'not_cs',
        'cd' => 'not_cd',
        'ov' => 'not_ov',
        'sl' => 'not_sl',
        'sr' => 'not_sr',
        'nxl' => 'not_nxl',
        'nxr' => 'not_nxr',
        'adj' => 'not_adj',
    ];

    /**
     * Operators the engine accepts but a family's columns cannot run on a driver:
     * `contains` is a LIKE, and PostgreSQL has no LIKE for jsonb.
     */
    private const FAMILY_DRIVER_EXCLUSIONS = ['json' => ['pgsql' => ['contains']]];

    private const T = 'text';

    private const N = 'number';

    private const D = 'temporal';

    private const B = 'boolean';

    private const J = 'json';

    private const U = 'uuid';

    private const A = 'array';

    private const R = 'range';

    private const O = 'other';

    /**
     * Advertised operators in display order: name, syntax, example, summary, families.
     * Negated forms are not listed; "not." / "not_" negates any of them.
     */
    private const CATALOGUE = [
        ['eq', 'eq.{value}', 'status=eq.open', 'Equal. A comma-separated list means IN', [self::T, self::N, self::D, self::B, self::J, self::A, self::U, self::R, self::O]],
        ['neq', 'neq.{value}', 'status=neq.void', 'Not equal. A comma-separated list means NOT IN', [self::T, self::N, self::D, self::B, self::J, self::A, self::U, self::R, self::O]],
        ['in', 'in.{a,b,c}', 'status=in.open,paid', 'Value is in the list', [self::T, self::N, self::D, self::U, self::O]],
        ['not_in', 'not_in.{a,b,c}', 'status=not_in.void,draft', 'Value is not in the list', [self::T, self::N, self::D, self::U, self::O]],
        ['gt', 'gt.{value}', 'total=gt.100', 'Greater than', [self::N, self::D]],
        ['gte', 'gte.{value}', 'total=gte.100', 'Greater than or equal', [self::N, self::D]],
        ['lt', 'lt.{value}', 'total=lt.100', 'Less than', [self::N, self::D]],
        ['lte', 'lte.{value}', 'total=lte.100', 'Less than or equal', [self::N, self::D]],
        ['between', 'between.{from,to}', 'issued_at=between.2026-01-01,2026-03-31', 'Inclusive range', [self::N, self::D]],
        ['not_between', 'not_between.{from,to}', 'total=not_between.1,10', 'Outside the range', [self::N, self::D]],
        ['like', 'like.{text}', 'name=like.acme', 'Contains the text (a substring match: do not add %)', [self::T]],
        ['not_like', 'not_like.{text}', 'name=not_like.test', 'Does not contain the text', [self::T]],
        ['ilike', 'ilike.{text}', 'name=ilike.acme', 'Contains the text, ignoring case', [self::T]],
        ['contains', 'contains.{text}', 'name=contains.acme', 'Contains the text (same as like)', [self::T, self::J]],
        ['starts_with', 'starts_with.{text}', 'ref_number=starts_with.INV-', 'Starts with the text', [self::T]],
        ['ends_with', 'ends_with.{text}', 'email=ends_with.@acme.com', 'Ends with the text', [self::T]],
        ['is', 'is.null', 'deleted_at=is.null', 'Column is NULL', [self::T, self::N, self::D, self::B, self::J, self::A, self::U, self::R, self::O]],
        ['is_not', 'is_not.null', 'deleted_at=is_not.null', 'Column is not NULL', [self::T, self::N, self::D, self::B, self::J, self::A, self::U, self::R, self::O]],
        ['empty', 'empty.null', 'notes=empty.null', 'NULL or empty string (non-text columns: NULL)', [self::T, self::N, self::D, self::U]],
        ['not_empty', 'not_empty.null', 'notes=not_empty.null', 'Neither NULL nor empty string (non-text columns: not NULL)', [self::T, self::N, self::D, self::U]],
        ['date_eq', 'date_eq.{YYYY-MM-DD}', 'created_at=date_eq.2026-01-01', 'Date part equals, ignoring the time', [self::D]],
        ['date_gt', 'date_gt.{YYYY-MM-DD}', 'created_at=date_gt.2026-01-01', 'Date part is after', [self::D]],
        ['date_gte', 'date_gte.{YYYY-MM-DD}', 'created_at=date_gte.2026-01-01', 'Date part is on or after', [self::D]],
        ['date_lt', 'date_lt.{YYYY-MM-DD}', 'created_at=date_lt.2026-12-31', 'Date part is before', [self::D]],
        ['date_lte', 'date_lte.{YYYY-MM-DD}', 'created_at=date_lte.2026-12-31', 'Date part is on or before', [self::D]],
        ['regex', 'regex.{pattern}', 'ref_number=regex.^INV-[0-9]+$', 'Regular expression match', [self::T]],
        ['match', 'match.{pattern}', 'ref_number=match.^INV-', 'Regular expression match (same as regex)', [self::T]],
        ['imatch', 'imatch.{pattern}', 'ref_number=imatch.^inv-', 'Regular expression match, ignoring case', [self::T]],
        ['fts', 'fts.{query}', 'notes=fts.late payment', 'Full-text search, plain query', [self::T]],
        ['plfts', 'plfts.{query}', 'notes=plfts.late payment', 'Full-text search, plain-language query', [self::T]],
        ['phfts', 'phfts.{query}', 'notes=phfts.late payment', 'Full-text search, phrase query', [self::T]],
        ['wfts', 'wfts.{query}', 'notes=wfts.late -refund', 'Full-text search, web-search style query', [self::T]],
        ['cs', 'cs.{value}', 'tags=cs.{a,b}', 'jsonb, array or range contains the value', [self::J, self::A, self::R]],
        ['cd', 'cd.{value}', 'tags=cd.{a,b,c}', 'jsonb, array or range is contained by the value', [self::J, self::A, self::R]],
        ['ov', 'ov.{value}', 'tags=ov.{a,b}', 'Array or range overlaps the value', [self::A, self::R]],
        ['sl', 'sl.{range}', 'period=sl.[2026-01-01,2026-02-01)', 'Range is strictly left of the value', [self::R]],
        ['sr', 'sr.{range}', 'period=sr.[2026-01-01,2026-02-01)', 'Range is strictly right of the value', [self::R]],
        ['nxl', 'nxl.{range}', 'period=nxl.[2026-01-01,2026-02-01)', 'Range does not extend to the left of the value', [self::R]],
        ['nxr', 'nxr.{range}', 'period=nxr.[2026-01-01,2026-02-01)', 'Range does not extend to the right of the value', [self::R]],
        ['adj', 'adj.{range}', 'period=adj.[2026-01-01,2026-02-01)', 'Range is adjacent to the value', [self::R]],
    ];

    /** The operator `not.{operator}` stands for, or null when it has no negated form. */
    public static function negation(string $operator): ?string
    {
        return self::NEGATIONS[$operator] ?? null;
    }

    /**
     * Advertised operators that `not.` can negate on a driver.
     *
     * @return array<int, string>
     */
    public static function negatable(?string $driver = null): array
    {
        return array_values(array_filter(
            array_column(self::catalogue($driver), 'name'),
            static fn (string $name): bool => null !== self::negation($name),
        ));
    }

    /** @return array<int, string> */
    public static function names(): array
    {
        return self::NAMES;
    }

    /**
     * @return array<int, string>|null null = every driver
     */
    public static function drivers(string $operator): ?array
    {
        return self::DRIVER_LIMITED[$operator] ?? null;
    }

    public static function supports(string $operator, ?string $driver = null): bool
    {
        $drivers = self::drivers($operator);
        if (null === $drivers) {
            return true;
        }

        return in_array($driver ?? self::currentDriver(), $drivers, true);
    }

    /**
     * The connection's driver, reporting `mariadb` when a `mysql` connection
     * talks to MariaDB (they differ for a few operators).
     */
    public static function currentDriver(): string
    {
        $driver = DB::getDriverName();
        if ('mysql' === $driver) {
            $version = strtolower((string) DB::selectOne('select version() as v')->v ?? '');
            if (str_contains($version, 'mariadb')) {
                $driver = 'mariadb';
            }
        }

        return $driver;
    }

    /**
     * Operator names advertised for a column family on a driver, in catalogue order.
     *
     * @return array<int, string>
     */
    public static function forFamily(string $family, ?string $driver = null): array
    {
        $driver ??= self::currentDriver();
        $names = [];
        foreach (self::CATALOGUE as [$name, , , , $families]) {
            if (in_array($family, $families, true) && self::supports($name, $driver) && !in_array($name, self::FAMILY_DRIVER_EXCLUSIONS[$family][$driver] ?? [], true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * The operators the driver can run, with syntax and an example for each.
     *
     * @return array<int, array{name: string, syntax: string, example: string, summary: string, families: array<int, string>, drivers: array<int, string>|null}>
     */
    public static function catalogue(?string $driver = null): array
    {
        $driver ??= self::currentDriver();
        $rows = [];
        foreach (self::CATALOGUE as [$name, $syntax, $example, $summary, $families]) {
            if (!self::supports($name, $driver)) {
                continue;
            }

            $rows[] = [
                'name' => $name,
                'syntax' => $syntax,
                'example' => $example,
                'summary' => $summary,
                'families' => $families,
                'drivers' => self::drivers($name),
            ];
        }

        return $rows;
    }
}
