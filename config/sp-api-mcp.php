<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API Schema MCP — Schema-only Model Context Protocol endpoint
    |--------------------------------------------------------------------------
    |
    | Exposes API endpoint schemas (routes, fields, filters, relationships,
    | permissions) to frontend AI coding agents as MCP tools over HTTP.
    |
    | This is a READ-ONLY schema discovery endpoint. It does NOT expose any
    | data access (CRUD) tools regardless of the `record.mcp.enabled` flag.
    |
    | Security:
    |   - Local env: optional token
    |   - Production: token is REQUIRED when enabled
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Enable / disable the schema MCP endpoint
    |--------------------------------------------------------------------------
    |
    | When false, the POST /api/v1/mcp/schema route is not registered and any
    | request will return a 404.
    |
    | Default: false (opt-in)
    |
    */

    'enabled' => env('SP_API_MCP_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Bearer token for authentication
    |--------------------------------------------------------------------------
    |
    | In local environments, leave this null to allow unauthenticated access.
    | In production, set a strong random token.
    |
    | The authorization header format is: Authorization: Bearer <token>
    |
    | Default: null (no auth required)
    |
    */

    'token' => env('SP_API_MCP_TOKEN', null),

];
