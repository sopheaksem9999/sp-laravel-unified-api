---
title: "SP Laravel API Pagination Module (Page and Per Page)"
description: "Pagination behavior summary covering page and per_page defaults, response metadata, and cursor pagination deprecation."
keywords:
  - pagination architecture
  - page per_page standard
  - pagination metadata
  - list endpoint paging
  - cursor deprecation
---

# Pagination Module

Record listing endpoints use page-based pagination.

## Standard Response Meta

- `meta.page`
- `meta.per_page`
- `meta.total`

Cursor pagination is deprecated and removed.

## Related Feature Docs

- [Pagination (Page/Per Page)](./feature-pagination-page-per-page.md)
