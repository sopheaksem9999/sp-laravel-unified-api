---
title: "Documentation Map and Agent Metadata Standard"
description: "Navigation map and metadata standard for module and feature docs to improve AI agent retrieval and context routing."
keywords:
  - sp laravel api docs
  - ai agent documentation routing
  - module index
  - feature index
  - semantic search
  - retrieval metadata
  - developer onboarding
---

# SP Laravel API Documentation

This directory now uses a single `guide` folder layout so AI agents and developers can find scoped documentation faster with less navigation overhead.

## Metadata Standard

Every new doc file should start with YAML front matter using:

- `title`
- `description`
- `keywords`

This metadata is required for consistent indexing and retrieval by AI agents.

## Directory Structure

- `docs/features/`: module architecture entry pages (high-level overviews).
- `docs/guide/`: how-to guides and chunked API docs (deep dives, examples).
- `docs/sp-laravel-api-backend.md`: canonical AI-agent backend skill guide with trigger routing.
- `docs/sp-laravel-api-front.md`: canonical AI-agent front skill guide with trigger routing.
- `docs/superpowers/`: planning/spec artifacts.
- top-level files in `docs/` remain as compatibility entry points and quick indexes.

## AI Agent Skills

- [Backend Agent Guide](/sp-laravel-api-backend)
- [Frontend Agent Guide](/sp-laravel-api-front)

## Module Docs

- [Attachment Module](/features/attachments)
- [Audit Module](/features/audit-logging)
- [AI SDK Integration](/guide/modules/module-ai-sdk)
- [Pagination Module](/guide/module-pagination)
- [MCP Module](/guide/module-mcp)
- [Record Config Module](/core-concepts/record-table-types)

## Feature Docs

- [Attachment Upload](/guide/feature-attachments-upload)
- [Attachment Linking](/guide/feature-attachments-linking)
- [Attachment Visibility and Access](/guide/feature-attachments-visibility-access)
- [Attachment Folder Management](/guide/feature-attachments-folders)
- [Attachment Temp Cleanup (Cron)](/guide/feature-attachments-cleanup-cron)
- [Audit in Controller Flow](/guide/feature-audit-manual-controller)
- [Audit in Dynamic Record API](/guide/feature-audit-record-hooks)
- [Pagination (Page/Per Page)](/guide/feature-pagination-page-per-page)
- [Record Data Types](/guide/feature-record-data-types)
- [Record Trigger Functions](/guide/feature-record-trigger-functions)
- [Record Type Config Examples](/guide/feature-record-types-config-examples)
- [Record Hooks](/guide/record-hooks)
- [Record Middleware Map](/guide/record-middleware-map)
- [Record Cache](/guide/record-cache)
- [Record Tenancy](/guide/record-tenancy)

## Top-level Entries

- [Attachments Entry](/features/attachments)
- [Audit Entry](/features/audit-logging)
- [Record Config Entry](/core-concepts/record-table-types)
- [API Documentation Entry](/core-concepts/api-documentation)

## Chunked API Docs

- [Chunked API Docs Index](/guide/api-index)
