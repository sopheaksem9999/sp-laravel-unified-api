---
title: "Attachment Access Control"
description: "Feature guide for visibility modes, temp expiry logic, URL generation choices, and protected download behavior. Create, Update, Delete, List attachments."
keywords:
  - private file access
  - public file url
  - temp_public protection
  - temp_private expiry
  - download endpoint authorization
  - protect_temp_public_via_download
  - attachment expiration
  - http 410 gone
---

# Attachment Visibility and Access

## Visibility Modes

- `private`: protected download URL
- `public`: direct disk URL
- `temp_private`: protected download URL + timeout lifecycle
- `temp_public`: direct disk URL by default + timeout lifecycle

## Filesystem Disks (local vs public)

This module assumes you use different filesystem disks for public vs private assets:

- `public` / `temp_public` → `attachments.disk_public` (default: `public`)
- `private` / `temp_private` → `attachments.disk_private` (default: `local`)

When using local storage, the secure setup is:

- `public` disk root: `storage/app/public` and exposed via `/storage/*` (Laravel `php artisan storage:link`)
- `local` disk root: `storage/app` and NOT exposed by the web server

If a `private` attachment can be opened by concatenating `APP_URL` + `path`, it means the private storage directory is being served publicly (usually a misconfigured symlink or web server rule). The fix is to ensure only `storage/app/public` is web-accessible.

For S3 (or any cloud disk), `public` attachments will typically return a bucket/CDN URL (via `disk->url()`), while `private` attachments should still return API-proxied URLs (`/view` and `/download`) so authentication + tenant scope + expiry checks are enforced.

## URL Strategy

Use `attachments.url_strategy` to choose how the `url` field is generated:

- `auto` (default): keeps existing behavior, returning direct URLs for public visibility and API URLs for private visibility.
- `api`: always returns the API `/view` URL.
- `temporary`: uses the disk driver's `temporaryUrl()` when available, otherwise falls back to API `/view`.
- `direct`: returns direct public disk URLs.

## Protection Option for temp_public

Use `attachments.protect_temp_public_via_download`:

- `false` (default): `temp_public` returns direct URL
- `true`: `temp_public` returns `/download` URL so API checks execute before access

## Runtime Expiry Enforcement

Download endpoint denies expired temp files with HTTP `410 Gone`.

This protects access immediately, even before scheduled cleanup removes stale files.

`temp_timeout_at` is capped by `attachments.max_temp_timeout_minutes`, matching the existing cap for `temp_timeout_minutes`.
