---
title: "Attachments Module"
description: "Attachments Module Architecture overview for attachments including table design, function endpoints, visibility strategy, and temporary file lifecycle."
keywords:
  - attachments architecture
  - attachments config
  - RecordTableType attachments
  - RecordFunctionType upload
  - sp_attachments schema
  - file upload module
  - visibility model
  - temporary files
---

# Attachment Module

The attachment module provides dynamic endpoints for file management using `RecordTableType` + `RecordFunctionType` in `config/attachments.php`.

## Scope

- Upload and optional image resizing
- Attachment metadata management
- Link/unlink attachments to arbitrary records
- Folder organization
- Public/private/temp visibility strategies
- Temp attachment lifecycle with timeout and cleanup command

## Data Tables

- `sp_attachments`
- `sp_attachment_links`
- `sp_attachment_folders` (renamed from `sp_document_folders`; the old config key is kept registered, pointing at the same table, as a deprecated alias for the generic `/{table}` CRUD route — see [Attachment Folder Management](/guide/feature-attachments-folders))

## Main Endpoints

- `POST /{api_prefix}/{attachment_prefix}/upload`
- `POST /{api_prefix}/{attachment_prefix}/clone-temp`
- `GET /{api_prefix}/{attachment_prefix}/{id}/view` (optional read-time resizing)
- `GET /{api_prefix}/{attachment_prefix}/{id}/download`
- `GET|POST /{api_prefix}/{attachment_prefix}/record/{table}/{record_id}`
- `DELETE /{api_prefix}/{attachment_prefix}/record/{table}/{record_id}/{attachment_id}`
- `GET|POST /{api_prefix}/{attachment_prefix}/folders`
- `PUT|PATCH|DELETE /{api_prefix}/{attachment_prefix}/folders/{id}`

## Related Feature Docs

- [Attachment Upload](/guide/feature-attachments-upload)
- [Attachment Read-Time Resizing](/guide/feature-attachments-read-resizing)
- [Attachment Linking](/guide/feature-attachments-linking)
- [Attachment Visibility and Access](/guide/feature-attachments-visibility-access)
- [Attachment Folder Management](/guide/feature-attachments-folders)
- [Attachment Temp Cleanup (Cron)](/guide/feature-attachments-cleanup-cron)
