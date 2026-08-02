---
title: "Commands"
description: "Package CLI commands reference for development, testing, and static analysis."
keywords:
  - commands
  - artisan
  - composer
  - phpunit
---

# Commands (Shared)

## Install
```bash
composer install
```

## Run (dev)
```bash
# Test in a Laravel application that uses this package
composer serve
```

## Test
```bash
composer test
# or
vendor/bin/phpunit
```

## Lint / Analyze
```bash
composer analyse
# or
vendor/bin/phpstan analyse src tests
```

## Format
```bash
composer format
# or
vendor/bin/rector process
```

## Build
```bash
# Package for distribution
composer build
```