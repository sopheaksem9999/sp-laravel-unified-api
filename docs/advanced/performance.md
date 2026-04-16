---
title: "Performance"
description: "Practical performance guidance for dynamic CRUD, relationship loading, caching, and bulk operations."
keywords:
  - performance
  - scalability
  - bulk operations
  - relationships
  - caching
  - pagination
  - indexing
---

# Performance

## Dynamic CRUD Query Cost

- Prefer `select=` to limit columns, especially on wide tables.
- Use pagination (`page`, `per_page`) instead of unbounded lists.
- Add DB indexes for common filters and sorts (`status`, `created_at`, foreign keys, tenant column).

## Relationship Loading

- Prefer loading only needed relationships (`select=*,customer(id,name)`).
- Keep relationship depth small and predictable (`max_depth` is your safety valve).
- Avoid joining multiple high-cardinality relations on list endpoints unless required.

## Caching

- Enable query caching when tables are read-heavy and change infrequently.
- Keep TTL short for frequently-updated tables; invalidate on write paths.

## Bulk Operations

- Bulk operations still execute validation + trigger logic; do not assume “free” writes.
- Use smaller batch sizes when payloads are large to avoid timeouts.
- Ensure the DB has proper indexes on the primary key and tenant column.

