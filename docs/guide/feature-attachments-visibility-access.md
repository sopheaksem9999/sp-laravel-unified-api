---
title: "Attachment Access Control (Visibility, Expiry, Protected Download)"
description: "Feature guide for visibility modes, temp expiry logic, URL generation choices, and protected download behavior."
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

## Protection Option for temp_public

Use `attachments.protect_temp_public_via_download`:

- `false` (default): `temp_public` returns direct URL
- `true`: `temp_public` returns `/download` URL so API checks execute before access

## Runtime Expiry Enforcement

Download endpoint denies expired temp files with HTTP `410 Gone`.

This protects access immediately, even before scheduled cleanup removes stale files.
