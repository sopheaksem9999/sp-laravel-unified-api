---
title: "OpenAPI Realtime and Application Contributions Plan"
description: "Task-by-task implementation plan for opt-in realtime metadata and safe application OpenAPI contributions."
keywords:
  - openapi
  - realtime
  - contributions
  - implementation plan
date: 2026-09-07
---

# OpenAPI Realtime and Application Contributions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Publish opt-in package broadcast metadata and safely merge explicitly declared application OpenAPI paths, components, tags, and extensions into the package-generated OpenAPI 3.0.3 document.

**Architecture:** Keep `OpenApiService` responsible for base dynamic CRUD/RPC generation. A new append-only document builder and contribution service own static configuration, class-based contributors, collision detection, and structural validation. A realtime metadata helper derives the package `RecordMutated` contract from existing broadcast configuration and appends declared application channels without inspecting Laravel routes or channel closures.

**Tech Stack:** PHP 8.2+, Laravel 12/13, Orchestra Testbench, PHPUnit, OpenAPI 3.0.3, Laravel config cache.

**Spec:** `docs/superpowers/specs/2026-09-07-openapi-realtime-and-document-contributions-design.md`

## Global Constraints

- Preserve OpenAPI `3.0.3`; do not introduce an OpenAPI 3.1 `webhooks` field.
- All new behavior is opt-in; absent or disabled configuration leaves the base document structurally unchanged.
- Config accepts arrays and class strings only; never permit closures or object instances in published config.
- Contributions are append-only: package-generated paths, components, tags, security, and `x-sp-*` metadata cannot be replaced.
- Keep the `record` property of `RecordMutated` broad (`object` with additional properties) and `action` open-ended.
- Do not add broadcaster credentials, transport secrets, or inferred application authorization to OpenAPI.
- Do not commit, push, or create a pull request unless the user later requests it.

---

### Task 1: Define cache-safe configuration and public contribution contracts

**Files:**
- Create: `src/Contracts/OpenApiDocumentContributorInterface.php`
- Create: `src/Exceptions/OpenApiContributionException.php`
- Modify: `config/sp-laravel-api.php:7-10`
- Modify: `src/Console/SetupPackageCommand.php` in the generated `openapi` config block
- Test: `tests/Feature/OpenApiContributionsTest.php`

**Interfaces:**
- Produces `OpenApiDocumentContributorInterface::contribute(OpenApiDocumentBuilder $document): void`.
- Produces `OpenApiContributionException extends RuntimeException` for all invalid declaration and collision failures.
- Consumes `sp-laravel-api.openapi.realtime` and `sp-laravel-api.openapi.contributions`.

- [ ] **Step 1: Write the failing default-contract test**

```php
public function test_default_openapi_configuration_does_not_add_realtime_metadata(): void
{
    Config::set('record.broadcast_events', false);
    Config::set('sp-laravel-api.openapi.realtime.enabled', false);

    $spec = OpenApiService::generateInternal();

    $this->assertArrayNotHasKey('x-sp-realtime', $spec);
    $this->assertArrayNotHasKey('RecordMutated', $spec['components']['schemas']);
}
```

- [ ] **Step 2: Run the focused test and verify it fails because the test class is absent**

Run: `vendor/bin/phpunit tests/Feature/OpenApiContributionsTest.php --filter=test_default_openapi_configuration_does_not_add_realtime_metadata`

Expected: PHPUnit reports that `OpenApiContributionsTest.php` does not exist.

- [ ] **Step 3: Add the new test class and public contract defaults**

```php
'openapi' => [
    'output' => 'openapi-schema.json',
    'realtime' => ['enabled' => false, 'channels' => []],
    'contributions' => [
        'paths' => [], 'components' => [], 'tags' => [],
        'extensions' => [], 'contributors' => [],
    ],
],
```

```php
interface OpenApiDocumentContributorInterface
{
    public function contribute(OpenApiDocumentBuilder $document): void;
}
```

- [ ] **Step 4: Run the focused test and verify it passes**

Run: `vendor/bin/phpunit tests/Feature/OpenApiContributionsTest.php --filter=test_default_openapi_configuration_does_not_add_realtime_metadata`

Expected: PASS; default OpenAPI has no new extension or schema.

### Task 2: Build append-only merge and validation primitives

**Files:**
- Create: `src/Services/OpenApiDocumentBuilder.php`
- Create: `src/Services/OpenApiContributionService.php`
- Modify: `tests/Feature/OpenApiContributionsTest.php`

**Interfaces:**
- Consumes base `array<string, mixed>` OpenAPI document.
- Produces `OpenApiDocumentBuilder::addPath()`, `addComponent()`, `addTag()`, `addExtension()`, and `document()`.
- Produces `OpenApiContributionService::apply(array $document): array`.

- [ ] **Step 1: Write failing static-contribution and collision tests**

```php
public function test_it_appends_declared_application_documentation(): void
{
    Config::set('sp-laravel-api.openapi.contributions', [
        'tags' => [['name' => 'Reports']],
        'paths' => ['/api/v1/reports/monthly' => $this->monthlyReportPathItem()],
        'components' => ['schemas' => ['MonthlyReport' => ['type' => 'object']]],
        'extensions' => ['x-client-documentation' => ['owner' => 'reports']],
        'contributors' => [],
    ]);

    $spec = OpenApiService::generateInternal();

    $this->assertArrayHasKey('/api/v1/reports/monthly', $spec['paths']);
    $this->assertSame(['Reports'], $spec['paths']['/api/v1/reports/monthly']['get']['tags']);
    $this->assertArrayHasKey('MonthlyReport', $spec['components']['schemas']);
    $this->assertSame(['owner' => 'reports'], $spec['x-client-documentation']);
}

public function test_it_rejects_a_contribution_that_overwrites_a_package_path(): void
{
    Config::set('sp-laravel-api.openapi.contributions.paths', [
        '/api/v1/widgets' => $this->monthlyReportPathItem(),
    ]);

    $this->expectException(OpenApiContributionException::class);
    $this->expectExceptionMessage('paths./api/v1/widgets.get conflicts');

    OpenApiService::generateInternal();
}
```

- [ ] **Step 2: Run the two tests and verify they fail because merging does not exist**

Run: `vendor/bin/phpunit tests/Feature/OpenApiContributionsTest.php --filter='test_it_(appends_declared_application_documentation|rejects_a_contribution_that_overwrites_a_package_path)'`

Expected: the valid path is absent and the collision does not throw.

- [ ] **Step 3: Implement the builder and service**

Implement only these public builder methods:

```php
public function addPath(string $path, array $pathItem): void;
public function addComponent(string $group, string $name, array $definition): void;
public function addTag(array $tag): void;
public function addExtension(string $name, mixed $value): void;
public function document(): array;
```

Require every custom operation to contain a non-empty `summary`, a unique
`operationId`, and at least one response. Require `/` path prefixes, required
matching path parameters, recognized component groups, unique tag names, and
non-reserved `x-*` extension names. Report source paths in exceptions.

- [ ] **Step 4: Run the focused tests and verify they pass**

Run: `vendor/bin/phpunit tests/Feature/OpenApiContributionsTest.php --filter='test_it_(appends_declared_application_documentation|rejects_a_contribution_that_overwrites_a_package_path)'`

Expected: PASS.

### Task 3: Support class-based application contributors

**Files:**
- Modify: `src/Services/OpenApiContributionService.php`
- Modify: `tests/Feature/OpenApiContributionsTest.php`

**Interfaces:**
- Consumes `openapi.contributions.contributors` as ordered class strings.
- Uses Laravel's container to resolve each valid `OpenApiDocumentContributorInterface`.
- Produces definitions through the same builder collision checks used by static config.

- [ ] **Step 1: Write failing contributor tests**

```php
public function test_it_resolves_a_class_string_contributor_through_the_container(): void
{
    Config::set('sp-laravel-api.openapi.contributions.contributors', [TestReportContributor::class]);

    $spec = OpenApiService::generateInternal();

    $this->assertArrayHasKey('/api/v1/reports/from-contributor', $spec['paths']);
}

public function test_it_rejects_a_contributor_that_does_not_implement_the_contract(): void
{
    Config::set('sp-laravel-api.openapi.contributions.contributors', [\stdClass::class]);

    $this->expectException(OpenApiContributionException::class);
    $this->expectExceptionMessage('must implement OpenApiDocumentContributorInterface');

    OpenApiService::generateInternal();
}
```

- [ ] **Step 2: Run the tests and verify they fail for missing contributor support**

Run: `vendor/bin/phpunit tests/Feature/OpenApiContributionsTest.php --filter='test_it_(resolves_a_class_string_contributor_through_the_container|rejects_a_contributor_that_does_not_implement_the_contract)'`

Expected: contributor path is absent and invalid class does not raise the contract error.

- [ ] **Step 3: Resolve contributors in declared order**

```php
$contributor = app($class);
if (!$contributor instanceof OpenApiDocumentContributorInterface) {
    throw new OpenApiContributionException("Contributor {$class} must implement OpenApiDocumentContributorInterface.");
}
$contributor->contribute($builder);
```

Wrap resolution and contribution exceptions in `OpenApiContributionException`
without returning a partially merged document.

- [ ] **Step 4: Run the focused contributor tests and verify they pass**

Run: `vendor/bin/phpunit tests/Feature/OpenApiContributionsTest.php --filter='test_it_(resolves_a_class_string_contributor_through_the_container|rejects_a_contributor_that_does_not_implement_the_contract)'`

Expected: PASS.

### Task 4: Generate and validate package realtime metadata

**Files:**
- Modify: `src/Services/OpenApiService.php`
- Modify: `tests/Feature/OpenApiContributionsTest.php`

**Interfaces:**
- Consumes `RecordConfigService::broadcastEventsEnabled()`, `broadcastTables()`, schema registry tables, and table `disableBroadcast`.
- Produces top-level `x-sp-realtime` and `components.schemas.RecordMutated` only when both feature switches are enabled.

- [ ] **Step 1: Write failing package-realtime tests**

```php
public function test_it_documents_the_effective_package_broadcast_contract(): void
{
    Config::set('record.broadcast_events', true);
    Config::set('record.broadcast_tables', ['invoices', 'audit_snapshots']);
    Config::set('sp-laravel-api.openapi.realtime.enabled', true);
    Config::set('record.tables', [
        'invoices' => new RecordTableType(table: 'invoices'),
        'audit_snapshots' => new RecordTableType(table: 'audit_snapshots', disableBroadcast: true),
    ]);

    $spec = OpenApiService::generateInternal();

    $channel = $spec['x-sp-realtime']['channels'][0];
    $this->assertSame('tenant.{tenantId}', $channel['pattern']);
    $this->assertSame('{table}.{action}', $channel['events'][0]['pattern']);
    $this->assertSame(['invoices'], $spec['components']['schemas']['RecordMutated']['properties']['table']['enum']);
    $this->assertSame(['table', 'action', 'record', 'tenant_id', 'timestamp'], $spec['components']['schemas']['RecordMutated']['required']);
}
```

- [ ] **Step 2: Run the focused test and verify it fails because realtime metadata is absent**

Run: `vendor/bin/phpunit tests/Feature/OpenApiContributionsTest.php --filter=test_it_documents_the_effective_package_broadcast_contract`

Expected: FAIL with missing `x-sp-realtime`.

- [ ] **Step 3: Add the realtime document helper**

Generate a single `record-mutations` private channel with `tenantId`
parameter documentation and event payload reference. Include only configured,
registered, non-opted-out tables. Add a `RecordMutated` object schema with the
five required wire fields, open `record`, open `action`, and RFC 3339
`date-time` timestamp.

- [ ] **Step 4: Run the focused realtime test and verify it passes**

Run: `vendor/bin/phpunit tests/Feature/OpenApiContributionsTest.php --filter=test_it_documents_the_effective_package_broadcast_contract`

Expected: PASS.

### Task 5: Merge application realtime channels and protect final output

**Files:**
- Modify: `src/Services/OpenApiContributionService.php`
- Modify: `src/Services/OpenApiService.php`
- Modify: `tests/Feature/OpenApiContributionsTest.php`

**Interfaces:**
- Consumes `openapi.realtime.channels`.
- Produces a validated extension list with unique channel names and locally
  resolvable payload references.

- [ ] **Step 1: Write failing custom-channel validation tests**

```php
public function test_it_appends_an_explicit_application_realtime_channel(): void
{
    Config::set('record.broadcast_events', true);
    Config::set('sp-laravel-api.openapi.realtime', [
        'enabled' => true,
        'channels' => [[
            'name' => 'booking-status', 'pattern' => 'booking.{bookingId}', 'private' => true,
            'parameters' => ['bookingId' => ['schema' => ['type' => 'string']]],
            'events' => [['name' => 'booking.status.updated', 'payload' => ['$ref' => '#/components/schemas/BookingStatusUpdated']]],
        ]],
    ]);
    Config::set('sp-laravel-api.openapi.contributions.components.schemas.BookingStatusUpdated', ['type' => 'object']);

    $spec = OpenApiService::generateInternal();

    $this->assertSame('booking-status', $spec['x-sp-realtime']['channels'][1]['name']);
}

public function test_it_rejects_a_channel_with_undeclared_pattern_parameter(): void
{
    Config::set('record.broadcast_events', true);
    Config::set('sp-laravel-api.openapi.realtime', [
        'enabled' => true,
        'channels' => [[
            'name' => 'booking-status', 'pattern' => 'booking.{bookingId}', 'private' => true,
            'parameters' => [], 'events' => [['name' => 'booking.updated', 'payload' => ['type' => 'object']]],
        ]],
    ]);

    $this->expectException(OpenApiContributionException::class);
    $this->expectExceptionMessage('bookingId');

    OpenApiService::generateInternal();
}
```

- [ ] **Step 2: Run the focused tests and verify they fail for missing custom-channel support**

Run: `vendor/bin/phpunit tests/Feature/OpenApiContributionsTest.php --filter='test_it_(appends_an_explicit_application_realtime_channel|rejects_a_channel_with_undeclared_pattern_parameter)'`

Expected: custom channel is absent and malformed channel does not throw.

- [ ] **Step 3: Append and validate declared channels**

Require non-empty unique names, non-empty patterns, exact placeholder keys,
boolean privacy, non-empty event arrays, exactly one event name/pattern, and a
payload schema or `#/components/` reference. Reject a duplicate
`record-mutations` channel or an unresolved local component reference.

- [ ] **Step 4: Run the focused tests and verify they pass**

Run: `vendor/bin/phpunit tests/Feature/OpenApiContributionsTest.php --filter='test_it_(appends_an_explicit_application_realtime_channel|rejects_a_channel_with_undeclared_pattern_parameter)'`

Expected: PASS.

### Task 6: Integrate generation, exporters, docs, and broad regression checks

**Files:**
- Modify: `src/Services/OpenApiService.php`
- Modify: `docs/guide/api/api-realtime-openapi-attribute-config.md`
- Modify: `docs/guide/api/api-custom-function-endpoints.md`
- Modify: `docs/changelog.md`
- Modify: `tests/Feature/OpenApiContributionsTest.php`
- Test: `tests/Feature/OpenApiTest.php`
- Test: `tests/Feature/ApiClientExportServiceTest.php` or the existing exporter test file located by `rg 'export-bruno|export-postman' tests`

**Interfaces:**
- `OpenApiService::generateInternal()` returns the fully validated final document.
- Existing export commands and client emitters consume the contribution-merged document without special cases.

- [ ] **Step 1: Write failing exporter visibility and legacy-regression tests**

```php
public function test_contributed_operations_are_available_to_api_client_exporters(): void
{
    Config::set('sp-laravel-api.openapi.contributions.paths', [
        '/api/v1/reports/monthly' => $this->monthlyReportPathItem(),
    ]);

    $result = app(ApiClientExportService::class)->build(
        OpenApiService::generateInternal(), [], [], new PostmanEmitter(),
    );

    $this->assertContains('Get monthly report', $result->added);
}
```

Also assert a document with empty new config retains existing table paths,
`security`, and `x-sp-auth` values.

- [ ] **Step 2: Run the focused tests and verify they fail before final integration**

Run: `vendor/bin/phpunit tests/Feature/OpenApiContributionsTest.php tests/Feature/OpenApiTest.php --filter='test_(contributed_operations_are_available_to_api_client_exporters|default_openapi_configuration_does_not_add_realtime_metadata)'`

Expected: the exporter test fails until the final document is passed through
the existing exporter parsing path.

- [ ] **Step 3: Wire generation and write user documentation**

Call the contribution service after base paths/components exist. Add the
realtime extension before final contribution validation so custom channels can
reference contributed schemas. Document the configuration examples, no-secret
boundary, collision failures, custom route requirements, and the difference
between package RPC schemas and app-owned route contributions. Add a concise
changelog entry.

- [ ] **Step 4: Run focused and full validation**

Run:

```bash
vendor/bin/phpunit tests/Feature/OpenApiContributionsTest.php tests/Feature/OpenApiTest.php
composer analyse
composer docs:validate
git diff --check
graphify update .
```

Expected: focused OpenAPI tests and PHPStan pass; document validation is
reported precisely if pre-existing files still lack frontmatter; graph update
completes after source changes.
