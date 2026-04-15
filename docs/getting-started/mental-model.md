---
title: "Mental Model"
description: "How to think about SP Laravel API as config-driven CRUD instead of MVC."
keywords:
  - mental model
  - config driven
  - dynamic crud
  - recordtabletype
  - openapi
---

# The Mental Model: Thinking in Config

SP Laravel API replaces the traditional Laravel MVC (Model-View-Controller) pattern with a **Config-Driven Pattern**.

## 1. No Controllers, No Models
In standard Laravel, you create a Model and a Controller for every table. In this package, you define a **Schema** in `config/records/tables/*.php` (or `config/record.php`). The package then uses its own internal logic (`RecordService`) to handle the database operations dynamically.

## 2. Why this is better
- **Zero Boilerplate:** Add a new table in seconds by updating a config array.
- **Performance:** Bypasses Eloquent overhead using raw `DB::table()` queries.
- **Consistency:** Every endpoint in your API behaves exactly the same way, with identical response structures.
- **Immediate Documentation:** Your configuration is used directly to generate the OpenAPI schema.

## 3. How to Debug
Since you don't have a specific controller to open and add a `dd()`, you debug by:
- Inspecting your `RecordTableType` configuration.
- Checking your Audit Logs (`sp_audit_logs`).
- Utilizing the `sp-laravel-api:validate` command to catch configuration issues.
- Verifying your permission `pmsName` mapping if encountering 403 errors.
