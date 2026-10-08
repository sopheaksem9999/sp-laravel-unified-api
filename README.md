# SP Laravel Unified API

[![Latest Stable Version](https://img.shields.io/packagist/v/sopheak/sp-laravel-api.svg)](https://packagist.org/packages/sopheak/sp-laravel-api)
[![Total Downloads](https://img.shields.io/packagist/dt/sopheak/sp-laravel-api.svg)](https://packagist.org/packages/sopheak/sp-laravel-api)
[![PHP Version](https://img.shields.io/packagist/dependency-v/sopheak/sp-laravel-api/php.svg)](https://packagist.org/packages/sopheak/sp-laravel-api)
[![License](https://img.shields.io/packagist/l/sopheak/sp-laravel-api.svg)](https://packagist.org/packages/sopheak/sp-laravel-api)
[![Security](https://github.com/sopheaksem9999/sp-laravel-unified-api/actions/workflows/security.yml/badge.svg)](https://github.com/sopheaksem9999/sp-laravel-unified-api/actions/workflows/security.yml)

`sopheak/sp-laravel-api` is a comprehensive, config-driven REST API package for Laravel applications. It replaces repetitive controllers, boilerplate query builders, and manual CRUD endpoints with declarative schema definitions while providing standardized API responses, multi-tenant isolation, granular field permissions, transactional audit trails, file attachment workflows, and AI/MCP tool integrations.

The package owns API infrastructure, dynamic endpoint resolution, query filtering, and audit logging. Your application defines the table schemas, business rules, custom functions, and authorization policies.

Public package links:
- **Packagist:** [packagist.org/packages/sopheak/sp-laravel-api](https://packagist.org/packages/sopheak/sp-laravel-api)
- **Canonical Repository:** [github.com/sopheaksem9999/sp-laravel-unified-api](https://github.com/sopheaksem9999/sp-laravel-unified-api)
- **Documentation:** [Full Documentation & Guide](https://github.com/sopheaksem9999/sp-laravel-api-docs)

---

## Features

| Feature | What it provides | Default | Guide |
|---|---|---|---|
| **Config-Driven Dynamic CRUD** | Declarative `RecordTableType` schema; automatic `GET`, `POST`, `PUT`, `DELETE`, and atomic `upsert` endpoints | Enabled | [CRUD Operations](docs/guide/api/api-crud-operations.md) |
| **Standardized API Envelope** | Consistent JSON response format (`{ success, error_code, data, meta }`), request IDs, and microsecond execution timing | Enabled | [API Responses](docs/guide/api/api-errors-rate-security.md) |
| **Multi-Tenant Isolation** | Automatic tenant scoping via `X-Tenant-ID` header across queries, includes, bulk operations, and cache namespaces | Enabled | [Tenant Isolation](docs/guide/records/record-tenancy.md) |
| **Advanced Query Filtering** | PostgREST-style operators (`eq`, `neq`, `like`, `in`, `gt`, `gte`, `between`, `is_null`), sorting, and field projection | Enabled | [Query Filters](docs/guide/api/api-apply-request-filters.md) |
| **Relational Includes** | Subquery loading and JOIN resolution via `select=` query syntax (`RecordHasManyType`, `RecordBelongsToType`, etc.) | Enabled | [Relationships](docs/core-concepts/relationships.md) |
| **Pagination Engine** | Offset-based (`page`/`per_page`) and cursor-based pagination for high-volume datasets | Enabled | [Pagination](docs/guide/modules/module-pagination.md) |
| **Bulk Operations** | High-throughput batch create, update, delete, and upsert with queue support | Enabled | [Bulk Operations](docs/guide/api/api-nested-and-bulk-operations.md) |
| **Permissions & RLS Scoping** | Column hidden lists (`columnHiddens`), operation guards (`canRead`, `canCreate`), `viewOwn` scoping, and Laravel Gate integration | Enabled | [Permissions](docs/guide/features/feature-permission.md) |
| **Transactional Audit Logging** | Comprehensive change tracking (old/new diffs, actor ID, client IP, user agent, tenant ID) with queue buffering | Enabled | [Audit Logging](docs/features/audit-logging.md) |
| **Attachments & Direct Upload** | S3/Cloudflare R2 direct uploads, multipart upload, presigned private preview URLs, and image resizing | Configurable | [Attachments](docs/features/attachments.md) |
| **Real-time OpenAPI Generator** | Dynamic OpenAPI 3.0 specification auto-generated from active table types and custom function attributes | Enabled | [OpenAPI Docs](docs/core-concepts/api-documentation.md) |
| **API Client Exporters** | Instant export to Bruno (`.bru`) and Postman collection files with auth header management | Enabled | [API Clients](docs/guide/modules/module-api-clients.md) |
| **Table Triggers & Hooks** | Lifecycle hooks (`beforeCreate`, `afterUpdate`, etc.) and database triggers for custom domain rules | Configurable | [Record Hooks](docs/guide/records/record-hooks.md) |
| **AI SDK & MCP Record Tools** | First-class AI tools and Model Context Protocol (MCP) server driver (`laravel/mcp`, `laravel/ai`) for agentic workflows | Optional | [AI & MCP](docs/guide/modules/module-mcp.md) |
| **Cache Management** | High-performance per-table and query caching with automatic cache invalidation on writes | Optional (Opt-in) | [Cache Guide](docs/guide/records/record-cache.md) |

---

## Core package features

The enabled-by-default core provides enterprise SaaS applications with a unified API layer while leaving application-specific business logic in your app:

- **Config-Driven Architecture** — Define table schemas in `config/records/tables/*.php` without repetitive boilerplate controllers, requests, or repository classes.
- **Unified Response Contract** — Every endpoint returns `{ success, error_code, data, meta }` with automatic error normalization, validation errors, and `X-Request-ID` correlation.
- **Built-in Multi-Tenancy** — Strict tenant isolation via `X-Tenant-ID` header, preventing cross-tenant leakage across reads, writes, nested relationships, and cache tags.
- **Granular Authorization** — Configure table-level permissions via `isAuthRead` / `isAuthWrite`, action availability (`canRead`, `canCreate`, `canUpdate`, `canDelete`, `canUpsert`), column hiding, and `viewOwn` ownership scoping.
- **Full Observability & Audit Trail** — Automatically record all state mutations with before/after state diffs, authenticated userstamps, IP addresses, and user agents.
- **AI & MCP Native** — Expose your dynamic tables directly to LLM agents using Laravel MCP (`laravel/mcp`) or Laravel AI SDK (`laravel/ai`) tools.
- **Operational Tooling** — CLI commands to scaffold schemas, validate configuration, and export OpenAPI 3.0 specifications and Bruno/Postman collections.

---

## 🚀 Quick Start

### Requirements
- **PHP:** `^8.2`, `^8.3`, `^8.4`, or `^8.5` (PHP 8.3+ required for AI SDK tools)
- **Laravel:** `^12.0` or `^13.0`
- **Database:** MySQL 8.0+, PostgreSQL 13+, or SQLite

### Installation

1. Install the package via Composer:
   ```bash
   composer require sopheak/sp-laravel-api
   ```

2. Publish package configuration and migrations:
   ```bash
   php artisan sp-laravel-api:setup
   ```

3. Run the database migrations:
   ```bash
   php artisan migrate
   ```

4. Validate the installation configuration:
   ```bash
   php artisan sp-laravel-api:validate
   ```

---

## ⚙️ Configuration Example

Define a table configuration in `config/records/tables/orders.php`:

```php
<?php

declare(strict_types=1);

use Sopheak\Core\Types\RecordBelongsToType;
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Types\RecordTableType;

return new RecordTableType(
    pmsName: 'order',
    table: 'orders',
    isAuthRead: true,
    isAuthWrite: true,
    canRead: true,
    canCreate: true,
    canUpdate: true,
    canDelete: true,
    canUpsert: true,
    relationships: [
        'customer' => new RecordBelongsToType(
            table: 'customers',
            foreignKey: 'customer_id',
            ownerKey: 'id',
        ),
        'items' => new RecordHasManyType(
            table: 'order_items',
            foreignKey: 'order_id',
            localKey: 'id',
        ),
    ],
);
```

### Dynamic Endpoints Generated

Once the table config is defined, the package instantly generates standard RESTful endpoints:

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/records/orders` | List records with filtering, sorting, pagination, and includes |
| `GET` | `/api/records/orders/{id}` | Retrieve a single record by ID |
| `POST` | `/api/records/orders` | Create a new record |
| `PUT` | `/api/records/orders/{id}` | Update an existing record |
| `DELETE` | `/api/records/orders/{id}` | Delete a record |
| `POST` | `/api/records/orders/upsert` | Atomically insert or update records |

### Querying with Filters & Relationships

```bash
# Filter orders by status, eager load customer and items, 15 per page:
curl -X GET "https://api.example.com/api/records/orders?filter[status]=completed&select=customer,items&per_page=15" \
  -H "Authorization: Bearer <token>" \
  -H "X-Tenant-ID: tenant-123"
```

### Standard Response Structure

```json
{
  "success": true,
  "error_code": 0,
  "data": [
    {
      "id": 101,
      "order_number": "ORD-2026-001",
      "status": "completed",
      "customer": {
        "id": 42,
        "name": "Acme Corp"
      },
      "items": [
        {
          "id": 201,
          "product_name": "Premium License",
          "quantity": 1
        }
      ]
    }
  ],
  "meta": {
    "total": 1,
    "page": 1,
    "per_page": 15,
    "request_id": "req_65b3f2e1a9c4"
  }
}
```

---

## 🛠️ Artisan CLI Tooling

The package provides Artisan commands for developer workflows:

| Command | Description |
|---|---|
| `php artisan sp-laravel-api:setup` | Publish package configuration and database migrations |
| `php artisan sp-laravel-api:record {name}` | Scaffold a new table configuration schema |
| `php artisan sp-laravel-api:validate` | Validate all active table definitions and relationships |
| `php artisan sp-laravel-api:export-openapi` | Export full OpenAPI 3.0 specification file |
| `php artisan sp-laravel-api:export-bruno` | Export Bruno (`.bru`) API client collection |
| `php artisan sp-laravel-api:export-postman` | Export Postman API client collection |
| `php artisan sp-laravel-api:agent` | Scaffold AI agent skills, rules, and MCP configuration |

---

## 📚 Documentation

For full documentation, architecture guides, and advanced features, visit:
- **[Official Documentation Guide](https://github.com/sopheaksem9999/sp-laravel-api-docs)**
- **[Contributing Guidelines](CONTRIBUTING.md)**

---

## 📄 License

This package is proprietary software. All rights reserved.

