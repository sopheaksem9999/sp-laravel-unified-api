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
