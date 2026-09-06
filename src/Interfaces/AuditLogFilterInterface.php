<?php

declare(strict_types=1);

namespace Sopheak\Core\Interfaces;

use Illuminate\Contracts\Auth\Authenticatable;
use Sopheak\Core\Enums\AuditLogEventEnum;

interface AuditLogFilterInterface
{
    /**
     * @param array<string, mixed> $auditData Incoming payload, not a guaranteed full diff.
     * @param array<string, mixed> $context Runtime-only metadata; never serialized.
     */
    public function shouldLog(AuditLogEventEnum $event, string $table, array $auditData, ?Authenticatable $user, array $context = []): bool;
}
