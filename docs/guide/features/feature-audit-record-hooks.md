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
- `columnHiddens`: sensitive columns (e.g. `password`, `remember_token`) that are unconditionally stripped (`unset`) from `sp_audit_logs.old_data`, `sp_audit_logs.new_data`, `metadata.field_changes`, update recap messages, and field timeline/stats endpoints. Hidden columns are never displayed in changed-field lists on UI audit feeds.

## Security & Sensitive Field Sanitization

When audit records are generated, sensitive attributes configured under `columnHiddens` (along with `audit.excluded_attributes`) are automatically removed:
- Mutations affecting hidden fields still generate an audit log entry so the mutation history is preserved, but values are omitted from persisted data payloads.
- Recap summaries exclude hidden fields.
- `AuditLogService::getFieldTimeline()` and `AuditLogService::getFieldStats()` return empty data for hidden fields.
- Real-time broadcasting (`RecordMutated` event), outgoing webhook deliveries (`WebhookTrigger`), and Eloquent model audit logs (`AuditableTrait`) also strip hidden columns by default.

## Global Config Dependencies

- `audit.enabled`
- `audit.queue_enabled`
- `audit.log_relationships`

## Row Narrative (`title`, `subject`, `recap`)

These three human-readable columns are never stored empty. A value you supply
always wins; blanks are filled from the event and entity:

| Column | Filled with | Fallback when that is empty |
|---|---|---|
| `title` | `getAuditTitle(event, entity)` — e.g. `Updated Settings` | the entity label |
| `subject` | the first present `audit.subject_fields` value | `{Entity} #{entity_id}` |
| `recap` | `generateRecap()` — e.g. `Updated Settings: Name` | the resolved `title` |

`recap` falls back most often on a record's **first** update: the previous state
is read from the last audit row rather than the live row, so there is nothing to
diff against and the generated recap is empty. Later updates diff normally and
name the changed fields.

Tenant IDs may be `int` or `string` throughout this path — `record.id_type` of
`integer` is fully supported.

## Handler Context

Custom handler receives event/entity/audit data plus runtime context (`request`, `table`, `operation`, `record_context`).

## Optional Global Mutation Policy

`audit.filter` defaults to null; configure it in published `config/sp-audit.php` as an `AuditLogFilterInterface` class or `[ClassName::class, 'method']`. The container resolves instance dependencies. A false result skips the built-in submission before audit preparation/dispatch; true continues normal processing. Invalid config/results or exceptions retain logging with a sanitized warning.

The five arguments are event enum, table, incoming audit payload, nullable actor, and runtime context. Context includes request, operation, authoritative tenant_id, record_context, request_context, and source (`record`). Snapshot/diff completeness is not guaranteed. Keep the policy stateless for long-running workers.

Existing custom logger return values are ignored: callable means handled, even for void/null/false. Global filtering does not suppress custom callback invocation. Table/global triggers and broadcasting continue when the built-in submission is rejected. Existing custom-handler early-return behavior is unchanged.

Normal post-write logic and the separate bulk-upsert wrapper both use the policy. Existing bulk upsert also audits through its inner update path; each submission gets its own decision. Authentication and direct low-level processing/trait calls are outside this hook. Queued lifecycle listeners evaluate when they call `log` in the worker, without the originating request actor.

See [audit policy configuration and coverage](/features/audit-logging).
