<?php

declare(strict_types=1);

namespace Sopheak\Core\Services;

use Sopheak\Core\Types\RecordTableType;
use InvalidArgumentException;
use Throwable;
use Sopheak\Core\Jobs\AuditLogJob;
use Sopheak\Core\Support\RecordConfigLoader;

class RecordConfigService
{
    private const DIRECT_UPLOAD_FUNCTION_KEYS = [
        'create-upload-url',
        'complete-upload',
        'create-multipart-upload',
        'sign-multipart-part',
        'complete-multipart-upload',
        'abort-multipart-upload',
    ];

    private const PREVIEW_FUNCTION_KEY = '{id}/preview';

    public static function enableTenantId(): bool
    {
        return (bool) config('record.enable_tenant_id', false);
    }

    public static function tenantColumn(): string
    {
        return (string) config('record.tenant_column', 'tenant_id');
    }

    public static function tenantColumnType(): string
    {
        return (string) config('record.tenant_column_type', 'string');
    }

    /**
     * Primary key type for the bundled sp_permissions and sp_roles tables.
     *
     * Config is a system boundary, so an unrecognized value fails loudly here
     * rather than silently falling back to integer, which would be expensive
     * to discover once tables are already migrated.
     *
     * @throws InvalidArgumentException when the configured value is not
     *                                  'uuid' or 'integer'
     */
    public static function idType(): string
    {
        $configured = config('record.id_type') ?? 'integer';

        if (!is_string($configured)) {
            throw new InvalidArgumentException(sprintf(
                'record.id_type must be "uuid" or "integer", got %s',
                get_debug_type($configured)
            ));
        }

        $normalized = strtolower(trim($configured));

        if (!in_array($normalized, ['uuid', 'integer'], true)) {
            throw new InvalidArgumentException(sprintf(
                'record.id_type must be "uuid" or "integer", got "%s"',
                $configured
            ));
        }

        return $normalized;
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
        $prefix = config('record.rpc_prefix');

        return is_string($prefix) ? $prefix : 'rpc';
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

    public static function cacheAdmissionEnabled(): bool
    {
        $enabled = config('record.cache.admission.enabled');
        if ($enabled !== null) {
            return (bool) $enabled;
        }

        if (self::cacheAdmissionOnlyTables() !== []) {
            return true;
        }

        if (self::cacheAdmissionExceptTables() !== []) {
            return true;
        }

        if (self::cacheAdmissionOnlyActions() !== []) {
            return true;
        }

        if (self::cacheAdmissionExceptActions() !== []) {
            return true;
        }

        return self::cacheAdmissionSkipQueryParams() !== [];
    }

    public static function cacheAdmissionOnlyTables(): array
    {
        return (array) config('record.cache.admission.only_tables', []);
    }

    public static function cacheAdmissionExceptTables(): array
    {
        return (array) config('record.cache.admission.except_tables', []);
    }

    public static function cacheAdmissionOnlyActions(): array
    {
        return (array) config('record.cache.admission.only_actions', []);
    }

    public static function cacheAdmissionExceptActions(): array
    {
        return (array) config('record.cache.admission.except_actions', []);
    }

    public static function cacheAdmissionSkipQueryParams(): array
    {
        return (array) config('record.cache.admission.skip_query_params', []);
    }

    public static function pgsqlTenantContextMode(): string
    {
        $mode = (string) config('record.pgsql_tenant_context.mode', 'session');

        return in_array($mode, ['session', 'session_once', 'transaction_local', 'off'], true) ? $mode : 'session';
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

    /**
     * Owner-column resolution order for `viewOwn:*` scoping, used when a table
     * declares no explicit `ownerColumn`. Non-string / blank entries are dropped
     * so a malformed config degrades to the shipped default rather than
     * producing an invalid column reference.
     *
     * @return array<int, string>
     */
    public static function ownRecordsOwnerColumns(): array
    {
        $configured = config('record.own_records_owner_columns', ['created_by_id', 'created_by']);

        $columns = [];
        foreach ((array) $configured as $column) {
            if (!is_string($column)) {
                continue;
            }

            $column = trim($column);
            if ('' !== $column) {
                $columns[] = $column;
            }
        }

        return [] === $columns ? ['created_by_id', 'created_by'] : array_values(array_unique($columns));
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
        $configured = array_merge(self::globalFunctionConfigFiles(), (array) config('record.global_functions', []));

        if (!(bool) config('sp-laravel-api.attribute_discovery.enabled', false)) {
            return $configured;
        }

        try {
            $discovered = AttributeDiscoveryService::discoverGlobalFunctions();
        } catch (Throwable) {
            $discovered = [];
        }

        if ([] === $discovered) {
            return $configured;
        }

        // File-based config takes precedence on key conflicts.
        return array_merge($discovered, $configured);
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
        return (bool) config('record.default_validation.enabled', false);
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

    public static function getTableConfig(?string $table = null): mixed
    {
        $recordTables = array_merge((array) config('record.tables', []), self::tableConfigFiles());
        $attachmentEnabled = (bool) config('attachments.enabled', true);
        $attachmentTables = $attachmentEnabled ? (array) config('attachments.tables', []) : [];
        $webhookEnabled = (bool) config('webhooks.enabled', false);
        $webhookTables = $webhookEnabled ? (array) config('webhooks.tables', []) : [];
        $auditEnabled = (bool) config('audit.enabled', false);
        $auditTables = $auditEnabled ? (array) config('audit.tables', []) : [];
        $permissionTables = (array) config('permissions.tables', []);

        $tables = array_merge($webhookTables, $attachmentTables, $auditTables, $permissionTables, $recordTables);
        $tables = self::applyAttachmentFeatureGating($tables);

        if ($table !== null) {
            return $tables[$table] ?? null;
        }

        return $tables;
    }

    /**
     * @param array<string, mixed> $tables
     * @return array<string, mixed>
     */
    private static function applyAttachmentFeatureGating(array $tables): array
    {
        $attachment = $tables['sp_attachments'] ?? null;
        if (!$attachment instanceof RecordTableType) {
            return $tables;
        }

        $remove = [];
        if (!(bool) config('attachments.direct_upload.enabled', false)) {
            $remove = self::DIRECT_UPLOAD_FUNCTION_KEYS;
        }

        if (!(bool) config('attachments.preview_url_enabled', false)) {
            $remove[] = self::PREVIEW_FUNCTION_KEY;
        }

        if ([] === $remove) {
            return $tables;
        }

        $clone = clone $attachment;
        $clone->functions = array_diff_key((array) $attachment->functions, array_flip($remove));
        $tables['sp_attachments'] = $clone;

        return $tables;
    }

    public static function table(string $table): mixed
    {
        return self::getTableConfig($table) ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function tableConfigFiles(): array
    {
        if ((bool) config('record.autoloaded', false)) {
            return [];
        }

        return RecordConfigLoader::tables(config_path(self::tableConfigPath()));
    }

    /**
     * @return array<string, mixed>
     */
    private static function globalFunctionConfigFiles(): array
    {
        if ((bool) config('record.autoloaded', false)) {
            return [];
        }

        return RecordConfigLoader::globalFunctions(...self::globalFunctionConfigDirectories());
    }

    /**
     * The two accepted spellings of the global-function config directory,
     * relative to a config/ directory.
     *
     * Single source of truth for both call sites that need this list:
     * globalFunctionConfigDirectories() below maps these onto config_path()
     * for the runtime scan, and config/sp-record.php maps the same list onto
     * __DIR__ for the config-cache-friendly autoloaded scan. Add or remove a
     * spelling here only -- editing either call site's own hardcoded list
     * would let the two drift apart silently, since config/sp-record.php is
     * publish-only and no test exercises it directly.
     *
     * @return string[]
     */
    public static function globalFunctionDirectoryNames(): array
    {
        return ['records/globalFunctions', 'records/global-functions'];
    }

    /**
     * @return string[]
     */
    private static function globalFunctionConfigDirectories(): array
    {
        return array_map(config_path(...), self::globalFunctionDirectoryNames());
    }

    /**
     * Clear the memoized table/global-function directory scans.
     *
     * RecordConfigLoader is an implementation detail of this service; callers
     * that need to bust its cache (e.g. SchemaRegistryUtils's test-hygiene
     * and runtime-refresh sweep) go through here rather than reaching into
     * Support directly.
     */
    public static function flushConfigFileCache(): void
    {
        RecordConfigLoader::flush();
    }

    public static function subqueryOptimizationMaxRecords(): int
    {
        return (int) config('record.subquery_optimization_max_records', 100);
    }

    public static function paginationDefaultMode(): string
    {
        return (string) config('record.pagination.default_mode', 'offset');
    }

    public static function cursorDefaultColumn(): string
    {
        $column = config('record.pagination.cursor.default_column');

        return is_string($column) && '' !== trim($column) ? $column : 'id';
    }

    public static function cursorCompositeEnabled(): bool
    {
        return (bool) config('record.pagination.cursor.composite_enabled', true);
    }

    public static function cursorBoundaryEnabled(): bool
    {
        return (bool) config('record.pagination.cursor.boundary_cursors', true);
    }

    public static function skipTotalDefault(): bool
    {
        return (bool) config('record.pagination.skip_total_default', false);
    }

    public static function readConnection(): ?string
    {
        $conn = config('record.database.read_connection');

        return is_string($conn) && $conn !== '' ? $conn : null;
    }

    public static function writeConnection(): ?string
    {
        $conn = config('record.database.write_connection');

        return is_string($conn) && $conn !== '' ? $conn : null;
    }

    public static function indexHints(): array
    {
        return (array) config('record.index_hints', []);
    }

    public static function tableIndexHints(string $table): array
    {
        $hints = self::indexHints();

        return (array) ($hints[$table] ?? []);
    }

    public static function profilingEnabled(): bool
    {
        return (bool) config('record.profiling.enabled', false);
    }

    public static function auditEnabled(bool $default = false): bool
    {
        return (bool) config('audit.enabled', $default);
    }

    public static function auditLogModel(): string
    {
        return (string) config('audit.audit_log_model', 'sp_audit_logs');
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
