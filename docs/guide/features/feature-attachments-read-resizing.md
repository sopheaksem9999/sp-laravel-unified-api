---
title: "Attachment Read-Time Resizing"
description: "Opt-in image resizing on the attachment view endpoint via w, h, fit, format, and size_name query params, with bounds validation, cache headers, and optional write-back caching."
keywords:
  - attachment view resizing
  - read_resizing
  - image resize query params
  - webp attachment
  - read_resize_cache
  - size_name view
  - attachment image format
---

# Attachment Read-Time Resizing

The `GET /{api_prefix}/{attachment_prefix}/{id}/view` endpoint can resize and
re-encode images on the fly. The feature is **off by default**; until you enable
it, the view endpoint serves the stored bytes exactly as before.

## Enabling

```php
// config/sp-attachments.php
'attachments.read_resizing' => true,
```

The package merges defaults into your published file, so new keys appear
automatically. See the full config reference below.

## How It Works

Append query params to the view URL:

```
GET /api/v1/sp_attachments/{id}/view?w=800&h=600&fit=contain&format=webp
```

| Param | Type | Description | Default |
|---|---|---|---|
| `w` | int | Target width in px (bounded, see config) | none |
| `h` | int | Target height in px (bounded, see config) | none |
| `fit` | string | `contain` (scale to fit) or `crop` (cover + center crop) | `contain` |
| `format` | string | Output format: `webp`, `jpg`, `jpeg`, `png`, `gif` | `jpg` |
| `size_name` | string | Predefined size from `attachments.image_sizes`; overrides `w`/`h`/`fit` | none |

At least one of `w`, `h`, `format`, or `size_name` is required to trigger a
transform. Without them, the endpoint serves the original file byte-for-byte.

`size_name` works exactly like upload-time sizing — the same
`attachments.image_sizes` map:

```php
'attachments.image_sizes' => [
    'thumbnail' => ['w' => 150, 'h' => 150, 'fit' => 'crop'],
    'medium' => ['w' => 800, 'h' => null, 'fit' => 'contain'],
],
```

```text
GET /api/v1/sp_attachments/{id}/view?size_name=thumbnail
```

## Response

The response `Content-Type` reflects the **output** format, not the stored one.
A stored JPEG resized to `format=webp` returns `image/webp`.

```json
// Error: out-of-range dimension
{
  "success": false,
  "error_code": 422,
  "message": "Dimension must be between 32 and 2000"
}
```

```json
// Error: format not in the allow-list
{
  "success": false,
  "error_code": 422,
  "message": "Invalid format value"
}
```

## Behavior Rules

- **Non-images are untouched.** A PDF with `?w=100` is served as-is; resize
  params are only honored for `image/*` mime types.
- **Bounds are enforced.** Dimensions outside `read_resizing_min`/`read_resizing_max`
  return `422`. This prevents memory-exhaustion resizes on public endpoints.
- **The download endpoint is not affected.** `{id}/download` always serves the
  original file.
- **URLs are not rewritten.** `AttachmentUrlService` output is unchanged;
  clients opt into resizing by appending query params themselves.

## Caching

By default every resized request re-encodes the image. Two optional layers are
available:

### Cache Headers

```php
'attachments.read_resize_cache_max_age' => 31536000, // seconds
```

When > 0, resized responses carry `Cache-Control: public, max-age=N`. The
default is `0`, meaning no cache header (the endpoint stays dynamic). Pair a
long max-age with a cache-busting query param derived from the attachment's
`updated_at`.

### Write-Back Cache

```php
'attachments.read_resize_cache' => true,
'attachments.read_resize_cache_disk' => 'public',
'attachments.read_resize_cache_ttl_minutes' => 10080,
```

The first request for a variant encodes and stores the derived file at
`attachments/resized/{hash}.{format}` on the configured disk; later requests
serve the stored file directly. The cache key includes the attachment id,
dimensions, fit, format, and source file mtime, so a replaced source file
produces a new variant path automatically.

## Config Reference

| Key | Default | Description |
|---|---|---|
| `read_resizing` | `false` | Master switch for read-time resizing |
| `read_resizing_min` | `32` | Minimum accepted dimension |
| `read_resizing_max` | `2000` | Maximum accepted dimension |
| `read_resizing_formats` | `['webp', 'jpg', 'jpeg', 'png', 'gif']` | Allowed output formats |
| `read_resize_cache` | `false` | Enable write-back caching |
| `read_resize_cache_disk` | `public` | Disk for derived files |
| `read_resize_cache_ttl_minutes` | `10080` | How long derived files remain valid |
| `read_resize_cache_max_age` | `0` | `Cache-Control` max-age; `0` = no header |

## Related Docs

- [Attachment Module](/features/attachments)
- [Attachment Upload](/guide/feature-attachments-upload)
- [Attachment Visibility and Access](/guide/feature-attachments-visibility-access)
