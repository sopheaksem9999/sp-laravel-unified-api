---
title: "MCP Agentic Structured Output"
description: "Standard MCP outputs and API-call guidance for the package Data and Schema MCP endpoints."
keywords:
  - mcp
  - structured output
  - schema discovery
  - agentic development
  - api guidance
---

# MCP Agentic Structured Output Design

## Goal

Make the package's two MCP endpoints useful to agents on first connection by
advertising standard MCP output schemas and returning structured context for
calling the documented HTTP API correctly.

## Endpoint Boundaries

| Endpoint | Role | May return |
|---|---|---|
| `/api/v1/mcp` | Data MCP for agentic CRUD | Authorized record and mutation results, plus schema guidance |
| `/api/v1/mcp/schema` | Schema MCP for agentic development | API metadata and calling guidance only; never database records |

The Schema MCP adds `sp_api_get_api_guidance` alongside the existing endpoint,
endpoint-detail, and permission discovery tools. Its guidance tells an agent to
discover endpoints, inspect one endpoint before calling it, respect auth and
tenant requirements, send GET filters as query parameters, and never invent a
request body.

## Standard MCP Contract

Every listed tool includes both `inputSchema` and `outputSchema`. Successful
tool calls return:

- `structuredContent`, matching the advertised `outputSchema`; and
- the equivalent JSON in a `content` text item for clients that do not yet read
  structured MCP results.

Tool/business failures continue to use `isError: true`; invalid JSON-RPC
requests and unknown tools remain protocol errors.

For list-shaped discovery output, `structuredContent` uses an object wrapper:
`{ endpoints: [...] }` or `{ permissions: [...] }`. The legacy text result
remains the existing top-level array. For endpoint-detail and guidance tools,
the structured object is the documented object itself. Data-tool structured
output is `{ response: <existing API envelope> }`; its text output remains the
unchanged serialized API envelope.

## API Call Context

`sp_api_get_endpoint` describes each action's request and response rather than
only naming a method and URI. A body-less GET/DELETE action explicitly declares
`payload: null`, any path parameter, permitted query parameters, the standard
response envelope, a `data` shape, and a concise calling rule. Write actions
declare a body schema derived from writeable fields.

Response context is metadata only. It must not query database rows or pretend
that an unknown custom RPC response has a table-record shape. Custom RPCs use
their configured `payloadSchema` and `responseSchema` when available, otherwise
they receive an explicit generic response-context marker.

## Compatibility and Security

- Existing MCP text consumers retain the same business JSON payload in
  `content[0].text`; internal Laravel request objects, which were never an API
  contract and are not valid structured MCP output, are omitted.
- No route, auth, tenancy, permission, or `record.mcp.read_only` behavior
  changes.
- Schema MCP remains schema-only; Data MCP continues to authorize every CRUD
  action before executing it.
- Empty-input tools use an object input schema that accepts no extra arguments.

## Verification

Feature tests will assert tool registration, `outputSchema`,
`structuredContent`, legacy text compatibility, body-less action context, and
Schema MCP's continued rejection of CRUD tools. Existing MCP feature tests and
scoped PHPStan/formatting checks will run after implementation.
