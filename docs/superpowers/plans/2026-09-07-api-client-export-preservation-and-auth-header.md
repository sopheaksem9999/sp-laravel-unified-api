---
title: "API Client Export Preservation and Auth Header Plan"
description: "Test-first implementation plan for safe Bruno and Postman exports with force regeneration and request-level auth headers."
keywords:
  - bruno
  - postman
  - export
  - force
  - authToken
---

# API Client Export Preservation and Auth Header Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Bruno and Postman exports preserve user edits by default, support explicit `--force` replacement, and generate request-level `Authorization: Bearer {{authToken}}` headers for protected endpoints.

**Architecture:** Keep OpenAPI parsing and tag-selection in `ApiClientExportService`; extend `ExportResult` with regeneration scope so emitters can make safe write/merge decisions. Bruno returns only files safe to write, while Postman structurally merges generated items into the existing collection. Both emitters generate the same request-level auth contract.

**Tech Stack:** PHP 8.2+, Laravel Artisan commands, PHPUnit 11, Bruno `.bru` files, Postman Collection v2.1 JSON.

---

### Task 1: Carry an explicit overwrite scope through the shared export result

**Files:**
- Modify: `src/Services/ApiClient/ExportResult.php`
- Modify: `src/Services/ApiClientExportService.php`
- Test: `tests/Feature/ApiClientExportServiceTest.php`

- [x] **Step 1: Write failing service tests for selective regeneration adding new endpoints and for all-tag scope.**

```php
$result = $this->service->build($spec, $existing, ['users'], $this->brunoEmitter());

$this->assertContains('Create Orders', $result->added);
$this->assertTrue($result->shouldRegenerateTag('Users'));
$this->assertFalse($result->shouldRegenerateTag('Orders'));
```

```php
$result = $this->service->build($spec, $existing, ['all'], $this->brunoEmitter());

$this->assertTrue($result->shouldRegenerateTag('Users'));
$this->assertTrue($result->shouldRegenerateTag('RPC - Auth'));
```

- [x] **Step 2: Run the focused service tests and verify they fail because `shouldRegenerateTag()` and the add-missing behavior do not exist.**

Run: `vendor/bin/phpunit tests/Feature/ApiClientExportServiceTest.php --filter=regeneration`

- [x] **Step 3: Add `regenerateAll` and selected tag scope to `ExportResult`, plus `shouldRegenerateTag(string $tag): bool`.**

```php
public function shouldRegenerateTag(string $tag): bool
{
    return $this->regenerateAll || isset($this->regenerateTags[strtolower($tag)]);
}
```

Populate the scope in `ApiClientExportService::build()`. Always include fresh
OpenAPI requests in the result: existing non-selected requests are reported as
skipped, selected existing requests as regenerated, and absent requests as
added.

- [x] **Step 4: Re-run the focused service tests and verify they pass.**

Run: `vendor/bin/phpunit tests/Feature/ApiClientExportServiceTest.php --filter=regeneration`

### Task 2: Add force-mode command handling and safe Bruno file writes

**Files:**
- Modify: `src/Console/AbstractExportCommand.php`
- Modify: `src/Console/ExportBrunoCommand.php`
- Modify: `src/Console/ExportPostmanCommand.php`
- Modify: `src/Services/ApiClient/ApiClientEmitterInterface.php`
- Modify: `src/Services/ApiClient/BrunoEmitter.php`
- Test: `tests/Feature/ExportBrunoCommandTest.php`

- [x] **Step 1: Write failing Bruno command tests.**

```php
file_put_contents($this->outputPath . '/Users/List Users.bru', $editedRequest);

$this->artisan('sp-laravel-api:export-bruno', ['--output' => $this->outputPath])
    ->assertExitCode(0);

$this->assertSame($editedRequest, file_get_contents($this->outputPath . '/Users/List Users.bru'));
```

Add tests that `--regen=users` replaces the Users request but preserves Orders,
`--force` replaces all generated requests/support files while retaining a
custom `.bru` file, and `--force` plus `--regen` exits `2` without writes.

- [x] **Step 2: Run the focused Bruno command tests and verify default export currently overwrites the edited body.**

Run: `vendor/bin/phpunit tests/Feature/ExportBrunoCommandTest.php`

- [x] **Step 3: Add `--force` to both command signatures and make it mutually exclusive with `--regen`.**

`AbstractExportCommand` derives `force` once, uses `['all']` as the shared
service regeneration scope, and passes the existing collection plus force mode
to the emitter. The existing `--regen=all` behavior remains valid.

- [x] **Step 4: Make Bruno render only safe writes.**

Update the emitter contract so `render()` receives the existing collection map
and force flag. For each generated request path, render when it is absent,
force is true, or `ExportResult::shouldRegenerateTag($folder)` is true.
Preserve existing support files except that `environments/Local.bru` gets a
missing `authToken` secret entry appended without replacing existing values.
Force renders fresh `bruno.json`, `collection.bru`, and `Local.bru`. Never
delete files.

- [x] **Step 5: Re-run focused Bruno command tests and verify they pass.**

Run: `vendor/bin/phpunit tests/Feature/ExportBrunoCommandTest.php`

### Task 3: Structurally merge Postman collections and emit request-level auth

**Files:**
- Modify: `src/Services/ApiClient/PostmanEmitter.php`
- Modify: `src/Services/ApiClient/BrunoEmitter.php`
- Test: `tests/Feature/PostmanEmitterTest.php`
- Test: `tests/Feature/BrunoEmitterTest.php`
- Test: `tests/Feature/ExportPostmanCommandTest.php`

- [x] **Step 1: Write failing auth-rendering tests for both emitters.**

```php
$this->assertStringContainsString('Authorization: Bearer {{authToken}}', $protectedBru);
$this->assertStringNotContainsString('Authorization:', $publicBru);
$this->assertStringContainsString('bru.setVar("authToken", token)', $loginBru);
```

```php
$headers = array_column($protectedPostman['request']['header'], 'value', 'key');
$this->assertSame('Bearer {{authToken}}', $headers['Authorization']);
$this->assertSame('noauth', $protectedPostman['request']['auth']['type']);
```

Assert the generated collection has no collection-level bearer auth, has an
`authToken` variable, and login scripts update `authToken`.

- [x] **Step 2: Write a failing Postman command test that preserves an edited generated item and a custom item by default, then replaces only matching generated items with `--regen` and `--force`.**

```php
$existing['item'][0]['item'][0]['request']['body']['raw'] = '{"name":"fixture"}';
$existing['item'][0]['item'][] = ['name' => 'Manual smoke test', 'request' => $manualRequest];
file_put_contents($this->outputPath, json_encode($existing));
```

After a default export, assert the fixture body and manual item are unchanged
and a newly declared OpenAPI request exists. After regeneration, assert only
the matching generated request is replaced.

- [x] **Step 3: Run emitter and Postman command tests to verify they fail for auth/header and preservation behavior.**

Run: `vendor/bin/phpunit tests/Feature/BrunoEmitterTest.php tests/Feature/PostmanEmitterTest.php tests/Feature/ExportPostmanCommandTest.php`

- [x] **Step 4: Implement request-level auth in both emitters.**

For protected requests append the exact Authorization header and set request
auth to `none`/`noauth`. Public/login requests receive no Authorization header.
Remove generated collection-level bearer auth, replace `bearerToken` with
`authToken` in newly generated support files, and update generated login scripts
to write `authToken`.

- [x] **Step 5: Implement Postman structural merge.**

Start with the existing collection when present. Index top-level folders by
OpenAPI tag and direct request items by name. Preserve a matching item unless
the result's tag is regenerated or force is active; append missing generated
items; retain unmatched existing items/folders. Merge variables by key, adding
missing `baseUrl`, `apiPrefix`, and `authToken`; force refreshes only those
package-managed values. On force, use fresh generated root auth/info while
retaining unmatched custom items and variables.

- [x] **Step 6: Re-run emitter and Postman command tests and verify they pass.**

Run: `vendor/bin/phpunit tests/Feature/BrunoEmitterTest.php tests/Feature/PostmanEmitterTest.php tests/Feature/ExportPostmanCommandTest.php`

### Task 4: Document and verify the exporter contract

**Files:**
- Modify: `docs/guide/modules/module-api-clients.md`
- Modify: `docs/changelog.md`
- Test: `tests/Feature/ApiClientExportServiceTest.php`
- Test: `tests/Feature/ExportBrunoCommandTest.php`
- Test: `tests/Feature/ExportPostmanCommandTest.php`

- [x] **Step 1: Update the guide's quick start, flags, diff table, variables, auth table, login capture text, and examples.**

Document `--force`, the `--force`/`--regen` conflict, default preservation,
selective regeneration, `authToken`, and the request-level Authorization
header. State that custom requests and stale examples are never deleted.

- [x] **Step 2: Add a concise changelog entry under version `0.4.98`.**

State that exports are now non-destructive by default, `--force` explicitly
replaces generated output, and protected generated requests use `authToken`.

- [x] **Step 3: Run the focused export suite.**

Run: `vendor/bin/phpunit tests/Feature/ApiClientExportServiceTest.php tests/Feature/BrunoEmitterTest.php tests/Feature/PostmanEmitterTest.php tests/Feature/ExportBrunoCommandTest.php tests/Feature/ExportPostmanCommandTest.php`

Expected: all tests pass.

- [x] **Step 4: Run static analysis, style, documentation, and graph checks.**

Run:

```bash
vendor/bin/phpstan analyse src/Console/AbstractExportCommand.php src/Console/ExportBrunoCommand.php src/Console/ExportPostmanCommand.php src/Services/ApiClientExportService.php src/Services/ApiClient/BrunoEmitter.php src/Services/ApiClient/PostmanEmitter.php tests/Feature/ApiClientExportServiceTest.php tests/Feature/ExportBrunoCommandTest.php tests/Feature/ExportPostmanCommandTest.php --debug --no-progress --memory-limit=1G
vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff
composer docs:validate
git -c core.fsmonitor=false diff --check
graphify update .
```

- [x] **Step 5: Do not commit or push.**

The user requested work on the current branch; leave all changes available in
the working tree for their review.
