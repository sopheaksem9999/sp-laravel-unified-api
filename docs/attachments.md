# Attachment & File Manager

The `sp-laravel-api` package includes a powerful, dynamic Attachment and File Manager. It is designed to handle file uploads, client-dictated image resizing, and polymorphic relationships (linking files to any record) while fully respecting the package's dynamic multi-tenancy configuration.

## Architecture

Attachment endpoints are registered via `config/attachments.php` using `RecordTableType` + `RecordFunctionType`, following the same config-driven architecture as the rest of the package.

- The route prefix comes from `attachments.route_prefix`.
- Set `attachments.enabled=false` to disable attachment table/function registration.

## Features

- **Client-Dictated Resizing:** Pass `w` (width) and `h` (height) during upload to resize images on the fly before saving, saving storage space and improving read performance.
- **Dynamic Multi-Tenancy:** Automatically uses the `tenant_id` column configured in `config('record.tenant_column')`.
- **Polymorphic Linking:** Link a single uploaded file to any record (e.g., User, Invoice) using `record_id` and `record_type`.
- **Collection Support:** Group attachments by collection (e.g., `avatar`, `gallery`, `documents`).
- **Auto-Replace:** Option to automatically delete old attachments in a collection when uploading a new one (perfect for Avatars).

## Installation

The attachment manager requires the `intervention/image` package for image resizing.

```bash
composer require intervention/image
```

## Database Schema

The system uses three tables. The `tenant_id` column is dynamically named based on your `config('record.tenant_column')` setting.

1. `sp_document_folders`: For organizing files into folders.
2. `sp_attachments`: Stores the actual file metadata (path, size, mime_type).
3. `sp_attachment_links`: A pivot table linking attachments to your business records.

## API Usage

### 1. Basic File Upload

Upload a file without linking it to a specific record.

**Endpoint:** `POST /api/v1/attachments/upload`
**Content-Type:** `multipart/form-data`

| Parameter | Type | Description |
| :--- | :--- | :--- |
| `file` | File | **Required.** The file to upload (max 10MB). |
| `folder_id` | UUID | Optional. The ID of the folder to place the file in. |
| `title` | String | Optional. Title of the attachment (useful for SEO `alt` tags). |
| `caption` | String | Optional. Caption or description of the attachment. |
| `visibility` | String | Optional. `public` or `private` (default). |

### 2. Upload and Resize Image (Client-Dictated or Config-Driven)

Upload an image and have the server resize it *before* saving. This ensures only the optimized image is stored.

You can either pass explicit dimensions (`w`, `h`) OR pass a `size_name` that matches a predefined size in your `config/attachments.php`.

**Endpoint:** `POST /api/v1/attachments/upload`
**Content-Type:** `multipart/form-data`

| Parameter | Type | Description |
| :--- | :--- | :--- |
| `file` | File | **Required.** The image file. |
| `size_name` | String | Optional. A predefined size key from `config('attachments.image_sizes')` (e.g., `thumbnail`). |
| `w` | Integer | Optional. Target width in pixels (overridden by `size_name` if provided). |
| `h` | Integer | Optional. Target height in pixels (overridden by `size_name` if provided). |
| `fit` | String | Optional. `contain` (default) or `crop`. |

**Example Request (Explicit Dimensions):**
```http
POST /api/v1/attachments/upload
Content-Type: multipart/form-data

file: (binary)
w: 800
h: 600
fit: crop
```

**Example Request (Config-Driven Size):**
```http
POST /api/v1/attachments/upload
Content-Type: multipart/form-data

file: (binary)
size_name: thumbnail
```

### 3. Upload and Link to a Record

Upload a file and immediately link it to an existing record (e.g., attaching a receipt to an expense).

**Endpoint:** `POST /api/v1/attachments/upload`
**Content-Type:** `multipart/form-data`

| Parameter | Type | Description |
| :--- | :--- | :--- |
| `file` | File | **Required.** The file. |
| `record_id` | String | **Required for linking.** The ID of the target record. |
| `record_type` | String | **Required for linking.** The table name of the target record (e.g., `expenses`). |
| `collection_name`| String | Optional. Grouping name (e.g., `receipts`). Defaults to `default`. |

### 4. Upload Avatar (Replace Old)

When a user uploads a new profile picture, you usually want to delete the old one to save space. Use the `replace_old` flag.

**Endpoint:** `POST /api/v1/attachments/upload`
**Content-Type:** `multipart/form-data`

| Parameter | Type | Description |
| :--- | :--- | :--- |
| `file` | File | **Required.** The new avatar image. |
| `w` | Integer | `250` (Resize for avatar) |
| `h` | Integer | `250` (Resize for avatar) |
| `fit` | String | `crop` |
| `record_id` | String | `123` (User ID) |
| `record_type` | String | `users` |
| `collection_name`| String | `avatar` |
| `replace_old` | Boolean| `true` (Deletes the previous avatar attachment and file) |

### 5. Update Attachment Metadata

Update the title, caption, or folder of an existing attachment.

**Endpoint:** `PUT /api/v1/attachments/{id}`
**Content-Type:** `application/json`

| Parameter | Type | Description |
| :--- | :--- | :--- |
| `title` | String | Optional. New title. |
| `caption` | String | Optional. New caption. |
| `folder_id` | UUID | Optional. Move to a different folder. |

**Example Request:**
```http
PUT /api/v1/attachments/123e4567-e89b-12d3-a456-426614174000
Content-Type: application/json

{
  "title": "Updated Profile Picture",
  "caption": "Taken in 2024"
}
```

### 6. List Attachments

Retrieve a paginated list of attachments. The response will automatically include the correct `url` for each attachment based on its visibility.

**Endpoint:** `GET /api/v1/attachments`

| Parameter | Type | Description |
| :--- | :--- | :--- |
| `folder_id` | UUID | Optional. Filter by folder. |
| `visibility` | String | Optional. Filter by visibility. |
| `select` | String | Optional. Standard `sp-laravel-api` select syntax. |

**Example Request:**
```http
GET /api/v1/attachments?folder_id=eq.123e4567-e89b-12d3-a456-426614174000
```

### 7. Get Attachments for a Specific Record

Retrieve all attachments linked to a specific record (e.g., all attachments for a specific invoice). This endpoint automatically fetches the links and joins the actual attachment data, including the generated `url`.

**Endpoint:** `GET /api/v1/attachments/record/{record_type}/{record_id}`

| Parameter | Type | Description |
| :--- | :--- | :--- |
| `collection_name` | String | Optional. Filter by a specific collection (e.g., `avatar`, `receipts`). |

**Example Request:**
```http
GET /api/v1/attachments/record/invoices/123?collection_name=receipts
```

### 8. Link an Existing Attachment to a Record

If an attachment is already uploaded, you can link it to a record using this endpoint.

**Endpoint:** `POST /api/v1/attachments/record/{record_type}/{record_id}`
**Content-Type:** `application/json`

| Parameter | Type | Description |
| :--- | :--- | :--- |
| `attachment_id` | String | **Required.** The ID of the existing attachment. |
| `collection_name` | String | Optional. Grouping name (e.g., `receipts`). Defaults to `default`. |

**Example Request:**
```http
POST /api/v1/attachments/record/invoices/123
Content-Type: application/json

{
  "attachment_id": "123e4567-e89b-12d3-a456-426614174000",
  "collection_name": "receipts"
}
```

### 9. Unlink an Attachment from a Record

Remove the link between an attachment and a record. This does **not** delete the actual attachment file, only the link.

**Endpoint:** `DELETE /api/v1/attachments/record/{record_type}/{record_id}/{attachment_id}`

| Parameter | Type | Description |
| :--- | :--- | :--- |
| `collection_name` | String | Optional (Query Parameter). Filter by a specific collection. |

**Example Request:**
```http
DELETE /api/v1/attachments/record/invoices/123/123e4567-e89b-12d3-a456-426614174000
```

## Folder Management

You can organize attachments into folders. The folder endpoints support standard `sp-laravel-api` filtering and pagination.

### List Folders
**Endpoint:** `GET /api/v1/attachments/folders`

### Create Folder
**Endpoint:** `POST /api/v1/attachments/folders`
**Payload:**
```json
{
  "name": "Invoices 2024",
  "parent_id": null
}
```

### Update Folder
**Endpoint:** `PUT /api/v1/attachments/folders/{id}`
**Payload:**
```json
{
  "name": "Invoices 2024 (Archived)"
}
```

### Delete Folder
**Endpoint:** `DELETE /api/v1/attachments/folders/{id}`

## Visibility & Temporary Files

The attachment manager supports four visibility modes:

1. **`private` (Default):** The file is stored on the local disk. The generated `url` points to the `/download` endpoint, which requires authentication and tenant context to access.
2. **`public`:** The file is stored on the public disk. The generated `url` is a direct link to the file, accessible by anyone.
3. **`temp_private`:** Same as `private`, but the file is marked as temporary.
4. **`temp_public`:** Same as `public`, but the file is marked as temporary.

If you want stricter protection for `temp_public`, enable:

```php
'protect_temp_public_via_download' => true,
```

When enabled, `temp_public` URLs are generated as `/download` API URLs instead of direct disk URLs, so expiration checks are enforced before file access.

### Cleaning Up Temporary Files

Temporary files (`temp_private` and `temp_public`) are designed to be automatically deleted after a certain period (e.g., files uploaded during a multi-step form that were never finalized).

You can configure the lifetime in `config/attachments.php`:
```php
return [
    'temp_lifetime' => 1440, // Minutes (default: 24 hours)
    'default_temp_visibility' => 'temp_private',
    'max_temp_timeout_minutes' => 43200,
    'protect_temp_public_via_download' => false,
];
```

`sp_attachments.temp_timeout` is now supported for per-file expiration. The cleanup command deletes:

1. Temporary files where `temp_timeout <= now()`
2. Temporary files with `temp_timeout = null` and `created_at` older than `temp_lifetime` (fallback)

The `/download` endpoint also denies expired temp files immediately with HTTP `410 Gone`, even before cron cleanup runs.

### Temp Upload Options

When uploading (`POST /api/v1/attachments/upload`), you can set:

- `as_temp` (`boolean`): Force temp mode (`temp_private` / `temp_public`)
- `temp_timeout_minutes` (`int`): Relative timeout from now
- `temp_timeout_at` (`date`): Absolute expiration datetime

If temp visibility is used and timeout is not provided, `attachments.temp_lifetime` is applied automatically.

### Clone As Temp

Clone an existing attachment into a new temp attachment:

- **Endpoint:** `POST /api/v1/attachments/clone-temp`
- **Payload (example):**
```json
{
  "attachment_id": "123e4567-e89b-12d3-a456-426614174000",
  "as_temp": true,
  "temp_timeout_minutes": 60,
  "visibility": "temp_private"
}
```

To actually delete the expired temporary files, run the provided console command. You should schedule this command to run daily in your Laravel application's `Console/Kernel.php`:

```php
// In app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    $schedule->command('sp-laravel-api:clean-temp-attachments')->daily();
}
```

You can also run it manually:
```bash
php artisan sp-laravel-api:clean-temp-attachments
```
