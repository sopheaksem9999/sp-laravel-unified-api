---
layout: home

hero:
  name: "SP Laravel API"
  text: "Unified Dynamic CRUD API for Laravel"
  tagline: "A comprehensive Laravel package providing standardized API responses, dynamic API controllers, query helpers, audit logging, and OpenAPI generation."
  image:
    src: /logo.png
    alt: "SP Laravel API"
  actions:
    - theme: brand
      text: "Get Started"
      link: "/getting-started/architecture"
    - theme: alt
      text: "API Reference"
      link: "/guide/api-index"

features:
  - title: "Dynamic API Controller"
    details: "Full CRUD operations for any database table with advanced filtering, eliminating boilerplate controllers."
  - title: "Query Helpers & Filtering"
    details: "PostgREST-style filters, sorting, search, and relationship loading with a rich operator set out of the box."
  - title: "Relationships & Upsert"
    details: "Powerful relation loading and atomic update-or-create operations out of the box."
  - title: "Pagination & Bulk Operations"
    details: "Offset and cursor pagination plus bulk create, update, delete, and upsert for high-throughput workflows."
  - title: "Audit Logging"
    details: "Comprehensive, queue-based audit trail for all data changes across your system."
  - title: "Permissions & Row-Level Security"
    details: "Built-in role/permission integration with optional PostgreSQL RLS scoping to own records."
  - title: "OpenAPI Spec Generation"
    details: "Dynamically generate API documentation directly from your table configurations."
  - title: "Webhooks & Realtime"
    details: "Event-driven webhook delivery and realtime events triggered from your record lifecycle hooks."
---

## Install in minutes

Set up the package in your Laravel backend and have your first dynamic endpoint running
in under five minutes.

<div class="cta-actions">

[Get Started](/getting-started/setup)

</div>

<style>
.cta-actions {
  display: flex;
  justify-content: center;
  gap: 16px;
  margin-top: 8px;
}
.cta-actions a {
  display: inline-block;
  padding: 12px 24px;
  border-radius: 8px;
  background-color: var(--vp-c-brand-1);
  color: var(--vp-c-white);
  font-weight: 600;
  transition: background-color 0.25s;
}
.cta-actions a:hover {
  background-color: var(--vp-c-brand-2);
  color: var(--vp-c-white);
}
</style>
