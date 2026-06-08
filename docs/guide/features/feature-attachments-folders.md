---
title: "Attachment Folder API"
description: "Feature guide for folder management endpoints and hierarchy model used to organize attachments. Create, Update, Delete, List folders."
keywords:
  - folder endpoints
  - create folder
  - update folder
  - delete folder
  - list folders
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

Folders are stored in `sp_document_folders` with:

- `id`
- `name`
- `parent_id`
- `scope` (`internal` by default)
- `visibility` (`private` by default)
- `owner_type` / `owner_id`
- `metadata`

The model supports simple tree-like grouping through `parent_id`, plus optional public/internal resource organization through `scope` and `visibility`.

## Safety Options

- `attachments.access.validate_folder_exists=false` by default preserves legacy behavior. Set it to `true` to reject uploads or folder updates that reference a missing folder.
- `attachments.folder_delete_strategy=legacy` preserves existing delete behavior. Set it to `restrict` to block deleting folders that still contain child folders or attachments.
