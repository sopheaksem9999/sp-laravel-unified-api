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
  - [Two MCP Endpoints](#two-mcp-endpoints)
  - [Features](#features)
  - [Configuration](#configuration)
    - [Data MCP (`record.mcp.*`)](#data-mcp-recordmcp)
    - [Schema MCP (`sp-api-mcp.*`)](#schema-mcp-sp-api-mcp)
  - [Available Resources \& Tools](#available-resources--tools)
    - [Resources](#resources)
    - [Data Tools (CRUD)](#data-tools-crud)
    - [Schema Tools (Discovery)](#schema-tools-discovery)
  - [Use Case 1: Local AI IDE Integration (Stdio)](#use-case-1-local-ai-ide-integration-stdio)
  - [Use Case 2: Remote Web AI Agents (HTTP / SSE)](#use-case-2-remote-web-ai-agents-http--sse)
  - [Use Case 3: Frontend AI Agent — API Schema Discovery](#use-case-3-frontend-ai-agent--api-schema-discovery)
    - [Sample: List endpoints matching "invoice"](#sample-list-endpoints-matching-invoice)
    - [Sample: Get full schema for the `invoices` endpoint](#sample-get-full-schema-for-the-invoices-endpoint)
    - [Sample: List all available permissions](#sample-list-all-available-permissions)
    - [AI Agent MCP Configuration](#ai-agent-mcp-configuration)
  - [Security \& Authentication](#security--authentication)

## Two MCP Endpoints

The package provides two separate MCP endpoints with different security postures:

| | Data MCP | Schema MCP |
|---|---|---|
| **Route** | `POST /mcp/message` | `POST /api/v1/mcp/schema` |
| **Tools** | CRUD (`list_*`, `read_*`, `create_*`, `update_*`, `delete_*`) + 3 schema tools | 3 schema tools **only** |
| **Data access** | Yes (reads/writes real data) | **None** (read-only schema) |
| **Auth** | User Bearer token (your app auth) | `SP_API_MCP_TOKEN` (separate shared secret) |
| **Production-safe** | Only behind full auth | Yes — no data exposure even if token leaks |
| **Config** | `config/record.php` → `mcp.*` | `config/sp-api-mcp.php` |

The Schema MCP is specifically designed for **frontend AI coding agents** (Cursor, Claude Code, opencode, Copilot) that need to discover API routes, fields, filters, and permissions — without ever touching production data.

## Features

- **Schema Auto-Discovery**: The Schema MCP exposes your configured tables, endpoints, fields, filters, sorts, relationships, and validation rules as searchable tools.
- **Dynamic CRUD Tools**: The Data MCP exposes `list_{table}`, `read_{table}`, `create_{table}`, `update_{table}`, and `delete_{table}` operations.
- **Native Security**: Integrates seamlessly with your `RecordTableType` auth flags (`isAuthRead`/`isAuthWrite`), custom authorizers, and Spatie Permissions.
- **Tenancy Support**: MCP operations enforce your `tenant_id` configurations automatically.
- **Read-Only Mode**: A global toggle to strictly disable write operations (Create, Update, Delete) for the AI.

## Configuration

### Data MCP (`record.mcp.*`)

The Data MCP configuration lives in your `config/record.php` file under the `mcp` key. If you ran `php artisan sp-laravel-api:setup` recently, this will be generated for you.

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

### Schema MCP (`sp-api-mcp.*`)

The Schema MCP has its own dedicated config file: `config/sp-api-mcp.php`.

```php
// config/sp-api-mcp.php
return [
    // Enable/disable the POST /api/v1/mcp/schema route
    'enabled' => env('SP_API_MCP_ENABLED', false),

    // Bearer token for authentication.
    // - In local: leave null for open access, or set a token.
    // - In production: a token is REQUIRED when enabled.
    'token' => env('SP_API_MCP_TOKEN', null),
];
```

**`.env` examples:**

```bash
# Local dev — no auth needed
SP_API_MCP_ENABLED=true

# Production — locked behind shared secret
SP_API_MCP_ENABLED=true
SP_API_MCP_TOKEN=YOUR_MCP_TOKEN
```

## Available Resources & Tools

When an MCP client connects, it queries your server for available capabilities. The Data MCP exposes both resources and CRUD tools. The Schema MCP exposes only the 3 schema discovery tools (always present).

### Resources
- `schema://{table}`: Returns a JSON representation of the `RecordTableType` configuration, showing the AI which columns exist, which relations are available, and the primary key details.

### Data Tools (CRUD)
For every table where `isAuthRead` (or public) is enabled:
- `list_{table}`: Lists records with standard `sp-laravel-api` filtering (supports `s`, `select`, `with`, etc.).
- `read_{table}`: Fetches a single record by ID.

For every table where `isAuthWrite` is enabled (and `mcp.read_only` is false):
- `create_{table}`: Creates a new record.
- `update_{table}`: Updates an existing record by ID.
- `delete_{table}`: Soft or force deletes a record by ID.

### Schema Tools (Discovery)
Available on **both** endpoints (Data MCP and Schema MCP):

| Tool | Description |
|------|-------------|
| `sp_api_list_endpoints` | List all API endpoints (tables + custom RPCs). Returns endpoint name, HTTP method, URI, table, and supported actions. Accepts `?search` for substring filtering. |
| `sp_api_get_endpoint` | Get full schema for a single endpoint: fields (name, type, nullable, writeable), filters (field + operators), sortable columns, relationship includes, validation rules, and required permissions. Requires `?endpoint` param. |
| `sp_api_list_permissions` | List all available permissions across all configured tables: `{name, guard, table}`. Deduplicated and grouped by resource. |

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

## Use Case 3: Frontend AI Agent — API Schema Discovery

**Scenario:** Your frontend developer is building a React/Vue/Next.js app in an AI-powered IDE (opencode, Cursor, Claude Code, Copilot). They need to know what API endpoints exist, what fields each endpoint accepts/returns, what filters are available, and what permissions are required — without loading a 50K+ token OpenAPI JSON file.

**Solution:** The Schema MCP endpoint (`POST /api/v1/mcp/schema`) exposes exactly the data the AI needs on-demand.

### Sample: List endpoints matching "invoice"

**Request:**
```http
POST /api/v1/mcp/schema
Authorization: Bearer YOUR_MCP_TOKEN

{
  "jsonrpc": "2.0",
  "method": "tools/call",
  "params": {
    "name": "sp_api_list_endpoints",
    "arguments": { "search": "invoice" }
  },
  "id": 1
}
```

**Response (key fields):**
```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    "content": [{
      "type": "text",
      "text": "[
        {\"name\":\"invoices\",\"method\":[\"GET\",\"POST\"],\"uri\":\"/api/v1/invoices\",\"table\":\"invoices\",\"actions\":[\"list\",\"create\"]},
        {\"name\":\"invoices.detail\",\"method\":[\"GET\",\"PUT\",\"PATCH\",\"DELETE\"],\"uri\":\"/api/v1/invoices/{id}\",\"table\":\"invoices\",\"actions\":[\"read\",\"update\",\"delete\"]},
        {\"name\":\"invoices.sync\",\"method\":[\"POST\"],\"uri\":\"/api/v1/invoices/sync\",\"table\":\"invoices\",\"actions\":[\"rpc\"],\"permission\":\"invoice.sync\"}
      ]"
    }]
  }
}
```

### Sample: Get full schema for the `invoices` endpoint

**Request:**
```http
POST /api/v1/mcp/schema
Authorization: Bearer YOUR_MCP_TOKEN

{
  "jsonrpc": "2.0",
  "method": "tools/call",
  "params": {
    "name": "sp_api_get_endpoint",
    "arguments": { "endpoint": "invoices" }
  },
  "id": 2
}
```

**Response (key fields):**
```json
{
  "jsonrpc": "2.0",
  "id": 2,
  "result": {
    "content": [{
      "type": "text",
      "text": "{
        \"name\":\"invoices\",
        \"table\":\"invoices\",
        \"primaryKey\":\"id\",
        \"softDeletes\":true,
        \"isAuthRead\":false,
        \"isAuthWrite\":false,
        \"actions\":{
          \"list\":{\"method\":\"GET\",\"uri\":\"/api/v1/invoices\"},
          \"create\":{\"method\":\"POST\",\"uri\":\"/api/v1/invoices\"},
          \"read\":{\"method\":\"GET\",\"uri\":\"/api/v1/invoices/{id}\"},
          \"update\":{\"method\":[\"PUT\",\"PATCH\"],\"uri\":\"/api/v1/invoices/{id}\"},
          \"delete\":{\"method\":\"DELETE\",\"uri\":\"/api/v1/invoices/{id}\"}
        },
        \"fields\":[
          {\"name\":\"id\",\"type\":\"integer\",\"nullable\":false,\"in\":[\"read\"]},
          {\"name\":\"invoice_number\",\"type\":\"string\",\"nullable\":false,\"in\":[\"read\",\"write\"]},
          {\"name\":\"status\",\"type\":\"string\",\"nullable\":false,\"in\":[\"read\",\"write\"],\"enum\":[\"draft\",\"sent\",\"paid\",\"void\"]},
          {\"name\":\"total_amount\",\"type\":\"decimal\",\"nullable\":true,\"in\":[\"read\",\"write\"]}
        ],
        \"filters\":[
          {\"field\":\"id\",\"operators\":[\"=\",\"!=\",\">\",\"<\",\">=\",\"<=\",\"in\",\"not_in\"]},
          {\"field\":\"status\",\"operators\":[\"=\",\"!=\",\"in\"]},
          {\"field\":\"invoice_number\",\"operators\":[\"=\",\"contains\",\"starts_with\",\"ends_with\"]},
          {\"field\":\"total_amount\",\"operators\":[\"=\",\">\",\"<\",\">=\",\"<=\",\"between\"]}
        ],
        \"sorts\":[\"id\",\"invoice_number\",\"total_amount\",\"created_at\"],
        \"includes\":[
          {\"name\":\"customer\",\"type\":\"belongsTo\",\"table\":\"customers\",\"foreignKey\":\"customer_id\"},
          {\"name\":\"items\",\"type\":\"hasMany\",\"table\":\"invoice_items\",\"foreignKey\":\"invoice_id\"}
        ],
        \"permissions\":{
          \"read\":[\"invoices.read\"],
          \"write\":[\"invoices.write\"],
          \"delete\":[\"invoices.force_delete\"],
          \"sync\":[\"invoice.sync\"]
        },
        \"scopes\":[\"active\",\"draft\"]
      }"
    }]
  }
}
```

### Sample: List all available permissions

**Request:**
```http
POST /api/v1/mcp/schema
Authorization: Bearer YOUR_MCP_TOKEN

{
  "jsonrpc": "2.0",
  "method": "tools/call",
  "params": { "name": "sp_api_list_permissions", "arguments": {} },
  "id": 3
}
```

**Response:**
```json
{
  "jsonrpc": "2.0",
  "id": 3,
  "result": {
    "content": [{
      "type": "text",
      "text": "[
        {\"name\":\"invoices.read\",\"guard\":\"api\",\"table\":\"invoices\"},
        {\"name\":\"invoices.write\",\"guard\":\"api\",\"table\":\"invoices\"},
        {\"name\":\"customers.read\",\"guard\":\"api\",\"table\":\"customers\"},
        {\"name\":\"customers.write\",\"guard\":\"api\",\"table\":\"customers\"}
      ]"
    }]
  }
}
```

### AI Agent MCP Configuration

Add the Schema MCP endpoint to your AI agent's config. The agent will automatically discover the 3 tools on startup and use them to understand your API.

**opencode** (`.opencode/opencode.json`):
```json
{
  "mcp": {
    "sp-api-schema": {
      "type": "remote",
      "url": "http://localhost:8000/api/v1/mcp/schema"
    }
  }
}
```

With token (production):
```json
{
  "mcp": {
    "sp-api-schema": {
      "type": "remote",
      "url": "https://api.yoursaas.com/api/v1/mcp/schema",
      "headers": {
        "Authorization": "Bearer YOUR_MCP_TOKEN"
      }
    }
  }
}
```

**Claude Code** (`.claude/mcp.json`):
```json
{
  "mcpServers": {
    "sp-api-schema": {
      "type": "http",
      "url": "http://localhost:8000/api/v1/mcp/schema"
    }
  }
}
```

**Cursor** (`.cursor/mcp.json`):
```json
{
  "mcpServers": {
    "sp-api-schema": {
      "transport": "http",
      "url": "http://localhost:8000/api/v1/mcp/schema"
    }
  }
}
```

**What the AI agent learns after calling the 3 tools:**

| Knowledge | Source |
|-----------|--------|
| Every API route, HTTP method, and URI | `sp_api_list_endpoints` |
| Which fields are writable vs read-only | `sp_api_get_endpoint` → `fields[].in` |
| Available filters + operators per field | `sp_api_get_endpoint` → `filters[]` |
| Sortable fields | `sp_api_get_endpoint` → `sorts[]` |
| Relationship structure (foreign keys, table names, types) | `sp_api_get_endpoint` → `includes[]` |
| Required permissions per action | `sp_api_get_endpoint` → `permissions` |
| All permission names across the app | `sp_api_list_permissions` |

This is ~2-3K tokens of targeted data vs. 50K+ tokens for the full OpenAPI JSON — the agent queries only what it needs, when it needs it.

## Security & Authentication

The MCP integration is not a backdoor. It strictly adheres to the security layers already defined in `sp-laravel-api`.

### Data MCP Security (`POST /mcp/message`)

1. **`authorizeAction()` Enforcement**: Every tool execution passes through the exact same `HasControllerHelpers::authorizeAction()` checks as the REST API. If the user doesn't have the `create_invoice` permission, the `create_invoice` MCP tool will fail.
2. **Tenant Scoping**: If the table has `hasTenantId: true`, the MCP tool will automatically scope the queries and mutations to the resolved tenant ID from the HTTP request or Context.
3. **Trigger Validation**: Your `beforeCreate`, `afterUpdate`, and custom `Validator` closures defined in `RecordTableType` run exactly as they do in HTTP requests.

### Schema MCP Security (`POST /api/v1/mcp/schema`)

The Schema MCP exposes **no data** — only endpoint metadata. Even if the token leaks, an attacker gains zero access to records.

| Environment | `SP_API_MCP_TOKEN` set? | Behavior |
|---|---|---|
| `local` | No | Open access — no auth |
| `local` | Yes | Requires `Authorization: Bearer <token>` |
| `production` | No | **401 Unauthorized** — token is mandatory |
| `production` | Yes | Requires `Authorization: Bearer <token>` |

**Best practices:**

```bash
# Generate a strong token
php -r "echo bin2hex(random_bytes(32));"

# .env (local dev)
SP_API_MCP_ENABLED=true

# .env (production)
SP_API_MCP_ENABLED=true
SP_API_MCP_TOKEN=abc123...your_64_hex_chars_here...
```

**Disable in production when not needed:**

```bash
# .env (production)
SP_API_MCP_ENABLED=false
# → POST /api/v1/mcp/schema returns 404 (route not registered)
```
