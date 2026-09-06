---
title: "Audit Filter Review and Implementation Plan"
description: "Add an opt-in audit predicate while preserving existing audit handlers, queue contracts, and client upgrade behavior."
keywords:
  - audit filter
  - backward compatibility
  - customAuditLog
  - Octane
  - implementation plan
---

# Audit Log Filter Hook Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans to implement this plan task-by-task. This document records the approved design. See the implementation status below for delivered scope and verification limits.

**Goal:** Provide a general-purpose audit admission policy for consuming applications across HTTP, manual service calls, background work, and multi-tenant operations, without changing existing clients by default.

**Architecture:** Keep table orchestration in `RecordService`, audit admission and persistence in the audit module, and application user-type rules in the consuming application. Add one optional predicate before audit preparation and queue dispatch. Preserve the existing custom logger as a replacement handler.

**Tech Stack:** Existing PHP 8.2+ package, Laravel 12/13, Orchestra Testbench, PHPUnit, database-agnostic query builder.

## Implementation status (2026-09-06)

The core is implemented in the working tree: nullable config, interface and stateless evaluator, context-aware admission, record/bulk integration, configuration validation, fallback scaffold config, guide updates, and regression coverage. Compatibility tests are consolidated in `tests/Feature/AuditLogFilterTest.php` rather than a separate compatibility file. Optional roadmap features remain deferred.

Verified: full suite executed 790 tests / 2358 assertions with no test failures or errors; four tests were skipped. The runner exits nonzero for the existing abstract `PackageTableGovernedIdTypeTest` warning and reports existing deprecations. New policy tests pass independently. Local execution uses PHP 8.4 / SQLite; real Octane worker and MySQL/PostgreSQL compatibility smoke checks remain deployment/CI work.

Repository docs validation still reports the seven pre-existing missing-frontmatter files. The normal formatter/analysis parallel mode cannot bind a sandbox localhost socket; debug mode is used instead. Repository-wide formatting also proposes unrelated existing changes; the new policy files receive focused formatter verification. The AST graph update completed.

Implementation inspection additionally confirmed two existing behaviors that were not fully traced in the original review:

- Bulk upsert submits through both its inner `update` path and the `upsert` wrapper. Four submissions for two items are preserved and regression-tested; filtering evaluates each submission independently.
- `AuditableTrait` directly processes/dispatches audits and bypasses the submission API. `executeCreate`/`executeUpdate`/`executeDelete` dispatch queued lifecycle listeners; those listeners reach `log` in the worker, where no originating request actor is available. The hook cannot remove the already-enqueued listener's overhead. These boundaries are documented, not changed through a queue contract redesign in this release.

The checklist below remains the original implementation/release checklist; external worker/database checks are not claimed complete.

## Package design decision

The client request is evidence of a missing extension point, not the package specification. Design for application-owned policy across actors, events, tables, tenants, and execution sources. The package must not know about CamboGara, a `type` column, named roles, or any particular authentication package.

| Approach | Strength | Cost / limitation | Decision |
| --- | --- | --- | --- |
| Config-only lists for users, roles, tables, and routes | Easy for a narrow use case | Assumes application schema, grows many precedence rules, cannot express business exceptions | Do not build a policy language into config. |
| One stateless policy hook with explicit runtime context | Expresses simple and complex app rules while keeping one package admission boundary | Requires a small application class | Recommended core. |
| Ordered policy pipeline with allow/deny/abstain decisions | Reusable independent policies and decision reasons | More public types, ordering rules, and harder debugging | Defer until multiple consumers demonstrate the need. |

Retain the short config name `filter` and boolean `shouldLog` contract for a small first API; describe it as an admission policy. A single class can compose application rules. Do not add both `filter` and `should_log` aliases.

The recommended release contains the hook, explicit context, compatibility tests, configuration validation, and documentation. Optional diagnostics and more advanced controls are prioritized below. These are design recommendations, not implemented features.

## Review of the originating request

Accept requirement 1 with the scope and contract below. Accept requirement 3 using the existing `src/Interfaces/` convention. Do not implement requirement 2 in this release: its proposed return semantics are breaking, even when the new global filter is absent.

The requested client policy is reasonable, but the request's claim of “100% Backward Compatible” is incorrect. A legacy custom logger commonly returns `void`/`null` after writing its own row. Falling through on that return value writes a second row. A handler returning `false` may likewise already have intentionally consumed the event. Both must retain their current behavior.

A separate table predicate could be proposed later if needed. Do not add a global compatibility-mode switch or overload `customAuditLog` merely to deliver the global filter.

## Verified source findings

Reviewed against this checkout (`composer.json` version `0.4.96`), rather than assuming the client's `^0.4.94` implementation is identical.

| Source | Finding and implication |
| --- | --- |
| `src/Services/RecordService.php:449` | Actual method is `processPostWriteLogic`, not the request's `executeTableTriggers`. Table and global triggers precede audit; broadcasting follows it. A filter must skip only audit, not return from this entire method. |
| `src/Services/RecordService.php:1054` | `callCustomAuditLogger` ignores the callback result and returns whether it invoked a handler. Preserve signature, resolution forms, fallback behavior, and return semantics. |
| `src/Services/RecordService.php:994` | Bulk upsert has a separate custom/default audit branch. Cover this branch explicitly; changing only post-write logic is incomplete. |
| `src/Services/AuditLogService.php:313` | `insertAuditLog` is the shared admission point for manual calls and normal record audits. Its diff-only preparation can query prior audit history before dispatch, so place rejection before this preparation. |
| `src/Services/AuditLogService.php:260` | `authEvent` has its own dispatch path. Keep authentication auditing outside the mutation predicate in this release. |
| `src/Jobs/AuditLogJob.php` | Jobs call `handleAuditDataEntry`; do not evaluate a request-dependent filter again in workers. Custom job classes are supported and their dispatch arguments must remain unchanged. |
| `src/CoreSpLaravelApiProvider.php:55` and `src/Config/ConfigNamespaceBridge.php` | Published `sp-audit.php` maps to canonical `audit.*`. Use this bridge, not another namespace or precedence scheme. |
| `src/Types/RecordTableType.php:132` | Existing schema already has custom/disabled audit controls. No schema constructor or `__set_state` change is required. |
| `src/Enums/AuditLogEventEnum.php` | Restore is currently mapped to UPDATED by record orchestration; there is no RESTORED enum case. Keep that mapping. |

Graphify query was attempted, but `graphify-out/graph.json` is absent. Findings above come from the guide pages and direct source inspection. No client application or production traffic was inspected.

## Public contract and scope

Add only this configuration default in `config/sp-audit.php`:

```php
'filter' => null,
```

Canonical read: `config('audit.filter', null)`. Missing/null means unconditional admission without resolving a filter or actor. Old published configs need no republish or edits. No migration, dependency increase, route change, response change, or version bump is part of implementation.

Create `src/Interfaces/AuditLogFilterInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Interfaces;

use Illuminate\Contracts\Auth\Authenticatable;
use Sopheak\Core\Enums\AuditLogEventEnum;

interface AuditLogFilterInterface
{
    public function shouldLog(
        AuditLogEventEnum $event,
        string $table,
        array $auditData,
        ?Authenticatable $user,
        array $context = [],
    ): bool;
}
```

Supported configuration forms are null, a class string implementing this interface, or `[ClassName::class, 'method']` with a public static or container-resolved instance method. No closures or object instances in published config; keep `config:cache` safe. Resolve dependencies through the container at invocation time. Do not automatically discover interface bindings when config is null. An application wanting a binding can explicitly set `filter` to `AuditLogFilterInterface::class` and bind it to a concrete implementation.

The predicate receives the incoming audit array before timestamp removal, historical lookup, or diff reduction. It may contain `old_data`/`new_data` or their existing aliases, but those snapshots and a complete diff are **not guaranteed**. Do not query history to build a new payload contract just for this filter.

Context keys:

- `request`: current request when available, otherwise null; never serialized into a job.
- `table`: actual table passed by RecordService; manual calls use the existing entity-to-table resolver.
- `operation`: record operation including restore/upsert; manual calls default to the event value.
- `tenant_id`: the explicit tenant argument already used for this audit, including custom tenant column configurations. Do not derive policy tenancy from untrusted request payloads.
- `record_context`: existing record context, or an empty array for manual calls.
- `request_context`: existing record request context, or an empty array when unavailable.
- `source`: one of `record`, `manual`, or `authentication`, describing the submission API rather than guessing from the URL. A manual submission can originate in a job or command.
- `actor`: optional explicit `Authenticatable|null` for the new context-aware manual API. Use `array_key_exists` so explicit null means anonymous/system and never falls back to an unrelated authenticated user. This value is used to resolve the separate `$user` argument, not serialized.

Treat these context keys as a documented public contract. Additional keys may be added without requiring callback signature changes. Do not assume Laravel's bound request proves an HTTP request exists in console execution. When constructing generic context in console, default request and actor to null; callers needing an actor use the explicit context API.

Actor resolution first honors an explicit context actor (including explicit null), then uses the supplied request's user resolver when present; only for HTTP calls without a supplied request use the current authentication context. A supplied request resolving no user remains anonymous. Never substitute the mutated record's user for the actor. CLI/queue-originated manual writes may have a null actor. Filters must not retain requests, users, tenants, or decisions in static properties or singleton fields.

Only an actual boolean false suppresses. True admits. Invalid configuration, a non-boolean result, or an exception admits the audit and emits a sanitized warning containing only a stable reason code and filter class/method identifier. Do not log exception messages, payloads, actor objects, or traces. This preserves audit coverage and business writes during a policy failure; it can increase audit volume, so document this choice prominently. Tests must prove failures remain observable.

### Admission boundaries

| Path | Proposed behavior |
| --- | --- |
| Built-in create/update/delete/restore and bulk upsert | Filter immediately before built-in audit admission, once per entry. |
| Manual `insertAuditLog` and instance `log` | Same filter, with generic runtime context. |
| Custom logger that calls `insertAuditLog` | That built-in call is filtered; custom callback invocation itself is unchanged. |
| Custom logger writing directly to another sink/DB | Outside package admission control. |
| `authEvent` | Unchanged; login/logout/failed-login coverage is not disabled by a mobile-client mutation rule. |
| Direct `handleAuditDataEntry`, `createAuditLogEntry`, or direct job dispatch | Low-level processing paths remain unchanged and bypass admission; document that callers should use `insertAuditLog` for policy enforcement. |

Apply the initial filter only to CREATED, UPDATED, and DELETED enum events, including existing restore/upsert mappings. Other events submitted through `insertAuditLog` remain unchanged too; do not protect `authEvent` while accidentally filtering LOGIN passed through another API. Authentication admission is a separate optional capability below.

This is a global filter for the built-in mutation submission API, not a universal database-write interceptor. Existing queued jobs execute unchanged; disabling a user's new submissions does not retroactively cancel queued entries.

## General-client behavior matrix

| Use case | Required behavior / policy responsibility |
| --- | --- |
| Existing client with no policy | Same persistence, custom callback, and job behavior as today. |
| Staff/admin/mobile users | Application policy resolves its own roles or account types; package supplies actor without assuming a database column. |
| Guest or public endpoint | Actor may be null; application explicitly chooses whether to retain the event. |
| Multiple auth guards / service accounts | Use the request's resolved actor or explicit actor; never infer the actor from a changed row's `user_id`. |
| Multi-tenant app | Supply authoritative tenant ID, including string/UUID IDs and custom tenant column names; do not cache decisions by actor alone. |
| Import, scheduler, CLI, queue-originated write | New context-aware manual entry accepts explicit actor/null, tenant and operation. No HTTP request required for policy evaluation. This does not promise fixes to existing downstream console persistence behavior. |
| High-volume location/profile updates | Policy can deny selected table/operation combinations without disabling all activity by that actor. |
| Business-critical exceptions | App checks protected operations first, e.g. retains a payment change even when it suppresses routine mobile-client mutations. This governs only submissions reaching the hook. |
| Restore and upsert | Preserve existing event mapping; use `operation` for finer distinctions. Do not infer whether an upsert created a row when current orchestration does not provide that fact. |
| Bulk mixed operations | Evaluate per audit entry using that entry's data and context; never allow one decision to suppress the whole batch. |
| No-op update | True means eligible, not guaranteed persistence; existing no-change checks still apply. |
| Transaction rollback | Policy changes admission only. Preserve existing transaction/queue timing; test that this feature introduces no new dispatch timing behavior. |
| Custom audit storage | Replacement callback remains responsible for its own policy unless it submits through the package admission API. |
| Filter failure | Default to retaining the audit and emit a sanitized diagnostic. This is a coverage-first default, not an assurance that excluded sensitive content will never be stored. |
| Queued deployment / retries | Evaluate before submission; do not re-evaluate under a worker's actor or newer config. Preserve existing job payloads. |

Policy order is explicit: existing audit disable/custom-handler controls remain in place; built-in eligible mutation submissions consult the policy; accepted submissions continue existing preparation and persistence checks. The policy cannot turn disabled auditing back on or force an unchanged update to be stored. Existing custom handlers retain their current independence from the built-in enabled guard.

The hook is for admission, not payload redaction, authorization, or transactional enforcement. Do not return modified payloads, mutate request/config, or put business writes in a predicate. Payloads are incoming audit data, potentially containing sensitive values; the package must not duplicate them into diagnostic logs.

### Recommended application policy shape

Evaluate business exceptions before broad exclusions. For example: retain business-critical tables, suppress selected noisy table/operation combinations for regular users, then retain everything else. Table names, actor classification, and protected operations belong in the application. A policy depending on complete historical diffs must be implemented at a domain layer that already has those snapshots; the early package hook does not manufacture them.

## Nice-to-have roadmap

| Priority | Addition | Value | Boundary / delivery condition |
| --- | --- | --- | --- |
| Include in first release | Validate configured class/method | Detect typos during deployment instead of silently admitting excess logs | Extend the existing config validation command; check shape, existence, interface and public method without invoking user policy or constructing request-dependent services. Runtime fallback remains necessary. |
| Next small feature | Policy dry-run command | Explain admission for a supplied synthetic event, table, actor and tenant before activation | No business writes or audit dispatch; explicitly supplied fixtures only. A callback itself may have side effects, so document the pure-policy contract. Do not replay production payloads automatically. |
| Next small feature | Optional decision diagnostics | Count admitted/denied/error decisions without filling audit storage | Off by default; bounded source/event/reason labels, no raw IDs/payloads, no audit write on a skipped event, and no observer failure blocking business writes. |
| Separate opt-in feature | Authentication policy | Some applications need distinct login/logout rules | Separate config key with null default and explicit event/source scope; preserve failed-login trails by default. Never inherit a mutation-deny rule implicitly. |
| Defer pending demand | Per-table `auditFilter` | Reusable local rules without replacement loggers | New predicate field, never reinterpret `customAuditLog`; global deny wins, then table deny. Requires constructor/cache/schema exporter review. |
| Defer pending demand | Typed immutable context object | Stronger discoverability and static analysis | Evaluate before wider API adoption; if added later provide a new interface/adapter rather than changing this interface's argument types. Avoid publishing array and object APIs together without need. |
| Separate audit correctness work | Explicit actor metadata in queued persistence and after-commit dispatch | Improve attribution and rollback behavior independently of filtering | Requires queue compatibility/mixed-version rollout design; do not claim request actor supplied to the predicate also fixes existing worker-side metadata. |
| Separate privacy work | Audited payload redaction | Prevent sensitive fields reaching storage | Review actual excluded-attribute enforcement independently; admission is not redaction. |
| Avoid for audit policy | Random sampling / request-wide mutable disable switch | May reduce volume | Sampling loses deterministic coverage; mutable switches risk leaking decisions between requests. Use explicit domain rules. |

Do not add all optional features in one release. The first release should make the extension point predictable; diagnostics should follow before adding policy composition complexity.

## Implementation tasks

### Task 1: Lock existing client behavior

Files: extend `tests/Unit/AuditLogServiceTest.php`; create `tests/Feature/AuditLogFilterCompatibilityTest.php`.

- [ ] Add baseline tests for missing/null filter in sync and queued audit modes, preserving inserted content and dispatched arguments.
- [ ] Add custom logger fixtures returning void, null, false, and true; assert each callable still consumes the default audit. An invalid/unresolvable existing handler must still fall back as before.
- [ ] Cover `disableAuditLog`, disabled global audit, diff-only snapshots, no-change updates, custom tenant column, and custom audit job dispatch.
- [ ] Run `vendor/bin/phpunit tests/Unit/AuditLogServiceTest.php tests/Feature/AuditLogFilterCompatibilityTest.php`. These characterization tests must pass before runtime edits.

### Task 2: Implement isolated predicate evaluation

Files: create `src/Interfaces/AuditLogFilterInterface.php`, `src/Services/AuditLogFilterService.php`, and `tests/Unit/AuditLogFilterServiceTest.php`; modify `config/sp-audit.php`. Extend `src/Console/ValidateSetupCommand.php` using its current reporting mechanism; cover it in `tests/Feature/ValidateSetupCommandTest.php`.

- [ ] Write failing tests for each supported configuration form, dependency injection, true/false, null actor, invalid return, invalid method/class, and thrown exceptions.
- [ ] Add the interface above and a stateless evaluator with `shouldLog(AuditLogEventEnum $event, string $table, array $auditData, ?Authenticatable $user, array $context = []): bool`. Read the configured filter each call; return true immediately for null. Resolve only the documented forms, invoke with the five arguments in order, strictly validate a boolean result, and use the failure policy above.
- [ ] Extend the existing validation command to reject malformed filter config, nonexistent classes/methods, non-public methods and class-only filters missing the interface. Do not execute the predicate or instantiate services during validation. Add valid/invalid configuration test cases.
- [ ] Add the null configuration key with cache-safe examples. Avoid registering a singleton that captures request dependencies.
- [ ] Run `vendor/bin/phpunit tests/Unit/AuditLogFilterServiceTest.php`; all evaluator cases must pass.

### Task 3: Integrate admission without changing old method signatures

Files: modify `src/Services/AuditLogService.php`; extend `tests/Unit/AuditLogServiceTest.php`; create `tests/Feature/AuditLogFilterTest.php`.

- [ ] Write failing tests asserting false causes neither `AuditLogJob` dispatch nor audit persistence, including diff-only mode where history lookup must not run. Assert true dispatches/inserts once and the callback runs once.
- [ ] Preserve `insertAuditLog`'s current parameter names, types, defaults, and return type. Add a new `insertAuditLogWithContext` entry with the same six arguments plus trailing `array $context = []`; do not add an optional parameter to the existing method, because consumers may override it in subclasses.
- [ ] Extract the current post-enabled-check body into a private shared persistence helper, preserving its ordering and late-static calls to existing processing methods. Both entry methods apply the enabled guard and admission before invoking that helper. The new entry uses supplied context; the old entry builds generic context. Do not have the context entry call the filtered old entry and evaluate twice.
- [ ] Add source, explicit actor/null precedence, CLI-without-request, manual LOGIN bypass, and guard-specific actor tests from the behavior matrix. Preserve non-mutation events even when they enter through `insertAuditLog`.
- [ ] Keep `log` delegating through its existing public entry. Preserve `authEvent`, `handleAuditDataEntry`, `createAuditLogEntry`, and job signatures. Never pass request/filter objects to dispatch and never re-evaluate in a worker.
- [ ] Add an inheritance regression fixture overriding the old method with its exact existing signature. Verify old callers remain compatible. Document that opting into the new context entry is a new API and does not invoke overrides of the old entry.
- [ ] Run `vendor/bin/phpunit tests/Unit/AuditLogServiceTest.php tests/Feature/AuditLogFilterTest.php tests/Feature/AuditLogFilterCompatibilityTest.php`.

### Task 4: Carry record context and preserve unrelated side effects

Files: modify `src/Services/RecordService.php`; extend `tests/Feature/AuditLogFilterTest.php` and `tests/Feature/AuditLogFilterCompatibilityTest.php`.

- [ ] Add failing integration tests for normal mutation audits and the separate bulk-upsert branch. Assert actual table, operation, supplied request actor, payload, and tenant argument reach the callback.
- [ ] At both existing built-in audit call sites, retain the current call when filter config is null. When configured, call `insertAuditLogWithContext` with the existing local context plus authoritative tenant/table values. Leave `callCustomAuditLogger` untouched.
- [ ] Do not return from `processPostWriteLogic` when the predicate denies: the service entry returns locally so broadcasting continues. Test table/global triggers and broadcasts still execute for filtered built-in audits.
- [ ] Preserve the existing early return after a custom logger. Its effect on broadcasting is a pre-existing separate issue; do not silently fix it in this feature.
- [ ] Test two distinct requests in the same application instance: client/tenant A denied, administrator/tenant B admitted, then anonymous according to the application policy. Verify no state retention and no configuration mutation. Add an Octane consuming-app smoke check for real worker isolation; an in-process test alone is not proof of every Octane mode.
- [ ] Run `vendor/bin/phpunit tests/Feature/AuditLogFilterTest.php tests/Feature/AuditLogFilterCompatibilityTest.php tests/Feature/AuditableRelationshipAuditTest.php`.

### Task 5: Configuration, documentation, and release gates

Files: extend `tests/Feature/ConfigNamespaceBridgeTest.php`; update `docs/features/audit-logging.md`, `docs/guide/features/feature-audit-record-hooks.md`, and `docs/guide/features/feature-audit-manual-controller.md`. Update the fallback `defaultAuditConfig` in `src/Console/SetupPackageCommand.php:818`.

- [ ] Test old config without filter, published `sp-audit.filter`, canonical legacy `audit.filter`, and existing bridge precedence. Test a real config-cache round trip using class and class-method settings; assert cached null remains a no-op and class resolution happens at runtime.
- [ ] Include the new commented null default in setup's `defaultAuditConfig`. Extend `tests/Feature/SetupPackageScaffoldTest.php` if changed.
- [ ] Cross-check every general-client behavior matrix row against an automated regression or explicitly recorded consuming-app smoke check. Verify no cross-entry suppression in mixed bulk operations.
- [ ] Document scope, raw payload limitations, strict booleans, failure behavior, anonymous/system policy, existing custom handler behavior, and the explicit opt-in interface binding.
- [ ] Include this consuming-app example (application-specific account types stay outside the package):

```php
// In config/sp-audit.php:
'filter' => [App\Record\Shared\AuditLogFilter::class, 'shouldLog'],

// App\Record\Shared\AuditLogFilter::shouldLog uses the interface signature:
// return $user?->type !== 'client';
// Anonymous actors are admitted by this example; choose this intentionally.
```

- [ ] Run `composer docs:validate`, `composer format-check`, `composer analyse`, and `composer test`. Record existing failures separately from regressions; do not claim compatibility when mandatory checks are blocked.
- [ ] Run `graphify update .` after implementation code changes, following the installed skill. If no graph exists, report that limitation rather than generating an unrelated full graph without need.
- [ ] Verify supported Laravel/PHP CI combinations and database jobs available to the project. No new SQL is expected, but existing MySQL/PostgreSQL/SQLite audit behavior must remain intact. Report untested combinations explicitly.

## Rollout and acceptance

1. Ship as an additive release under the existing dependency range only after the regression gates pass. No mandatory config republish, schema migration, or data cleanup.
2. Existing clients upgrade with a missing/null filter and retain current logging, custom handlers, queue payloads, and response behavior.
3. CamboGara enables the app-owned filter in staging, rebuilds cached config, and reloads its long-running workers through its normal deployment procedure. Test client, administrator, service-provider, anonymous, and system mutations using the actual auth guard and tenant middleware.
4. Confirm no rejected entry reaches audit preparation or queue dispatch, admitted entries match existing content, and authentication trails remain present. Business writes still succeed in both cases.
5. Roll back the policy by returning the filter setting to null and refreshing config/workers. Previously skipped audit entries are not recoverable automatically; this release does not delete existing audit data.

Package acceptance takes precedence over matching the originating request: verify the general-client behavior matrix and first-release scope above.

Originating request coverage: requirement 1 is delivered by tasks 2–4 with a deliberately bounded admission API; requirement 2 is rejected for compatibility and its legacy behavior is tested in tasks 1 and 4; requirement 3 is delivered in task 2 using package naming conventions. No runtime code is changed by this planning document.
