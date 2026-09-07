---
title: "MCP Agentic Structured Output Plan"
description: "Test-first implementation plan for MCP output schemas and API call guidance."
keywords:
  - mcp
  - plan
  - structured output
  - schema
---

# MCP Agentic Structured Output Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add MCP-standard structured output and enough response context for an agent to safely call a body-less or body-bearing package API endpoint.

**Architecture:** Keep `McpServerService` as the single protocol boundary. Add reusable result construction and output-schema helpers there; enrich only schema discovery metadata, keeping Data MCP CRUD execution and its authorization intact. Preserve the existing serialized text payload beside every new `structuredContent` value.

**Tech Stack:** PHP 8.2+, Laravel, PHPUnit, JSON-RPC/MCP 2025-11-25 conventions.

---

### Task 1: Standard MCP output contract tests

**Files:**
- Create: `tests/Feature/McpStructuredOutputTest.php`
- Modify: `src/Services/McpServerService.php:177-361`

- [ ] **Step 1: Write failing Schema MCP registration and result tests**

```php
public function schema_tools_advertise_output_schemas_and_return_structured_content(): void
{
    $tools = $this->schemaTools();
    $guidance = collect($tools)->firstWhere('name', 'sp_api_get_api_guidance');

    $this->assertNotNull($guidance);
    $this->assertSame(['type' => 'object', 'additionalProperties' => false], $guidance['inputSchema']);
    $this->assertSame('object', $guidance['outputSchema']['type']);

    $result = $this->schemaToolCall('sp_api_list_endpoints');
    $this->assertSame(json_decode($result['content'][0]['text'], true), $result['structuredContent']['endpoints']);
}
```

- [ ] **Step 2: Run the new test and verify it fails because `outputSchema`, `structuredContent`, and the guidance tool do not exist**

Run: `vendor/bin/phpunit tests/Feature/McpStructuredOutputTest.php --filter=structured_content`

Expected: FAIL with a missing `sp_api_get_api_guidance` tool or missing `structuredContent` array key.

- [ ] **Step 3: Add output-schema definitions and a compatibility result helper**

```php
private function toolResult(array $structuredContent, mixed $legacyContent): array
{
    return [
        'content' => [[
            'type' => 'text',
            'text' => json_encode($legacyContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ]],
        'structuredContent' => $structuredContent,
    ];
}
```

Define `outputSchema` for every Schema and Data MCP tool. Wrap list results as
`endpoints`/`permissions`; wrap Data MCP results as `response`; retain legacy
text payloads unchanged.

- [ ] **Step 4: Run the new test and verify it passes**

Run: `vendor/bin/phpunit tests/Feature/McpStructuredOutputTest.php --filter=structured_content`

Expected: PASS.

### Task 2: Schema MCP first-call API guidance

**Files:**
- Modify: `src/Services/McpServerService.php:177-361, 520-1130`
- Modify: `src/Http/Controllers/ApiSchemaMcpController.php:15-20`
- Modify: `docs/guide/modules/module-mcp.md:115-180, 250-420`
- Test: `tests/Feature/McpStructuredOutputTest.php`

- [ ] **Step 1: Write a failing guidance-tool test**

```php
public function schema_guidance_explains_the_two_mcp_endpoints_and_safe_calling_flow(): void
{
    $result = $this->schemaToolCall('sp_api_get_api_guidance');
    $guidance = $result['structuredContent'];

    $this->assertSame('/api/mcp/message', $guidance['dataMcp']['route']);
    $this->assertSame('/api/v1/mcp/schema', $guidance['schemaMcp']['route']);
    $this->assertContains('sp_api_list_endpoints', $guidance['workflow']);
    $this->assertStringContainsString('query parameters', $guidance['httpRules']['get']);
}
```

- [ ] **Step 2: Run the test and verify it fails because the guidance handler is absent**

Run: `vendor/bin/phpunit tests/Feature/McpStructuredOutputTest.php --filter=schema_guidance`

Expected: FAIL with `Tool not found: sp_api_get_api_guidance`.

- [ ] **Step 3: Implement `handleSchemaGetApiGuidance()` and register it in the schema-only allowlist**

```php
protected function handleSchemaGetApiGuidance(): array
{
    return [
        'dataMcp' => ['route' => '/api/' . config('record.mcp.route_prefix', 'mcp') . '/message'],
        'schemaMcp' => ['route' => '/api/' . RecordConfigService::apiPrefix() . '/mcp/schema'],
        'workflow' => ['sp_api_list_endpoints', 'sp_api_get_endpoint', 'call the documented API or Data MCP tool'],
        'httpRules' => [
            'get' => 'Do not send a request body; send filters and selection as query parameters.',
            'write' => 'Use only documented writeable fields and inspect endpoint context before calling.',
        ],
    ];
}
```

Update the Schema MCP controller comment and the guide to list four discovery tools and document `structuredContent`.

- [ ] **Step 4: Run the guidance test and verify it passes**

Run: `vendor/bin/phpunit tests/Feature/McpStructuredOutputTest.php --filter=schema_guidance`

Expected: PASS.

### Task 3: Request and response context for endpoint actions

**Files:**
- Modify: `src/Services/McpServerService.php:725-1130`
- Test: `tests/Feature/McpStructuredOutputTest.php`

- [ ] **Step 1: Write a failing body-less action context test**

```php
public function endpoint_schema_marks_get_as_bodyless_and_describes_its_response(): void
{
    $endpoint = $this->schemaToolCall('sp_api_get_endpoint', ['endpoint' => 'mcp_invoices']);
    $list = $endpoint['structuredContent']['actions']['list'];

    $this->assertNull($list['request']['payload']);
    $this->assertSame('array', $list['response']['dataSchema']['type']);
    $this->assertStringContainsString('query parameters', $list['guidance']);
}
```

- [ ] **Step 2: Run the test and verify it fails because action request/response context is absent**

Run: `vendor/bin/phpunit tests/Feature/McpStructuredOutputTest.php --filter=bodyless`

Expected: FAIL with a missing `request` or `response` key.

- [ ] **Step 3: Add action-context helpers and enrich table actions**

```php
private function actionContext(string $action, string|array $method, string $uri, array $fields): array
{
    $isRead = in_array($action, ['list', 'read'], true);

    return [
        'request' => [
            'pathParameters' => str_contains($uri, '{id}') ? ['id'] : [],
            'payload' => $isRead || in_array($action, ['delete', 'forceDelete'], true)
                ? null
                : $this->writePayloadSchema($fields),
        ],
        'response' => [
            'envelope' => ['success', 'error_code', 'data', 'meta'],
            'dataSchema' => $this->actionDataSchema($action, $fields),
        ],
        'guidance' => $isRead
            ? 'Do not send a request body; send filters and selection as query parameters.'
            : 'Send only documented writeable fields in the JSON request body.',
    ];
}
```

Use generic, explicit context for custom RPCs unless their configured schemas
are available. Do not query database records while generating this metadata.

- [ ] **Step 4: Run the action-context test and the existing MCP schema suites**

Run: `vendor/bin/phpunit tests/Feature/McpStructuredOutputTest.php tests/Feature/McpSchemaEndpointCoverageTest.php tests/Feature/McpEndpointRelationshipSchemaTest.php`

Expected: PASS.

### Task 4: Documentation and final verification

**Files:**
- Modify: `docs/guide/modules/module-mcp.md:20-180, 250-420`

- [ ] **Step 1: Document the two MCP endpoint roles, the four Schema MCP tools, and the standard result fields**

```md
Schema MCP returns API guidance in `result.structuredContent` and repeats the
same JSON in `result.content[0].text` for older clients. It never returns rows.
Call `sp_api_get_api_guidance`, then `sp_api_list_endpoints`, then
`sp_api_get_endpoint` before issuing an HTTP request or Data MCP operation.
```

- [ ] **Step 2: Run focused verification**

Run: `vendor/bin/phpunit tests/Feature/McpStructuredOutputTest.php tests/Feature/McpSchemaEndpointCoverageTest.php tests/Feature/McpEndpointRelationshipSchemaTest.php && vendor/bin/phpstan analyse src/Services/McpServerService.php tests/Feature/McpStructuredOutputTest.php --debug --no-progress --memory-limit=1G`

Expected: PASS with PHPStan `[OK] No errors`.

- [ ] **Step 3: Run documentation and diff checks**

Run: `composer docs:validate; git diff --check`

Expected: the new/changed MCP documentation validates; report existing unrelated documentation debt separately if the repository-wide docs command remains nonzero.

## Plan Review

- Spec coverage: Tasks 1-3 cover standard output, zero-input guidance, body-less action context, compatibility, and Schema/Data endpoint separation. Task 4 covers public guidance and verification.
- Placeholder scan: no deferred implementation placeholders remain.
- Type consistency: every `structuredContent` value is an object; list outputs use stable wrapper keys and all existing text payloads remain unchanged.
