<?php

declare(strict_types=1);

namespace Sopheak\Core\Services;

use Throwable;
use Sopheak\Core\Enums\AuditLogEventEnum;
use Sopheak\Core\Jobs\AuditLogJob;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Sopheak\Core\Services\RecordConfigService;

/**
 * Centralized service for creating and querying audit logs.
 */
class AuditLogService
{
    /**
     * Build and persist an audit log entry for the given event.
     *
     * Only persists update events when there are actual changes between
     * the old and new data snapshots.
     * @param array<string, mixed> $queryData
     */
    public static function handleAuditDataEntry(AuditLogEventEnum $event, string $entityName, string $entityType, array $queryData, ?string $subject = null, ?string $recap = null, mixed $tenantId = null): void
    {
        if (!static::isAuditEnabled()) {
            return;
        }

        $oldData = [];
        $newData = [];
        $providedOldData = $queryData['old_data'] ?? $queryData['__old_data'] ?? null;
        $providedNewData = $queryData['new_data'] ?? $queryData['__new_data'] ?? null;
        $entityId = $queryData['id'] ?? $queryData['entity_id'] ?? null;

        if (is_array($providedNewData) && (null === $entityId || '' === $entityId) && isset($providedNewData['id'])) {
            $entityId = $providedNewData['id'];
        }

        if (is_array($providedOldData) && (null === $entityId || '' === $entityId) && isset($providedOldData['id'])) {
            $entityId = $providedOldData['id'];
        }

        if (in_array($event, [AuditLogEventEnum::UPDATED, AuditLogEventEnum::DELETED], true) && (null === $entityId || '' === $entityId)) {
            return;
        }

        switch ($event) {
            case AuditLogEventEnum::CREATED:
                $newData = is_array($providedNewData) ? $providedNewData : $queryData;

                break;

            case AuditLogEventEnum::UPDATED:
                if (is_array($providedOldData)) {
                    $oldData = $providedOldData;
                } else {
                    $getOldAuditLogDate = static::getOldAuditLogDate(entityId: $entityId, entityName: $entityName, tenantId: $tenantId);
                    $oldData = null === $getOldAuditLogDate || [] === $getOldAuditLogDate ? [] : $getOldAuditLogDate;
                }

                $newData = is_array($providedNewData) ? $providedNewData : $queryData;

                // Check if there are actual changes for UPDATE events
                if (!self::hasDataChanges($oldData, $newData)) {
                    // No changes detected, skip audit log creation
                    return;
                }

                break;

            case AuditLogEventEnum::DELETED:
                if (is_array($providedOldData)) {
                    $oldData = $providedOldData;
                } else {
                    $getOldAuditLogDate = static::getOldAuditLogDate(entityId: $entityId, entityName: $entityName, tenantId: $tenantId);
                    $oldData = null === $getOldAuditLogDate || [] === $getOldAuditLogDate ? [] : $getOldAuditLogDate;
                }

                break;
        }

        // Create audit log entry only when there are changes or for CREATE/DELETE events
        $tenantColumn = RecordConfigService::tenantColumn();
        static::createAuditLogEntry([
            'title' => static::getAuditTitle($event, $entityName),
            'old_data' => $oldData,
            'new_data' => $newData,
            'recap' => null != $recap ? $recap : static::generateRecap(event: $event, entityName: $entityName, oldData: $oldData, newData: $newData),
            'subject' => null != $subject ? $subject : static::getAuditSubject(data: [] === $newData ? ([] !== $oldData ? $oldData : $queryData) : ($newData)),
            'entity_type' => $entityType ?? null,
            'entity_id' => $entityId,
            'event' => $event->value,
            $tenantColumn => $tenantId,
            'metadata' => $queryData,
        ]);
    }

    /**
     * Persist a formatted audit log entry.
     *
     * Includes an additional guard to avoid storing update entries with no changes.
     * @param array<string, mixed> $data
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

            if (!self::hasDataChanges($oldData, $newData)) {
                return;
            }
        }

        $tableName = static::getTableNameFromEntityType($data['entity_type']);

        // Determine changed fields for enhanced metadata
        $changedFields = self::getChangedFields(oldData: $data['old_data'] ?? [], newData: $data['new_data'] ?? []);

        // Prepare the audit log data
        $auditData = [
            'title' => $data['title'],
            'old_data' => is_array($data['old_data']) ? json_encode($data['old_data'], JSON_PRETTY_PRINT) : $data['old_data'],
            'new_data' => is_array($data['new_data']) ? json_encode($data['new_data'], JSON_PRETTY_PRINT) : $data['new_data'],
            'recap' => $data['recap'] ?? '',
            'subject' => $data['subject'] ?? '',
            'user_id' => $data['user_id'] ?? auth(RecordConfigService::authGuard())->id(),
            'entity_type' => $tableName,
            'entity_id' => $data['entity_id'],
            'entity_name' => $tableName,
            'event' => $data['event'],
            'metadata' => isset($data['metadata']) ? (is_array($data['metadata']) ? json_encode($data['metadata']) : $data['metadata']) : null,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'request_id' => request()->attributes->get('request_id') ?? request()->header('X-Request-ID'),
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ];

        if (RecordConfigService::enableTenantId()) {
            $tenantColumn = RecordConfigService::tenantColumn();
            $auditData[$tenantColumn] = $data[$tenantColumn] ?? null;
        }

        if (!in_array($data['event'], [AuditLogEventEnum::LOGIN->value, AuditLogEventEnum::LOGOUT->value, AuditLogEventEnum::FAILED_LOGIN->value])) {
            $auditData['metadata'] = json_encode(static::getAuditMetadata(
                changedFields: $changedFields,
                oldData: $data['old_data'] ?? [],
                newData: $data['new_data'] ?? [],
                entityType: $data['entity_type'] ?? null,
                entityId: $data['entity_id'] ?? null,
                event: $data['event'] ?? null,
                tenantId: RecordConfigService::enableTenantId() ? ($data[RecordConfigService::tenantColumn()] ?? null) : null
            ));
        }

        DB::table(RecordConfigService::auditLogModel())->insert($auditData);
    }

    /**
     * Build enriched audit metadata with field-level change tracking.
     */
    public static function getAuditMetadata(array $changedFields = [], array $oldData = [], array $newData = [], ?string $entityType = null, mixed $entityId = null, ?string $event = null, ?string $tenantId = null): array
    {
        $currentTime = now()->toISOString();
        $guard = RecordConfigService::authGuard();
        $userId = auth($guard)->id();
        $userName = auth($guard)->user()?->name ?? 'Unknown';
        $isCreateEvent = strtolower((string) $event) === AuditLogEventEnum::CREATED->value;
        $tableName = null !== $entityType ? static::getTableNameFromEntityType($entityType) : null;
        $lookupEntityType = $tableName ?? $entityType;

        $prevEntry = self::getPreviousAuditEntry(entityType: $lookupEntityType, entityId: $entityId, tenantId: $tenantId);
        $prevMetadata = [];
        $prevEntryCreatedAt = null;

        if ($prevEntry && isset($prevEntry->metadata)) {
            $prevMetadata = json_decode((string) $prevEntry->metadata, true) ?? [];
            if (!is_array($prevMetadata)) {
                $prevMetadata = [];
            }
        }

        if ($prevEntry && isset($prevEntry->created_at)) {
            try {
                $prevEntryCreatedAt = Carbon::parse($prevEntry->created_at);
            } catch (Throwable) {
                $prevEntryCreatedAt = null;
            }
        }

        $globalPrevTs = self::resolveGlobalPrevTimestamp($prevMetadata, $prevEntryCreatedAt);
        $globalPrevIso = $globalPrevTs instanceof Carbon ? $globalPrevTs->toISOString() : null;

        $metadata = [
            'field_changes' => [],
            'change_summary' => [
                'total_fields_changed' => count($changedFields),
                'change_type' => strtolower((string) $event) ?: 'update',
                'user_agent' => request()->userAgent() ?? 'unknown',
                'ip_address' => request()->ip() ?? 'unknown',
                'session_id' => session()->getId() ?? null,
            ],
            'user_id' => $userId,
            'user_name' => $userName,
        ];

        foreach ($changedFields as $changedField) {
            $oldValue = $oldData[$changedField] ?? null;
            $newValue = $newData[$changedField] ?? null;

            // Special handling for items array
            if ('items' === $changedField && is_array($oldValue) && is_array($newValue)) {
                $itemChanges = self::getItemChanges(
                    oldItems: $oldValue,
                    newItems: $newValue,
                    prevMetadata: $prevMetadata,
                    isCreateEvent: $isCreateEvent,
                    globalPrevIso: $globalPrevIso,
                    prevEntryUserId: $prevEntry?->user_id
                );
                $metadata['field_changes'] = array_merge($metadata['field_changes'], $itemChanges);
            } else {
                $prevFieldMeta = $prevMetadata['field_changes'][$changedField] ?? [];
                $prevChangeCount = is_array($prevFieldMeta) ? (int) ($prevFieldMeta['change_count'] ?? 0) : 0;
                $prevChangedAt = is_array($prevFieldMeta) ? ($prevFieldMeta['changed_at'] ?? null) : null;
                $prevUser = is_array($prevFieldMeta) ? ($prevFieldMeta['previous_user'] ?? null) : null;
                $previousChange = $isCreateEvent ? null : ($prevChangedAt ?? $globalPrevIso);

                $metadata['field_changes'][$changedField] = [
                    'old_value' => $oldValue,
                    'new_value' => $newValue,
                    'data_type' => self::getFieldDataType(oldValue: $oldValue, newValue: $newValue),
                    'change_type' => self::getChangeType(oldValue: $oldValue, newValue: $newValue),
                    'changed_at' => $currentTime,
                    'previous_change' => $previousChange,
                    'change_count' => $prevChangeCount + 1,
                    'previous_user' => $prevUser ?? ($prevEntry && isset($prevEntry->user_id) ? 'user_' . $prevEntry->user_id : null),
                ];
            }
        }

        return $metadata;
    }

    /**
     * Record authentication-related audit events.
     */
    public static function authEvent(AuditLogEventEnum $event, ?array $data = [], mixed $tenantId = null): void
    {
        if (!static::isAuditEnabled()) {
            return;
        }

        $userModel = config('auth.providers.users.model', 'App\\Models\\User');
        $auditLogJobClass = RecordConfigService::auditLogJobClass(default: AuditLogJob::class);

        $entityName = static::getTableNameFromEntityType(entityType: $userModel);

        if (static::isAuditQueueEnabled() && class_exists($auditLogJobClass) && method_exists($auditLogJobClass, 'dispatch')) {
            $auditLogJobClass::dispatch(
                event: $event,
                entityName: $entityName,
                entityType: $entityName,
                queryData: $data,
                subject: null,
                recap: null,
                tenantId: $tenantId,
            );
        } else {
            static::handleAuditDataEntry(
                event: $event,
                entityName: $entityName,
                entityType: $entityName,
                queryData: $data,
                tenantId: $tenantId
            );
        }
    }

    /**
     * Log an audit event (wrapper for instance calls).
     * @param array<string, mixed> $auditContext
     */
    public function log(AuditLogEventEnum $event, string $table, array $auditContext): void
    {
        $tenantId = $auditContext['tenant_id'] ?? null;
        self::insertAuditLog(
            auditLogEventEnum: $event,
            entityClass: $table,
            queryData: $auditContext,
            tenantId: $tenantId
        );
    }

    /**
     * Insert an audit log entry for a given entity class and payload.
     */
    public static function insertAuditLog(AuditLogEventEnum $auditLogEventEnum, string $entityClass, array|object $queryData = [], ?string $subject = '', ?string $recap = '', mixed $tenantId = null): void
    {
        if (!static::isAuditEnabled()) {
            return;
        }

        $entityName = AuditLogService::getTableNameFromEntityType($entityClass);
        $entityType = $entityName;

        if (is_object($queryData)) {
            $queryData = (array) $queryData;
        }

        // Remove timestamp fields from nested arrays before comparison
        $queryData = self::removeTimestampFields($queryData);

        if ($auditLogEventEnum === AuditLogEventEnum::UPDATED && config('audit.store_diff_only', false)) {
            $entityId = $queryData['id'] ?? $queryData['entity_id'] ?? null;
            $providedOldData = $queryData['old_data'] ?? $queryData['__old_data'] ?? null;
            $providedNewData = $queryData['new_data'] ?? $queryData['__new_data'] ?? null;

            $oldData = is_array($providedOldData) ? $providedOldData : null;
            if (null === $oldData && null !== $entityId) {
                $getOldAuditLogDate = static::getOldAuditLogDate(entityId: $entityId, entityName: $entityName, tenantId: $tenantId);
                $oldData = null === $getOldAuditLogDate || [] === $getOldAuditLogDate ? [] : $getOldAuditLogDate;
            }

            $newData = is_array($providedNewData) ? $providedNewData : $queryData;

            if (is_array($oldData) && is_array($newData)) {
                $filteredOld = [];
                $filteredNew = [];

                if (isset($oldData['id'])) {
                    $filteredOld['id'] = $oldData['id'];
                }

                if (isset($newData['id'])) {
                    $filteredNew['id'] = $newData['id'];
                }

                $allKeys = array_unique(array_merge(array_keys($oldData), array_keys($newData)));
                foreach ($allKeys as $key) {
                    if ('id' === $key) {
                        continue;
                    }

                    $oldVal = $oldData[$key] ?? null;
                    $newVal = $newData[$key] ?? null;
                    if (self::valuesAreDifferent($oldVal, $newVal)) {
                        if (array_key_exists($key, $oldData)) {
                            $filteredOld[$key] = $oldData[$key];
                        }

                        if (array_key_exists($key, $newData)) {
                            $filteredNew[$key] = $newData[$key];
                        }
                    }
                }

                if (!is_array($providedNewData) && !is_array($providedOldData)) {
                    $queryData = [
                        'old_data' => $filteredOld,
                        'new_data' => $filteredNew,
                        'id' => $entityId,
                    ];
                } else {
                    if (isset($queryData['old_data'])) {
                        $queryData['old_data'] = $filteredOld;
                    }

                    if (isset($queryData['__old_data'])) {
                        $queryData['__old_data'] = $filteredOld;
                    }

                    if (isset($queryData['new_data'])) {
                        $queryData['new_data'] = $filteredNew;
                    }

                    if (isset($queryData['__new_data'])) {
                        $queryData['__new_data'] = $filteredNew;
                    }
                }
            }
        }

        // Handle audit logging based on queue configuration
        if (static::isAuditQueueEnabled()) {
            $auditLogJobClass = RecordConfigService::auditLogJobClass(default: AuditLogJob::class);
            if (!class_exists($auditLogJobClass) || !method_exists($auditLogJobClass, 'dispatch')) {
                static::handleAuditDataEntry(
                    event: $auditLogEventEnum,
                    entityName: $entityName,
                    entityType: $entityType,
                    queryData: $queryData,
                    subject: $subject,
                    recap: $recap,
                    tenantId: $tenantId,
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
                tenantId: $tenantId,
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
            tenantId: $tenantId,
        );
    }

    public static function getOldAuditLogDate(int|string $entityId, string $entityName, ?string $tenantId = null): ?array
    {
        $query = DB::table(RecordConfigService::auditLogModel())
            ->where('entity_id', $entityId)
            ->where('entity_name', $entityName);

        if (RecordConfigService::enableTenantId()) {
            $query->where(RecordConfigService::tenantColumn(), $tenantId);
        }

        $query->orderByDesc('created_at');

        $data = $query->first();

        if (!empty($data->new_data)) {
            return json_decode((string) $data->new_data, true);
        }

        return null;
    }

    /**
     * Retrieve audit logs for a specific entity record.
     */
    public static function getEntityAuditLogs(string $entityType, mixed $entityId, ?string $tenantId = null, int $limit = 50): Collection
    {
        $query = DB::table(RecordConfigService::auditLogModel())
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId);

        if (RecordConfigService::enableTenantId()) {
            $query->where(RecordConfigService::tenantColumn(), $tenantId);
        }

        $query->orderBy('created_at', 'desc')
            ->limit($limit);

        return $query->get();
    }

    /**
     * Clean up old audit logs based on retention policy.
     *
     * @return int Number of deleted records.
     */
    public static function cleanupOldLogs(?string $tenantId = null, int $daysToKeep = 365): int
    {
        $cutoffDate = Carbon::now()->subDays($daysToKeep);

        $query = DB::table(RecordConfigService::auditLogModel())
            ->where('created_at', '<', $cutoffDate);

        if (RecordConfigService::enableTenantId()) {
            $query->where(RecordConfigService::tenantColumn(), $tenantId);
        }

        return $query->delete();
    }

    /**
     * Resolve the audit subject (typically a human-readable identifier).
     */
    public static function getAuditSubject(array $data): string
    {
        $identifierFields = config('audit.subject_fields', []);
        if (!is_array($identifierFields) || [] === $identifierFields) {
            return '';
        }

        foreach ($identifierFields as $identifierField) {
            if (isset($data[$identifierField])) {
                return static::generateLabel((string) $data[$identifierField]);
            }
        }

        return '';
    }

    /**
     * Get the audit log title for the given event.
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

        return trim(static::generateLabel($eventLabel) . ' ' . static::getEntityLabel($entityLabel));
    }

    public static function getEntityLabel(string $label): string
    {
        $customLabels = config('audit.entity_labels', []);
        if (is_array($customLabels) && array_key_exists($label, $customLabels)) {
            return (string) $customLabels[$label];
        }

        return static::generateLabel($label);
    }

    /**
     * Generate a human-readable recap of audit log events.
     *
     * @param AuditLogEventEnum $event The type of audit event.
     * @param string|null $entityName The name of the entity being audited.
     * @param array|null $oldData The previous state of the entity.
     * @param array|null $newData The new state of the entity.
     *
     * @return string A formatted recap string.
     */
    public static function generateRecap(AuditLogEventEnum $event, ?string $entityName = '', ?array $oldData = [], ?array $newData = []): string
    {
        $entityLabel = null !== $entityName && '' !== $entityName && '0' !== $entityName
            ? static::getEntityLabel($entityName)
            : '';
        $actionLabel = match ($event) {
            AuditLogEventEnum::CREATED => 'Created',
            AuditLogEventEnum::UPDATED => 'Updated',
            AuditLogEventEnum::DELETED => 'Deleted',
            AuditLogEventEnum::LOGIN => 'Logged in',
            AuditLogEventEnum::LOGOUT => 'Logged out',
            AuditLogEventEnum::FAILED_LOGIN => 'Failed login',
            default => '',
        };

        switch ($event) {
            case AuditLogEventEnum::CREATED:
            case AuditLogEventEnum::DELETED:
            case AuditLogEventEnum::LOGIN:
            case AuditLogEventEnum::LOGOUT:
            case AuditLogEventEnum::FAILED_LOGIN:
            default:
                if ('' === $actionLabel) {
                    return '';
                }

                if ('' !== $entityLabel) {
                    return $actionLabel . ' ' . $entityLabel;
                }

                return $actionLabel;
            case AuditLogEventEnum::UPDATED:
                $excluded = RecordConfigService::auditExcludedAttributes();
                if (!is_array($excluded)) {
                    $excluded = [];
                }

                $excluded = array_values(array_unique(array_merge($excluded, ['id', 'created_at', 'updated_at', 'deleted_at'])));

                $changes = array_reduce(array_keys($newData ?? []), function (array $acc, int|string $key) use ($oldData, $newData, $excluded): array {
                    if (!isset($oldData[$key])) {
                        return $acc;
                    }

                    if (is_string($key) && in_array($key, $excluded, true)) {
                        return $acc;
                    }

                    if (is_array($oldData[$key]) || is_array($newData[$key]) || is_object($oldData[$key]) || is_object($newData[$key])) {
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

                if ([] === $changes) {
                    return '';
                }

                $recapEntities = RecordConfigService::auditRecapEntities();
                if (null !== $entityName && '' !== $entityName && '0' !== $entityName && in_array($entityName, $recapEntities, true)) {
                    $details = self::generateDetailedUpdateRecap($entityName, $changes);
                    if ('' === $details) {
                        return '';
                    }

                    if ('' === $entityLabel || $details === $entityLabel) {
                        return $actionLabel . ' ' . $details;
                    }

                    return $actionLabel . ' ' . $entityLabel . ': ' . $details;
                }

                $labels = [];
                $fieldMappings = RecordConfigService::auditMainFieldLabels();
                if (!is_array($fieldMappings)) {
                    $fieldMappings = [];
                }

                foreach (array_keys($changes) as $field) {
                    if (!is_string($field)) {
                        continue;
                    }

                    $label = $fieldMappings[$field] ?? static::generateLabel($field);
                    if (!in_array($label, $labels, true)) {
                        $labels[] = $label;
                    }
                }

                if ([] === $labels) {
                    return '';
                }

                $maxFields = RecordConfigService::auditRecapMaxFields();
                if ($maxFields > 0 && count($labels) > $maxFields) {
                    $remaining = count($labels) - $maxFields;
                    $labels = array_slice($labels, 0, $maxFields);
                    $labels[] = 'and ' . $remaining . ' more';
                }

                $details = implode(', ', $labels);

                if ('' === $entityLabel) {
                    return $actionLabel . ': ' . $details;
                }

                return $actionLabel . ' ' . $entityLabel . ': ' . $details;
        }
    }

    /**
     * Resolve the table name from an entity class or identifier.
     */
    public static function getTableNameFromEntityType(string $entityType): string
    {
        if (!str_contains($entityType, '\\')) {
            return $entityType;
        }

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
     * Normalize mapper query data for audit logging.
     * @param array<string, mixed> $queryData
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
        return RecordConfigService::auditEnabled(default: true);
    }

    public static function isAuditQueueEnabled(): bool
    {
        return RecordConfigService::auditQueueEnabled();
    }

    /**
     * Check if an event should be excluded from logging.
     */
    public static function isEventExcluded(string $event): bool
    {
        $excludedEvents = RecordConfigService::auditExcludedEvents();

        return in_array($event, $excludedEvents);
    }



    /**
     * Build item-level changes for nested items arrays.
     * @param array<string, mixed> $prevMetadata
     */
    private static function getItemChanges(array $oldItems, array $newItems, array $prevMetadata = [], bool $isCreateEvent = false, ?string $globalPrevIso = null, mixed $prevEntryUserId = null): array
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

                    if (self::valuesAreDifferent($oldValue, $newValue)) {
                        $fieldKey = sprintf('items.%s.%s', $itemId, $fieldName);
                        $prevFieldMeta = $prevMetadata['field_changes'][$fieldKey] ?? [];
                        $prevChangeCount = is_array($prevFieldMeta) ? (int) ($prevFieldMeta['change_count'] ?? 0) : 0;
                        $prevChangedAt = is_array($prevFieldMeta) ? ($prevFieldMeta['changed_at'] ?? null) : null;
                        $prevUser = is_array($prevFieldMeta) ? ($prevFieldMeta['previous_user'] ?? null) : null;

                        $itemChanges[$fieldKey] = [
                            'old_value' => $oldValue,
                            'new_value' => $newValue,
                            'data_type' => self::getFieldDataType($oldValue, $newValue),
                            'change_type' => self::getChangeType($oldValue, $newValue),
                            'changed_at' => $currentTime,
                            'previous_change' => $isCreateEvent ? null : ($prevChangedAt ?? $globalPrevIso),
                            'change_count' => $prevChangeCount + 1,
                            'previous_user' => $prevUser ?? ($prevEntryUserId ? 'user_' . $prevEntryUserId : null),
                        ];
                    }
                }
            }
        }

        // Check for added items
        foreach (array_keys($newItemsById) as $itemId) {
            if (!isset($oldItemsById[$itemId])) {
                $fieldKey = sprintf('items.%s.added', $itemId);
                $prevFieldMeta = $prevMetadata['field_changes'][$fieldKey] ?? [];
                $prevChangeCount = is_array($prevFieldMeta) ? (int) ($prevFieldMeta['change_count'] ?? 0) : 0;
                $prevChangedAt = is_array($prevFieldMeta) ? ($prevFieldMeta['changed_at'] ?? null) : null;
                $prevUser = is_array($prevFieldMeta) ? ($prevFieldMeta['previous_user'] ?? null) : null;

                $itemChanges[$fieldKey] = [
                    'old_value' => null,
                    'new_value' => $newItemsById[$itemId] ?? null,
                    'data_type' => self::getFieldDataType(null, $newItemsById[$itemId] ?? null),
                    'change_type' => self::getChangeType(null, $newItemsById[$itemId] ?? null),
                    'changed_at' => $currentTime,
                    'previous_change' => $isCreateEvent ? null : ($prevChangedAt ?? $globalPrevIso),
                    'change_count' => $prevChangeCount + 1,
                    'previous_user' => $prevUser ?? ($prevEntryUserId ? 'user_' . $prevEntryUserId : null),
                ];
            }
        }

        // Check for deleted items
        foreach (array_keys($oldItemsById) as $itemId) {
            if (!isset($newItemsById[$itemId])) {
                $fieldKey = sprintf('items.%s.deleted', $itemId);
                $prevFieldMeta = $prevMetadata['field_changes'][$fieldKey] ?? [];
                $prevChangeCount = is_array($prevFieldMeta) ? (int) ($prevFieldMeta['change_count'] ?? 0) : 0;
                $prevChangedAt = is_array($prevFieldMeta) ? ($prevFieldMeta['changed_at'] ?? null) : null;
                $prevUser = is_array($prevFieldMeta) ? ($prevFieldMeta['previous_user'] ?? null) : null;

                $itemChanges[$fieldKey] = [
                    'old_value' => $oldItemsById[$itemId] ?? null,
                    'new_value' => null,
                    'data_type' => self::getFieldDataType($oldItemsById[$itemId] ?? null, null),
                    'change_type' => self::getChangeType($oldItemsById[$itemId] ?? null, null),
                    'changed_at' => $currentTime,
                    'previous_change' => $isCreateEvent ? null : ($prevChangedAt ?? $globalPrevIso),
                    'change_count' => $prevChangeCount + 1,
                    'previous_user' => $prevUser ?? ($prevEntryUserId ? 'user_' . $prevEntryUserId : null),
                ];
            }
        }

        return $itemChanges;
    }

    /**
     * @param array<string, mixed> $prevMetadata
     */
    private static function resolveGlobalPrevTimestamp(array $prevMetadata, ?Carbon $prevEntryCreatedAt = null): ?Carbon
    {
        try {
            $itemsPrev = $prevMetadata['field_changes']['items']['changed_at'] ?? null;
            if (!empty($itemsPrev)) {
                return Carbon::parse($itemsPrev);
            }
        } catch (Throwable) {
            return $prevEntryCreatedAt ?? null;
        }

        return $prevEntryCreatedAt ?? null;
    }

    private static function getPreviousAuditEntry(?string $entityType = null, mixed $entityId = null, ?string $tenantId = null): ?object
    {
        if (null === $entityType || '' === $entityType || '0' === $entityType || !$entityId) {
            return null;
        }

        $query = DB::table(RecordConfigService::auditLogModel())
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->orderBy('created_at', 'desc')
            ->select(['id', 'metadata', 'created_at', 'user_id']);

        if (RecordConfigService::enableTenantId()) {
            $query->where(RecordConfigService::tenantColumn(), $tenantId);
        }

        return $query->first();
    }

    /**
     * Generate detailed update recap for configured recap entities.
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
     * Format main field changes (totals, quantities, relationships).
     */
    private static function formatMainFieldChanges(array $changes): string
    {
        $fieldMappings = RecordConfigService::auditMainFieldLabels();
        if (!is_array($fieldMappings)) {
            $fieldMappings = [];
        }

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
     * @param array<string, mixed> $changes The changes array containing item modifications.
     *
     * @return array Array of formatted item change descriptions.
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
            $itemChanges[] = 1 === $addedItemCount ? sprintf('Added %d line product', $addedItemCount) : sprintf('Added %d line products', $addedItemCount);
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
            if (1 === $removedItemCount) {
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
            if (1 === $modifiedItemCount) {
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
     * @param array $oldData The previous state of the data.
     * @param array $newData The new state of the data.
     *
     * @return bool True if there are changes, false otherwise.
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
        $changedFields = self::getChangedFields($oldData, $newData);

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
            if (self::valuesAreDifferent($oldValue, $newValue)) {
                $changedFields[] = $allKey;
            }
        }

        return $changedFields;
    }

    /**
     * Check if two values are different (arrays, objects, scalars).
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
     *
     * @param array $data The data array to process.
     *
     * @return array The processed array with timestamp fields removed.
     */
    private static function removeTimestampFields(array $data): array
    {
        $timestampFields = ['created_at', 'updated_at', 'deleted_at'];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                // Recursively process nested arrays
                $data[$key] = self::removeTimestampFields($value);
            }
        }

        // Remove timestamp fields from current level
        foreach ($timestampFields as $timestampField) {
            unset($data[$timestampField]);
        }

        return $data;
    }

    public static function generateLabel(string $name): string
    {
        // Normalize common delimiters to spaces
        $normalized = preg_replace('/[_\-]+/', ' ', $name);

        // Split camelCase/PascalCase and keep numbers as separate tokens
        // Matches sequences like: "Table", "Id", "Or", "Name", "API", "v2", etc.
        preg_match_all('/[A-Z]+(?=[A-Z][a-z0-9])|[A-Z]?[a-z0-9]+|[A-Z]+|\d+/', (string) $normalized, $matches);
        $words = $matches[0] ?? [];

        if (empty($words)) {
            return 'CustomListener' . uniqid();
        }

        $labelWords = array_map(fn($w): string => ucfirst(strtolower($w)), $words);

        return ucwords(str_replace('_', ' ', ucfirst(implode(' ', $labelWords))));
    }

    /**
     * Get aggregated statistics of audit logs based on filters.
     *
     * @param array<string, mixed> $filters Filters for querying stats (e.g. tenant_id, start_date, end_date, user_id, entity_type, event)
     * @return array<string, int|mixed[]>
     */
    public static function getAuditStats(array $filters = []): array
    {
        $query = DB::table(RecordConfigService::auditLogModel());

        if (RecordConfigService::enableTenantId()) {
            $tenantColumn = RecordConfigService::tenantColumn();
            if (!empty($filters[$tenantColumn])) {
                $query->where($tenantColumn, $filters[$tenantColumn]);
            }
        }

        if (!empty($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }

        if (!empty($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }

        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (!empty($filters['entity_type'])) {
            $query->where('entity_type', static::getTableNameFromEntityType($filters['entity_type']));
        }

        if (!empty($filters['event'])) {
            $query->where('event', $filters['event']);
        }

        $total = $query->count();

        $byEvent = (clone $query)
            ->select('event', DB::raw('count(*) as count'))
            ->groupBy('event')
            ->pluck('count', 'event')
            ->toArray();

        $byUser = (clone $query)
            ->select('user_id', DB::raw('count(*) as count'))
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->pluck('count', 'user_id')
            ->toArray();

        $byEntity = (clone $query)
            ->select('entity_type', DB::raw('count(*) as count'))
            ->whereNotNull('entity_type')
            ->groupBy('entity_type')
            ->pluck('count', 'entity_type')
            ->toArray();

        return [
            'total' => $total,
            'by_event' => $byEvent,
            'by_user' => $byUser,
            'by_entity' => $byEntity,
        ];
    }

    /**
     * Parse old_data and new_data to extract the history of a specific field.
     *
     * @param string $entityType The entity type or table name.
     * @param int $entityId The ID of the entity.
     * @param string $field The field name to track.
     * @param int $limit Maximum number of history entries to return.
     */
    public static function getFieldTimeline(string $entityType, int $entityId, string $field, int $limit = 50): array
    {
        $tableName = static::getTableNameFromEntityType($entityType);

        $query = DB::table(RecordConfigService::auditLogModel())
            ->where('entity_type', $tableName)
            ->where('entity_id', $entityId)
            ->orderBy('created_at', 'desc');

        if (RecordConfigService::enableTenantId() && request()->has(RecordConfigService::tenantColumn())) {
            $query->where(RecordConfigService::tenantColumn(), request()->input(RecordConfigService::tenantColumn()));
        }

        // We fetch logs and filter them in memory because old_data/new_data are JSON and we want to ensure accuracy
        // To prevent massive memory usage, we could process in chunks if needed, but usually audit logs per entity are reasonable.
        $logs = $query->get();

        $timeline = [];

        foreach ($logs as $log) {
            $oldData = is_string($log->old_data) ? json_decode($log->old_data, true) : (array) $log->old_data;
            $newData = is_string($log->new_data) ? json_decode($log->new_data, true) : (array) $log->new_data;

            $oldValue = $oldData[$field] ?? null;
            $newValue = $newData[$field] ?? null;

            // Only include in timeline if the field was actually changed in this event
            // or if it's the creation event where the field was first set
            if (self::valuesAreDifferent($oldValue, $newValue) || ($log->event === AuditLogEventEnum::CREATED->value && $newValue !== null)) {
                $timeline[] = [
                    'audit_log_id' => $log->id,
                    'event' => $log->event,
                    'user_id' => $log->user_id,
                    'created_at' => $log->created_at,
                    'old_value' => $oldValue,
                    'new_value' => $newValue,
                ];

                if (count($timeline) >= $limit) {
                    break;
                }
            }
        }

        return $timeline;
    }

    /**
     * Aggregate field changes for a specific entity's field.
     *
     * @param string $entityType The entity type or table name.
     * @param int $entityId The ID of the entity.
     * @param string $field The field name to aggregate stats for.
     * @return array<string, mixed>
     */
    public static function getFieldStats(string $entityType, int $entityId, string $field): array
    {
        $timeline = self::getFieldTimeline($entityType, $entityId, $field, 10000);

        $totalChanges = count($timeline);
        $users = [];
        $values = [];

        foreach ($timeline as $entry) {
            $userId = $entry['user_id'];
            if ($userId) {
                $users[$userId] = ($users[$userId] ?? 0) + 1;
            }

            $valKey = is_scalar($entry['new_value']) ? (string) $entry['new_value'] : json_encode($entry['new_value']);
            if ($valKey !== '' && $valKey !== 'null') {
                $values[$valKey] = ($values[$valKey] ?? 0) + 1;
            }
        }

        return [
            'total_changes' => $totalChanges,
            'changed_by_users' => $users,
            'value_frequency' => $values,
            'current_value' => $timeline[0]['new_value'] ?? null,
            'first_changed_at' => empty($timeline) ? null : end($timeline)['created_at'],
            'last_changed_at' => empty($timeline) ? null : $timeline[0]['created_at'],
        ];
    }
}
