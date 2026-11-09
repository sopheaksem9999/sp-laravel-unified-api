<?php

use Sopheak\Core\Enums\RecordColumnTypeEnum;
use Sopheak\Core\Enums\RecordFunctionMethodEnum;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Triggers\AttachmentTrigger;
use Sopheak\Core\Types\RecordColumnType;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Types\RecordFunctionType;
use Sopheak\Core\Enums\RecordSchemaFormatEnum;
use Sopheak\Core\Enums\RecordSchemaTypeEnum;
use Sopheak\Core\Types\RecordSchemaType;
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
                'id' => new RecordColumnType(type: RecordConfigService::idType() === 'uuid' ? RecordColumnTypeEnum::UUID : RecordColumnTypeEnum::BIGINT, nullable: false, primary: true, autoIncrement: RecordConfigService::idType() !== 'uuid'),
                'folder_id' => new RecordColumnType(type: RecordColumnTypeEnum::STRING),
                'title' => new RecordColumnType(type: RecordColumnTypeEnum::STRING),
                'caption' => new RecordColumnType(type: RecordColumnTypeEnum::STRING),
                'disk' => new RecordColumnType(type: RecordColumnTypeEnum::STRING, nullable: false),
                'path' => new RecordColumnType(type: RecordColumnTypeEnum::STRING, nullable: false),
                'filename' => new RecordColumnType(type: RecordColumnTypeEnum::STRING, nullable: false),
                'mime_type' => new RecordColumnType(type: RecordColumnTypeEnum::STRING, nullable: false),
                'size' => new RecordColumnType(type: RecordColumnTypeEnum::INTEGER, nullable: false),
                'visibility' => new RecordColumnType(type: RecordColumnTypeEnum::STRING, nullable: false),
                'temp_timeout' => new RecordColumnType(type: RecordColumnTypeEnum::DATETIME),
                // Created by $table->timestamps() in the migration. Declaring
                // them is what makes ?sortby=created_at and created_at=gte.…
                // work; they stay server-managed either way (overrideTimestamps
                // is false, so write payloads are stripped of them).
                'created_at' => new RecordColumnType(type: RecordColumnTypeEnum::DATETIME),
                'updated_at' => new RecordColumnType(type: RecordColumnTypeEnum::DATETIME),
            ],
            functions: [
                'upload' => new RecordFunctionType(
                    httpMethod: [RecordFunctionMethodEnum::POST->value],
                    class: AttachmentUploadController::class,
                    functionName: 'upload',
                    description: 'Upload a new attachment',
                    payloadSchema: RecordSchemaType::object(
                        properties: [
                            'file' => RecordSchemaType::string(format: RecordSchemaFormatEnum::BINARY, description: 'The file to upload'),
                            'visibility' => RecordSchemaType::string(
                                enum: ['private', 'public', 'temp_private', 'temp_public'],
                                description: 'Visibility level of the attachment',
                            ),
                            'as_temp' => RecordSchemaType::boolean(description: 'Mark as temporary file'),
                            'temp_timeout_minutes' => RecordSchemaType::integer(description: 'Minutes until temporary file expires'),
                            'temp_timeout_at' => RecordSchemaType::dateTime(description: 'Exact date-time when temporary file expires'),
                            'folder_id' => RecordSchemaType::string(description: 'Folder ID to store the attachment'),
                            'title' => RecordSchemaType::string(description: 'Title of the attachment'),
                            'caption' => RecordSchemaType::string(description: 'Caption for the attachment'),
                            'record_id' => RecordSchemaType::string(description: 'ID of the record to link'),
                            'record_type' => RecordSchemaType::string(description: 'Table name of the record to link'),
                            'collection_name' => RecordSchemaType::string(description: 'Collection name for the link'),
                            'replace_old' => RecordSchemaType::boolean(description: 'Replace existing attachment in collection'),
                        ],
                        required: ['file'],
                    ),
                    responseSchema: RecordSchemaType::object(
                        properties: [
                            'id' => RecordSchemaType::string(),
                            'url' => RecordSchemaType::string(),
                            'filename' => RecordSchemaType::string(),
                            'mime_type' => RecordSchemaType::string(),
                            'size' => RecordSchemaType::integer(),
                            'visibility' => RecordSchemaType::string(),
                        ],
                    )
                ),
                'clone-temp' => new RecordFunctionType(
                    httpMethod: [RecordFunctionMethodEnum::POST->value],
                    class: AttachmentUploadController::class,
                    functionName: 'cloneTemp',
                    description: 'Clone an existing attachment as temporary attachment',
                    payloadSchema: RecordSchemaType::object(
                        properties: [
                            'attachment_id' => RecordSchemaType::string(description: 'ID of the source attachment to clone'),
                            'visibility' => RecordSchemaType::string(
                                enum: ['temp_private', 'temp_public'],
                                description: 'Visibility level of the new attachment',
                            ),
                            'temp_timeout_minutes' => RecordSchemaType::integer(description: 'Minutes until temporary file expires'),
                            'temp_timeout_at' => RecordSchemaType::dateTime(description: 'Exact date-time when temporary file expires'),
                        ],
                        required: ['attachment_id'],
                    ),
                    responseSchema: RecordSchemaType::object(
                        properties: [
                            'id' => RecordSchemaType::string(),
                            'url' => RecordSchemaType::string(),
                            'filename' => RecordSchemaType::string(),
                            'mime_type' => RecordSchemaType::string(),
                            'size' => RecordSchemaType::integer(),
                            'visibility' => RecordSchemaType::string(),
                            'temp_timeout' => RecordSchemaType::dateTime(),
                        ],
                    )
                ),
                'create-upload-url' => new RecordFunctionType(
                    httpMethod: [RecordFunctionMethodEnum::POST->value],
                    class: AttachmentUploadController::class,
                    functionName: 'createUploadUrl',
                    description: 'Request a presigned upload URL for a direct browser upload',
                    payloadSchema: RecordSchemaType::object(
                        properties: [
                            'filename' => RecordSchemaType::string(),
                            'content_type' => RecordSchemaType::string(),
                            'visibility' => RecordSchemaType::string(),
                            'folder_id' => RecordSchemaType::string(),
                        ],
                        required: ['filename'],
                    )
                ),
                'complete-upload' => new RecordFunctionType(
                    httpMethod: [RecordFunctionMethodEnum::POST->value],
                    class: AttachmentUploadController::class,
                    functionName: 'completeUpload',
                    description: 'Persist an attachment after a direct upload',
                    payloadSchema: RecordSchemaType::object(
                        properties: [
                            'key' => RecordSchemaType::string(),
                            'upload_token' => RecordSchemaType::string(),
                            'expires_at' => RecordSchemaType::integer(),
                            'filename' => RecordSchemaType::string(),
                            'content_type' => RecordSchemaType::string(),
                            'visibility' => RecordSchemaType::string(),
                            'file' => RecordSchemaType::string(format: RecordSchemaFormatEnum::BINARY),
                        ],
                        required: ['key', 'upload_token', 'expires_at'],
                    )
                ),
                'create-multipart-upload' => new RecordFunctionType(
                    httpMethod: [RecordFunctionMethodEnum::POST->value],
                    class: AttachmentUploadController::class,
                    functionName: 'createMultipartUpload',
                    description: 'Start an S3 multipart upload',
                    disableCache: true,
                    payloadSchema: RecordSchemaType::object(
                        properties: [
                            'filename' => RecordSchemaType::string(),
                            'content_type' => RecordSchemaType::string(),
                            'visibility' => RecordSchemaType::string(),
                            'folder_id' => RecordSchemaType::string(),
                        ],
                        required: ['filename'],
                    )
                ),
                'sign-multipart-part' => new RecordFunctionType(
                    httpMethod: [RecordFunctionMethodEnum::POST->value],
                    class: AttachmentUploadController::class,
                    functionName: 'signMultipartPart',
                    description: 'Sign an S3 multipart part',
                    disableCache: true,
                    payloadSchema: RecordSchemaType::object(
                        properties: [
                            'key' => RecordSchemaType::string(),
                            'upload_id' => RecordSchemaType::string(),
                            'upload_token' => RecordSchemaType::string(),
                            'expires_at' => RecordSchemaType::integer(),
                            'part_number' => RecordSchemaType::integer(),
                            'visibility' => RecordSchemaType::string(),
                        ],
                        required: ['key', 'upload_id', 'upload_token', 'expires_at', 'part_number'],
                    )
                ),
                'complete-multipart-upload' => new RecordFunctionType(
                    httpMethod: [RecordFunctionMethodEnum::POST->value],
                    class: AttachmentUploadController::class,
                    functionName: 'completeMultipartUpload',
                    description: 'Complete an S3 multipart upload',
                    disableCache: true,
                    payloadSchema: RecordSchemaType::object(
                        properties: [
                            'key' => RecordSchemaType::string(),
                            'upload_id' => RecordSchemaType::string(),
                            'upload_token' => RecordSchemaType::string(),
                            'expires_at' => RecordSchemaType::integer(),
                            'parts' => RecordSchemaType::array(
                                items: RecordSchemaType::object(
                                    properties: [
                                        'part_number' => RecordSchemaType::integer(),
                                        'etag' => RecordSchemaType::string(),
                                    ],
                                ),
                            ),
                            'visibility' => RecordSchemaType::string(),
                            'folder_id' => RecordSchemaType::string(),
                            'filename' => RecordSchemaType::string(),
                        ],
                        required: ['key', 'upload_id', 'upload_token', 'expires_at', 'parts'],
                    )
                ),
                'abort-multipart-upload' => new RecordFunctionType(
                    httpMethod: [RecordFunctionMethodEnum::POST->value],
                    class: AttachmentUploadController::class,
                    functionName: 'abortMultipartUpload',
                    description: 'Abort an S3 multipart upload',
                    disableCache: true,
                    payloadSchema: RecordSchemaType::object(
                        properties: [
                            'key' => RecordSchemaType::string(),
                            'upload_id' => RecordSchemaType::string(),
                            'upload_token' => RecordSchemaType::string(),
                            'expires_at' => RecordSchemaType::integer(),
                            'visibility' => RecordSchemaType::string(),
                        ],
                        required: ['key', 'upload_id', 'upload_token', 'expires_at', 'visibility'],
                    )
                ),
                '{id}/download' => new RecordFunctionType(
                    httpMethod: [RecordFunctionMethodEnum::GET->value],
                    class: AttachmentUploadController::class,
                    functionName: 'download',
                    description: 'Download an attachment',
                    responseSchema: RecordSchemaType::string(format: RecordSchemaFormatEnum::BINARY)
                ),
                '{id}/preview' => new RecordFunctionType(
                    httpMethod: [RecordFunctionMethodEnum::GET->value],
                    class: AttachmentUploadController::class,
                    functionName: 'preview',
                    isPublic: true,
                    description: 'Serve an attachment inline using a signed preview URL',
                    responseSchema: RecordSchemaType::string(format: RecordSchemaFormatEnum::BINARY)
                ),
                '{id}/view' => new RecordFunctionType(
                    httpMethod: [RecordFunctionMethodEnum::GET->value],
                    class: AttachmentUploadController::class,
                    functionName: 'view',
                    description: 'View an attachment inline',
                    responseSchema: RecordSchemaType::string(format: RecordSchemaFormatEnum::BINARY)
                ),
                'folders' => new RecordFunctionType(
                    httpMethod: [RecordFunctionMethodEnum::GET->value, RecordFunctionMethodEnum::POST->value],
                    class: AttachmentUploadController::class,
                    functionName: 'folders',
                    description: 'List or create folders',
                    querySchema: RecordSchemaType::object(
                        properties: [
                            'parent_id' => RecordSchemaType::string(description: 'Parent folder ID'),
                        ],
                    ),
                    payloadSchema: RecordSchemaType::object(
                        properties: [
                            'name' => RecordSchemaType::string(description: 'Folder name (required for POST)'),
                            'parent_id' => RecordSchemaType::string(description: 'Parent folder ID'),
                            'scope' => RecordSchemaType::string(description: 'Resource scope such as internal or public'),
                            'visibility' => RecordSchemaType::string(
                                enum: ['private', 'public', 'temp_private', 'temp_public'],
                                description: 'Default visibility for resources organized under this folder',
                            ),
                            'owner_type' => RecordSchemaType::string(description: 'Optional owner type for application-specific folder ownership'),
                            'owner_id' => RecordSchemaType::string(description: 'Optional owner ID for application-specific folder ownership'),
                            'metadata' => RecordSchemaType::object(description: 'Optional folder metadata'),
                        ],
                        required: ['name'],
                    ),
                    responseSchema: RecordSchemaType::array(
                        items: RecordSchemaType::object(
                            properties: [
                                'id' => RecordSchemaType::string(),
                                'name' => RecordSchemaType::string(),
                                'parent_id' => RecordSchemaType::string(),
                                'scope' => RecordSchemaType::string(),
                                'visibility' => RecordSchemaType::string(),
                                'owner_type' => RecordSchemaType::string(),
                                'owner_id' => RecordSchemaType::string(),
                                'metadata' => RecordSchemaType::object(),
                            ],
                        ),
                    )
                ),
                'folders/{id}' => new RecordFunctionType(
                    httpMethod: [RecordFunctionMethodEnum::PUT->value, RecordFunctionMethodEnum::PATCH->value, RecordFunctionMethodEnum::DELETE->value],
                    class: AttachmentUploadController::class,
                    functionName: 'folderItem',
                    description: 'Update or delete folder',
                    payloadSchema: RecordSchemaType::object(
                        properties: [
                            'name' => RecordSchemaType::string(description: 'New folder name'),
                            'parent_id' => RecordSchemaType::string(description: 'New parent folder ID'),
                            'scope' => RecordSchemaType::string(description: 'Resource scope such as internal or public'),
                            'visibility' => RecordSchemaType::string(
                                enum: ['private', 'public', 'temp_private', 'temp_public'],
                                description: 'Default visibility for resources organized under this folder',
                            ),
                            'owner_type' => RecordSchemaType::string(description: 'Optional owner type for application-specific folder ownership'),
                            'owner_id' => RecordSchemaType::string(description: 'Optional owner ID for application-specific folder ownership'),
                            'metadata' => RecordSchemaType::object(description: 'Optional folder metadata'),
                        ],
                    )
                ),
                'record/{table}/{record_id}' => new RecordFunctionType(
                    httpMethod: [RecordFunctionMethodEnum::GET->value, RecordFunctionMethodEnum::POST->value],
                    class: AttachmentUploadController::class,
                    functionName: 'record',
                    description: 'Get or link attachments for a specific record',
                    querySchema: RecordSchemaType::object(
                        properties: [
                            'collection_name' => RecordSchemaType::string(description: 'Filter by collection name'),
                        ],
                    ),
                    payloadSchema: new RecordSchemaType(RecordSchemaTypeEnum::OBJECT, properties: [
                            'attachment_ids' => RecordSchemaType::array(
                                items: RecordSchemaType::string(),
                                description: 'Array of attachment IDs to link (idempotent per id); required for POST unless attachment_id is sent',
                            ),
                            'attachment_id' => RecordSchemaType::string(description: 'Single attachment ID to link (legacy form; use attachment_ids)'),
                            'collection_name' => RecordSchemaType::string(description: 'Collection name for the link'),
                        ], anyOf: [['required' => ['attachment_ids']], ['required' => ['attachment_id']]])
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
                'id' => new RecordColumnType(type: RecordConfigService::idType() === 'uuid' ? RecordColumnTypeEnum::UUID : RecordColumnTypeEnum::BIGINT, nullable: false, primary: true, autoIncrement: RecordConfigService::idType() !== 'uuid'),
                'name' => new RecordColumnType(type: RecordColumnTypeEnum::STRING, nullable: false),
                'parent_id' => new RecordColumnType(type: RecordColumnTypeEnum::STRING),
                'scope' => new RecordColumnType(type: RecordColumnTypeEnum::STRING, nullable: false),
                'visibility' => new RecordColumnType(type: RecordColumnTypeEnum::STRING, nullable: false),
                'owner_type' => new RecordColumnType(type: RecordColumnTypeEnum::STRING),
                'owner_id' => new RecordColumnType(type: RecordColumnTypeEnum::STRING),
                'metadata' => new RecordColumnType(type: RecordColumnTypeEnum::JSON),
                'created_at' => new RecordColumnType(type: RecordColumnTypeEnum::DATETIME),
                'updated_at' => new RecordColumnType(type: RecordColumnTypeEnum::DATETIME),
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
                'id' => new RecordColumnType(type: RecordColumnTypeEnum::INTEGER, nullable: false, primary: true, autoIncrement: true),
                'attachment_id' => new RecordColumnType(type: RecordColumnTypeEnum::STRING, nullable: false),
                'record_id' => new RecordColumnType(type: RecordColumnTypeEnum::STRING, nullable: false),
                'record_type' => new RecordColumnType(type: RecordColumnTypeEnum::STRING, nullable: false),
                'collection_name' => new RecordColumnType(type: RecordColumnTypeEnum::STRING),
                'created_at' => new RecordColumnType(type: RecordColumnTypeEnum::DATETIME),
                'updated_at' => new RecordColumnType(type: RecordColumnTypeEnum::DATETIME),
            ],
        ),
    ],
];
