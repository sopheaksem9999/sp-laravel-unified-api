<?php

namespace Sopheak\Core\Services;

use Sopheak\Core\Enums\AuditLogEventEnum;
use Sopheak\Core\Jobs\AuditLogJob;
use Carbon\Carbon;
use Illuminate\Support\Collection;
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
            'entity_type' => $tableName,
            'entity_id' => $data['entity_id'],
            'entity_name' => $tableName,
            'event' => $data['event'],
            'metadata' => null,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => null,
        ];

        if (!in_array($data['event'], [AuditLogEventEnum::LOGIN->value, AuditLogEventEnum::LOGOUT->value, AuditLogEventEnum::FAILED_LOGIN->value])) {
            $auditData['metadata'] = json_encode(static::getAuditMetadata($changedFields, $data['old_data'] ?? [], $data['new_data'] ?? [], $data['entity_type'] ?? null, $data['entity_id'] ?? null));
        }

        DB::table('audit_logs')->insert($auditData);
    }

    /**
     * Get enhanced audit metadata with field-level changes and timestamps.
     */
    public static function getAuditMetadata(array $changedFields = [], array $oldData = [], array $newData = [], ?string $entityType = null, mixed $entityId = null): array
    {
        $currentTime = now()->toISOString();
        $userId = Auth::id();
        $userName = Auth::user()?->name ?? 'Unknown';

        $metadata = [
            'field_changes' => [],
            'change_summary' => [
                'total_fields_changed' => count($changedFields),
                'change_type' => 'update',
                'user_agent' => request()->userAgent() ?? 'unknown',
                'ip_address' => request()->ip() ?? 'unknown',
                'session_id' => session()->getId() ?? null,
            ],
            'user_id' => $userId,
            'user_name' => $userName,
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
                    'old_value' => $oldValue,
                    'new_value' => $newValue,
                    'data_type' => static::getFieldDataType($oldValue, $newValue),
                    'change_type' => static::getChangeType($oldValue, $newValue),
                    'changed_at' => $currentTime,
                    'previous_change' => $previousChangeData['previous_change'],
                    'change_count' => $previousChangeData['change_count'] + 1,
                    'previous_user' => $previousChangeData['previous_user'],
                ];
            }
        }

        return $metadata;
    }

    /**
     * Log user authentication events.
     */
    public static function authEvent(AuditLogEventEnum $event, ?array $data = []): void
    {
        if (!static::isAuditEnabled()) {
            return;
        }

        $userModel = config('auth.providers.users.model', 'App\\Models\\User');
        $auditLogJobClass = config('audit.audit_log_job', AuditLogJob::class);

        $entityName = static::getTableNameFromEntityType($userModel);

        if (static::isAuditQueueEnabled() && class_exists($auditLogJobClass) && method_exists($auditLogJobClass, 'dispatch')) {
            $auditLogJobClass::dispatch(
                event: $event,
                entityName: $entityName,
                entityType: $entityName,
                queryData: $data
            );
        } else {
            static::handleAuditDataEntry(
                event: $event,
                entityName: $entityName,
                entityType: $entityName,
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
        $entityType = $entityName;

        // Remove timestamp fields from nested arrays before comparison
        $queryData = static::removeTimestampFields($queryData);

        // Handle audit logging based on queue configuration
        if (static::isAuditQueueEnabled()) {
            $auditLogJobClass = config('audit.audit_log_job', AuditLogJob::class);
            if (!class_exists($auditLogJobClass) || !method_exists($auditLogJobClass, 'dispatch')) {
                static::handleAuditDataEntry(
                    event: $auditLogEventEnum,
                    entityName: $entityName,
                    entityType: $entityType,
                    queryData: $queryData,
                    subject: $subject,
                    recap: $recap,
                );

                return;
            }

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
        $query = DB::table('audit_logs');

        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $query->whereBetween('created_at', [
                Carbon::parse($filters['start_date'])->startOfDay(),
                Carbon::parse($filters['end_date'])->endOfDay(),
            ]);
        }

        if (!empty($filters['entity_type'])) {
            $query->where('entity_type', $filters['entity_type']);
        }

        if (!empty($filters['entity_id'])) {
            $query->where('entity_id', $filters['entity_id']);
        }

        if (!empty($filters['event'])) {
            $query->where('event', $filters['event']);
        }

        $baseQuery = clone $query;

        $totalLogs = (clone $baseQuery)->count();

        $actionsBreakdown = (clone $baseQuery)
            ->select('event', DB::raw('count(*) as count'))
            ->groupBy('event')
            ->pluck('count', 'event')
            ->toArray();

        $topUsersQuery = (clone $baseQuery)
            ->whereNotNull('user_id')
            ->select('user_id', DB::raw('count(*) as count'))
            ->groupBy('user_id')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        $userIds = $topUsersQuery->pluck('user_id')->filter()->unique()->values();
        $userNames = [];

        if ($userIds->isNotEmpty()) {
            $userModelClass = config('auth.providers.users.model', 'App\Models\User');

            if (class_exists($userModelClass)) {
                $userModel = new $userModelClass();
                if (method_exists($userModel, 'getTable')) {
                    $userTable = $userModel->getTable();
                    $userNames = DB::table($userTable)
                        ->whereIn('id', $userIds)
                        ->pluck('name', 'id')
                        ->toArray();
                }
            }
        }

        $topUsers = $topUsersQuery->map(function ($item) use ($userNames): array {
            $userId = $item->user_id;

            return [
                'user_name' => $userId && isset($userNames[$userId]) ? $userNames[$userId] : 'Unknown',
                'count' => $item->count,
            ];
        })->toArray();

        $entityTypes = (clone $baseQuery)
            ->whereNotNull('entity_type')
            ->select('entity_type', DB::raw('count(*) as count'))
            ->groupBy('entity_type')
            ->orderByDesc('count')
            ->pluck('count', 'entity_type')
            ->toArray();

        return [
            'total_logs' => $totalLogs,
            'actions_breakdown' => $actionsBreakdown,
            'top_users' => $topUsers,
            'entity_types' => $entityTypes,
        ];
    }

    /**
     * Get audit logs for a specific entity.
     */
    public static function getEntityAuditLogs(string $entityType, mixed $entityId, int $limit = 50): Collection
    {
        return DB::table('audit_logs')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Clean up old audit logs based on retention policy.
     *
     * @return int Number of deleted records
     */
    public static function cleanupOldLogs(int $daysToKeep = 365): int
    {
        $cutoffDate = Carbon::now()->subDays($daysToKeep);

        return DB::table('audit_logs')
            ->where('created_at', '<', $cutoffDate)
            ->delete();
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
     * @param string $event The action performed
     */
    public static function getAuditTitle(AuditLogEventEnum $event, string $entityName): string
    {
        $eventLabel = '';
        match ($event) {
            AuditLogEventEnum::CREATED => $eventLabel = 'created',
            AuditLogEventEnum::UPDATED => $eventLabel = 'updated',
            AuditLogEventEnum::DELETED => $eventLabel = 'deleted',
            AuditLogEventEnum::LOGIN => $eventLabel = 'logged in',
            AuditLogEventEnum::LOGOUT => $eventLabel = 'logged out',
            AuditLogEventEnum::FAILED_LOGIN => $eventLabel = 'failed to log in',
            default => $eventLabel = '',
        };

        $entityLabel = '';
        match ($event) {
            AuditLogEventEnum::LOGIN => $entityLabel = '',
            AuditLogEventEnum::LOGOUT => $entityLabel = '',
            AuditLogEventEnum::FAILED_LOGIN => $entityLabel = '',
            default => $entityLabel = $entityName,
        };

        return ucfirst($eventLabel) . ' ' . static::getEntityLabel($entityLabel);
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
     * @param AuditLogEventEnum $event The type of audit event
     * @param null|string       $entityName        The name of the entity being audited
     * @param null|array        $oldData           The previous state of the entity
     * @param null|array        $newData           The new state of the entity
     *
     * @return string A formatted recap string
     */
    public static function generateRecap(AuditLogEventEnum $event, ?string $entityName = '', ?array $oldData = [], ?array $newData = []): string
    {
        switch ($event) {
            case AuditLogEventEnum::CREATED:

            case AuditLogEventEnum::DELETED:

            case AuditLogEventEnum::LOGIN:

            case AuditLogEventEnum::LOGOUT:

            case AuditLogEventEnum::FAILED_LOGIN:

            default:
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
        }
    }

    /**
     * Get table name from entity type.
     */
    public static function getTableNameFromEntityType(string $entityType): string
    {
        if (class_exists($entityType)) {
            $model = new $entityType();
            if (method_exists($model, 'getTable')) {
                return $model->getTable();
            }
        }

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
                ->implode(', ');

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
     * Get database-specific JSON extract query.
     */
    private static function getJsonExtractQuery(string $column, string $path): string
    {
        $driver = DB::getDriverName();
        $rawParts = explode('.', $path);
        $isFieldChanges = ($rawParts[0] ?? '') === 'field_changes';
        $parts = $isFieldChanges ? ['field_changes', implode('.', array_slice($rawParts, 1))] : $rawParts;

        switch ($driver) {
            case 'sqlite':
                $segments = array_map(fn($p): string => preg_match('/[^A-Za-z0-9_]/', $p) ? "['" . str_replace("'", "''", $p) . "']" : "." . $p, $parts);
                $sqlitePath = '$' . implode('', $segments);
                return sprintf("json_extract(%s, '%s')", $column, $sqlitePath);
            case 'mysql':
            case 'mariadb':
                $segments = array_map(fn($p): string => preg_match('/[^A-Za-z0-9_]/', $p) ? '["' . str_replace('"', '\\"', $p) . '"]' : "." . $p, $parts);
                $mysqlPath = '$' . implode('', $segments);
                return sprintf("JSON_EXTRACT(%s, '%s')", $column, $mysqlPath);
            case 'pgsql':
                $pgPath = '{' . implode(',', $parts) . '}';
                return sprintf("(%s #> '%s')", $column, $pgPath);
            default:
                $segments = array_map(fn($p): string => preg_match('/[^A-Za-z0-9_]/', $p) ? '["' . str_replace('"', '\\"', $p) . '"]' : "." . $p, $parts);
                $pathStr = '$' . implode('', $segments);
                return sprintf("JSON_EXTRACT(%s, '%s')", $column, $pathStr);
        }
    }

    /**
     * Get field timeline for a specific entity and field.
     */
    public static function getFieldTimeline(string $entityType, mixed $entityId, string $field, int $limit = 10): array
    {
        $driver = DB::getDriverName();
        $query = DB::table('audit_logs')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId);

        if ('sqlite' === $driver) {
            $safeField = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $field);
            $query->where('metadata', 'LIKE', '%"field_changes"%')
                ->where('metadata', 'LIKE', '%"' . $safeField . '":%');
        } else {
            $jsonQuery = static::getJsonExtractQuery('metadata', 'field_changes.' . $field);
            $query->whereRaw($jsonQuery . ' IS NOT NULL');
        }

        $logs = $query->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

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
        $driver = DB::getDriverName();
        $query = DB::table('audit_logs')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId);

        if ('sqlite' === $driver) {
            $safeField = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $field);
            $query->where('metadata', 'LIKE', '%"field_changes"%')
                ->where('metadata', 'LIKE', '%"' . $safeField . '":%');
        } else {
            $jsonQuery = static::getJsonExtractQuery('metadata', 'field_changes.' . $field);
            $query->whereRaw($jsonQuery . ' IS NOT NULL');
        }

        $logs = $query->get();

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
                        $fieldKey = sprintf('items.%s.%s', $itemId, $fieldName);
                        $previousChangeData = static::getFieldPreviousChange($entityType, $entityId, $fieldKey);

                        $itemChanges[$fieldKey] = [
                            'old_value' => $oldValue,
                            'new_value' => $newValue,
                            'data_type' => static::getFieldDataType($oldValue, $newValue),
                            'change_type' => static::getChangeType($oldValue, $newValue),
                            'changed_at' => $currentTime,
                            'previous_change' => $previousChangeData['previous_change'],
                            'change_count' => $previousChangeData['change_count'] + 1,
                            'previous_user' => $previousChangeData['previous_user'],
                        ];
                    }
                }
            }
        }

        // Check for added items
        foreach (array_keys($newItemsById) as $itemId) {
            if (!isset($oldItemsById[$itemId])) {
                $fieldKey = sprintf('items.%s.added', $itemId);
                $previousChangeData = static::getFieldPreviousChange($entityType, $entityId, $fieldKey);

                $itemChanges[$fieldKey] = [
                    'old_value' => null,
                    'new_value' => $newItemsById[$itemId] ?? null,
                    'data_type' => static::getFieldDataType(null, $newItemsById[$itemId] ?? null),
                    'change_type' => static::getChangeType(null, $newItemsById[$itemId] ?? null),
                    'changed_at' => $currentTime,
                    'previous_change' => $previousChangeData['previous_change'],
                    'change_count' => $previousChangeData['change_count'] + 1,
                    'previous_user' => $previousChangeData['previous_user'],
                ];
            }
        }

        // Check for deleted items
        foreach (array_keys($oldItemsById) as $itemId) {
            if (!isset($newItemsById[$itemId])) {
                $fieldKey = sprintf('items.%s.deleted', $itemId);
                $previousChangeData = static::getFieldPreviousChange($entityType, $entityId, $fieldKey);

                $itemChanges[$fieldKey] = [
                    'old_value' => $oldItemsById[$itemId] ?? null,
                    'new_value' => null,
                    'data_type' => static::getFieldDataType($oldItemsById[$itemId] ?? null, null),
                    'change_type' => static::getChangeType($oldItemsById[$itemId] ?? null, null),
                    'changed_at' => $currentTime,
                    'previous_change' => $previousChangeData['previous_change'],
                    'change_count' => $previousChangeData['change_count'] + 1,
                    'previous_user' => $previousChangeData['previous_user'],
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

        $driver = DB::getDriverName();
        $baseQuery = DB::table('audit_logs')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId);

        if ('sqlite' === $driver) {
            $safeField = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $field);
        }

        $previousQuery = clone $baseQuery;
        if ('sqlite' === $driver) {
            $safeField = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $field);
            $previousQuery->where('metadata', 'LIKE', '%"field_changes"%')
                ->where('metadata', 'LIKE', '%"' . $safeField . '":%');
        } else {
            $jsonQuery = static::getJsonExtractQuery('metadata', 'field_changes.' . $field);
            $previousQuery->whereRaw($jsonQuery . ' IS NOT NULL');
        }

        $previousLog = $previousQuery->orderBy('created_at', 'desc')->first();

        $totalQuery = clone $baseQuery;
        if ('sqlite' === $driver) {
            $safeField = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $field);
            $totalQuery->where('metadata', 'LIKE', '%"field_changes"%')
                ->where('metadata', 'LIKE', '%"' . $safeField . '":%');
        } else {
            $jsonQuery = static::getJsonExtractQuery('metadata', 'field_changes.' . $field);
            $totalQuery->whereRaw($jsonQuery . ' IS NOT NULL');
        }

        $totalChanges = $totalQuery->count();

        return [
            'previous_change' => $previousLog ? Carbon::parse($previousLog->created_at)->toISOString() : null,
            'change_count' => $totalChanges,
            'previous_user' => $previousLog ? 'user_' . $previousLog->user_id : null,
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
            $recap[] = $mainFieldChanges . ', ';
        }

        // Handle item changes
        $itemChanges = self::formatItemChanges($changes);
        if ([] !== $itemChanges) {
            $recap = array_merge($recap, $itemChanges);
        }

        return [] === $recap ? static::getEntityLabel($entityName) : implode(', ', $recap);
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
            $itemChanges[] = 1 == $addedItemCount ? sprintf('Added %d line product', $addedItemCount) : sprintf('Added %d line products', $addedItemCount);
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
                $itemChanges[] = sprintf('Deleted %d line product', $removedItemCount);
            } else {
                $itemChanges[] = sprintf('Deleted %d line products', $removedItemCount);
            }
        }

        // Find modified items (exist in both but with changes)
        $modifiedItemCount = 0;
        foreach ($oldItemsById as $id => $oldItem) {
            if (isset($newItemsById[$id])) {
                $newItem = $newItemsById[$id];
                $hasChanges = false;

                // Check if product_id changed
                if (
                    isset($oldItem['product_id'], $newItem['product_id'])
                    && $oldItem['product_id'] !== $newItem['product_id']
                ) {
                    $hasChanges = true;
                }

                // Check quantity changes
                if (
                    !$hasChanges && isset($oldItem['quantity'], $newItem['quantity'])
                    && (float) $oldItem['quantity'] !== (float) $newItem['quantity']
                ) {
                    $hasChanges = true;
                }

                // Check price changes
                if (
                    !$hasChanges && isset($oldItem['price'], $newItem['price'])
                    && (float) $oldItem['price'] !== (float) $newItem['price']
                ) {
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
                $itemChanges[] = sprintf('Modified %d line product', $modifiedItemCount);
            } else {
                $itemChanges[] = sprintf('Modified %d line products', $modifiedItemCount);
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
     */
    private static function valuesAreDifferent(mixed $oldValue, mixed $newValue): bool
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
     */
    private static function getFieldDataType(mixed $oldValue, mixed $newValue): string
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
     */
    private static function getChangeType(mixed $oldValue, mixed $newValue): string
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
