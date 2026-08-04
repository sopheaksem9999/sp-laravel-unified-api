<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Database\MigrationIdHelper;

/**
 * Converts the four columns that reference CLIENT-owned models from an integer
 * type to a string on installs that migrated before those columns were fixed.
 *
 * | table                | column    | references          |
 * |----------------------|-----------|---------------------|
 * | sp_model_has_roles   | model_id  | any client model    |
 * | sp_model_permissions | model_id  | any client model    |
 * | sp_audit_logs        | entity_id | any client record   |
 * | sp_audit_logs        | user_id   | client User model   |
 *
 * Two reasons this cannot be left to fresh installs only:
 *
 * 1. A client whose User model has a uuid primary key cannot store its key in
 *    a bigint column at all. That install is broken today and has no way to
 *    fix itself without an ALTER.
 * 2. The package's own metadata (config/audit.php) now declares entity_id and
 *    user_id as 'string'. SchemaRegistryUtils trusts that declaration, so a
 *    filter such as ?entity_id=empty.null emits `= ''` against the column. On
 *    PostgreSQL that is a 500 (invalid input syntax for type bigint), on MySQL
 *    it silently coerces to 0 and returns the wrong rows. Config and schema
 *    have to agree.
 *
 * Laravel 11 and later change column types natively, so doctrine/dbal is NOT
 * required and is deliberately not added as a dependency. This package requires
 * laravel/framework ^12.0|^13.0.
 *
 * This migration is a no-op when the column is already a string (every fresh
 * install) and when the table does not exist (sp_audit_logs with audit.enabled
 * set to false), so it is safe to run on any install in any order.
 */
return new class extends Migration {
    /**
     * @var list<array{0: string, 1: string}>
     */
    private const TARGETS = [
        ['sp_model_has_roles', 'model_id'],
        ['sp_model_permissions', 'model_id'],
        ['sp_audit_logs', 'entity_id'],
        ['sp_audit_logs', 'user_id'],
    ];

    /**
     * Column types this migration converts. Anything else — varchar, text,
     * uuid, or a type this package does not recognise — is left untouched.
     *
     * @var list<string>
     */
    private const INTEGER_TYPES = [
        'bigint', 'int8', 'int', 'int4', 'integer', 'mediumint',
        'smallint', 'int2', 'tinyint', 'numeric', 'decimal', 'bigserial', 'serial',
    ];

    public function up(): void
    {
        foreach (self::TARGETS as [$table, $column]) {
            $current = $this->columnDefinition($table, $column);

            if ($current === null) {
                continue;
            }

            $nullable = (bool) ($current['nullable'] ?? true);

            Schema::table($table, function (Blueprint $blueprint) use ($column, $nullable): void {
                // change() replaces the whole definition, so nullability has to
                // be restated or it would be silently dropped. It is read from
                // the live schema rather than hardcoded, because a client may
                // have relaxed or tightened it.
                $blueprint->string($column, MigrationIdHelper::INDEX_SAFE_LENGTH)
                    ->nullable($nullable)
                    ->change();
            });
        }
    }

    /**
     * Deliberately a no-op.
     *
     * Reversing this is lossy and cannot be done safely: once an install stores
     * uuid keys in these columns, casting them back to bigint either errors
     * (PostgreSQL: invalid input syntax for type bigint) or silently destroys
     * every non-numeric key (MySQL in non-strict mode writes 0). Rolling back
     * would also restore the exact bug this migration exists to fix, since
     * config/audit.php declares these columns as strings.
     *
     * A string column holds every value a bigint column could, so leaving the
     * wider type in place after a rollback costs nothing.
     */
    public function down(): void
    {
        // Intentionally empty. See the docblock above.
    }

    /**
     * The column's current definition, or null when there is nothing to do:
     * the table is absent, the column is absent, or its type is not an integer.
     *
     * @return array<string, mixed>|null
     */
    private function columnDefinition(string $table, string $column): ?array
    {
        if (!Schema::hasTable($table)) {
            return null;
        }

        foreach (Schema::getColumns($table) as $candidate) {
            if (($candidate['name'] ?? null) !== $column) {
                continue;
            }

            $type = strtolower((string) ($candidate['type_name'] ?? $candidate['type'] ?? ''));

            return in_array($type, self::INTEGER_TYPES, true) ? $candidate : null;
        }

        return null;
    }
};
