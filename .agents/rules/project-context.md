---
title: "Project Context"
description: "High-level overview, technology stack, and repository map for the SP Laravel API core package."
keywords:
  - project context
  - repository map
  - stack
---

# Project Context (Shared)

## TL;DR
- Package: `sopheak/sp-laravel-api` — namespace `Sopheak\Core` (PSR-4: `src/`)
- Product: Laravel API core utilities — config-driven dynamic CRUD, standardized responses, request IDs, audit logging
- Stack: PHP 8.2+ (8.5), Laravel 12/13, MySQL/PostgreSQL/SQLite
- Repo type: Composer package (library), registered as a Laravel provider
- Goal: Config-driven dynamic CRUD with standardized API contract, multi-tenancy, auth, filters, triggers
- Non-goals: Full application framework, UI components, frontend

## Entrypoint
- Provider: `Sopheak\Core\CoreSpLaravelApiProvider` in `src/CoreSpLaravelApiProvider.php` (registered via `composer.json extra.laravel.providers`)

## Repo Map
- `src/`: Core package source (PSR-4 `Sopheak\Core\`)
- `src/Attributes/`: Attribute classes
- `src/Authorization/`: Auth/permission wiring
- `src/Config/`: Config loaders
- `src/Console/`: Artisan commands (15 total)
- `src/Constants/`: Shared constants
- `src/Enums/`: PHP enumerations
- `src/Events/`, `src/Listeners/`, `src/Jobs/`: Events, listeners, queue jobs
- `src/Exceptions/`: Package exceptions
- `src/Http/`: Controllers, middleware, requests
- `src/Interfaces/`: PHP interfaces
- `src/Resources/`: API resources/transformers
- `src/Services/`: Service classes (`RecordService`, `RecordApiResponseService`, etc.)
- `src/Support/`: Support utilities and helpers
- `src/Traits/`: PHP traits (`HasControllerHelpers`, etc.)
- `src/Triggers/`: Table triggers
- `src/Types/`: `RecordTableType` and relationship types
- `src/Utilities/`: Utility classes
- `config/`: Package config (`record.php`, `records/tables/*.php`, …)
- `database/migrations/`: Database migrations
- `routes/`: Route definitions
- `tests/`: PHPUnit tests (`tests/Unit/`, `tests/Feature/`)
- `docs/`: User-facing docs (VitePress + Mermaid + OpenAPI)
- `docs/guide/*`: Chunked AI-facing reference (`api/`, `features/`, `modules/`, `records/`) — read first for module context

## Local Setup
### Requirements
- PHP 8.2+
- Composer
- Laravel 12+ application for testing (Orchestra Testbench used in tests)

### Install
```bash
composer install
```

## Commands
See `.agents/rules/commands.md`

## Coding Standards
See `.agents/rules/coding-standards.md`

## Architecture
See `.agents/rules/architecture.md`

## Current Priorities
- Now: Maintain API compatibility, add tests for every core behaviour change
- Next: Add more relationship types, improve performance
- Risks: Database compatibility across MySQL/PostgreSQL/SQLite
