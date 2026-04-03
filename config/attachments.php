<?php

use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Types\RecordFunctionType;
use Sopheak\Core\Http\Controllers\AttachmentUploadController;

$routePrefix = (string) env('SP_LARAVEL_API_ATTACHMENTS_ROUTE_PREFIX', 'attachments');

return [
    /*
    |--------------------------------------------------------------------------
    | Attachment Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the attachment and file manager settings.
    |
    | - enabled: Enable or disable the attachment module (default: true)
    | - route_prefix: The prefix for attachment routes (default: 'attachments')
    | - max_upload_size: Maximum file upload size in kilobytes (default: 10240 = 10MB)
    | - temp_lifetime: The number of minutes before temp_private and temp_public 
    |   attachments are automatically deleted by the cleanup command (default: 1440 = 24h).
    | - default_temp_visibility: Default temp visibility when using as_temp=true.
    | - max_temp_timeout_minutes: Maximum allowed custom temp timeout minutes.
    | - protect_temp_public_via_download: Force temp_public URLs to use API download endpoint
    |   so timeout checks are enforced before file access.
    | - image_sizes: Define predefined image sizes that can be requested during upload.
    |   If a client requests a size that is not defined here, it will be rejected
    |   unless you allow arbitrary sizes.
    |
    */
    'enabled' => env('SP_LARAVEL_API_ATTACHMENTS_ENABLED', true),
    'route_prefix' => $routePrefix,
    'max_upload_size' => 10240, // 10MB
    'temp_lifetime' => 1440,
    'default_temp_visibility' => 'temp_private',
    'max_temp_timeout_minutes' => 43200, // 30 days
    'protect_temp_public_via_download' => false,
    'image_sizes' => [
        // Example:
        // 'thumbnail' => ['w' => 150, 'h' => 150, 'fit' => 'crop'],
        // 'medium' => ['w' => 800, 'h' => null, 'fit' => 'contain'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Attachment Tables Configuration
    |--------------------------------------------------------------------------
    |
    | This file defines the default table configurations for the attachment system.
    | These configurations are automatically merged into the main record.tables
    | configuration by the CoreSpLaravelApiProvider.
    |
    */
    'tables' => [
        'sp_attachments' => new RecordTableType(
            table: 'sp_attachments',
            pmsName: array_values(array_unique(array_filter([$routePrefix, 'attachment']))),
            primaryKey: 'id',
            softDeletes: false,
            hasTenantId: true,
            isAuthRead: true,
            isAuthWrite: true,
            columns: [
                'id' => ['type' => 'string', 'nullable' => false],
                'folder_id' => ['type' => 'string', 'nullable' => true],
                'title' => ['type' => 'string', 'nullable' => true],
                'caption' => ['type' => 'string', 'nullable' => true],
                'disk' => ['type' => 'string', 'nullable' => false],
                'path' => ['type' => 'string', 'nullable' => false],
                'filename' => ['type' => 'string', 'nullable' => false],
                'mime_type' => ['type' => 'string', 'nullable' => false],
                'size' => ['type' => 'integer', 'nullable' => false],
                'visibility' => ['type' => 'string', 'nullable' => false],
                'temp_timeout' => ['type' => 'datetime', 'nullable' => true],
            ],
            triggers: [
                \Sopheak\Core\Triggers\AttachmentTrigger::class,
            ],
            functions: [
                'upload' => new RecordFunctionType(
                    class: AttachmentUploadController::class,
                    functionName: 'upload',
                    httpMethod: ['POST'],
                    description: 'Upload a new attachment'
                ),
                'clone-temp' => new RecordFunctionType(
                    class: AttachmentUploadController::class,
                    functionName: 'cloneTemp',
                    httpMethod: ['POST'],
                    description: 'Clone an existing attachment as temporary attachment'
                ),
                '{id}/download' => new RecordFunctionType(
                    class: AttachmentUploadController::class,
                    functionName: 'download',
                    httpMethod: ['GET'],
                    description: 'Download an attachment'
                ),
                'folders' => new RecordFunctionType(
                    class: AttachmentUploadController::class,
                    functionName: 'folders',
                    httpMethod: ['GET', 'POST'],
                    description: 'List or create folders'
                ),
                'folders/{id}' => new RecordFunctionType(
                    class: AttachmentUploadController::class,
                    functionName: 'folderItem',
                    httpMethod: ['PUT', 'PATCH', 'DELETE'],
                    description: 'Update or delete folder'
                ),
                'record/{table}/{record_id}' => new RecordFunctionType(
                    class: AttachmentUploadController::class,
                    functionName: 'record',
                    httpMethod: ['GET', 'POST'],
                    description: 'Get or link attachments for a specific record'
                ),
                'record/{table}/{record_id}/{attachment_id}' => new RecordFunctionType(
                    class: AttachmentUploadController::class,
                    functionName: 'unlinkFromRecord',
                    httpMethod: ['DELETE'],
                    description: 'Unlink an attachment from a record'
                ),
            ]
        ),
        'sp_document_folders' => new RecordTableType(
            table: 'sp_document_folders',
            pmsName: 'attachment',
            primaryKey: 'id',
            softDeletes: false,
            hasTenantId: true,
            isAuthRead: true,
            isAuthWrite: true,
            columns: [
                'id' => ['type' => 'string', 'nullable' => false],
                'name' => ['type' => 'string', 'nullable' => false],
                'parent_id' => ['type' => 'string', 'nullable' => true],
            ],
        ),
        'sp_attachment_links' => new RecordTableType(
            table: 'sp_attachment_links',
            pmsName: 'attachment',
            primaryKey: 'id',
            softDeletes: false,
            hasTenantId: true,
            isAuthRead: true,
            isAuthWrite: true,
            columns: [
                'id' => ['type' => 'integer', 'nullable' => false],
                'attachment_id' => ['type' => 'string', 'nullable' => false],
                'record_id' => ['type' => 'string', 'nullable' => false],
                'record_type' => ['type' => 'string', 'nullable' => false],
                'collection_name' => ['type' => 'string', 'nullable' => true],
            ],
        ),
    ],
];
