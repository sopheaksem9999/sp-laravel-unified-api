<?php
namespace Sopheak\Core\Services;

class UtilityService
{
    public static function isTenantIdEnabled(): bool
    {
        return RecordConfigService::enableTenantId();
    }

    public static function normalizeTenantId(mixed $tenantId): mixed
    {
        if (is_string($tenantId)) {
            return trim($tenantId);
        }

        return $tenantId;
    }

    public static function isTenantIdMissing(mixed $tenantId): bool
    {
        $tenantId = self::normalizeTenantId($tenantId);

        return null === $tenantId || '' === $tenantId;
    }

    public static function shouldApplyTenantId(object $tableSchema): bool
    {
        return self::isTenantIdEnabled() && (bool) ($tableSchema->has_tenant_id ?? false);
    }
}
