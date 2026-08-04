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
     */
    public static function morph(Blueprint $table, string $column): ColumnDefinition
    {
        return $table->string($column);
    }

    private static function isUuid(): bool
    {
        return RecordConfigService::idType() === 'uuid';
    }
}
