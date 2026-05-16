# Architecture

## MCP (Model Context Protocol)

The package provides two MCP endpoints via a single `McpServerService`:

### Schema MCP — `POST /api/v1/mcp/schema` (route: `api_schema_mcp`)
- **Controller**: `ApiSchemaMcpController` → `McpServerService(schemaOnly: true)`
- **Auth**: `SP_API_MCP_TOKEN` Bearer token (config: `sp-api-mcp.*`)
- **Tools**: 3 schema discovery tools only — no data access
  - `sp_api_list_endpoints` — list all API routes
  - `sp_api_get_endpoint` — full schema for one endpoint
  - `sp_api_list_permissions` — all permissions
- **Security**: Token optional in local, required in production

### Data MCP — `POST /mcp/message` (route: `mcp.message`)
- **Controller**: `McpHttpController` → `McpServerService(schemaOnly: false)`
- **Auth**: User Bearer token via middleware (config: `record.mcp.*`)
- **Tools**: 3 schema tools + CRUD per table (`list_{table}`, `read_{table}`, `create_{table}`, etc.)

### Key design points
- `McpServerService::handleToolsList()` always includes 3 schema tools; CRUD tools only when `!$this->schemaOnly`
- `McpServerService::handleToolsCall()` routes schema tool names first, rejects data tools when `$this->schemaOnly`
- Schema data is read live from `SchemaRegistryUtils::get()`, `RecordConfigService`, and `PermissionUtils` — always fresh
