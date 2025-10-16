<?php

namespace Sopheak\Core\Services;

use Illuminate\Foundation\Bus\DispatchesJobs;
use Sopheak\Core\Enums\AuditLogEventEnum;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuditLogService
{
    /**
     * Handle audit data entry based on the provided event.
     * Only creates audit log entry if there are actual differences between old and new data.
     */
    public static function handleAuditDataEntry(AuditLogEventEnum $event, string $entityName, string $entityType, array $queryData, ?string $subject = null, ?string $recap = null): void
    {
        if (!static::isAuditEnabled()) {
            return;
        }

        $oldData = [];
        $newData = [];

        switch ($event) {
            case AuditLogEventEnum::CREATED:
                $newData = $queryData;

                break;

            case AuditLogEventEnum::UPDATED:
                $getOldAuditLogDate = static::getOldAuditLogDate($queryData['id'], $entityName);
                $oldData = null === $getOldAuditLogDate || [] === $getOldAuditLogDate ? [] : $getOldAuditLogDate;
                $newData = $queryData;

                // Check if there are actual changes for UPDATE events
                if (!static::hasDataChanges($oldData, $newData)) {
                    // No changes detected, skip audit log creation
                    return;
                }

                break;

            case AuditLogEventEnum::DELETED:
                $getOldAuditLogDate = static::getOldAuditLogDate($queryData['id'], $entityName);
                $oldData = null === $getOldAuditLogDate || [] === $getOldAuditLogDate ? [] : $getOldAuditLogDate;

                break;
        }

        // Create audit log entry only when there are changes or for CREATE/DELETE events
        static::createAuditLogEntry([
            'title' => static::getAuditTitle($event, $entityName),
            'old_data' => $oldData,
            'new_data' => $newData,
            'recap' => null != $recap ? $recap : static::generateRecap($event, $entityName, $oldData, $newData),
            'subject' => null != $subject ? $subject : static::getAuditSubject([] === $newData ? ([] !== $oldData ? $oldData : $queryData) : ($newData)),
            'entity_type' => $entityType ?? null,
            'entity_id' => $queryData['id'] ?? null,
            'event' => $event->value,
        ]);
    }

    /**
     * Create an audit log entry with proper data formatting.
     * Includes additional validation to ensure only meaningful changes are logged.
     */
    public static function createAuditLogEntry(array $data): void
    {
        if (!static::isAuditEnabled()) {
            return;
        }

        // Additional safeguard: For UPDATE events, verify there are actual changes
        if (isset($data['event']) && $data['event'] === AuditLogEventEnum::UPDATED->value) {
            $oldData = is_array($data['old_data']) ? $data['old_data'] : [];
            $newData = is_array($data['new_data']) ? $data['new_data'] : [];

            if (!static::hasDataChanges($oldData, $newData)) {
                return;
            }
        }

        // Get table name from entity type
        $tableName = static::getTableNameFromEntityType($data['entity_type']);

        // Determine changed fields for enhanced metadata
        $changedFields = static::getChangedFields($data['old_data'] ?? [], $data['new_data'] ?? []);

        // Prepare the audit log data
        $auditData = [
            'title' => $data['title'],
            'old_data' => is_array($data['old_data']) ? json_encode($data['old_data'], JSON_PRETTY_PRINT) : $data['old_data'],
            'new_data' => is_array($data['new_data']) ? json_encode($data['new_data'], JSON_PRETTY_PRINT) : $data['new_data'],
            'recap' => $data['recap'] ?? '',
            'subject' => $data['subject'] ?? '',
            'user_id' => $data['user_id'] ?? Auth::id(),
            'entity_type' => $data['entity_type'],
            'entity_id' => $data['entity_id'],
            'entity_name' => $tableName, // Use table name instead of class basename
            'event' => $data['event'],
            'metadata' => json_encode(static::getAuditMetadata($changedFields, $data['old_data'] ?? [], $data['new_data'] ?? [], $data['entity_type'] ?? null, $data['entity_id'] ?? null)),
            'created_at' => now()->toDateTimeString(),
            'updated_at' => null,
        ];

        DB::table('audit_logs')->insert($auditData);
    }

    /**
     * Get enhanced audit metadata with field-level changes and timestamps.
     */
    public static function getAuditMetadata(array $changedFields = [], array $oldData = [], array $newData = [], ?string $entityType = null, mixed $entityId = null): array
    {
        $currentTime = now()->toISOString();
        $userId = Auth::id();

        $metadata = [
            'field_changes' => [],
            'change_summary' => [
                'total_fields_changed' => count($changedFields),
                'change_type' => 'update',
                'user_agent' => request()->userAgent() ?? 'unknown',
                'ip_address' => request()->ip() ?? 'unknown',
                'session_id' => session()->getId() ?? null,
            ],
        ];

        // Add field-level change tracking with enhanced structure
        foreach ($changedFields as $changedField) {
            $oldValue = $oldData[$changedField] ?? null;
            $newValue = $newData[$changedField] ?? null;

            // Special handling for items array
            if ('items' === $changedField && is_array($oldValue) && is_array($newValue)) {
                $itemChanges = static::getItemChanges($oldValue, $newValue, $entityType, $entityId);
                $metadata['field_changes'] = array_merge($metadata['field_changes'], $itemChanges);
            } else {
                // Get previous change timestamp and count for this field
                $previousChangeData = static::getFieldPreviousChange($entityType, $entityId, $changedField);

                $metadata['field_changes'][$changedField] = [
                    'changed_at' => $currentTime,
                    'previous_change' => $previousChangeData['previous_change'],
                    'change_count' => $previousChangeData['change_count'] + 1,
                ];
            }
        }

        return $metadata;
    }

    /**
     * Log user authentication events.
     */
    public static function authEvent(AuditLogEventEnum $auditLogEventEnum, array $data = []): void
    {
        if (!static::isAuditEnabled()) {
            return;
        }

        $userModel = config('audit.user_model', 'App\Models\User');
        $auditLogJobClass = config('audit.audit_log_job', 'App\Jobs\AuditLogJob');

        if (static::isAuditQueueEnabled()) {
            $auditLogJobClass::dispatch(
                event: $auditLogEventEnum,
                entityName: 'users',
                entityType: $userModel,
                queryData: $data
            );
        } else {
            static::handleAuditDataEntry(
                event: $auditLogEventEnum,
                entityName: 'users',
                entityType: $userModel,
                queryData: $data
            );
        }
    }

    /**
     * Insert audit log entry.
     *
     * @param array $queryData
     */
    public static function insertAuditLog(AuditLogEventEnum $auditLogEventEnum, string $entityClass, array|object $queryData = [], ?string $subject = '', ?string $recap = ''): void
    {
        if (!static::isAuditEnabled()) {
            return;
        }

        $entityName = AuditLogService::getTableNameFromEntityType($entityClass);
        $entityType = $entityClass;

        // Remove timestamp fields from nested arrays before comparison
        $queryData = static::removeTimestampFields($queryData);

        // Handle audit logging based on queue configuration
        if (static::isAuditQueueEnabled()) {
            $auditLogJobClass = config('audit.audit_log_job', 'App\Jobs\AuditLogJob');
            $auditLogJobClass::dispatch(
                event: $auditLogEventEnum,
                entityName: $entityName,
                entityType: $entityType,
                queryData: $queryData,
                subject: $subject,
                recap: $recap,
            );

            return;
        }

        // Process audit log immediately if queue is disabled
        static::handleAuditDataEntry(
            event: $auditLogEventEnum,
            entityName: $entityName,
            entityType: $entityType,
            queryData: $queryData,
            subject: $subject,
            recap: $recap,
        );
    }

    public static function getOldAuditLogDate(int|string $entityId, string $entityName): ?array
    {
        $data = DB::table('audit_logs')->where('entity_id', $entityId)->where('entity_name', $entityName)->orderByDesc('created_at')->first();

        if (!empty($data->new_data)) {
            return json_decode((string) $data->new_data, true);
        }

        return null;
    }

    /**
     * Get audit statistics.
     */
    public static function getAuditStats(array $filters = []): array
    {
        $auditLogModel = config('audit.audit_log_model', 'App\Models\AuditLog');
        $query = $auditLogModel::query();

        // Apply date filter if provided
        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $query->whereBetween('created_at', [
                Carbon::parse($filters['start_date'])->startOfDay(),
                Carbon::parse($filters['end_date'])->endOfDay(),
            ]);
        }

        return [
            'total_logs' => $query->count(),
            'actions_breakdown' => $query->groupBy('event')
                ->selectRaw('event, count(*) as count')
                ->pluck('count', 'event')
                ->toArray(),
            'top_users' => $query->whereNotNull('user_id')
                ->with('user')
                ->groupBy('user_id')
                ->selectRaw('user_id, count(*) as count')
                ->orderByDesc('count')
                ->limit(10)
                ->get()
                ->map(fn ($item): array => [
                    'user_name' => $item->user?->name ?? 'Unknown',
                    'count' => $item->count,
                ])
                ->toArray(),
            'entity_types' => $query->whereNotNull('entity_type')
                ->groupBy('entity_type')
                ->selectRaw('entity_type, count(*) as count')
                ->orderByDesc('count')
                ->pluck('count', 'entity_type')
                ->toArray(),
        ];
    }

    /**
     * Get audit logs for a specific entity.
     */
    public static function getEntityAuditLogs(string $entityType, mixed $entityId, int $limit = 50): Collection
    {
        $auditLogModel = config('audit.audit_log_model', 'App\Models\AuditLog');
        return $auditLogModel::with(['user', 'module'])
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
        ;
    }

    /**
     * Clean up old audit logs based on retention policy.
     *
     * @return int Number of deleted records
     */
    public static function cleanupOldLogs(int $daysToKeep = 365): int
    {
        $cutoffDate = Carbon::now()->subDays($daysToKeep);
        $auditLogModel = config('audit.audit_log_model', 'App\Models\AuditLog');

        return $auditLogModel::where('created_at', '<', $cutoffDate)->delete();
    }

    /**
     * Get the audit subject (usually a human-readable identifier).
     */
    public static function getAuditSubject(array $data): string
    {
        // Try common identifier fields
        $identifierFields = ['name', 'title', 'ref_number', 'account_name', 'entity'];

        $label = '';
        foreach ($identifierFields as $identifierField) {
            if (isset($data[$identifierField])) {
                $label = (string) $data[$identifierField];

                continue;
            }
        }

        // Fallback to model name with ID
        return $label;
    }

    /**
     * Get the audit log title for the given action.
     *
     * @param string $auditLogEventEnum The action performed
     */
    public static function getAuditTitle(AuditLogEventEnum $auditLogEventEnum, string $entityName): string
    {
        $eventLabel = '';
        match ($auditLogEventEnum) {
            AuditLogEventEnum::CREATED => $eventLabel = 'created',
            AuditLogEventEnum::UPDATED => $eventLabel = 'updated',
            AuditLogEventEnum::DELETED => $eventLabel = 'deleted',
            AuditLogEventEnum::LOGIN => $eventLabel = 'logged in',
            AuditLogEventEnum::LOGOUT => $eventLabel = 'logged out',
            AuditLogEventEnum::FAILED_LOGIN => $eventLabel = 'failed to log in',
            default => $eventLabel = '',
        };

        $entityLabel = '';
        match ($auditLogEventEnum) {
            AuditLogEventEnum::LOGIN => $entityLabel = '',
            AuditLogEventEnum::LOGOUT => $entityLabel = '',
            AuditLogEventEnum::FAILED_LOGIN => $entityLabel = '',
            default => $entityLabel = $entityName,
        };

        return ucfirst($eventLabel).' '.static::getEntityLabel($entityLabel);
    }

    public static function getEntityLabel(string $label): string
    {
        match ($label) {
            'estimates' => $label = 'Estimate & SO',
            'sale_orders' => $label = 'Sale Receipt',
            'purchase_orders' => $label = 'Purchase Request',
            'receive_notes' => $label = 'Receive Note',
            'inter_transfers' => $label = 'Inter Transfer Request',
            'transfers' => $label = 'Direct Transfer',
            'inventory_valuations' => $label = 'Inventory Movement Detail',
            'inventory_summaries' => $label = 'Inventory Summaries',
            default => $label,
        };

        $label = str_replace('_', ' ', $label);

        return ucfirst($label);
    }

    /**
     * Generate a recap for an audit log entry.
     */
    /**
     * Generate a human-readable recap of audit log events.
     *
     * @param AuditLogEventEnum $auditLogEventEnum The type of audit event
     * @param null|string       $entityName        The name of the entity being audited
     * @param null|array        $oldData           The previous state of the entity
     * @param null|array        $newData           The new state of the entity
     *
     * @return string A formatted recap string
     */
    public static function generateRecap(AuditLogEventEnum $auditLogEventEnum, ?string $entityName = '', ?array $oldData = [], ?array $newData = []): string
    {
        switch ($auditLogEventEnum) {
            case AuditLogEventEnum::CREATED:
                return '';

            case AuditLogEventEnum::UPDATED:
                // Enhanced recap for specific entities
                $changes = array_reduce(array_keys($newData ?? []), function (array $acc, $key) use ($oldData, $newData): array {
                    if (!isset($oldData[$key])) {
                        return $acc;
                    }

                    if ($oldData[$key] !== $newData[$key]) {
                        $acc[$key] = [
                            'old' => $oldData[$key],
                            'new' => $newData[$key],
                        ];
                    }

                    return $acc;
                }, []);

                // on this condition entity name is table name
                if (null !== $entityName && '' !== $entityName && '0' !== $entityName && in_array(
                    $entityName,
                    [
                        'estimates',
                        'delivery_notes',
                        'invoices',
                        'sale_orders',
                        'pos',
                        'purchase_orders',
                        'receive_notes',
                        'bills',
                        'inter_transfers',
                        'transfers',
                    ]
                )) {
                    return self::generateDetailedUpdateRecap($entityName, $changes);
                }

                return '';

            case AuditLogEventEnum::DELETED:
                return '';

            case AuditLogEventEnum::LOGIN:
                return '';

            case AuditLogEventEnum::LOGOUT:
                return '';

            case AuditLogEventEnum::FAILED_LOGIN:
                return '';

            default:
                return '';
        }
    }

    /**
     * Get table name from entity type.
     */
    public static function getTableNameFromEntityType(string $entityType): string
    {
        // Fallback to pluralized snake_case of class basename
        $className = class_basename($entityType);

        return Str::snake(Str::plural($className));
    }

    /**
     * handle map query data.
     */
    public static function handleMapperQueryData(array $queryData): array
    {
        // Convert relationship array to comma-separated ref_numbers string
        if (isset($queryData['relationship']) && is_array($queryData['relationship'])) {
            $refNumbers = collect($queryData['relationship'])
                ->pluck('ref_number')
                ->filter()
                ->implode(', ')
            ;

            $queryData['relationship'] = $refNumbers;
        }

        return $queryData;
    }

    /**
     * Check if audit logging is enabled.
     */
    public static function isAuditEnabled(): bool
    {
        return config('audit.enabled', true);
    }

    public static function isAuditQueueEnabled(): bool
    {
        return config('audit.queue_enabled', false);
    }

    /**
     * Check if an event should be excluded from logging.
     */
    public static function isEventExcluded(string $event): bool
    {
        $excludedEvents = config('audit.excluded_events', []);

        return in_array($event, $excludedEvents);
    }

    /**
     * Get field timeline for a specific entity and field.
     */
    public static function getFieldTimeline(string $entityType, mixed $entityId, string $field, int $limit = 10): array
    {
        $auditLogModel = config('audit.audit_log_model', 'App\Models\AuditLog');
        $logs = $auditLogModel::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->whereRaw("JSON_EXTRACT(metadata, '$.field_changes.{$field}') IS NOT NULL")
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
        ;

        return $logs->map(function ($log) use ($field): array {
            $metadata = json_decode((string) $log->metadata, true);
            $fieldChange = $metadata['field_changes'][$field] ?? null;

            return [
                'id' => $log->id,
                'changed_at' => $fieldChange['changed_at'] ?? $log->created_at,
                'old_value' => $fieldChange['old_value'] ?? null,
                'new_value' => $fieldChange['new_value'] ?? null,
                'change_type' => $fieldChange['change_type'] ?? 'unknown',
                'data_type' => $fieldChange['data_type'] ?? 'unknown',
                'user_name' => $metadata['user_name'] ?? 'Unknown',
                'event' => $log->event,
            ];
        })->toArray();
    }

    /**
     * Get field statistics for a specific entity and field.
     */
    public static function getFieldStats(string $entityType, mixed $entityId, string $field): array
    {
        $auditLogModel = config('audit.audit_log_model', 'App\Models\AuditLog');
        $logs = $auditLogModel::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->whereRaw("JSON_EXTRACT(metadata, '$.field_changes.{$field}') IS NOT NULL")
            ->get()
        ;

        $totalChanges = $logs->count();
        $firstChange = $logs->sortBy('created_at')->first();
        $lastChange = $logs->sortByDesc('created_at')->first();

        $changesByUser = $logs->groupBy(function ($log) {
            $metadata = (string) $log->metadata;
            $metadata = json_decode($metadata, true);

            return $metadata['user_name'] ?? 'Unknown';
        })->map->count();

        return [
            'total_changes' => $totalChanges,
            'first_changed_at' => $firstChange?->created_at,
            'last_changed_at' => $lastChange?->created_at,
            'changes_by_user' => $changesByUser->toArray(),
            'field_name' => $field,
        ];
    }

    /**
     * Get item-level changes for items array.
     */
    private static function getItemChanges(array $oldItems, array $newItems, ?string $entityType = null, mixed $entityId = null): array
    {
        $currentTime = now()->toISOString();
        $itemChanges = [];

        // Create lookup arrays by item ID
        $oldItemsById = [];
        $newItemsById = [];

        foreach ($oldItems as $item) {
            if (isset($item['id'])) {
                $oldItemsById[$item['id']] = $item;
            }
        }

        foreach ($newItems as $item) {
            if (isset($item['id'])) {
                $newItemsById[$item['id']] = $item;
            }
        }

        // Check for modified items
        foreach ($newItemsById as $itemId => $newItem) {
            if (isset($oldItemsById[$itemId])) {
                $oldItem = $oldItemsById[$itemId];

                // Compare each field in the item
                foreach ($newItem as $fieldName => $newValue) {
                    $oldValue = $oldItem[$fieldName] ?? null;

                    if (static::valuesAreDifferent($oldValue, $newValue)) {
                        $fieldKey = "items.{$itemId}.{$fieldName}";
                        $previousChangeData = static::getFieldPreviousChange($entityType, $entityId, $fieldKey);

                        $itemChanges[$fieldKey] = [
                            'changed_at' => $currentTime,
                            'previous_change' => $previousChangeData['previous_change'],
                            'change_count' => $previousChangeData['change_count'] + 1,
                        ];
                    }
                }
            }
        }

        // Check for added items
        foreach (array_keys($newItemsById) as $itemId) {
            if (!isset($oldItemsById[$itemId])) {
                $fieldKey = "items.{$itemId}.added";
                $previousChangeData = static::getFieldPreviousChange($entityType, $entityId, $fieldKey);

                $itemChanges[$fieldKey] = [
                    'changed_at' => $currentTime,
                    'previous_change' => $previousChangeData['previous_change'],
                    'change_count' => $previousChangeData['change_count'] + 1,
                ];
            }
        }

        // Check for deleted items
        foreach (array_keys($oldItemsById) as $itemId) {
            if (!isset($newItemsById[$itemId])) {
                $fieldKey = "items.{$itemId}.deleted";
                $previousChangeData = static::getFieldPreviousChange($entityType, $entityId, $fieldKey);

                $itemChanges[$fieldKey] = [
                    'changed_at' => $currentTime,
                    'previous_change' => $previousChangeData['previous_change'],
                    'change_count' => $previousChangeData['change_count'] + 1,
                ];
            }
        }

        return $itemChanges;
    }

    /**
     * Get previous change information for a specific field.
     */
    private static function getFieldPreviousChange(?string $entityType = null, mixed $entityId = null, ?string $field = null): array
    {
        if (null === $entityType || '' === $entityType || '0' === $entityType || !$entityId || (null === $field || '' === $field || '0' === $field)) {
            return [
                'previous_change' => null,
                'change_count' => 0,
                'previous_user' => null,
            ];
        }

        $auditLogModel = config('audit.audit_log_model', 'App\Models\AuditLog');
        $previousLog = $auditLogModel::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->whereRaw('JSON_EXTRACT(metadata, "$.field_changes.\"'.$field.'\"") IS NOT NULL')
            ->orderBy('created_at', 'desc')
            ->first()
        ;

        $totalChanges = $auditLogModel::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->whereRaw('JSON_EXTRACT(metadata, "$.field_changes.\"'.$field.'\"") IS NOT NULL')
            ->count()
        ;

        return [
            'previous_change' => $previousLog ? $previousLog->created_at->toISOString() : null,
            'change_count' => $totalChanges,
            'previous_user' => $previousLog ? 'user_'.$previousLog->user_id : null,
        ];
    }

    /**
     * Generate detailed update recap for estimates, delivery notes, and invoices.
     */
    private static function generateDetailedUpdateRecap(string $entityName, array $changes): string
    {
        $recap = [];
        // Handle main field changes
        $mainFieldChanges = self::formatMainFieldChanges($changes);
        if ('' !== $mainFieldChanges && '0' !== $mainFieldChanges) {
            $recap[] = "{$mainFieldChanges}, ";
        }

        // Handle item changes
        $itemChanges = self::formatItemChanges($changes);
        if ([] !== $itemChanges) {
            $recap = array_merge($recap, $itemChanges);
        }

        $label = [] === $recap ? static::getEntityLabel($entityName) : implode(', ', $recap);

        return $label;
    }

    /**
     * Format main field changes (totals, quantities, relationships, etc.).
     */
    private static function formatMainFieldChanges(array $changes): string
    {
        // Relationship field mappings (cleaned up duplicates)
        $fieldMappings = [
            'customer_name' => 'Customer',
            'customer_attended_name' => 'Customer Attended',
            'bank_account_name' => 'Bank Account',
            'bank_name' => 'Bank',
            'class_name' => 'Class',
            'location_name' => 'Location',
            'term_name' => 'Term',
            'vendor_name' => 'Vendor',
            'warehouse_name' => 'Warehouse',
            'warehouse' => 'Warehouse',
            'from_warehouse' => 'From Warehouse',
            'to_warehouse' => 'To Warehouse',
            'ref_number' => 'Reference Number',
            'private_note' => 'Private Note',
            'customer_memo' => 'Customer Memo',
            'address' => 'Address',
            'date' => 'Date',
            'due_date' => 'Due Date',
            'total_amount' => 'Total Amount',
        ];

        $formattedChanges = [];
        $uniqueLabels = [];

        // Handle fields and ensure unique labels
        foreach ($fieldMappings as $field => $label) {
            if (isset($changes[$field]) && !in_array($label, $uniqueLabels)) {
                $formattedChanges[] = $label;
                $uniqueLabels[] = $label;
            }
        }

        return implode(', ', $formattedChanges);
    }

    /**
     * Format item addition/removal changes.
     *
     * @param array $changes The changes array containing item modifications
     *
     * @return array Array of formatted item change descriptions
     */
    private static function formatItemChanges(array $changes): array
    {
        $itemChanges = [];

        // Check if items changes exist and is an array
        if (!isset($changes['items']) || !is_array($changes['items'])) {
            return $itemChanges;
        }

        // Extract old and new item arrays
        $oldItems = $changes['items']['old'] ?? [];
        $newItems = $changes['items']['new'] ?? [];

        // Ensure both are arrays
        if (!is_array($oldItems) || !is_array($newItems)) {
            return $itemChanges;
        }

        // Create lookup arrays by item ID for easier comparison
        $oldItemsById = [];
        foreach ($oldItems as $item) {
            if (isset($item['id'])) {
                $oldItemsById[$item['id']] = $item;
            }
        }

        $newItemsById = [];
        foreach ($newItems as $item) {
            if (isset($item['id'])) {
                $newItemsById[$item['id']] = $item;
            }
        }

        // Find added items (exist in new but not in old)
        $addedItemCount = 0;
        foreach ($newItemsById as $id => $newItem) {
            if (!isset($oldItemsById[$id])) {
                ++$addedItemCount;
            }
        }

        // Add summary for added items
        if ($addedItemCount > 0) {
            $itemChanges[] = 1 == $addedItemCount ? "Added {$addedItemCount} line product" : "Added {$addedItemCount} line products";
        }

        // Find removed items (exist in old but not in new)
        $removedItemCount = 0;
        foreach ($oldItemsById as $id => $oldItem) {
            if (!isset($newItemsById[$id])) {
                ++$removedItemCount;
            }
        }

        // Add summary for removed items
        if ($removedItemCount > 0) {
            if (1 == $removedItemCount) {
                $itemChanges[] = "Deleted {$removedItemCount} line product";
            } else {
                $itemChanges[] = "Deleted {$removedItemCount} line products";
            }
        }

        // Find modified items (exist in both but with changes)
        $modifiedItemCount = 0;
        foreach ($oldItemsById as $id => $oldItem) {
            if (isset($newItemsById[$id])) {
                $newItem = $newItemsById[$id];
                $hasChanges = false;

                // Check if product_id changed
                if (isset($oldItem['product_id'], $newItem['product_id'])
                    && $oldItem['product_id'] !== $newItem['product_id']) {
                    $hasChanges = true;
                }

                // Check quantity changes
                if (!$hasChanges && isset($oldItem['quantity'], $newItem['quantity'])
                    && (float) $oldItem['quantity'] !== (float) $newItem['quantity']) {
                    $hasChanges = true;
                }

                // Check price changes
                if (!$hasChanges && isset($oldItem['price'], $newItem['price'])
                    && (float) $oldItem['price'] !== (float) $newItem['price']) {
                    $hasChanges = true;
                }

                // Count modified item if any changes detected
                if ($hasChanges) {
                    ++$modifiedItemCount;
                }
            }
        }

        // Add summary for modified items
        if ($modifiedItemCount > 0) {
            if (1 == $modifiedItemCount) {
                $itemChanges[] = "Modified {$modifiedItemCount} line product";
            } else {
                $itemChanges[] = "Modified {$modifiedItemCount} line products";
            }
        }

        return $itemChanges;
    }

    /**
     * Check if there are actual data changes between old and new data.
     *
     * @param array $oldData The previous state of the data
     * @param array $newData The new state of the data
     *
     * @return bool True if there are changes, false otherwise
     */
    private static function hasDataChanges(array $oldData, array $newData): bool
    {
        // If both arrays are empty, no changes
        if ([] === $oldData && [] === $newData) {
            return false;
        }

        // If one is empty and the other is not, there are changes
        if ([] === $oldData || [] === $newData) {
            return true;
        }

        // Use existing getChangedFields method to detect changes
        $changedFields = static::getChangedFields($oldData, $newData);

        // Return true if there are any changed fields
        return [] !== $changedFields;
    }

    /**
     * Get changed fields between old and new data.
     */
    private static function getChangedFields(array $oldData, array $newData): array
    {
        $changedFields = [];

        // Get all unique keys from both arrays
        $allKeys = array_unique(array_merge(array_keys($oldData), array_keys($newData)));

        foreach ($allKeys as $allKey) {
            $oldValue = $oldData[$allKey] ?? null;
            $newValue = $newData[$allKey] ?? null;

            // Compare values (handle different data types)
            if (static::valuesAreDifferent($oldValue, $newValue)) {
                $changedFields[] = $allKey;
            }
        }

        return $changedFields;
    }

    /**
     * Check if two values are different (handles arrays, objects, etc.).
     *
     * @param mixed $oldValue
     * @param mixed $newValue
     */
    private static function valuesAreDifferent($oldValue, $newValue): bool
    {
        // Handle null values
        if (null === $oldValue && null === $newValue) {
            return false;
        }

        if (null === $oldValue || null === $newValue) {
            return true;
        }

        // Handle arrays
        if (is_array($oldValue) && is_array($newValue)) {
            return json_encode($oldValue) !== json_encode($newValue);
        }

        // Handle objects
        if (is_object($oldValue) && is_object($newValue)) {
            return json_encode($oldValue) !== json_encode($newValue);
        }

        // Handle numeric values (avoid type juggling issues)
        if (is_numeric($oldValue) && is_numeric($newValue)) {
            return (float) $oldValue !== (float) $newValue;
        }

        // Default comparison
        return $oldValue !== $newValue;
    }

    /**
     * Get the data type of a field value.
     *
     * @param mixed $oldValue
     * @param mixed $newValue
     */
    private static function getFieldDataType($oldValue, $newValue): string
    {
        $value = $newValue ?? $oldValue;

        if (is_null($value)) {
            return 'null';
        }

        if (is_bool($value)) {
            return 'boolean';
        }

        if (is_int($value)) {
            return 'integer';
        }

        if (is_float($value)) {
            return 'float';
        }

        if (is_array($value)) {
            return 'array';
        }

        if (is_object($value)) {
            return 'object';
        }

        return 'string';
    }

    /**
     * Get the type of change that occurred.
     *
     * @param mixed $oldValue
     * @param mixed $newValue
     */
    private static function getChangeType($oldValue, $newValue): string
    {
        if (null === $oldValue && null !== $newValue) {
            return 'created';
        }

        if (null !== $oldValue && null === $newValue) {
            return 'deleted';
        }

        if (null !== $oldValue && null !== $newValue) {
            return 'updated';
        }

        return 'unchanged';
    }

    /**
     * Remove timestamp fields from data arrays recursively.
     * Removes 'created_at', 'updated_at', and 'deleted_at' from nested arrays.
     *
     * @param array $data The data array to process
     *
     * @return array The processed array with timestamp fields removed
     */
    private static function removeTimestampFields(array $data): array
    {
        $timestampFields = ['created_at', 'updated_at', 'deleted_at'];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                // Recursively process nested arrays
                $data[$key] = static::removeTimestampFields($value);
            }
        }

        // Remove timestamp fields from current level
        foreach ($timestampFields as $timestampField) {
            unset($data[$timestampField]);
        }

        return $data;
    }
}
