---
title: "Commands"
description: "Package CLI commands reference for development, testing, and static analysis."
keywords:
  - commands
  - artisan
  - composer
  - phpunit
  - phpstan
  - rector
---

# Commands (Shared)

## Install
```bash
composer install
```

## Test
```bash
composer test                    # vendor/bin/phpunit
composer test-coverage           # vendor/bin/phpunit --coverage-html coverage
```

## Static Analysis
```bash
composer analyse                 # vendor/bin/phpstan analyse src tests
```

## Format
```bash
composer format                  # rector process (fix)
composer format-check            # rector process --dry-run --no-progress-bar
vendor/bin/php-cs-fixer fix --dry-run --diff   # lint (used by /lint shortcut)
```

## Quality (order matters)
```bash
composer quality                 # format-check -> analyse -> test
```

## Docs
```bash
composer docs:validate           # php bin/validate-docs.php
```

## Artisan Commands (in `src/Console/`, 16 total)
- `php artisan sp-laravel-api:setup` — publish configs + migrations
- `php artisan sp-laravel-api:agent` — set up AI agent skill, rules, and MCP
- `php artisan sp-laravel-api:record {name}` — scaffold a table config
- `php artisan sp-laravel-api:validate` — validate current config
- `php artisan sp-laravel-api:export-openapi` — generate OpenAPI spec
- `php artisan sp-laravel-api:export-bruno` / `sp-laravel-api:export-postman` — API client collections

> Note: there is no `composer serve` or `composer build` script. Test within a Laravel app that consumes this package.
