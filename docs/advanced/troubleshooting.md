---
title: "Troubleshooting"
description: "Common debugging paths for config-driven endpoints, permissions, missing data, and 500 errors."
keywords:
  - troubleshooting
  - debugging
  - config
  - permissions
  - middleware
  - tenant
---

# Troubleshooting Dynamic APIs

Because SP Laravel API abstracts away controllers and models, debugging requires looking at configuration and core logs rather than stepping through controller code.

## Endpoint returning 404 Not Found?
- Check `config/record.php` or `config/records/tables/*.php` to ensure the table is registered.
- Check if you need to run `php artisan sp-laravel-api:cache-clear` (if caching is enabled).
- Ensure you have run `php artisan route:clear`.
- Verify the table name matches the route (e.g., `api/v1/sp_audit_logs`).
- If you're using attribute-based discovery (`#[RecordTable]`), ensure `SP_ATTRIBUTE_DISCOVERY=true` is set.

## Permission Denied (403)?
- Verify your `isAuthRead` or `isAuthWrite` flags on the `RecordTableType`.
- Check the `pmsName` configuration and ensure the Spatie permission (or your custom Laravel Gate) is correctly defined.
- Review your `config/record.php` for any `middleware_map` overrides that might be applying stricter checks.

## Data missing from response?
- Check `hidden_columns` in your `RecordTableType` configuration.
- Verify `max_depth` if you are trying to load deep relationships and they aren't appearing.
- Ensure the requested relationships are explicitly defined in the `relationships` array for the table.
- If using `select=`, ensure you explicitly select the relationship name as well as its columns (e.g. `?select=id,name,customer(*)`).

## Server Errors (500)
- Look in `storage/logs/laravel.log`. The most common cause is a misconfigured `foreignKey` or `localKey` in your relationships array causing SQL syntax errors.
- Run `php artisan sp-laravel-api:validate` to ensure your configuration passes basic health checks.
