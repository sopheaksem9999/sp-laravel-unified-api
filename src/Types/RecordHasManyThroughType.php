<?php

namespace Sopheak\Core\Types;

use App\Utilities\Enums\RecordRelationshipsEnum;

/**
 * This class represents a has-many-through relationship configuration between database models.
 * It is used to define relationships where a model is related to another model through an intermediate table.
 *
 * Example usage:
 * ```
 * // Invoice has many Payments through InvoicePayments
 * $relationship = new RecordHasManyThroughType(
 *     table: 'payments',                    // Target table (payments)
 *     through: 'invoice_payments',          // Pivot/intermediate table
 *     firstKey: 'invoice_id',              // Foreign key on pivot table referencing source (invoices.id)
 *     secondLocalKey: 'payment_id',        // Foreign key on pivot table referencing target (payments.id)
 *     orderBy: ['payment_date' => 'desc']  // Optional sorting
 * );
 * ```
 *
 * Database structure for the example above:
 * - invoices (id, ...)
 * - payments (id, payment_date, amount, ...)
 * - invoice_payments (invoice_id, payment_id, ...)
 */
/**
 * Class RecordHasManyThroughType.
 *
 * Represents a has-many-through relationship configuration between models.
 *
 * @property string                  $table          The target table name (e.g. 'payments')
 * @property RecordRelationshipsEnum $type           The relationship type (always HAS_MANY_THROUGH)
 * @property string                  $through        The intermediate/pivot table name (e.g. 'invoice_payments')
 * @property string                  $firstKey       The foreign key on intermediate table referencing source model (e.g. 'invoice_id')
 * @property string                  $secondKey      The primary key on target table being referenced (default: 'id')
 * @property string                  $localKey       The primary key on source model being referenced (default: 'id')
 * @property string                  $secondLocalKey The foreign key on intermediate table referencing target model (e.g. 'payment_id')
 * @property array                   $orderBy        Optional sorting configuration (default: ['date' => 'desc'])
 */
class RecordHasManyThroughType
{
    public function __construct(
        public string $table,
        public string $through,
        public string $firstKey,
        public string $secondKey = 'id',
        public string $localKey = 'id',
        public string $secondLocalKey = '',
        public array $orderBy = ['date' => 'desc'],
        public RecordRelationshipsEnum $type = RecordRelationshipsEnum::HAS_MANY_THROUGH,
    ) {}

    /**
     * Handle var_export() for configuration caching.
     * This method is required for Laravel's config:cache command.
     *
     * @param array $properties
     * @return static
     */
    public static function __set_state(array $properties): static
    {
        return new static(
            table: $properties['table'],
            through: $properties['through'],
            firstKey: $properties['firstKey'],
            secondKey: $properties['secondKey'] ?? 'id',
            localKey: $properties['localKey'] ?? 'id',
            secondLocalKey: $properties['secondLocalKey'] ?? '',
            orderBy: $properties['orderBy'] ?? ['date' => 'desc'],
            type: $properties['type'] ?? RecordRelationshipsEnum::HAS_MANY_THROUGH,
        );
    }
}
