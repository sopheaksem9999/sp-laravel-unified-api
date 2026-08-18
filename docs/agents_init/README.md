---
title: "Agent Init Prompts"
description: "Copy-paste bootstrap prompts that initialize the local agentic setup of an existing project (client or backend) that uses sp-laravel-api."
keywords:
  - agent init
  - bootstrap prompt
  - frontend setup
  - backend setup
  - onboarding
  - crud api
  - schema mcp
---

# Agent Init Prompts

This directory holds **copy-paste bootstrap prompts** that initialize the local
agentic setup of an existing project that talks to or runs a backend built on
`sopheak/sp-laravel-api` — either a **client** (frontend/mobile/HTTP consumer) or
the **backend** itself (Laravel app that configures the package).

A prompt here is meant to be pasted into an AI agent at the very start of an
"initialize our local agentic setup" session. It encodes the non-negotiable parts
of the API contract / package architecture so the agent does not have to re-learn
them — or, more importantly, re-make the classic mistakes (bracket filters,
`select=*`, invented relations, debugging the backend from the frontend, or
writing custom CRUD controllers). The agent is expected to **extend** the
project's existing `.agents/` rules, not rebuild them.

## Available Prompts

- [Frontend Setup Prompt](/agents_init/frontend-setup-prompt) — initialize the local
  agentic setup of an existing API client project: learn the package, internalize
  the query/response contract, wire up schema discovery (MCP), extend the
  project's `.agents/` rules with the missing sp-laravel-api knowledge, and set
  up the API-issue reporting workflow.
- [Backend Setup Prompt](/agents_init/backend-setup-prompt) — initialize the local
  agentic setup of an existing Laravel backend that uses the package: learn the
  package, internalize the config-driven architecture (RecordTableType, triggers,
  validators, permissions, RPC functions), follow the migration → config → sync →
  validate workflow, and extend the project's `.agents/` rules with the missing
  sp-laravel-api knowledge.

## How to use

1. Copy the whole prompt file (the text after the `---` front-matter).
2. Replace the `{PLACEHOLDERS}` (`{project-name}`, `{api-host}`,
   `{schema-mcp-url}`, `{api-prefix}`, etc.) with your deployment's values.
3. Paste it into your AI agent's chat (Claude Code, Trae, opencode, etc.) at the
   start of the session — it is a self-contained init-context prompt.

## Reference implementations

- **Client**: the KarunaFilm monorepo (`client_app` / `admin_app` Flutter apps).
  Its `.agents/rules/` and `.agents/context/backend-boundaries.md` show the
  intended end state: a root `AGENTS.md`, chunked rule files, a confirmed
  query-syntax table, minimal `select=` projections, and a frontend-only scope
  with an `api-reports/` folder.
- **Backend**: the KarunaFilm API (`karunafilm_api` Laravel app). Its
  `.agents/rules/` (architecture, coding-standards, metadata) and
  `.agents/context/sp-laravel-api.md` show the intended end state: Option-1 module
  structure (`config/records/tables/` + `app/Record/{Domain}/`), the
  migration → config → sync → validate workflow, and distilled known-behaviors.

## Docs the prompts point to

The prompts instruct the agent to read the package docs the "smart" way — the
token-efficient chunked reference first:

- `docs/getting-started/mental-model.md`, `docs/getting-started/architecture.md`
- `docs/guide/api/api-crud-operations.md`
- `docs/guide/modules/module-pagination.md`
- `docs/guide/api/api-nested-and-bulk-operations.md`
- `docs/guide/api/api-errors-rate-security.md`
- `docs/guide/api/api-config-and-middleware.md`
- `docs/guide/api/api-validation.md`
- `docs/guide/api/api-type-reference-and-examples.md`
- `docs/guide/features/feature-permission.md`
- `docs/guide/features/feature-record-trigger-functions.md`
- `docs/core-concepts/relationships.md`
