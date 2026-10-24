---
title: Releases stay on 0.5.0X; 0.6.0 is reserved for removing the legacy MCP driver
author: Sem
date: 2026-10-24
branch: develop
cost: { minutes: 792, tokens: 12462219 }
id: l-92e764e1
scope: repo
status: active
touches:
  - ".gitleaks.toml"
  - README.md
  - CONTRIBUTING.md
  - CHANGELOG.md
  - VERSION
  - composer.json
  - docs/changelog.md
  - docs/versions.md
  - ".agents/rules/architecture.md"
  - config/sp-record.php
---

## Decided
- Releases bump the last two-digit segment (0.5.03 → 0.5.04 → 0.5.05), even when they carry behaviour changes. The behaviour changes go in the changelog's "Upgrade note" entries instead of the version number. Chosen by the maintainer for 0.5.04 (2026-10-03).
- A release touches five files: VERSION, composer.json "version", CHANGELOG.md and docs/changelog.md (promote [Unreleased] to "## [0.5.0X] - date" and keep an empty [Unreleased] above it), and docs/versions.md (current version line and a new row in the release table). See commit cd6fde0 (0.5.02) for the pattern.

## Tried and ruled out
- 0.6.0 for 0.5.04: ruled out because the changelog's deprecation note promises 0.6.0 for removing the `legacy` MCP driver.
- 0.5.1: invalid, because Composer orders 0.5.1 below 0.5.03.

## Watch out
- `composer validate` warns that the `version` field is present. That warning is expected here, so don't remove the field.
