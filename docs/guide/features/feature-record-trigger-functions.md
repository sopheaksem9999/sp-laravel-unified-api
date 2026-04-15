---
title: "Record Trigger Functions"
description: "Compatibility entry for legacy trigger docs. Use Record Hooks as the canonical lifecycle hook guide."
keywords:
  - trigger function
  - record hooks
  - beforeCreate afterCreate
  - beforeUpdate afterUpdate
  - beforeRead afterRead
  - RecordTrigger attribute
  - RecordTableTriggerType
  - global triggers
---

# Record Trigger Functions

This page is kept for backward compatibility.
Use the canonical guide:

- [Record Hooks](/guide/record-hooks)

## Legacy Summary

- Hooks supported: `beforeRead`, `afterRead`, `beforeCreate`, `afterCreate`, `beforeUpdate`, `afterUpdate`, `beforeDelete`, `afterDelete`, `beforeRestore`, `afterRestore`.
- Signature shape: `fn(Request $request, string $table, array $context): Request|array|void`.
- Registration patterns: direct mapping (`RecordTableTriggerType`), class auto-discovery (`#[RecordTrigger]`), and `global_triggers`.
- Execution order: `before*` global then table; `after*` table then global.

## Advanced Reference

- [Configuration, Validation, and Triggers](/guide/api-config-validation-triggers)
