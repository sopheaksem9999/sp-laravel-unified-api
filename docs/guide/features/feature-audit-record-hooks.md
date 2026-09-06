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

## Optional Global Mutation Policy

`audit.filter` defaults to null; configure it in published `config/sp-audit.php` as an `AuditLogFilterInterface` class or `[ClassName::class, 'method']`. The container resolves instance dependencies. A false result skips the built-in submission before audit preparation/dispatch; true continues normal processing. Invalid config/results or exceptions retain logging with a sanitized warning.

The five arguments are event enum, table, incoming audit payload, nullable actor, and runtime context. Context includes request, operation, authoritative tenant_id, record_context, request_context, and source (`record`). Snapshot/diff completeness is not guaranteed. Keep the policy stateless for long-running workers.

Existing custom logger return values are ignored: callable means handled, even for void/null/false. Global filtering does not suppress custom callback invocation. Table/global triggers and broadcasting continue when the built-in submission is rejected. Existing custom-handler early-return behavior is unchanged.

Normal post-write logic and the separate bulk-upsert wrapper both use the policy. Existing bulk upsert also audits through its inner update path; each submission gets its own decision. Authentication and direct low-level processing/trait calls are outside this hook. Queued lifecycle listeners evaluate when they call `log` in the worker, without the originating request actor.

See [audit policy configuration and coverage](/features/audit-logging).
