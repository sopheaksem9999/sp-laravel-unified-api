---
title: MCP / AI SDK / audit-fix work state on develop (as of 2026-10-03)
author: Sem
date: 2026-10-28
id: l-93f03a24
scope: repo
status: active
touches:
  - bin/validate-docs.php
  - "src/Jobs/AuditLogJob.php#AuditLogJob"
  - "src/Services/RecordService.php#RecordService.syncQueryString"
  - "src/Traits/QueryHelpersTrait.php#QueryHelpersTrait.syncQueryString"
  - "tests/Feature/McpDriverParityTest.php#McpDriverParityTest.legacy"
---

## Decided
Committed on `develop` (not pushed): `f1e6343` nested-write authorization, `3d2868d` MCP core + laravel driver + guidance, `99804f0` docs. UNCOMMITTED as of 2026-10-03 (the user stages/commits themselves): the AI SDK record tools (staged by the user), the docs/guide audit, and the audit code fixes from `docs/superpowers/plans/2026-10-03-audit-code-fixes.md` (viewOwn reads the configured guard; HTTP 422 for tenant-scoped includes without a tenant via `Utilities/TenantScopedIncludes`; MCP/AI tools run record hooks + validators via `Mcp/RecordHookPipeline` behind `record.mcp.run_record_hooks`; `AuditLogJob` is `ShouldQueueAfterCommit`). Ledgers with every `Ruling:` and the deferred minors are in `.superpowers/sdd/2026-10-0{2,3}-*/progress.md` (git-ignored; the minors exist only there).

**Why:** the deferred minors and the open items below are not recorded anywhere in git.

The plan's follow-up Tasks 5–10 (also uncommitted) fixed the QA leftovers: beforeRead hook query changes now reach HTTP filters (`RecordService::syncQueryString`), grouped filters accept relationship columns, relationship filters and cached relationship reads are bound to the request tenant (three pre-existing cross-tenant leaks), and the repo is Rector/php-cs-fixer clean so `composer quality` passes. Team-facing status lives in the Claude Doc "AI SDK and Audit Fixes QA Update" (https://claude.ai/code/artifact/a4fa0509-7009-4b76-b721-acd31bf24627).

## Watch out
still open — (1) commit (the user commits; nothing committed yet); (2) verify OAuth discovery against a real Laravel Passport install; (3) attaching an existing related row needs only the parent's update permission — a users->roles relationship makes `update:user` a role-escalation path; (4) `legacy` MCP driver slated for removal in 0.6.0; (5) nothing tested against a live AI provider. Gate: `vendor/bin/phpunit`, `SP_MCP_DRIVER=laravel vendor/bin/phpunit --filter "Mcp|OwnRecords|NestedChildWrite|HiddenColumnSanitizationMutationChannelsTest|SchemaMcpToken|ReservedRouteSegment|PermissionGateInte
…

_From Claude Code's memory of this repo, last updated Oct 3._
