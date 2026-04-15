---
title: "Backend Agent Guide"
description: "Concise backend guidance for AI agents working on sp-laravel-api (core flows, where to look, and docs entry points)."
keywords:
  - backend
  - ai agent
  - claude
  - trae
  - laravel
  - recordservice
  - recordtabletype
---

# Backend Agent Guide

## Where to Start

- Core behavior is config-driven via `RecordTableType` in `config/record.php` and related config files.
- CRUD orchestration lives in `Sopheak\\Core\\Services\\RecordService`.
- Response envelope and error contract lives in `Sopheak\\Core\\Services\\RecordApiResponseService`.

## Docs Entry Points

- [Architecture](/getting-started/architecture)
- [Mental Model](/getting-started/mental-model)
- [API Docs (Chunked Index)](/core-concepts/api-documentation)
