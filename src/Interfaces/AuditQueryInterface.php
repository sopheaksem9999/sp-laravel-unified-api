<?php

declare(strict_types=1);

namespace Sopheak\Core\Interfaces;

/**
 * Interface for controllers that provide custom audit query functionality.
 *
 * Controllers implementing this interface can define custom queries for audit logging
 * that include related data and formatted relationships for better audit trails.
 */
interface AuditQueryInterface
{
    /**
     * Get audit query data with formatted relationship information.
     *
     * This method should return a comprehensive array of data for audit logging,
     * including related model data and formatted relationship strings.
     *
     * @param int $id The ID of the record to query
     * @return array Formatted audit data including relationships
     */
    public static function getAuditQuery(int|string $id): array;

    // Optional methods - implemented automatically by HasAuditQueryTrait trait if missing
    // /**
    //  * Get the entity name for audit logging.
    //  *
    //  * @return string The entity name (e.g., 'invoices', 'customers')
    //  */
    // public static function getAuditEntityName(): string;
    //
    // /**
    //  * Get the entity class for audit logging.
    //  *
    //  * @return string The fully qualified class name of the entity
    //  */
    // public static function getAuditEntityClass(): string;
}
