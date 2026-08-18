---
title: "API Documentation"
description: "Entry point for chunked API documentation (Chunked Index) split by feature and module to improve AI-agent retrieval speed and reduce token usage."
keywords:
  - api documentation chunked
  - feature based api docs
  - low token docs
  - ai agent optimized docs
  - sp laravel api reference
---

# API Documentation

This API reference is now chunked into smaller feature files for cleaner navigation and faster AI-agent context loading.

## API Docs Access Mode

The bundled docs UI endpoint is:

- `GET /api-docs`

Use `config/record.php` to control visibility:

```php
'api_docs' => [
    'is_private' => env('SP_LARAVEL_API_DOCS_PRIVATE', false),
    'access_token_key' => 'access_token',
    'login_api' => '/v1/auth/login',
    'email' => env('SP_LARAVEL_API_DOCS_EMAIL'),
],
```

Behavior:

- If `api_docs` config is missing, docs stay public by default.
- If `is_private=false`, `/api-docs` loads Scalar directly.
- If `is_private=true`, `/api-docs` shows a custom login form first.
- If `is_private=true`, Scalar uses secure web routes:
  - `POST /api-docs/auth/login`
  - `POST /api-docs/auth/logout`
  - `GET /api-docs/openapi.json`
- In private mode, API endpoints `/{api_prefix}/docs/openapi(.json)` and `/{api_prefix}/docs/llms.*` are hidden with `404` to avoid schema leakage.
- `login_api` supports relative route or absolute URL, so each client project can point docs login to its own auth endpoint.
- `access_token_key` controls token extraction key from login response payload.
- `email` is optional and enforces a fixed docs login account.

## Chunked Index

- [Open Chunked API Docs Index](/guide/api-index)

## Direct Chunk Links

1. [Recommended Folder Structure](/guide/api-folder-structure)
2. [Configuration and Middleware](/guide/api-config-and-middleware)
3. [Validation](/guide/api-validation)
4. [Record Type Reference and Class-Based Examples](/guide/api-type-reference-and-examples)
5. [Standard CRUD Operations](/guide/api-crud-operations)
6. [Global RPC Functions](/guide/api-rpc-functions)
7. [Nested Relationship Writes and Bulk Operations](/guide/api-nested-and-bulk-operations)
8. [Audit Management Endpoints](/guide/api-audit-management-endpoints)
9. [Custom Function Endpoints](/guide/api-custom-function-endpoints)
10. [Internal API Methods and Function Caching](/guide/api-internal-methods-core)
11. [Realtime Events, OpenAPI Export, and Attribute Config](/guide/api-realtime-openapi-attribute-config)
12. [Error Responses, Rate Limiting, and Security](/guide/api-errors-rate-security)
13. [QueryHelpers Trait Documentation](/guide/api-queryhelpers-trait)
14. [Model Context Protocol (MCP) Support](/guide/module-mcp)
15. [Laravel AI SDK Integration](/guide/modules/module-ai-sdk)
