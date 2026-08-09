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
| **Config** | `config/sp-record.php` → `mcp.*` | `config/sp-api-mcp.php` |

The Schema MCP is specifically designed for **frontend AI coding agents** (Cursor, Claude Code, opencode, Copilot) that need to discover API routes, fields, filters, and permissions — without ever touching production data.

## Features

- **Schema Auto-Discovery**: The Schema MCP exposes your configured tables, endpoints, fields, filters, sorts, relationships, and validation rules as searchable tools.
- **Dynamic CRUD Tools**: The Data MCP exposes `list_{table}`, `read_{table}`, `create_{table}`, `update_{table}`, and `delete_{table}` operations.
- **Native Security**: Integrates seamlessly with your `RecordTableType` auth flags (`isAuthRead`/`isAuthWrite`), custom authorizers, and Spatie Permissions.
- **Tenancy Support**: MCP operations enforce your `tenant_id` configurations automatically.
- **Read-Only Mode**: A global toggle to strictly disable write operations (Create, Update, Delete) for the AI.

## Configuration

### Data MCP (`record.mcp.*`)

The Data MCP configuration lives in your `config/sp-record.php` file under the `mcp` key. If you ran `php artisan sp-laravel-api:setup` recently, this will be generated for you.

```php
// config/sp-record.php
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

#### Filter syntax for `list_{table}` / `read_{table}`

`queryParams` filters are `{column: "operator.value"}` pairs — the same `{column}={operator}.{value}` syntax the HTTP API uses, just expressed as JSON instead of a query string:

```json
{ "queryParams": { "status": "eq.open", "total_amount": "gte.100" } }
```

Call `sp_api_get_endpoint` first to see which operators (`eq`, `neq`, `gt`, `lt`, `gte`, `lte`, `in`, `not_in`, `contains`, `starts_with`, `ends_with`, `between`, ...) each field supports. Do **not** nest filters under a `filter` key or use bracket syntax like `column[operator]=value` — that shape is rejected (or silently ignored) by the underlying query engine; pass the column name directly as the `queryParams` key.

#### Writing related data in a single `create_{table}` / `update_{table}` call

Before writing related rows with separate `create_{childTable}` calls, check `sp_api_get_endpoint`'s `includes[]` for that relationship:

- `"writable": true` (hasMany, belongsToMany, hasManyThrough, morphMany, morphToMany, morphByMany, spatiePermission) — the relationship can be nested directly in the parent's `payload`, so the parent row and its related rows are written in **one** `create_{table}`/`update_{table}` call instead of one call per table. `allowCreate`/`allowUpdate`/`allowDelete` say which of those operations are permitted through the nested array, and `payloadHint` gives the exact shape:
  ```json
  {
    "payload": {
      "invoice_number": "INV-1001",
      "customer_id": 10,
      "items": [1, { "id": 2 }, { "name": "Line A", "qty": 1 }, { "id": 5, "_delete": true }]
    }
  }
  ```
- `"writable": false` (belongsTo, hasOne, hasOneThrough, morphTo, morphOne) — there is no nested-array form; set the relationship via its own root field(s) in the same payload (`payloadHint` names them), e.g. `"customer_id": 10` instead of `"customer": { "id": 10 }`.

See [Standard CRUD Operations](/guide/api-crud-operations) and the "Relationship Write Payload Guide" in [Relationships](/core-concepts/relationships) for the full HTTP-side reference this mirrors.

### Schema Tools (Discovery)
Available on **both** endpoints (Data MCP and Schema MCP):

| Tool | Description |
|------|-------------|
| `sp_api_list_endpoints` | List all API endpoints (tables + custom RPCs). Returns endpoint name, HTTP method, URI, table, and supported actions — including `upsert`, `restore`, `forceDelete`, and the four `bulk*` endpoints when the table/config enables them, not just list/read/create/update/delete. Accepts `?search` for substring filtering. |
| `sp_api_get_endpoint` | Get full schema for a single endpoint: `actions` (every enabled operation — CRUD, `upsert`, `restore`, `forceDelete`, `bulkCreate`/`bulkUpdate`/`bulkDelete`/`bulkUpsert`/`bulkMixed` — each with method, URI, and a `note` on non-obvious ones like the `match_on` query param or the bulk-item shape), fields (name, type, nullable, writeable), filters (field + operators), sortable columns, relationship includes, validation rules, and required permissions. Requires `?endpoint` param. |
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
          \"delete\":{\"method\":\"DELETE\",\"uri\":\"/api/v1/invoices/{id}\"},
          \"upsert\":{\"method\":\"POST\",\"uri\":\"/api/v1/invoices/upsert\",\"note\":\"Requires a ?match_on=col1,col2 query parameter naming the columns to match an existing record on.\"},
          \"restore\":{\"method\":\"POST\",\"uri\":\"/api/v1/invoices/{id}/restore\",\"note\":\"Restores a soft-deleted record.\"},
          \"forceDelete\":{\"method\":\"DELETE\",\"uri\":\"/api/v1/invoices/{id}/force\",\"note\":\"Permanently deletes the record, bypassing soft deletes.\"},
          \"bulkCreate\":{\"method\":\"POST\",\"uri\":\"/api/v1/invoices/bulk/create\",\"note\":\"Body: a JSON array of records to create (max 1000 per request).\"},
          \"bulkUpdate\":{\"method\":\"POST\",\"uri\":\"/api/v1/invoices/bulk/update\",\"note\":\"Body: a JSON array of records to update, each including its primary key (max 1000 per request).\"},
          \"bulkDelete\":{\"method\":\"POST\",\"uri\":\"/api/v1/invoices/bulk/delete\",\"note\":\"Body: a JSON array of records naming the primary key to delete (max 1000 per request).\"},
          \"bulkUpsert\":{\"method\":\"POST\",\"uri\":\"/api/v1/invoices/bulk/upsert\",\"note\":\"Body: a JSON array of records to upsert (max 1000 per request). Requires ?match_on=col1,col2.\"},
          \"bulkMixed\":{\"method\":\"POST\",\"uri\":\"/api/v1/invoices/bulk\",\"note\":\"Body: a JSON array of records (max 1000 per request). Each item's operation (create/update/delete/upsert) is auto-detected from its shape, or set explicitly via an 'operation' field per item.\"}
        },
        \"fields\":[
          {\"name\":\"id\",\"type\":\"integer\",\"nullable\":false,\"in\":[\"read\"]},
          {\"name\":\"invoice_number\",\"type\":\"string\",\"nullable\":false,\"in\":[\"read\",\"write\"]},
          {\"name\":\"status\",\"type\":\"string\",\"nullable\":false,\"in\":[\"read\",\"write\"],\"enum\":[\"draft\",\"sent\",\"paid\",\"void\"]},
          {\"name\":\"total_amount\",\"type\":\"decimal\",\"nullable\":true,\"in\":[\"read\",\"write\"]}
        ],
        \"filters\":[
          {\"field\":\"id\",\"operators\":[\"eq\",\"neq\",\"gt\",\"lt\",\"gte\",\"lte\",\"in\",\"not_in\"]},
          {\"field\":\"status\",\"operators\":[\"eq\",\"neq\",\"in\",\"not_in\"]},
          {\"field\":\"invoice_number\",\"operators\":[\"eq\",\"neq\",\"in\",\"not_in\",\"contains\",\"starts_with\",\"ends_with\"]},
          {\"field\":\"total_amount\",\"operators\":[\"eq\",\"neq\",\"in\",\"not_in\",\"gt\",\"lt\",\"gte\",\"lte\",\"between\"]}
        ],
        \"sorts\":[\"id\",\"invoice_number\",\"total_amount\",\"created_at\"],
        \"includes\":[
          {\"name\":\"customer\",\"type\":\"belongsTo\",\"table\":\"customers\",\"foreignKey\":\"customer_id\",\"writable\":false,\"payloadHint\":\"Use the root field \\\"customer_id\\\": <id> in the same request — do not nest a \\\"customer\\\" object in the payload\"},
          {\"name\":\"items\",\"type\":\"hasMany\",\"table\":\"invoice_items\",\"foreignKey\":\"invoice_id\",\"writable\":true,\"allowCreate\":true,\"allowUpdate\":true,\"allowDelete\":true,\"payloadHint\":\"\\\"items\\\": [1, {\\\"id\\\": 2}, {...fields to create}, {\\\"id\\\": 5, \\\"_delete\\\": true}] — send this alongside the parent fields in one create/update call\"}
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
| Every API route, HTTP method, and URI — including upsert, restore, force-delete, and bulk endpoints, not just plain CRUD | `sp_api_list_endpoints`, `sp_api_get_endpoint` → `actions` |
| Which fields are writable vs read-only | `sp_api_get_endpoint` → `fields[].in` |
| Available filters + operators per field | `sp_api_get_endpoint` → `filters[]` |
| Sortable fields | `sp_api_get_endpoint` → `sorts[]` |
| Relationship structure (foreign keys, table names, types) | `sp_api_get_endpoint` → `includes[]` |
| Which relationships can be written in the same request as the parent, and the exact payload shape | `sp_api_get_endpoint` → `includes[].writable`/`allowCreate`/`allowUpdate`/`allowDelete`/`payloadHint` |
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
