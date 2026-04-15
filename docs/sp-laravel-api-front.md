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

## What the Client Should Assume

- All responses use the envelope `{ success, error_code, data, meta }`.
- List endpoints support filtering + pagination (`page`, `per_page`).
- Relationship loading is requested via `select=` syntax (see docs).

## Docs Entry Points

- [Query Filtering + Relationships](/core-concepts/relationships)
- [Standard CRUD](/guide/api-crud-operations)
- [Error Responses + Security](/guide/api-errors-rate-security)
