<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use Illuminate\Support\Facades\DB;
use Sopheak\Core\Database\MigrationIdHelper;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Tests\TestCase;

/**
 * Index-key byte budget for the permissions tables.
 *
 * SQLite drops varchar lengths entirely — Schema::getColumns reports a bare
 * 'varchar' whatever the declared size — so a column-type assertion cannot see
 * this. Instead the migration is compiled against a MySQL grammar with
 * Connection::pretend(), which produces the real DDL without needing a MySQL
 * server, and the emitted lengths are asserted directly.
 *
 * The budget under utf8mb4 (4 bytes per character):
 *
 *   sp_roles_key_tenant_unique = key + tenant_column
 *     unbounded: 255*4 + 255*4 = 2040 bytes
 *     bounded:   191*4 + 191*4 = 1528 bytes
 *
 * Both fit InnoDB's 3072-byte total, so this is headroom rather than a fix for
 * a live failure. What the unbounded form does break is the 767-byte
 * per-column cap of the COMPACT and REDUNDANT row formats, which a single
 * varchar(255) utf8mb4 column (1020 bytes) exceeds on its own.
 */
class PermissionsIndexLengthTest extends TestCase
{
    /**
     * Every string column that takes part in a unique index in this migration.
     *
     * @var list<array{string, string}>
     */
    private const INDEXED_STRING_COLUMNS = [
        ['sp_roles', 'key'],
        ['sp_roles', 'tenant_id'],
        ['sp_role_permissions', 'tenant_id'],
        ['sp_model_has_roles', 'model_type'],
        ['sp_model_has_roles', 'model_id'],
        ['sp_model_has_roles', 'tenant_id'],
        ['sp_model_permissions', 'model_type'],
        ['sp_model_permissions', 'model_id'],
        ['sp_model_permissions', 'tenant_id'],
    ];

    /** @test */
    public function every_indexed_string_column_is_bounded_to_the_index_safe_length(): void
    {
        $sql = $this->compileForMySql();

        foreach (self::INDEXED_STRING_COLUMNS as [$table, $column]) {
            $create = $this->createStatementFor($sql, $table);

            $this->assertStringContainsString(
                sprintf('`%s` varchar(%d)', $column, MigrationIdHelper::INDEX_SAFE_LENGTH),
                $create,
                sprintf('%s.%s is part of a unique index and must be bounded', $table, $column)
            );
        }
    }

    /** @test */
    public function the_role_key_tenant_index_fits_the_compact_row_format_budget(): void
    {
        $sql = $this->compileForMySql();

        $this->assertNotNull(
            $this->statementMatching($sql, 'sp_roles_key_tenant_unique'),
            'the composite unique index should exist when tenancy is enabled'
        );

        $create = $this->createStatementFor($sql, 'sp_roles');

        // 4 bytes per character under utf8mb4.
        $bytesPerPart = MigrationIdHelper::INDEX_SAFE_LENGTH * 4;

        $this->assertSame(764, $bytesPerPart);
        $this->assertLessThan(767, $bytesPerPart, 'each index part must fit the COMPACT/REDUNDANT per-column cap');
        $this->assertLessThan(3072, $bytesPerPart * 2, 'the composite key must fit the InnoDB index limit');

        // And nothing in this table quietly reintroduces a 255 in the index.
        $this->assertStringNotContainsString('`key` varchar(255)', $create);
        $this->assertStringNotContainsString('`tenant_id` varchar(255)', $create);
    }

    /**
     * Compile the migration to MySQL DDL without a MySQL server.
     *
     * @return list<string>
     */
    private function compileForMySql(): array
    {
        config()->set('record.enable_tenant_id', true);
        config()->set('record.tenant_column', 'tenant_id');
        config()->set('database.connections.permissions_probe_mysql', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'unused',
            'username' => 'unused',
            'password' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]);
        // The migration calls the Schema facade, which resolves the default
        // connection. pretend() records the SQL instead of running it, so no
        // MySQL server is contacted.
        config()->set('database.default', 'permissions_probe_mysql');

        $this->assertSame('tenant_id', RecordConfigService::tenantColumn());

        $queries = DB::connection('permissions_probe_mysql')->pretend(function (): void {
            $migration = require __DIR__ . '/../../database/migrations/2026_05_13_000000_create_sp_permissions_tables.php';
            $migration->up();
        });

        return array_map(static fn (array $query): string => $query['query'], $queries);
    }

    /**
     * @param list<string> $sql
     */
    private function createStatementFor(array $sql, string $table): string
    {
        $statement = $this->statementMatching($sql, sprintf('create table `%s` ', $table));

        $this->assertNotNull($statement, sprintf('no create statement emitted for %s', $table));

        return $statement;
    }

    /**
     * @param list<string> $sql
     */
    private function statementMatching(array $sql, string $needle): ?string
    {
        foreach ($sql as $statement) {
            if (str_contains($statement, $needle)) {
                return $statement;
            }
        }

        return null;
    }
}
