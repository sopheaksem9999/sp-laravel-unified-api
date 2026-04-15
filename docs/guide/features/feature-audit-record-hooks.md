---
title: "Dynamic Record API Audit Hooks and Custom Handlers"
description: "Feature guide for table-level audit controls with customAuditLog, disableAuditLog, and global audit config dependencies."
keywords:
  - RecordTableType customAuditLog
  - disableAuditLog
  - dynamic CRUD auditing
  - audit enabled
  - audit queue enabled
  - audit log relationships
  - custom handler context
---

# Audit in Dynamic Record API

## Per-table Controls

In `RecordTableType`:

- `disableAuditLog`: disable built-in audit for the table
- `customAuditLog`: custom handler for create/update/delete operations

## Global Config Dependencies

- `audit.enabled`
- `audit.queue_enabled`
- `audit.log_relationships`

## Handler Context

Custom handler receives event/entity/audit data plus runtime context (`request`, `table`, `operation`, `record_context`).
