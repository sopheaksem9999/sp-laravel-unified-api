<?php

declare(strict_types=1);

namespace Sopheak\Core\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Sopheak\Core\Services\RecordConfigService;

/**
 * Renders id columns for the package's own migrations.
 *
 * Three distinct column roles exist, and conflating them is the bug this
 * class prevents:
 *
 * - primary()  the pk of sp_permissions / sp_roles, governed by record.id_type
 * - foreign()  a reference to one of those pks, must match their type
 * - morph()    a reference to an arbitrary CLIENT model, whose key type is not
 *              knowable from package config, so it is always a string
 *
 * Surrogate pivot ids and sp_audit_logs.id use none of these. They are always
 * bigIncrements because nothing references them and their insert paths supply
 * no id value.
 */
class MigrationIdHelper
{
    /**
     * Length for string columns that take part in a composite index.
     *
     * 191 is the conventional Laravel value: under utf8mb4 (4 bytes per
     * character) MySQL's InnoDB index limit of 3072 bytes leaves room for four
     * such columns, and older row formats (COMPACT / REDUNDANT) cap a single
     * index part at 767 bytes, which a varchar(255) utf8mb4 column (1020 bytes)
     * already exceeds on its own.
     *
     * A key value is at most 36 characters (uuid) or 20 (bigint), so 191 is
     * never a real constraint on the data.
     */
    public const INDEX_SAFE_LENGTH = 191;

    /**
     * Primary key for a table governed by record.id_type.
     */
    public static function primary(Blueprint $table, string $column = 'id'): void
    {
        if (self::isUuid()) {
            $table->uuid($column)->primary();

            return;
        }

        $table->bigIncrements($column);
    }

    /**
     * Foreign key referencing a governed primary key.
     *
     * Returned so callers can chain ->index() / ->nullable().
     */
    public static function foreign(Blueprint $table, string $column): ColumnDefinition
    {
        return self::isUuid()
            ? $table->uuid($column)
            : $table->unsignedBigInteger($column);
    }

    /**
     * Reference to an arbitrary client-owned model.
     *
     * Always a string: it must hold a uuid or an integer key with equal ease,
     * and the package cannot know which the client uses. This mirrors the
     * existing sp_attachment_links.record_id column.
     *
     * Bounded to INDEX_SAFE_LENGTH by default because every such column this
     * package declares is part of an index, and an unbounded varchar(255)
     * under utf8mb4 costs 1020 bytes of the 3072-byte InnoDB index budget.
     * Pass null for an unindexed column that wants Laravel's default 255.
     */
    public static function morph(Blueprint $table, string $column, ?int $length = self::INDEX_SAFE_LENGTH): ColumnDefinition
    {
        return $table->string($column, $length);
    }

    private static function isUuid(): bool
    {
        return RecordConfigService::idType() === 'uuid';
    }
}
