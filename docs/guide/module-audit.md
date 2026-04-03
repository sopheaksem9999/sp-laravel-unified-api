---
title: "SP Laravel API Audit Module Architecture"
description: "Architecture overview for audit logging modes including dynamic table hooks, manual service patterns, and model trait support."
keywords:
  - audit architecture
  - audit configuration
  - custom audit hooks
  - queue audit
  - audit recap
  - entity labels
  - audit subject fields
  - auditable trait
---

# Audit Module

Audit support is available through three patterns:

- Dynamic Record API with per-table controls
- Manual controller/service-level audit insertion
- Eloquent model-level auditing via trait

## Core Controls

- Global enable/disable via `config('audit.enabled')`
- Queue mode via `config('audit.queue_enabled')`
- Per-table control via `RecordTableType` (`disableAuditLog`, `customAuditLog`)

## Related Feature Docs

- [Audit in Controller Flow](./feature-audit-manual-controller.md)
- [Audit in Dynamic Record API](./feature-audit-record-hooks.md)
