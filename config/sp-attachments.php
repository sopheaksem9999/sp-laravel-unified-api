<?php

use Sopheak\Core\Enums\RecordFunctionMethodEnum;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Triggers\AttachmentTrigger;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Types\RecordFunctionType;
use Sopheak\Core\Http\Controllers\AttachmentUploadController;

$routePrefix = 'sp_attachments';

return [
    /*
    |--------------------------------------------------------------------------
    | Disks Configuration
    |--------------------------------------------------------------------------
    |
    | Define which Laravel filesystem disks should be used based on visibility.
    |
    | - disk_public: The disk used for 'public' and 'temp_public' attachments.
    | - disk_private: The disk used for 'private' and 'temp_private' attachments.
    |
    */
    'disk_public' => 'public',
    'disk_private' => 'local',

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
    | - url_strategy: auto keeps existing public URL behavior, api forces API view URLs,
    |   temporary uses driver temporaryUrl() when available, direct always asks the disk
    |   for direct public URLs.
    | - folder_delete_strategy: legacy keeps existing delete behavior, restrict blocks
    |   deletes when the folder still has child folders or attachments.
    | - access: Optional safety checks. Defaults preserve existing loose behavior.
    | - image_sizes: Define predefined image sizes that can be requested during upload.
    |   If a client requests a size that is not defined here, it will be rejected
    |   unless you allow arbitrary sizes.
    | - read_resizing: Opt-in image resizing on the {id}/view endpoint via query
    |   params (w, h, fit, format, size_name). Disabled by default so existing
    |   clients get byte-for-byte identical responses.
    | - read_resizing_min/max: Dimension bounds for read-time resizing requests.
    | - read_resizing_formats: Allowed output formats for read-time resizing.
    | - read_resize_cache: When enabled, derived images are written to disk on
    |   first request and served from disk afterwards.
    | - read_resize_cache_disk: Disk used for derived image caching.
    | - read_resize_cache_ttl_minutes: How long cached derived files remain valid.
    | - read_resize_cache_max_age: Cache-Control max-age in seconds for resized
    |   responses. 0 means no cache header is sent.
    |
    */
    'enabled' => true,
    'route_prefix' => $routePrefix,
    'max_upload_size' => 10240, // 10MB
    'temp_lifetime' => 1440,
    'default_temp_visibility' => 'temp_private',
    'max_temp_timeout_minutes' => 43200, // 30 days
    'protect_temp_public_via_download' => false,
    'url_strategy' => 'auto', // auto, api, temporary, direct
    'temporary_url_ttl_minutes' => 5,
    'folder_delete_strategy' => 'legacy', // legacy, restrict
    'access' => [
        'validate_folder_exists' => false,
        'validate_record_exists' => false,
        'fail_unknown_record_tables' => false,
        'record_authorizer' => null,
    ],
    'image_sizes' => [
        // Example:
        // 'thumbnail' => ['w' => 150, 'h' => 150, 'fit' => 'crop'],
        // 'medium' => ['w' => 800, 'h' => null, 'fit' => 'contain'],
    ],
    'read_resizing' => false,
    'read_resizing_min' => 32,
    'read_resizing_max' => 2000,
    'read_resizing_formats' => ['webp', 'jpg', 'jpeg', 'png', 'gif'],
    'read_resize_cache' => false,
    'read_resize_cache_disk' => 'public',
    'read_resize_cache_ttl_minutes' => 10080, // one week
    'read_resize_cache_max_age' => 0,

    'direct_upload' => [
        'enabled' => false,
        'presign_ttl_seconds' => 1800,
        'min_multipart_size_bytes' => 104857600,
        'storage_prefix' => 'attachments',
    ],
    'preview_url_enabled' => false,
    'preview_url_ttl_seconds' => 300,

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
            hasTenantId: true,
            softDeletes: false,
            canCreate: false,
            canUpdate: true,
            canDelete: true,
            canUpsert: true,
            isAuthRead: true,
            isAuthWrite: true,
            primaryKey: 'id',
            columns: [
                // Governed by record.id_type — see MigrationIdHelper::primary()
                // in 2024_01_01_000000_create_sp_attachments_tables.
                'id' => ['type' => RecordConfigService::idType() === 'uuid' ? 'uuid' : 'bigIncrements', 'nullable' => false],
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
                // Created by $table->timestamps() in the migration. Declaring
                // them is what makes ?sortby=created_at and created_at=gte.…
                // work; they stay server-managed either way (overrideTimestamps
                // is false, so write payloads are stripped of them).
                'created_at' => ['type' => 'datetime', 'nullable' => true],
                'updated_at' => ['type' => 'datetime', 'nullable' => true],
            ],
            functions: [
                'upload' => new RecordFunctionType(
                    httpMethod: [RecordFunctionMethodEnum::POST->value],
                    class: AttachmentUploadController::class,
                    functionName: 'upload',
                    description: 'Upload a new attachment',
                    payloadSchema: [
                        'type' => 'object',
                        'properties' => [
                            'file' => ['type' => 'string', 'format' => 'binary', 'description' => 'The file to upload'],
                            'visibility' => ['type' => 'string', 'enum' => ['private', 'public', 'temp_private', 'temp_public'], 'description' => 'Visibility level of the attachment'],
                            'as_temp' => ['type' => 'boolean', 'description' => 'Mark as temporary file'],
                            'temp_timeout_minutes' => ['type' => 'integer', 'description' => 'Minutes until temporary file expires'],
                            'temp_timeout_at' => ['type' => 'string', 'format' => 'date-time', 'description' => 'Exact date-time when temporary file expires'],
                            'folder_id' => ['type' => 'string', 'description' => 'Folder ID to store the attachment'],
                            'title' => ['type' => 'string', 'description' => 'Title of the attachment'],
                            'caption' => ['type' => 'string', 'description' => 'Caption for the attachment'],
                            'record_id' => ['type' => 'string', 'description' => 'ID of the record to link'],
                            'record_type' => ['type' => 'string', 'description' => 'Table name of the record to link'],
                            'collection_name' => ['type' => 'string', 'description' => 'Collection name for the link'],
                            'replace_old' => ['type' => 'boolean', 'description' => 'Replace existing attachment in collection'],
                        ],
                        'required' => ['file'],
                    ],
                    responseSchema: [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'url' => ['type' => 'string'],
                            'filename' => ['type' => 'string'],
                            'mime_type' => ['type' => 'string'],
                            'size' => ['type' => 'integer'],
                            'visibility' => ['type' => 'string'],
                        ],
                    ]
                ),
                'clone-temp' => new RecordFunctionType(
                    httpMethod: [RecordFunctionMethodEnum::POST->value],
                    class: AttachmentUploadController::class,
                    functionName: 'cloneTemp',
                    description: 'Clone an existing attachment as temporary attachment',
                    payloadSchema: [
                        'type' => 'object',
                        'properties' => [
                            'attachment_id' => ['type' => 'string', 'description' => 'ID of the source attachment to clone'],
                            'visibility' => ['type' => 'string', 'enum' => ['temp_private', 'temp_public'], 'description' => 'Visibility level of the new attachment'],
                            'temp_timeout_minutes' => ['type' => 'integer', 'description' => 'Minutes until temporary file expires'],
                            'temp_timeout_at' => ['type' => 'string', 'format' => 'date-time', 'description' => 'Exact date-time when temporary file expires'],
                        ],
                        'required' => ['attachment_id'],
                    ],
                    responseSchema: [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'url' => ['type' => 'string'],
                            'filename' => ['type' => 'string'],
                            'mime_type' => ['type' => 'string'],
                            'size' => ['type' => 'integer'],
                            'visibility' => ['type' => 'string'],
                            'temp_timeout' => ['type' => 'string', 'format' => 'date-time'],
                        ],
                    ]
                ),
                'create-upload-url' => new RecordFunctionType(
                    httpMethod: [RecordFunctionMethodEnum::POST->value],
                    class: AttachmentUploadController::class,
                    functionName: 'createUploadUrl',
                    description: 'Request a presigned upload URL for a direct browser upload',
                    payloadSchema: [
                        'type' => 'object',
                        'properties' => [
                            'filename' => ['type' => 'string'],
                            'content_type' => ['type' => 'string'],
                            'visibility' => ['type' => 'string'],
                            'folder_id' => ['type' => 'string'],
                        ],
                        'required' => ['filename'],
                    ]
                ),
                'complete-upload' => new RecordFunctionType(
                    httpMethod: [RecordFunctionMethodEnum::POST->value],
                    class: AttachmentUploadController::class,
                    functionName: 'completeUpload',
                    description: 'Persist an attachment after a direct upload',
                    payloadSchema: [
                        'type' => 'object',
                        'properties' => [
                            'key' => ['type' => 'string'],
                            'upload_token' => ['type' => 'string'],
                            'expires_at' => ['type' => 'integer'],
                            'filename' => ['type' => 'string'],
                            'content_type' => ['type' => 'string'],
                            'visibility' => ['type' => 'string'],
                            'file' => ['type' => 'string', 'format' => 'binary'],
                        ],
                        'required' => ['key', 'upload_token', 'expires_at'],
                    ]
                ),
                'create-multipart-upload' => new RecordFunctionType(
                    httpMethod: [RecordFunctionMethodEnum::POST->value],
                    class: AttachmentUploadController::class,
                    functionName: 'createMultipartUpload',
                    description: 'Start an S3 multipart upload',
                    disableCache: true,
                    payloadSchema: [
                        'type' => 'object',
                        'properties' => [
                            'filename' => ['type' => 'string'],
                            'content_type' => ['type' => 'string'],
                            'visibility' => ['type' => 'string'],
                            'folder_id' => ['type' => 'string'],
                        ],
                        'required' => ['filename'],
                    ]
                ),
                'sign-multipart-part' => new RecordFunctionType(
                    httpMethod: [RecordFunctionMethodEnum::POST->value],
                    class: AttachmentUploadController::class,
                    functionName: 'signMultipartPart',
                    description: 'Sign an S3 multipart part',
                    disableCache: true,
                    payloadSchema: [
                        'type' => 'object',
                        'properties' => [
                            'key' => ['type' => 'string'],
                            'upload_id' => ['type' => 'string'],
                            'upload_token' => ['type' => 'string'],
                            'expires_at' => ['type' => 'integer'],
                            'part_number' => ['type' => 'integer'],
                            'visibility' => ['type' => 'string'],
                        ],
                        'required' => ['key', 'upload_id', 'upload_token', 'expires_at', 'part_number'],
                    ]
                ),
                'complete-multipart-upload' => new RecordFunctionType(
                    httpMethod: [RecordFunctionMethodEnum::POST->value],
                    class: AttachmentUploadController::class,
                    functionName: 'completeMultipartUpload',
                    description: 'Complete an S3 multipart upload',
                    disableCache: true,
                    payloadSchema: [
                        'type' => 'object',
                        'properties' => [
                            'key' => ['type' => 'string'],
                            'upload_id' => ['type' => 'string'],
                            'upload_token' => ['type' => 'string'],
                            'expires_at' => ['type' => 'integer'],
                            'parts' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'part_number' => ['type' => 'integer'],
                                        'etag' => ['type' => 'string'],
                                    ],
                                ],
                            ],
                            'visibility' => ['type' => 'string'],
                            'folder_id' => ['type' => 'string'],
                            'filename' => ['type' => 'string'],
                        ],
                        'required' => ['key', 'upload_id', 'upload_token', 'expires_at', 'parts'],
                    ]
                ),
                'abort-multipart-upload' => new RecordFunctionType(
                    httpMethod: [RecordFunctionMethodEnum::POST->value],
                    class: AttachmentUploadController::class,
                    functionName: 'abortMultipartUpload',
                    description: 'Abort an S3 multipart upload',
                    disableCache: true,
                    payloadSchema: [
                        'type' => 'object',
                        'properties' => [
                            'key' => ['type' => 'string'],
                            'upload_id' => ['type' => 'string'],
                            'upload_token' => ['type' => 'string'],
                            'expires_at' => ['type' => 'integer'],
                            'visibility' => ['type' => 'string'],
                        ],
                        'required' => ['key', 'upload_id', 'upload_token', 'expires_at', 'visibility'],
                    ]
                ),
                '{id}/download' => new RecordFunctionType(
                    httpMethod: [RecordFunctionMethodEnum::GET->value],
                    class: AttachmentUploadController::class,
                    functionName: 'download',
                    description: 'Download an attachment',
                    responseSchema: [
                        'type' => 'string',
                        'format' => 'binary',
                    ]
                ),
                '{id}/preview' => new RecordFunctionType(
                    httpMethod: [RecordFunctionMethodEnum::GET->value],
                    class: AttachmentUploadController::class,
                    functionName: 'preview',
                    isPublic: true,
                    description: 'Serve an attachment inline using a signed preview URL',
                    responseSchema: [
                        'type' => 'string',
                        'format' => 'binary',
                    ]
                ),
                '{id}/view' => new RecordFunctionType(
                    httpMethod: [RecordFunctionMethodEnum::GET->value],
                    class: AttachmentUploadController::class,
                    functionName: 'view',
                    description: 'View an attachment inline',
                    responseSchema: [
                        'type' => 'string',
                        'format' => 'binary',
                    ]
                ),
                'folders' => new RecordFunctionType(
                    httpMethod: [RecordFunctionMethodEnum::GET->value, RecordFunctionMethodEnum::POST->value],
                    class: AttachmentUploadController::class,
                    functionName: 'folders',
                    description: 'List or create folders',
                    querySchema: [
                        'type' => 'object',
                        'properties' => [
                            'parent_id' => ['type' => 'string', 'description' => 'Parent folder ID'],
                        ],
                    ],
                    payloadSchema: [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string', 'description' => 'Folder name (required for POST)'],
                            'parent_id' => ['type' => 'string', 'description' => 'Parent folder ID'],
                            'scope' => ['type' => 'string', 'description' => 'Resource scope such as internal or public'],
                            'visibility' => ['type' => 'string', 'enum' => ['private', 'public', 'temp_private', 'temp_public'], 'description' => 'Default visibility for resources organized under this folder'],
                            'owner_type' => ['type' => 'string', 'description' => 'Optional owner type for application-specific folder ownership'],
                            'owner_id' => ['type' => 'string', 'description' => 'Optional owner ID for application-specific folder ownership'],
                            'metadata' => ['type' => 'object', 'description' => 'Optional folder metadata'],
                        ],
                        'required' => ['name'],
                    ],
                    responseSchema: [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'string'],
                                'name' => ['type' => 'string'],
                                'parent_id' => ['type' => 'string'],
                                'scope' => ['type' => 'string'],
                                'visibility' => ['type' => 'string'],
                                'owner_type' => ['type' => 'string'],
                                'owner_id' => ['type' => 'string'],
                                'metadata' => ['type' => 'object'],
                            ],
                        ],
                    ]
                ),
                'folders/{id}' => new RecordFunctionType(
                    httpMethod: [RecordFunctionMethodEnum::PUT->value, RecordFunctionMethodEnum::PATCH->value, RecordFunctionMethodEnum::DELETE->value],
                    class: AttachmentUploadController::class,
                    functionName: 'folderItem',
                    description: 'Update or delete folder',
                    payloadSchema: [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string', 'description' => 'New folder name'],
                            'parent_id' => ['type' => 'string', 'description' => 'New parent folder ID'],
                            'scope' => ['type' => 'string', 'description' => 'Resource scope such as internal or public'],
                            'visibility' => ['type' => 'string', 'enum' => ['private', 'public', 'temp_private', 'temp_public'], 'description' => 'Default visibility for resources organized under this folder'],
                            'owner_type' => ['type' => 'string', 'description' => 'Optional owner type for application-specific folder ownership'],
                            'owner_id' => ['type' => 'string', 'description' => 'Optional owner ID for application-specific folder ownership'],
                            'metadata' => ['type' => 'object', 'description' => 'Optional folder metadata'],
                        ],
                    ]
                ),
                'record/{table}/{record_id}' => new RecordFunctionType(
                    httpMethod: [RecordFunctionMethodEnum::GET->value, RecordFunctionMethodEnum::POST->value],
                    class: AttachmentUploadController::class,
                    functionName: 'record',
                    description: 'Get or link attachments for a specific record',
                    querySchema: [
                        'type' => 'object',
                        'properties' => [
                            'collection_name' => ['type' => 'string', 'description' => 'Filter by collection name'],
                        ],
                    ],
                    payloadSchema: [
                        'type' => 'object',
                        'properties' => [
                            'attachment_ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Array of attachment IDs to link (required for POST)'],
                            'collection_name' => ['type' => 'string', 'description' => 'Collection name for the link'],
                        ],
                        'required' => ['attachment_ids'],
                    ]
                ),
                'record/{table}/{record_id}/{attachment_id}' => new RecordFunctionType(
                    httpMethod: [RecordFunctionMethodEnum::DELETE->value],
                    class: AttachmentUploadController::class,
                    functionName: 'unlinkFromRecord',
                    description: 'Unlink an attachment from a record'
                ),
            ],
            triggers: [
                AttachmentTrigger::class,
            ]
        ),
        // Canonical key as of the sp_document_folders -> sp_attachment_folders rename.
        'sp_attachment_folders' => new RecordTableType(
            table: 'sp_attachment_folders',
            pmsName: 'attachment',
            hasTenantId: true,
            softDeletes: false,
            isAuthRead: true,
            isAuthWrite: true,
            primaryKey: 'id',
            disableCache: true,
            columns: [
                // Governed by record.id_type — see MigrationIdHelper::primary()
                // in 2024_01_01_000000_create_sp_attachments_tables. The table
                // is created as sp_document_folders and renamed by
                // 2026_08_02_000000_rename_sp_document_folders_table.
                'id' => ['type' => RecordConfigService::idType() === 'uuid' ? 'uuid' : 'bigIncrements', 'nullable' => false],
                'name' => ['type' => 'string', 'nullable' => false],
                'parent_id' => ['type' => 'string', 'nullable' => true],
                'scope' => ['type' => 'string', 'nullable' => false],
                'visibility' => ['type' => 'string', 'nullable' => false],
                'owner_type' => ['type' => 'string', 'nullable' => true],
                'owner_id' => ['type' => 'string', 'nullable' => true],
                'metadata' => ['type' => 'json', 'nullable' => true],
                'created_at' => ['type' => 'datetime', 'nullable' => true],
                'updated_at' => ['type' => 'datetime', 'nullable' => true],
            ],
        ),
        'sp_attachment_links' => new RecordTableType(
            table: 'sp_attachment_links',
            pmsName: 'attachment',
            hasTenantId: true,
            softDeletes: false,
            isAuthRead: true,
            isAuthWrite: true,
            primaryKey: 'id',
            columns: [
                'id' => ['type' => 'integer', 'nullable' => false],
                'attachment_id' => ['type' => 'string', 'nullable' => false],
                'record_id' => ['type' => 'string', 'nullable' => false],
                'record_type' => ['type' => 'string', 'nullable' => false],
                'collection_name' => ['type' => 'string', 'nullable' => true],
                'created_at' => ['type' => 'datetime', 'nullable' => true],
                'updated_at' => ['type' => 'datetime', 'nullable' => true],
            ],
        ),
    ],
];
