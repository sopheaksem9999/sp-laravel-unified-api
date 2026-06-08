<?php

declare(strict_types=1);

namespace Sopheak\Core\Attributes;

use Attribute;

/**
 * Declare a lifecycle trigger on an Eloquent model method for use by the dynamic CRUD system.
 *
 * Multiple #[Trigger] attributes may be placed on the same class or method.
 * They are collected by AttributeDiscoveryService and merged into the
 * RecordTableType trigger arrays (e.g., beforeRead, afterCreate).
 *
 * Example:
 * ```php
 * class UserTriggers
 * {
 *     #[RecordTrigger('beforeRead', description: 'Auto-filter users by company_id')]
 *     public static function enforceCompanyFilter(Request $request, string $table, array $context): Request { ... }
 *
 *     #[RecordTrigger('afterRead', description: 'Log user view')]
 *     public static function logView(Request $request, string $table, array $context): void { ... }
 *
 *     #[RecordTrigger('beforeCreate', description: 'Hash password')]
 *     public static function hashPassword(Request $request, string $table, array $context): array { ... }
 *
 *     #[RecordTrigger('afterCreate', description: 'Send welcome email')]
 *     public static function sendWelcomeEmail(Request $request, string $table, array $context): void { ... }
 *
 *     #[RecordTrigger('beforeUpdate', description: 'Prevent email change')]
 *     public static function preventEmailChange(Request $request, string $table, array $context): array { ... }
 *
 *     #[RecordTrigger('afterUpdate', description: 'Notify profile update')]
 *     public static function notifyUpdate(Request $request, string $table, array $context): void { ... }
 *
 *     #[RecordTrigger('beforeDelete', description: 'Check active subscriptions')]
 *     public static function checkSubscriptions(Request $request, string $table, array $context): void { ... }
 *
 *     #[RecordTrigger('afterDelete', description: 'Cleanup related files')]
 *     public static function cleanupFiles(Request $request, string $table, array $context): void { ... }
 *
 *     #[RecordTrigger('beforeRestore', description: 'Check restore limits')]
 *     public static function checkRestoreLimits(Request $request, string $table, array $context): void { ... }
 *
 *     #[RecordTrigger('afterRestore', description: 'Notify restore')]
 *     public static function notifyRestore(Request $request, string $table, array $context): void { ... }
 * }
 * ```
 *
 * @see \Sopheak\Core\Attributes\RecordTable
 * @see \Sopheak\Core\Services\AttributeDiscoveryService
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class RecordTrigger
{
    public function __construct(
        public string $hook, // e.g., 'beforeRead', 'afterCreate'
        public ?string $description = null
    ) {}
}
