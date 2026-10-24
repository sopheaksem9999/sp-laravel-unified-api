---
title: All cursor pagination goes through Queries\CursorPaginator; Rector and php-cs-fixer fight over blank lines after split ifs
author: Sem
date: 2026-10-24
branch: develop
cost: { minutes: 23, tokens: 692828 }
id: l-e4fd62cd
scope: repo
status: active
touches:
  - CHANGELOG.md
  - VERSION
  - composer.json
  - docs/changelog.md
  - docs/versions.md
  - docs/guide/modules/module-pagination.md
  - src/Services/Queries/CursorPaginator.php
  - src/Services/Queries/RecordQueryBuilder.php
  - src/Services/RecordService.php
  - tests/Feature/CursorPaginationNavigationTest.php
---

## Decided
- `src/Services/Queries/CursorPaginator.php` is the only implementation of keyset paging. `RecordService::executeCursorPagination()` and `RecordQueryBuilder::cursorPaginate()` both delegate to it. The two copies had drifted: RecordQueryBuilder's tie-break compared the key against the cursor value. Fix cursor behaviour there, never in a caller.
- `direction=prev` reads in the reverse order and reverses the rows; `last_cursor` is the row just before the last page, read from the end of the list. Both depend on the key being the tie-break, so tests must use tied timestamps and run on integer and UUID keys (`tests/Feature/CursorPaginationNavigationTest.php`).

## Tried and ruled out
- Fixing the three bugs separately in each caller: ruled out because it would leave two diverging copies again.

## Watch out
- An explicit `select=` gets the paging column and key added and stripped afterwards, but not on a `distinct` query: adding a column changes which rows DISTINCT returns.
- Rector's ChangeOrIfContinueToMultiContinueRector splits `if (a || b) continue;` into two ifs and NewlineAfterStatementRector adds blank lines that php-cs-fixer then removes, so `composer quality` never settles. Write such checks as one condition (e.g. `!is_string($x)` already excludes Expression objects).
- `SP_MCP_DRIVER=laravel` matrix had one flaky failure on 2026-10-24 that passed on four reruns; capture the test name if it recurs.
