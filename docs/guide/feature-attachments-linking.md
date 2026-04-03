---
title: "Attachment Linking API (Record Relations and Collections)"
description: "Feature guide for linking and unlinking attachments to records using collection groups and replace-old behavior."
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
- `replace_old=true` removes older links in the same collection and deletes old attachment files/rows.
