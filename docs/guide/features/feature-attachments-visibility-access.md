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

## R2 Configuration

Cloudflare R2 uses Laravel's standard `s3` filesystem driver. Configure separate disks
when public and private attachments use separate buckets:

```php
// config/filesystems.php
'r2-public' => [
    'driver' => 's3',
    'key' => env('R2_PUBLIC_ACCESS_KEY'),
    'secret' => env('R2_PUBLIC_SECRET'),
    'region' => 'auto',
    'bucket' => env('R2_PUBLIC_BUCKET', 'karunafilm-public'),
    'endpoint' => env('R2_PUBLIC_ENDPOINT'),
    'url' => env('R2_PUBLIC_URL'),
    'use_path_style_endpoint' => true,
],
'r2-private' => [
    'driver' => 's3',
    'key' => env('R2_PRIVATE_ACCESS_KEY'),
    'secret' => env('R2_PRIVATE_SECRET'),
    'region' => 'auto',
    'bucket' => env('R2_PRIVATE_BUCKET', 'karunafilm-private'),
    'endpoint' => env('R2_PRIVATE_ENDPOINT'),
    'use_path_style_endpoint' => true,
],
```

```php
// config/sp-attachments.php
'disk_public' => 'r2-public',
'disk_private' => 'r2-private',
'direct_upload' => [
    'enabled' => false,
    'presign_ttl_seconds' => 1800,
    'min_multipart_size_bytes' => 104857600, // 100 MiB
],
'preview_url_enabled' => false,
'preview_url_ttl_seconds' => 300,
```

Bucket selection is based on visibility, not file type. Images and videos may use either
bucket. The public and private buckets must be provisioned before integration testing.

Apply this CORS policy to both buckets, replacing the origins with the approved dashboard
and development origins:

```json
[
  {
    "AllowedOrigins": ["https://admin.karunafilm.example", "http://localhost:3000"],
    "AllowedMethods": ["GET", "HEAD", "PUT", "POST"],
    "AllowedHeaders": ["*"],
    "ExposeHeaders": ["ETag", "Content-Length", "Content-Type"],
    "MaxAgeSeconds": 3600
  }
]
```

CORS does not make the private bucket public. Private object access remains restricted to
presigned requests or the package's signed preview/API endpoints.

**Direct upload size limit:** S3 presigned `PUT` URLs cannot carry a `content-length-range`,
so the object is uploaded to the bucket first and `max_upload_size` is enforced at
`complete-upload` time: oversized objects are deleted and the attachment row is refused
(422). Server-side `POST` fallback uploads are limited by the same `max_upload_size`
validation rule on the file input. Multipart uploads (>= `min_multipart_size_bytes`) are
bounded only by the bucket configuration, as agreed in the client contract.

**Signed preview URL shape:** `{id}`-pattern functions are dispatched with the id inside
the function name, e.g. `{api_prefix}/{route_prefix}/rpc/{id}/preview` under the default
`rpc_prefix='rpc'`. A non-empty `rpc_prefix` is required for id-bearing attachment
functions (`preview`, `download`, `view`).

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
