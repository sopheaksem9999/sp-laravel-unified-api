<?php

declare(strict_types=1);

namespace Sopheak\Core\Attributes;

use Attribute;

/**
 * Declare a RecordTableType configuration directly on an Eloquent model class.
 *
 * When attribute-based discovery is active (via AttributeDiscoveryService),
 * this attribute is read and converted to a RecordTableType instance that is
 * merged with the file-based config.  File-based config always wins on conflict.
 *
 * Example:
 * ```php
 * #[RecordTable(pmsName: 'invoice', tenantColumn: 'company_id')]
 * class Invoice extends Model {}
 * ```
 *
 * @see \Sopheak\Core\Attributes\RecordRelationship
 * @see \Sopheak\Core\Services\AttributeDiscoveryService
 */
#[Attribute(Attribute::TARGET_CLASS)]
class RecordTable
{
    /**
     * @param string|null  $pmsName          Permission-system resource name (defaults to snake_case model name)
     * @param string|null  $table            Database table name (defaults to Eloquent convention)
     * @param string       $primaryKey       Primary key column (default: 'id')
     * @param bool         $hasTenantId      Whether the table carries a tenant identifier column
     * @param bool         $softDeletes      Whether the table uses soft deletes (deleted_at)
     * @param bool         $isAuthRead       Require authentication for read endpoints
     * @param bool         $isAuthWrite      Require authentication for write endpoints
     * @param bool         $canRead          Expose list / show endpoints
     * @param bool         $canCreate        Expose create endpoint
     * @param bool         $canUpdate        Expose update endpoint
     * @param bool         $canDelete        Expose delete endpoint
     * @param bool         $canUpsert        Expose upsert endpoint
     * @param bool         $disableAuditLog  Disable audit logging for this table
     * @param bool         $disableCache     Disable query caching for this table
     * @param bool         $disableBroadcast Disable broadcast events for this table
     */
    public function __construct(
        public readonly ?string $pmsName = null,
        public readonly ?string $table = null,
        public readonly string $primaryKey = 'id',
        public readonly bool $hasTenantId = false,
        public readonly bool $softDeletes = false,
        public readonly bool $isAuthRead = true,
        public readonly bool $isAuthWrite = true,
        public readonly bool $canRead = true,
        public readonly bool $canCreate = true,
        public readonly bool $canUpdate = true,
        public readonly bool $canDelete = true,
        public readonly bool $canUpsert = true,
        public readonly bool $disableAuditLog = false,
        public readonly bool $disableCache = true,
        public readonly bool $disableBroadcast = false,
    ) {}
}
