# Project Context (Shared)

## TL;DR
- Product: Laravel API Core Utilities Package
- Stack: PHP 8.1+, Laravel 10+, PostgreSQL/MySQL/SQLite
- Goal: Standardized API responses, request ID middleware, audit logging, dynamic record handling
- Non-goals: Full application framework, UI components

## Repo Map
- `src/`: Core package source code
- `src/Console/`: CLI commands and utilities
- `src/Enums/`: PHP enumerations
- `src/Http/Controllers/`: API controllers
- `src/Http/Middleware/`: Custom middleware
- `src/Interfaces/`: PHP interfaces
- `src/Jobs/`: Queue jobs
- `src/Services/`: Service classes
- `src/Support/`: Support utilities and helpers
- `src/Traits/`: PHP traits
- `src/Types/`: Type definitions
- `config/`: Laravel package configuration
- `database/migrations/`: Database migrations
- `tests/`: PHPUnit tests
- `routes/`: Route definitions

## Local Setup
### Requirements
- PHP 8.1+
- Composer
- Laravel 10+ application for testing
- PostgreSQL/MySQL/SQLite database

## Commands
See docs/ai/commands.md

## Coding Standards
See docs/ai/coding-standards.md

## Architecture
See docs/ai/architecture.md

## Current Priorities
- Now: Maintain API compatibility, fix PostgreSQL issues
- Next: Add more relationship types, improve performance
- Risks: Database compatibility across MySQL/PostgreSQL/SQLite