<?php

namespace Sopheak\Core\Triggers;

use Illuminate\Http\Request;

/**
 * Base class for Record Triggers.
 *
 * Clients can extend this class to create custom triggers with easy override capability.
 *
 * Example - Custom trigger in your application:
 * ```php
 * class MyWebhookTrigger extends RecordTriggerBase
 * {
 *     protected static function afterCreate(Request $request, string $table, mixed $context): void
 *     {
 *         // Custom logic before calling parent
 *         parent::afterCreate($request, $table, $context);
 *         // Or completely override by NOT calling parent
 *     }
 * }
 * ```
 *
 * Register in config/records/tables/your_table.php:
 * ```php
 * 'triggers' => [
 *     MyWebhookTrigger::class,
 * ],
 * ```
 */
abstract class RecordTriggerBase
{
    /**
     * Called before a record is read (list/show).
     * Override to modify the request or abort early.
     *
     * @param Request $request The incoming HTTP request
     * @param string $table The table name
     * @param array $context Additional context (filters, tenant_id, etc.)
     * @return void
     */
    public static function beforeRead(Request $request, string $table, array $context): void
    {
        // Override in subclass
    }

    /**
     * Called after records are read.
     * Override to modify response or trigger side effects.
     *
     * @param Request $request The incoming HTTP request
     * @param string $table The table name
     * @param array $context Contains 'data', 'response', 'records', 'tenant_id'
     * @return void
     */
    public static function afterRead(Request $request, string $table, array $context): void
    {
        // Override in subclass
    }

    /**
     * Called before a record is created.
     * Override to modify payload or validate.
     *
     * @param Request $request The incoming HTTP request
     * @param string $table The table name
     * @param array $context Contains 'tenant_id', 'payload'
     * @return void
     */
    public static function beforeCreate(Request $request, string $table, array $context): void
    {
        // Override in subclass
    }

    /**
     * Called after a record is created.
     *
     * @param Request $request The incoming HTTP request
     * @param string $table The table name
     * @param array $context Contains 'id', 'payload', 'tenant_id', 'data'
     * @return void
     */
    public static function afterCreate(Request $request, string $table, array $context): void
    {
        // Override in subclass
    }

    /**
     * Called before a record is updated.
     *
     * @param Request $request The incoming HTTP request
     * @param string $table The table name
     * @param array $context Contains 'id', 'payload', 'tenant_id'
     * @return void
     */
    public static function beforeUpdate(Request $request, string $table, array $context): void
    {
        // Override in subclass
    }

    /**
     * Called after a record is updated.
     *
     * @param Request $request The incoming HTTP request
     * @param string $table The table name
     * @param array $context Contains 'id', 'payload', 'tenant_id', 'old_data', 'data'
     * @return void
     */
    public static function afterUpdate(Request $request, string $table, array $context): void
    {
        // Override in subclass
    }

    /**
     * Called before a record is deleted.
     *
     * @param Request $request The incoming HTTP request
     * @param string $table The table name
     * @param array $context Contains 'id', 'tenant_id', 'record'
     * @return void
     */
    public static function beforeDelete(Request $request, string $table, array $context): void
    {
        // Override in subclass
    }

    /**
     * Called after a record is deleted.
     *
     * @param Request $request The incoming HTTP request
     * @param string $table The table name
     * @param array $context Contains 'id', 'tenant_id', 'old_data'
     * @return void
     */
    public static function afterDelete(Request $request, string $table, array $context): void
    {
        // Override in subclass
    }

    /**
     * Called before a record is restored from soft delete.
     *
     * @param Request $request The incoming HTTP request
     * @param string $table The table name
     * @param array $context Contains 'id', 'tenant_id'
     * @return void
     */
    public static function beforeRestore(Request $request, string $table, array $context): void
    {
        // Override in subclass
    }

    /**
     * Called after a record is restored from soft delete.
     *
     * @param Request $request The incoming HTTP request
     * @param string $table The table name
     * @param array $context Contains 'id', 'tenant_id', 'data'
     * @return void
     */
    public static function afterRestore(Request $request, string $table, array $context): void
    {
        // Override in subclass
    }

    /**
     * Called before force delete.
     *
     * @param Request $request The incoming HTTP request
     * @param string $table The table name
     * @param array $context Contains 'id', 'tenant_id'
     * @return void
     */
    public static function beforeForceDelete(Request $request, string $table, array $context): void
    {
        // Override in subclass
    }

    /**
     * Called after force delete.
     *
     * @param Request $request The incoming HTTP request
     * @param string $table The table name
     * @param array $context Contains 'id', 'tenant_id', 'old_data'
     * @return void
     */
    public static function afterForceDelete(Request $request, string $table, array $context): void
    {
        // Override in subclass
    }
}