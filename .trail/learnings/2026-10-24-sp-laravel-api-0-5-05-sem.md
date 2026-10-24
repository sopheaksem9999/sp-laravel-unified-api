---
title: "sp-laravel-api 0.5.05 fixed cursor select, prev and last_cursor; frontend keeps its own paging by choice"
author: Sem
date: 2026-10-24
branch: main
cost: { minutes: 66, tokens: 770272 }
id: l-44d02df0
scope: repo
status: active
touches:
  - docs/bug-reports/2026-10-24-bug-report-sp-laravel-api-cursor-pagination-select-prev-last.md
  - src/Services/RecordService.php
---

## Decided
- sp-laravel-api 0.5.05 (pinned in speedx_sass_back composer.lock) fixed the three cursor bugs from 2026-10-24: meta.cursor with an explicit select=, direction=prev (send meta.prev_cursor), and meta.last_cursor (composite token, both sort orders). This supersedes "keep boundary_cursors off because last_cursor is wrong": it's now only a cost choice (one boundary query per list request); speedx_sass_back still has it off.
- The frontend keeps useCursorPagination as is: replay of visited pages for Previous/First, reversed oldest rows for Last. It covers every reachable page without the boundary query.
- The extra `created_at` in the Products/Customers/Vendors selects stays until every deployed backend runs 0.5.05 (harmless; still needed with distinct=true + explicit select).

## Watch out
- If boundary_cursors is turned on, Last could use `cursor={meta.last_cursor}&direction=next` instead; send cursors back as opaque tokens.
