---
title: "Manual Audit Logging"
description: "Feature guide for explicit audit logging in custom controller and service logic using snapshot-based patterns. Controller and Service Flows "
keywords:
  - manual audit logging
  - controller audit
  - service layer audit
  - audit log service
  - insert audit log
  - getAuditQuery
  - custom snapshot
---

# Audit in Controller Flow

Use this approach when data is updated outside Dynamic Record API or when you need explicit snapshot control.

## Typical Flow

1. Update business data.
2. Build snapshot payload (`getAuditQuery()` style).
3. Call `AuditLogService::insertAuditLog(...)`.

## Good Use Cases

- Custom transactional flows
- Command/queue-based domain updates
- Legacy controller endpoints

## Explicit Admission Context

`insertAuditLog` and instance `log` consult optional `audit.filter` before preparation or queue dispatch. Existing signatures and defaults remain supported. For background work or an explicit actor, use the additive API:

```php
AuditLogService::insertAuditLogWithContext(
    auditLogEventEnum: \Sopheak\Core\Enums\AuditLogEventEnum::UPDATED,
    entityClass: 'invoices',
    queryData: ['id' => $invoiceId, 'old_data' => $before, 'new_data' => $after],
    tenantId: $tenantId,
    context: [
        'actor' => $actor, // Authenticatable or explicit null for system work
        'request' => null,
        'operation' => 'import',
    ],
);
```

Explicit null actor never falls back to ambient authentication. The tenant argument wins over context. Manual source is `manual`. Context affects admission only: it does not rewrite stored actor metadata and is never serialized into the audit job. Old subclasses overriding `insertAuditLog` remain compatible; the new API does not invoke those overrides.

Only mutation events are filtered. False skips, true continues existing no-change/diff processing; errors retain the audit with a sanitized warning. Null/missing config preserves existing behavior. No automatic interface binding discovery occurs when config is null.

Direct processing methods, trait auditing, and direct job dispatch bypass admission. A queued lifecycle listener invoking `log` evaluates in its worker; use the context-aware submission API directly when the decision must happen with the originating actor before queueing. See [full policy contract](/features/audit-logging).
