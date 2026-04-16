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

This package is installed from a private Git repository.

### Configure Composer Repository (VCS)

In your host application's `composer.json`, add a VCS repository entry:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/<org>/<repo>.git"
    }
  ]
}
```

### Authenticate (Private Repo)

Use one of these approaches:

- SSH: use an SSH URL in `repositories.url` and ensure your deploy key / SSH agent is configured
- HTTPS token: configure a token using Composer auth (recommended) instead of putting tokens in `composer.json`

### Require the Package

Then require the package in your host Laravel app:

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

- `config/record.php`
- `config/audit.php`
- `config/attachments.php`
- `config/webhooks.php`
- `config/records/tables/*`
- `config/records/globalFunctions/*`

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

Run the validator to check config files, directories, migrations, routes, and optional modules:

```bash
php artisan sp-laravel-api:validate
```

Try auto-fixing common issues:

```bash
php artisan sp-laravel-api:validate --fix
```
