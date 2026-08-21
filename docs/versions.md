---
title: "Versions"
description: "Release history and version information for the sp-laravel-api package."
keywords:
  - versions
  - release history
  - changelog
  - semver
---

# Versions

The package follows [Semantic Versioning](https://semver.org) (MAJOR.MINOR.PATCH).

- **Current version:** `0.4.96` (2026-08-21)
- **Installation:** `composer require sopheak/sp-laravel-api`
- **Full details:** see the [Changelog](/changelog)

## Release History

| Version | Date | Highlights |
|---------|------|------------|
| [0.4.96](/changelog#0496---2026-08-21) | 2026-08-21 | `viewOwn` owner-column resolution (`ownerColumn` + `own_records_owner_columns`), `created_by_id` no longer rewritten on update |
| [0.4.95](/changelog#0495---2026-08-18) | 2026-08-18 | Disabled auto-creation of agent assets in Boost, introduced `sp-laravel-api:agent` on-demand command |
| [0.4.94](/changelog#0494---2026-08-18) | 2026-08-18 | Caching opt-in per table/function (`disableCache` defaults `true`), `httpMethod` enum validation, `public` deprecated |
| [0.4.93](/changelog#0493---2026-08-17) | 2026-08-17 | Direct upload, S3/R2 multipart upload, signed private preview URLs |
| [0.4.92](/changelog#0492---2026-08-16) | 2026-08-16 | Cursor pagination fixes (sort/cursor alignment, composite cursor token) |
| [0.4.91](/changelog#0491---2026-08-16) | 2026-08-16 | — |
| [0.4.90](/changelog#0490---2026-08-15) | 2026-08-15 | — |
| [0.4.89](/changelog#0489---2026-08-14) | 2026-08-14 | — |
| [0.4.88](/changelog#0488---2026-08-13) | 2026-08-13 | — |
| [0.4.87](/changelog#0487---2026-08-12) | 2026-08-12 | — |
| [0.4.86](/changelog#0486---2026-08-12) | 2026-08-12 | — |

## Upgrade Path

Upgrades within the same major version (0.4.x) are backward compatible. For
breaking changes and migration steps, see:

- [Upgrade 0.4.80 → 0.4.82](/getting-started/upgrade-0.4.80-to-0.4.82)
- [Changelog](/changelog) — all changes since 0.4.86