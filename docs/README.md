---
title: "SP Laravel API Documentation Map and Agent Metadata Standard"
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

- `docs/guide/`: all module, feature, and chunked API docs in one place.
- `docs/sp-laravel-api-backend.md`: canonical AI-agent backend skill guide with trigger routing.
- `docs/sp-laravel-api-front.md`: canonical AI-agent front skill guide with trigger routing.
- `docs/superpowers/`: planning/spec artifacts.
- top-level files in `docs/` remain as compatibility entry points and quick indexes.

## AI Agent Skills

- [sp-laravel-api-backend](./sp-laravel-api-backend.md)
- [sp-laravel-api-front](./sp-laravel-api-front.md)

## Module Docs

- [Attachment Module](./guide/module-attachments.md)
- [Audit Module](./guide/module-audit.md)
- [Pagination Module](./guide/module-pagination.md)
- [Record Config Module](./guide/module-record-config.md)

## Feature Docs

- [Attachment Upload](./guide/feature-attachments-upload.md)
- [Attachment Linking](./guide/feature-attachments-linking.md)
- [Attachment Visibility and Access](./guide/feature-attachments-visibility-access.md)
- [Attachment Folder Management](./guide/feature-attachments-folders.md)
- [Attachment Temp Cleanup (Cron)](./guide/feature-attachments-cleanup-cron.md)
- [Audit in Controller Flow](./guide/feature-audit-manual-controller.md)
- [Audit in Dynamic Record API](./guide/feature-audit-record-hooks.md)
- [Pagination (Page/Per Page)](./guide/feature-pagination-page-per-page.md)
- [Record Data Types](./guide/feature-record-data-types.md)
- [Record Type Config Examples](./guide/feature-record-types-config-examples.md)
- [Record Hooks](./guide/record-hooks.md)
- [Record Middleware Map](./guide/record-middleware-map.md)
- [Record Cache](./guide/record-cache.md)
- [Record Tenancy](./guide/record-tenancy.md)

## Top-level Entries

- [Attachments Entry](./attachments.md)
- [Audit Entry](./audit-interface.md)
- [Record Config Entry](./record-config.md)
- [API Documentation Entry](./api-documentation.md)

## Chunked API Docs

- [Chunked API Docs Index](./guide/api-index.md)
