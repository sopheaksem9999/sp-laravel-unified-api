---
title: "Attachment Folder API (Create, Update, Delete, List)"
description: "Feature guide for folder management endpoints and hierarchy model used to organize attachments."
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

The model supports simple tree-like grouping through `parent_id`.
