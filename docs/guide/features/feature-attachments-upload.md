---
title: "Attachment Upload API"
description: "Feature guide for multipart upload, image resize inputs, temp visibility controls, timeout options, and clone-temp flow. Create, Update, Delete, List attachments."
keywords:
  - upload attachment api
  - multipart file upload
  - image resize
  - size_name
  - temp_timeout_minutes
  - temp_timeout_at
  - clone temp endpoint
  - as_temp flag
  - attachment visibility
---

# Attachment Upload

## Endpoint

- `POST /{api_prefix}/{attachment_prefix}/upload`

## Key Inputs

- `file` (required)
- `size_name`, `w`, `h`, `fit` for image resizing
- `visibility` (`private|public|temp_private|temp_public`)
- `as_temp` to force temp visibility
- `temp_timeout_minutes` or `temp_timeout_at`

## Clone Temp Endpoint

- `POST /{api_prefix}/{attachment_prefix}/clone-temp`

Clone creates a new attachment record and copies the source asset into a new path, with optional temp-specific timeout.

## Notes

- Temp timeout resolves in this order: `temp_timeout_at` -> `temp_timeout_minutes` -> `attachments.temp_lifetime` fallback.
- `temp_timeout_at` and `temp_timeout_minutes` are both capped by `attachments.max_temp_timeout_minutes`.
- Disk mapping is visibility-driven (`public`/`temp_public` on public disk; others on local disk).
