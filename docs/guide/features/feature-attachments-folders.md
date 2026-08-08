---
title: "Attachment Folder API"
description: "Feature guide for folder management endpoints and hierarchy model used to organize attachments. Create, Update, Delete, List folders."
keywords:
  - folder endpoints
  - create folder
  - update folder
  - delete folder
  - list folders
  - sp_attachment_folders
  - sp_document_folders
  - parent_id hierarchy
---

# Attachment Folder Management

## Endpoints

- `GET /{api_prefix}/{attachment_prefix}/folders`
- `POST /{api_prefix}/{attachment_prefix}/folders`
- `PUT|PATCH /{api_prefix}/{attachment_prefix}/folders/{id}`
- `DELETE /{api_prefix}/{attachment_prefix}/folders/{id}`

## Data Model

Folders are stored in `sp_attachment_folders` (renamed from `sp_document_folders`) with:

- `id`
- `name`
- `parent_id`
- `scope` (`internal` by default)
- `visibility` (`private` by default)
- `owner_type` / `owner_id`
- `metadata`

The model supports simple tree-like grouping through `parent_id`, plus optional public/internal resource organization through `scope` and `visibility`.

### Rename from `sp_document_folders`

The endpoints above (`/folders`, `/folders/{id}`) are custom function routes and were never affected by this rename — they don't expose the table name in the URL. What changed is the physical table and the `record.tables`/`attachments.tables` config key used internally and by the generic `/{table}` CRUD route:

- The physical table was renamed via a guarded migration (`Schema::rename`, mirroring the `sp_audit_logs` rename precedent).
- `sp_attachment_folders` is now the canonical config key.
- `sp_document_folders` is kept registered as a deprecated alias pointing at the same table, so a client hitting the generic `/{api_prefix}/sp_document_folders` route directly (bypassing the `/folders` endpoints above) keeps working.
- Both entries set `disableCache: true` while both are live, so a write through one key's route is immediately visible through the other's — remove that flag (and the deprecated key entirely) once clients have migrated to `sp_attachment_folders`.

## Safety Options

- `attachments.access.validate_folder_exists=false` by default preserves legacy behavior. Set it to `true` to reject uploads or folder updates that reference a missing folder.
- `attachments.folder_delete_strategy=legacy` preserves existing delete behavior. Set it to `restrict` to block deleting folders that still contain child folders or attachments.
