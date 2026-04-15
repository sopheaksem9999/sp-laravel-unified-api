---
title: "MCP Support"
description: "MCP (Model Context Protocol) Support: Expose schema + CRUD tools to AI clients (Claude, Cursor, Trae) with auth, tenancy, and read-only controls."
keywords:
  - mcp
  - model context protocol
  - claude
  - trae
  - cursor
  - tools
  - resources
  - schema
---

# Module: MCP (Model Context Protocol) Support

The **Model Context Protocol (MCP)** integration enables AI assistants (like Claude, Cursor, Trae, etc.) to natively understand, securely query, and interact with your `sp-laravel-api` endpoints.

Instead of writing custom scripts or giving the AI raw database access, MCP securely exposes your API schema and CRUD operations over a standardized protocol. The AI respects your tenant boundaries, rate limits, and custom permission checks out of the box.

## Table of Contents
- [Module: MCP (Model Context Protocol) Support](#module-mcp-model-context-protocol-support)
  - [Table of Contents](#table-of-contents)
  - [Features](#features)
  - [Configuration](#configuration)
  - [Available Resources \& Tools](#available-resources--tools)
    - [Resources](#resources)
    - [Tools](#tools)
  - [Use Case 1: Local AI IDE Integration (Stdio)](#use-case-1-local-ai-ide-integration-stdio)
  - [Use Case 2: Remote Web AI Agents (HTTP / SSE)](#use-case-2-remote-web-ai-agents-http--sse)
  - [Security \& Authentication](#security--authentication)

## Features

- **Schema Auto-Discovery**: Exposes your configured tables as `schema://{table}` resources.
- **Dynamic CRUD Tools**: Exposes `list_{table}`, `read_{table}`, `create_{table}`, `update_{table}`, and `delete_{table}` operations.
- **Native Security**: Integrates seamlessly with your `RecordTableType` auth flags (`isAuthRead`/`isAuthWrite`), custom authorizers, and Spatie Permissions.
- **Tenancy Support**: MCP operations enforce your `tenant_id` configurations automatically.
- **Read-Only Mode**: A global toggle to strictly disable write operations (Create, Update, Delete) for the AI.

## Configuration

The MCP configuration lives in your `config/record.php` file under the `mcp` key. If you ran `php artisan sp-laravel-api:setup` recently, this will be generated for you.

```php
// config/record.php
'mcp' => [
    'enabled' => env('SP_MCP_ENABLED', false),
    
    // Set to true to disable all write tools (create, update, delete)
    'read_only' => env('SP_MCP_READ_ONLY', false),
    
    // Optional prefix for the HTTP/SSE endpoints (default: mcp)
    'route_prefix' => env('SP_MCP_ROUTE_PREFIX', 'mcp'),
    
    // Middleware applied to the HTTP/SSE routes
    'middleware' => ['api', 'auth:sanctum'],
],
```

## Available Resources & Tools

When an MCP client connects, it queries your server for available capabilities based on your `config/record.php` table configurations.

### Resources
- `schema://{table}`: Returns a JSON representation of the `RecordTableType` configuration, showing the AI which columns exist, which relations are available, and the primary key details.

### Tools
For every table where `isAuthRead` (or public) is enabled:
- `list_{table}`: Lists records with standard `sp-laravel-api` filtering (supports `s`, `select`, `with`, etc.).
- `read_{table}`: Fetches a single record by ID.

For every table where `isAuthWrite` is enabled (and `mcp.read_only` is false):
- `create_{table}`: Creates a new record.
- `update_{table}`: Updates an existing record by ID.
- `delete_{table}`: Soft or force deletes a record by ID.

## Use Case 1: Local AI IDE Integration (Stdio)

**Scenario:** You are developing a frontend application in Cursor, Trae, or Claude for Desktop, and you want the AI to read real data from your local Laravel backend to understand the schema and test the API natively.

**Solution:** Use the Stdio (Standard Input/Output) MCP server.

1. Open your AI IDE's MCP Configuration file (e.g., `claude_desktop_config.json` or IDE settings).
2. Add a new MCP server configuration pointing to your Laravel project's artisan command:

```json
{
  "mcpServers": {
    "my-laravel-api": {
      "command": "php",
      "args": [
        "/absolute/path/to/your/laravel/project/artisan",
        "sp-laravel-api:mcp"
      ]
    }
  }
}
```

3. **Usage:** Ask the AI: *"Can you check the `customers` schema and show me the latest 3 customers?"*
   - The AI will call the `schema://customers` resource.
   - Then, it will call the `list_customers` tool with `{"limit": 3, "order": "desc"}`.
   - It will format the response for you without leaving your IDE.

## Use Case 2: Remote Web AI Agents (HTTP / SSE)

**Scenario:** You have a SaaS platform and you want to offer an "AI Assistant" inside your web app that can securely query a user's own data or perform actions on their behalf.

**Solution:** Connect the web-based AI agent to the MCP HTTP/SSE endpoints.

1. Ensure your `config/record.php` has `mcp.middleware` set to include your authentication guard (e.g., `auth:sanctum`).
2. The AI Client establishes a Server-Sent Events (SSE) connection:
   ```http
   GET /api/v1/mcp/sse
   Authorization: Bearer {user_token}
   ```
3. The server responds with an endpoint to post messages to.
4. The AI Client sends JSON-RPC commands:
   ```http
   POST /api/v1/mcp/message
   Authorization: Bearer {user_token}
   
   {
     "jsonrpc": "2.0",
     "id": 1,
     "method": "tools/call",
     "params": {
       "name": "create_invoice",
       "arguments": {
         "customer_id": 123,
         "amount": 500.00
       }
     }
   }
   ```
5. **Usage:** Because the request uses the user's Bearer token, `sp-laravel-api` automatically enforces their tenant ID, restricts them to their own records, and runs your custom trigger validators.

## Security & Authentication

The MCP integration is not a backdoor. It strictly adheres to the security layers already defined in `sp-laravel-api`:

1. **`authorizeAction()` Enforcement**: Every tool execution passes through the exact same `HasControllerHelpers::authorizeAction()` checks as the REST API. If the user doesn't have the `create_invoice` permission, the `create_invoice` MCP tool will fail.
2. **Tenant Scoping**: If the table has `hasTenantId: true`, the MCP tool will automatically scope the queries and mutations to the resolved tenant ID from the HTTP request or Context.
3. **Trigger Validation**: Your `beforeCreate`, `afterUpdate`, and custom `Validator` closures defined in `RecordTableType` run exactly as they do in HTTP requests.
