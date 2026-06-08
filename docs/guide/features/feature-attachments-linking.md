---
title: "Attachment Linking API "
description: "Feature guide for linking and unlinking attachments to records using collection groups and replace-old behavior. Create, Update, Delete, List attachment links."
keywords:
  - link attachment to record
  - unlink attachment
  - collection_name
  - replace_old
  - polymorphic attachment relation
  - sp_attachment_links table
  - record_type record_id
---

# Attachment Linking

## Endpoints

- `GET /{api_prefix}/{attachment_prefix}/record/{table}/{record_id}`
- `POST /{api_prefix}/{attachment_prefix}/record/{table}/{record_id}`
- `DELETE /{api_prefix}/{attachment_prefix}/record/{table}/{record_id}/{attachment_id}`

## Link Model

Links are stored in `sp_attachment_links` using:

- `attachment_id`
- `record_id`
- `record_type`
- `collection_name`

## Behavior

- `collection_name` defaults to `default`.
- Upload and clone flows can auto-link when `record_id` and `record_type` are provided.
- `replace_old=true` removes older links in the same collection. Old attachment files/rows are deleted only when no other link still references that attachment.

## Optional Target Record Checks

By default, linking keeps legacy behavior and does not validate the target record. To make attachment links stricter:

- `attachments.access.validate_record_exists=true` checks that the target record exists when the table is registered.
- `attachments.access.record_authorizer` may be set to a callable to enforce application-specific read/link/unlink rules for the target record.

## Example: Upload Multiple Files and Link to an Invoice

This shows two common patterns for attaching multiple files to a record like an invoice.

### Option A: Upload First, Then Link (Recommended)

1) Upload each file (multipart):

```bash
curl -X POST "http://your-api.test/{api_prefix}/{attachment_prefix}/upload" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "file=@/path/to/a.png" \
  -F "visibility=private"
```

```bash
curl -X POST "http://your-api.test/{api_prefix}/{attachment_prefix}/upload" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "file=@/path/to/b.pdf" \
  -F "visibility=private"
```

Each upload returns an attachment `id`. Collect them (example):

- `a_id`
- `b_id`

2) Link them to an invoice (example invoice ID: `INV-1001`):

```bash
curl -X POST "http://your-api.test/{api_prefix}/{attachment_prefix}/record/invoices/INV-1001" \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{
    "attachment_ids": ["a_id", "b_id"],
    "collection_name": "invoice_files"
  }'
```

### Option B: Upload and Auto-Link Per File

Upload each file and include `record_type`, `record_id`, and optionally `collection_name`:

```bash
curl -X POST "http://your-api.test/{api_prefix}/{attachment_prefix}/upload" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "file=@/path/to/a.png" \
  -F "visibility=private" \
  -F "record_type=invoices" \
  -F "record_id=INV-1001" \
  -F "collection_name=invoice_files"
```
