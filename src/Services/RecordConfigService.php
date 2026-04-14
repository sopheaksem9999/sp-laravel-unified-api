<?php

namespace Sopheak\Core\Services;

use Sopheak\Core\Jobs\AuditLogJob;

class RecordConfigService
{
    public static function enableTenantId(): bool
    {
        return (bool) config('record.enable_tenant_id', false);
    }

    public static function tenantColumn(): string
    {
        return (string) config('record.tenant_column', 'tenant_id');
    }

    public static function tenantHeader(): string
    {
        return (string) config('record.tenant_header', 'X-Tenant-ID');
    }

    public static function tableConfigPath(): string
    {
        return (string) config('record.table_config_path', 'records/tables');
    }

    public static function apiPrefix(): string
    {
        return (string) config('record.api_prefix', 'api/v1');
    }

    public static function rpcPrefix(): string
    {
        return (string) config('record.rpc_prefix', '');
    }

    public static function perPageMax(): int
    {
        return (int) config('record.per_page_max', 10000);
    }

    public static function limitMax(): int
    {
        return (int) config('record.limit_max', 10000);
    }

    public static function bulkMax(): int
    {
        return (int) config('record.bulk_max', 1000);
    }

    public static function bulkOperationsEnabled(): bool
    {
        return (bool) config('record.bulk_operations', true);
    }

    public static function broadcastEventsEnabled(): bool
    {
        return (bool) config('record.broadcast_events', false);
    }

    /**
     * Tables that should broadcast mutations. Empty = all tables.
     *
     * @return string[]
     */
    public static function broadcastTables(): array
    {
        return (array) config('record.broadcast_tables', []);
    }

    public static function cacheEnabled(): bool
    {
        return (bool) config('record.cache.enabled', false);
    }

    public static function cacheTtl(): int
    {
        return (int) config('record.cache.ttl', 3600);
    }

    public static function cachePrefix(): string
    {
        return (string) config('record.cache.prefix', 'sp_laravel_api');
    }

    public static function cachePerTable(): array
    {
        return (array) config('record.cache.per_table', []);
    }

    public static function cachePerTableTtl(): array
    {
        return (array) config('record.cache.per_table_ttl', []);
    }

    public static function legacyCacheTtl(): int
    {
        return (int) config('record.cache_ttl', 3600);
    }

    public static function maxDepth(): int
    {
        return (int) config('record.max_depth', 10);
    }

    public static function defaultCascade(): array
    {
        return (array) config('record.default_cascade', [
            'create' => false,
            'update' => false,
            'upsert' => false,
        ]);
    }

    public static function permissionSeparator(): string
    {
        return (string) config('record.permission_separator', ':');
    }

    public static function restrictToOwnRecords(): bool
    {
        return (bool) config('record.restrict_to_own_records', false);
    }

    public static function ownRecordsPermissionPrefix(): string
    {
        return (string) config('record.own_records_permission_prefix', 'viewOwn');
    }

    public static function debugEnabled(): bool
    {
        return (bool) config('record.debug', false);
    }

    public static function middlewareMap(): array
    {
        return (array) config('record.middleware_map', []);
    }

    public static function globalFunctions(): array
    {
        return (array) config('record.global_functions', []);
    }

    public static function globalTriggers(): array
    {
        return (array) config('record.global_triggers', []);
    }

    public static function globalCasting(): array
    {
        return (array) config('record.casting', []);
    }

    public static function defaultValidationEnabled(): bool
    {
        return (bool) config('record.default_validation.enabled', true);
    }

    public static function defaultValidationOnlyWhenMissing(): bool
    {
        return (bool) config('record.default_validation.only_when_missing', true);
    }

    public static function defaultValidationIncludeRequired(): bool
    {
        return (bool) config('record.default_validation.required', true);
    }

    public static function defaultValidationIncludeTypes(): bool
    {
        return (bool) config('record.default_validation.types', true);
    }

    public static function defaultValidationIncludeUnique(): bool
    {
        return (bool) config('record.default_validation.unique', true);
    }

    public static function defaultValidationIncludeForeignKeys(): bool
    {
        return (bool) config('record.default_validation.foreign_keys', true);
    }

    public static function getTableConfig(): array
    {
        $recordTables = (array) config('record.tables', []);
        $attachmentEnabled = (bool) config('attachments.enabled', true);
        $attachmentTables = $attachmentEnabled ? (array) config('attachments.tables', []) : [];
        $webhookEnabled = (bool) config('webhooks.enabled', false);
        $webhookTables = $webhookEnabled ? (array) config('webhooks.tables', []) : [];

        return array_merge($webhookTables, $attachmentTables, $recordTables);
    }

    public static function table(string $table): mixed
    {
        $recordTable = config('record.tables.' . $table);
        if ($recordTable !== null) {
            return $recordTable;
        }

        if ((bool) config('attachments.enabled', true)) {
            $attachmentTable = config('attachments.tables.' . $table);
            if ($attachmentTable !== null) {
                return $attachmentTable;
            }
        }

        return config('webhooks.tables.' . $table, []);
    }

    public static function cacheDefaultTtl(): int
    {
        return (int) config('record.cache.default_ttl', self::cacheTtl());
    }

    public static function useSubqueryOptimization(): bool
    {
        return (bool) config('record.use_subquery_optimization', true);
    }

    public static function auditEnabled(bool $default = false): bool
    {
        return (bool) config('audit.enabled', $default);
    }

    public static function auditLogModel(): string
    {
        return (string) config('audit.audit_log_model', 'audit_logs');
    }

    public static function auditQueueEnabled(): bool
    {
        return (bool) config('audit.queue_enabled', false);
    }

    public static function auditQueueConnection(): string
    {
        return (string) config('audit.queue_connection', 'default');
    }

    public static function auditQueueName(): string
    {
        return (string) config('audit.queue_name', 'default');
    }

    public static function auditRetentionDays(): ?int
    {
        $value = config('audit.retention_days', 365);

        if (null === $value) {
            return null;
        }

        return (int) $value;
    }

    public static function auditExcludedEvents(): array
    {
        return (array) config('audit.excluded_events', []);
    }

    public static function auditExcludedAttributes(): array
    {
        return (array) config('audit.excluded_attributes', []);
    }

    public static function auditLogAuthenticationEvents(): bool
    {
        return (bool) config('audit.log_authentication_events', true);
    }

    public static function auditLogRelationships(): bool
    {
        return (bool) config('audit.log_relationships', false);
    }

    public static function auditRecapEntities(): array
    {
        return (array) config('audit.recap_entities', []);
    }

    public static function auditMainFieldLabels(): array
    {
        return (array) config('audit.main_field_labels', []);
    }

    public static function auditRecapMaxFields(): int
    {
        return (int) config('audit.recap_max_fields', 6);
    }

    public static function auditPerformanceMaxRelationships(): int
    {
        return (int) config('audit.performance.max_relationships', 10);
    }

    public static function auditPerformanceUseTransactions(): bool
    {
        return (bool) config('audit.performance.use_transactions', true);
    }

    public static function auditPerformanceBatchSize(): int
    {
        return (int) config('audit.performance.batch_size', 100);
    }

    public static function auditSecurityEncryptSensitiveData(): bool
    {
        return (bool) config('audit.security.encrypt_sensitive_data', false);
    }

    public static function auditSecurityHashIpAddresses(): bool
    {
        return (bool) config('audit.security.hash_ip_addresses', false);
    }

    public static function auditSecurityAnonymizeOldLogs(): bool
    {
        return (bool) config('audit.security.anonymize_old_logs', false);
    }

    public static function auditLogJobClass(string $default = AuditLogJob::class): string
    {
        return (string) config('audit.audit_log_job', $default);
    }

    public static function authGuard(): string
    {
        return (string) config('sp-laravel-api.auth.guard', 'api');
    }

    public static function includeRequestId(): bool
    {
        return (bool) config('sp-laravel-api.response.include_request_id', true);
    }

    public static function openApiOutput(): string
    {
        return (string) config('sp-laravel-api.openapi.output', 'openapi-schema.json');
    }
}
