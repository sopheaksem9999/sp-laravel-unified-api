---
title: "Setup"
description: "Install the package, generate config scaffolding, run migrations, and validate the dynamic API setup."
keywords:
  - setup
  - install
  - configuration
  - artisan
  - validate
  - migrations
---

# Setup

## 1) Install

This package is published on [Packagist (`sopheak/sp-laravel-api`)](https://packagist.org/packages/sopheak/sp-laravel-api). Install it directly via Composer in your Laravel application:

```bash
composer require sopheak/sp-laravel-api
```

## 2) Generate Config Scaffolding

Run the setup command to publish package config and create the record/audit/attachments/webhooks configs and folders:

```bash
php artisan sp-laravel-api:setup
```

Overwrite existing generated files:

```bash
php artisan sp-laravel-api:setup --force
```

This creates (or updates) common paths like:

- `config/sp-record.php`
- `config/sp-audit.php`
- `config/sp-attachments.php`
- `config/sp-webhooks.php`
- `config/records/tables/*`
- `config/records/global-functions/*`

(These publish under `sp-`-prefixed filenames; the config namespace each one
feeds — `record`, `audit`, `attachments`, `webhooks` — is unchanged. See
[Upgrade 0.4.80 → 0.4.82](/getting-started/upgrade-0.4.80-to-0.4.82) for
details if you have older, unprefixed copies of these files already
published.)

It also attempts to inject default rate limiters into `app/Providers/AppServiceProvider.php`:

- `api-reads`
- `api-writes`
- `api-functions`

## 3) Run Migrations

Run database migrations in your host app:

```bash
php artisan migrate
```

## 4) Validate Setup

Run the validator to check config files, directories, migrations, routes, function definitions, and optional modules. For every table and global function it warns when the class or method does not exist, an `httpMethod` is invalid, or a `querySchema` / `payloadSchema` / `responseSchema` is malformed:

```bash
php artisan sp-laravel-api:validate
```

Try auto-fixing common issues:

```bash
php artisan sp-laravel-api:validate --fix
```

## 5) Laravel Boost & AI Agent Setup (Optional)

`sp-laravel-api` ships first-class AI guidelines (`resources/boost/guidelines/core.blade.php`), an agent skill (`resources/boost/skills/sp-laravel-api-development/SKILL.md`), and a local stdio MCP server (`sp-laravel-api:mcp`).

### Option A: With Laravel Boost (`laravel/boost`)

1. Install Laravel Boost and run its installer (it auto-discovers `sopheak/sp-laravel-api`'s guidelines and `sp-laravel-api-development` skill):

```bash
composer require laravel/boost --dev
php artisan boost:install
```

2. Merge the `sp-laravel-api` MCP server into `.mcp.json` (preserving `laravel-boost` and any other MCP servers) and validate the setup:

```bash
php artisan sp-laravel-api:boost
```

> **Note:** `boost:install` regenerates `.mcp.json` from scratch. Re-run `php artisan sp-laravel-api:boost` whenever `boost:install` regenerates `.mcp.json`, or run `php artisan boost:update` when updating guidelines and skills.

### Option B: Standalone Agent Setup (Without Laravel Boost)

Install the `sp-laravel-api-development` skill (`.agents/skills/`), rules (`.agents/rules/sp-laravel-api.md`), and `.mcp.json` entry directly:

```bash
php artisan sp-laravel-api:agent

# Overwrite existing skill/rules after upgrading sp-laravel-api:
php artisan sp-laravel-api:agent --force
```

See [MCP Support — Laravel Boost & Agent Setup](/guide/module-mcp#laravel-boost-agent-setup) for full options (`--skill`, `--rules`, `--mcp`).
