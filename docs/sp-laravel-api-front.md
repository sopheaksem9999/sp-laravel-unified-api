---
title: "Frontend Agent Guide"
description: "Concise guidance for AI agents building clients for sp-laravel-api (query params, response envelope, and docs entry points)."
keywords:
  - frontend
  - ai agent
  - claude
  - trae
  - api client
  - filtering
  - relationships
  - pagination
---

# Frontend Agent Guide

For bootstrapping a new frontend/mobile client project, use the copy-paste
[Frontend Setup Prompt](/agents_init/frontend-setup-prompt).

## What the Client Should Assume

- All responses use the envelope `{ success, error_code, data, meta }`.
- List endpoints support filtering + pagination (`page`, `per_page`, `cursor`).
- Relationship loading is requested via `select=` syntax (see docs).

## Docs Entry Points

- [Filter Operators Reference](/guide/api-filter-operators)
- [Relationships](/core-concepts/relationships)
- [Standard CRUD](/guide/api-crud-operations)
- [Pagination Module](/guide/module-pagination)
- [Error Responses + Security](/guide/api-errors-rate-security)
