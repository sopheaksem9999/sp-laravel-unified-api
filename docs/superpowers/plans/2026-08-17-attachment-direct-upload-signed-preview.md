# Attachment Direct Upload + Signed Preview Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the attachment module a driver-agnostic "direct upload" family (browser presigned PUT + S3 multipart) plus a HMAC-signed preview URL so Bearer-token clients can render private images/videos in `<img>`/`<video>` tags, with zero behaviour change for existing clients when the features are off.

**Architecture:** Everything builds on the existing Laravel filesystem abstraction, not on hardcoded S3. A small `AttachmentStorageService` owns disk resolution + storage-path generation (extracted verbatim from the controller so nothing drifts). A `AttachmentPresignService` exposes `providesTemporaryUploadUrls()` / `temporaryUploadUrl()` / `mimeType()` / `size()` from the disk. Multipart (the only piece that genuinely needs the raw S3 client) is isolated behind a narrow `AttachmentMultipartDriver` interface with one `S3MultipartDriver` implementation. Private preview is a self-contained HMAC-signed `/preview` endpoint. Feature gating strips the new functions from the table registry when disabled, so routes, OpenAPI, and exporters never see them.

**Tech Stack:** PHP 8.2–8.5, Laravel 12/13 (`FilesystemAdapter`, `AwsS3V3Adapter`, `Storage` facade), PHPUnit 11 + Orchestra Testbench, SQLite `:memory:`, Mockery (for the presign branch), optional `aws/aws-sdk-php` (dev-only, for the multipart driver).

---

## Client-Approved Contract

- `disk_public` maps to `karunafilm-public` and `disk_private` maps to `karunafilm-private`. Bucket selection is based only on visibility; images and videos may use either bucket.
- Multipart starts at `100 MiB` by default and remains configurable.
- Single-upload completion requires the upload key. Multipart completion requires the upload key, `upload_id`, and uploaded parts with ETags.
- Client metadata may include filename, title, folder ID, and visibility. Object metadata is authoritative for content type, size, and ETag after upload.
- ETag is used internally to validate multipart completion only; it is not persisted or returned because `sp_attachments` has no ETag column.
- Private preview URLs expire after 5 minutes by default, are configurable, and work in browser media tags without Bearer headers.
- Direct upload is globally configurable and disabled by default. Existing server-side upload remains available for local/public disks and drivers without presign support.
- Completion preserves the existing attachment response shape, including the persisted `sp_attachments` row and resolved attachment URL.
- Before R2 integration testing, provision `karunafilm-public` and `karunafilm-private` and apply the documented CORS policies to both buckets.

## Global Constraints

- **Backward compatibility is the top priority.** When `attachments.direct_upload.enabled = false` and `attachments.preview_url_enabled = false`, the module is byte-for-byte unchanged: no new URL fields, no new routes/functions, no changed storage paths.
- R2 **is** the `s3` driver. Cloudflare R2 is S3-compatible; the only R2-specific config is `region => 'auto'`, `endpoint => https://<acctid>.r2.cloudflarestorage.com`, and `use_path_style_endpoint => true`. Do **not** write a custom "R2" driver.
- `resolveDiskFromVisibility()` and storage-path generation are **extracted**, not rewritten — output strings must stay identical (`attachments/public/YYYY/MM/DD/{uuid}.{ext}` and `attachments/private/...`).
- Use **named arguments** for `RecordTableType` and `RecordFunctionType` constructors.
- DB-agnostic; no DB-specific SQL. The direct-upload code is filesystem-only.
- Add tests for every core behaviour change (project rule).
- Run `composer quality` (format-check → analyse → test) before the final commit of each task. PHPStan is strict (larastan v3); use `--memory-limit=1G`.
- Do **not** edit anything in `sp-laravel-api-docs/` (auto-generated). Editable docs live in `docs/` and `package/docs/`.
- Rector does the real formatting. Do not hand-format; run `composer format` if `format-check` fails.
- Source report: `docs/bug-reports/sp-laravel-api-feature-direct-upload-s3-r2.md` is *not* required reading — every fact needed is inlined below, including the corrections to its outdated premise.

## Scope

Four independently-shippable parts:

- **Part A (foundation):** config keys + `AttachmentStorageService` extraction (no behaviour change).
- **Part B (direct upload):** `create-upload-url` + `complete-upload` (presigned PUT for S3/R2, server-side multipart fallback for local/public), plus gating.
- **Part C (signed preview):** `preview_url` + `{id}/preview` endpoint + gating.
- **Part D (multipart):** S3 multipart lifecycle behind `AttachmentMultipartDriver` (requires `aws/aws-sdk-php` as a dev dependency).
- **Part E (docs):** bucket/disk configuration guide + bug-report rewrite.

Parts A, B, and C are fully testable in this repository as-is. Part D adds `aws/aws-sdk-php` to `require-dev` so PHPStan and multipart tests can resolve `Aws\S3\S3ClientInterface`; it is committed scope, not optional implementation work.

## File Structure

| File | Responsibility | Change |
| --- | --- | --- |
| `config/sp-attachments.php` | Attachment module config + `sp_attachments` table functions | Modified: Parts A, B, C, D |
| `src/Services/AttachmentStorageService.php` | **New.** Disk resolution + storage-path generation | Created: Part A |
| `src/Services/AttachmentPresignService.php` | **New.** Presign/HEAD primitives over `FilesystemAdapter` | Created: Part B |
| `src/Services/AttachmentPreviewUrlService.php` | **New.** HMAC sign/verify for `preview_url` | Created: Part C |
| `src/Contracts/Attachment/AttachmentMultipartDriver.php` | **New.** Multipart capability interface | Created: Part D |
| `src/Services/AttachmentMultipart/S3MultipartDriver.php` | **New.** S3Client-backed multipart driver | Created: Part D |
| `src/Http/Controllers/AttachmentUploadController.php` | Direct-upload + preview endpoints; delegates to services | Modified: Parts A, B, C, D |
| `src/Services/AttachmentUrlService.php` | Emit `preview_url` | Modified: Part C |
| `src/Services/RecordConfigService.php` | Strip gated functions from the table registry | Modified: Parts B, C |
| `composer.json` | `suggest`/`require-dev` for `aws/aws-sdk-php` | Modified: Part D |
| `tests/Feature/AttachmentStorageServiceTest.php` | **New.** Extraction regression tests | Created: Part A |
| `tests/Feature/AttachmentDirectUploadTest.php` | **New.** Direct-upload behaviour tests | Created: Part B |
| `tests/Feature/AttachmentDirectUploadGatingTest.php` | **New.** Function-strip gating tests | Created: Part B |
| `tests/Feature/AttachmentPreviewUrlTest.php` | **New.** Signed preview tests | Created: Part C |
| `tests/Feature/AttachmentMultipartTest.php` | **New.** Multipart driver tests (skip without aws-sdk) | Created: Part D |
| `docs/guide/features/feature-attachments-visibility-access.md` | Bucket/disk configuration docs | Modified: Part E |
| `docs/bug-reports/sp-laravel-api-feature-direct-upload-s3-r2.md` | Rewrite outdated premise | Modified: Part E |

---

## Part A — Foundation: config keys + storage service extraction

### Task 1: Add config keys (no behaviour yet)

**Files:**
- Modify: `config/sp-attachments.php` (after the `'read_resize_cache_max_age' => 0,` line, before the "Attachment Tables Configuration" comment block)

- [ ] **Step 1: Add the two config blocks**

Insert immediately after `'read_resize_cache_max_age' => 0,` in `config/sp-attachments.php`:

```php
    'direct_upload' => [
        'enabled' => false,
        'multipart' => true,
        'presign_ttl_seconds' => 1800,
        'min_multipart_size_bytes' => 104857600, // 100 MB
        'storage_prefix' => 'attachments',
    ],
    'preview_url_enabled' => false,
    'preview_url_ttl_seconds' => 300,
```

- [ ] **Step 2: Verify syntax**

Run: `php -l config/sp-attachments.php`
Expected: `No syntax errors detected in config/sp-attachments.php`

- [ ] **Step 3: Run the existing attachment suite**

Run: `vendor/bin/phpunit --filter Attachment`
Expected: all pass (nothing reads the new keys yet).

- [ ] **Step 4: Commit**

```bash
git add config/sp-attachments.php
git commit -m "feat(attachments): add direct_upload and preview_url config scaffolding"
```

### Task 2: Create `AttachmentStorageService` (pure extraction)

**Files:**
- Create: `src/Services/AttachmentStorageService.php`
- Test: `tests/Feature/AttachmentStorageServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AttachmentStorageServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Support\Facades\Config;
use Sopheak\Core\Services\AttachmentStorageService;
use Sopheak\Core\Tests\TestCase;

class AttachmentStorageServiceTest extends TestCase
{
    /** @test */
    public function it_resolves_disks_from_visibility(): void
    {
        Config::set('attachments.disk_public', 'r2-public');
        Config::set('attachments.disk_private', 'r2-private');

        $service = app(AttachmentStorageService::class);

        $this->assertSame('r2-public', $service->resolveDiskFromVisibility('public'));
        $this->assertSame('r2-public', $service->resolveDiskFromVisibility('temp_public'));
        $this->assertSame('r2-private', $service->resolveDiskFromVisibility('private'));
        $this->assertSame('r2-private', $service->resolveDiskFromVisibility('temp_private'));
    }

    /** @test */
    public function it_generates_paths_with_the_same_shape_as_before(): void
    {
        Config::set('attachments.direct_upload.storage_prefix', 'attachments');

        $service = app(AttachmentStorageService::class);

        $public = $service->generateStoragePath('public', 'jpg');
        $private = $service->generateStoragePath('private', 'mp4');

        $this->assertMatchesRegularExpression('#^attachments/public/\d{4}/\d{2}/\d{2}/[0-9a-f\-]{36}\.jpg$#', $public);
        $this->assertMatchesRegularExpression('#^attachments/private/\d{4}/\d{2}/\d{2}/[0-9a-f\-]{36}\.mp4$#', $private);
    }

    /** @test */
    public function it_honours_a_custom_storage_prefix(): void
    {
        Config::set('attachments.direct_upload.storage_prefix', 'media/vault');

        $service = app(AttachmentStorageService::class);

        $this->assertStringStartsWith('media/vault/private/', $service->generateStoragePath('private', 'png'));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit --filter AttachmentStorageServiceTest`
Expected: FAIL — `Class "Sopheak\Core\Services\AttachmentStorageService" not found`.

- [ ] **Step 3: Implement the service**

Create `src/Services/AttachmentStorageService.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Services;

use Illuminate\Support\Str;

class AttachmentStorageService
{
    /**
     * Map a visibility to the filesystem disk that should store it.
     */
    public function resolveDiskFromVisibility(string $visibility): string
    {
        return in_array($visibility, ['public', 'temp_public'], true)
            ? (string) config('attachments.disk_public', 'public')
            : (string) config('attachments.disk_private', 'local');
    }

    /**
     * The base directory prefix for all attachment storage keys.
     */
    public function storagePrefix(): string
    {
        $prefix = trim((string) config('attachments.direct_upload.storage_prefix', 'attachments'));

        return '' === $prefix ? 'attachments' : trim($prefix, '/');
    }

    /**
     * Generate a storage key for a new attachment. Output is identical to the
     * controller's former generateAttachmentStoragePath() when the prefix is
     * the default 'attachments'.
     */
    public function generateStoragePath(string $visibility, ?string $extension = null): string
    {
        $filename = Str::uuid()->toString();
        if (is_string($extension) && '' !== trim($extension)) {
            $filename .= '.' . ltrim($extension, '.');
        }

        $baseDir = in_array($visibility, ['public', 'temp_public'], true)
            ? $this->storagePrefix() . '/public'
            : $this->storagePrefix() . '/private';

        return $baseDir . '/' . date('Y/m/d') . '/' . $filename;
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit --filter AttachmentStorageServiceTest`
Expected: OK (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Services/AttachmentStorageService.php tests/Feature/AttachmentStorageServiceTest.php
git commit -m "feat(attachments): add AttachmentStorageService for disk + path resolution"
```

### Task 3: Point the controller at the new service (no drift)

**Files:**
- Modify: `src/Http/Controllers/AttachmentUploadController.php`

- [ ] **Step 1: Add the service accessor and delegate the two private methods**

In `AttachmentUploadController.php`, add `AttachmentStorageService` to the imports:

```php
use Sopheak\Core\Services\AttachmentStorageService;
```

Add a private accessor next to the other `*Service()` helpers (after `accessService()` at ~line 133):

```php
    private function storageService(): AttachmentStorageService
    {
        return app(AttachmentStorageService::class);
    }
```

Replace the bodies of the two private methods so they delegate:

`resolveDiskFromVisibility` (was ~line 238):

```php
    private function resolveDiskFromVisibility(string $visibility): string
    {
        return $this->storageService()->resolveDiskFromVisibility($visibility);
    }
```

`generateAttachmentStoragePath` (was ~line 300):

```php
    private function generateAttachmentStoragePath(string $visibility, ?string $extension = null): string
    {
        return $this->storageService()->generateStoragePath($visibility, $extension);
    }
```

- [ ] **Step 2: Verify the existing suite still passes**

Run: `vendor/bin/phpunit --filter Attachment`
Expected: all pass — this proves the extraction is behaviour-preserving.

- [ ] **Step 3: Commit**

```bash
git add src/Http/Controllers/AttachmentUploadController.php
git commit -m "refactor(attachments): delegate disk + path resolution to AttachmentStorageService"
```

---

## Part B — Direct upload (presigned PUT + complete-upload) + gating

### Task 4: Create `AttachmentPresignService`

**Files:**
- Create: `src/Services/AttachmentPresignService.php`

- [ ] **Step 1: Implement the service**

Create `src/Services/AttachmentPresignService.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Services;

use Illuminate\Support\Facades\Storage;

class AttachmentPresignService
{
    /**
     * Whether the given disk can produce presigned upload URLs
     * (S3/R2 yes; local/public no unless signed routes are wired up).
     */
    public function supportsPresignedUploads(string $disk): bool
    {
        return Storage::disk($disk)->providesTemporaryUploadUrls();
    }

    /**
     * Produce a presigned PUT URL for the given key.
     *
     * @return array{url: string, headers: array<string, string>}
     */
    public function presignedUploadUrl(string $disk, string $path, ?string $contentType, \DateTimeInterface $expiresAt): array
    {
        $options = null !== $contentType && '' !== $contentType ? ['ContentType' => $contentType] : [];

        return Storage::disk($disk)->temporaryUploadUrl($path, $expiresAt, $options);
    }

    public function exists(string $disk, string $path): bool
    {
        return Storage::disk($disk)->exists($path);
    }

    /**
     * Read back object metadata after a direct upload.
     *
     * @return array{content_type: string, content_length: int}
     */
    public function headMetadata(string $disk, string $path): array
    {
        $adapter = Storage::disk($disk);
        $mime = $adapter->mimeType($path);

        return [
            'content_type' => false === $mime ? 'application/octet-stream' : (string) $mime,
            'content_length' => (int) $adapter->size($path),
        ];
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Services/AttachmentPresignService.php
git commit -m "feat(attachments): add AttachmentPresignService over FilesystemAdapter"
```

### Task 5: Add the `create-upload-url` and `complete-upload` controller methods

**Files:**
- Modify: `src/Http/Controllers/AttachmentUploadController.php`

- [ ] **Step 1: Add the service accessor and feature flag helper**

Add to imports:

```php
use Sopheak\Core\Services\AttachmentPresignService;
```

Add private helpers after `storageService()`:

```php
    private function presignService(): AttachmentPresignService
    {
        return app(AttachmentPresignService::class);
    }

    private function directUploadEnabled(): bool
    {
        return (bool) config('attachments.direct_upload.enabled', false);
    }
```

- [ ] **Step 2: Add `createUploadUrl` method**

Add after `cloneTemp()` (before `view()`), in the same class:

```php
    public function createUploadUrl(Request $request): JsonResponse
    {
        if (!$this->directUploadEnabled()) {
            return RecordApiResponseService::errorWrapped('Direct upload is disabled', Response::HTTP_NOT_FOUND);
        }

        $request->validate([
            'filename' => 'required|string|max:255',
            'content_type' => 'nullable|string|max:255',
            'visibility' => 'nullable|in:private,public,temp_private,temp_public',
            'folder_id' => 'nullable|string',
        ]);

        $tenantId = $this->resolveTenantId($request);
        if (!$this->accessService()->folderExists($request->input('folder_id'), $tenantId)) {
            return RecordApiResponseService::errorWrapped('Folder not found', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $visibility = $this->resolveVisibility($request, 'private');
        $disk = $this->resolveDiskFromVisibility($visibility);

        $filename = (string) $request->input('filename');
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $path = $this->generateAttachmentStoragePath($visibility, '' === $extension ? null : $extension);

        $contentType = $request->input('content_type');
        $expiresAt = Carbon::now()->addSeconds(max(1, (int) config('attachments.direct_upload.presign_ttl_seconds', 1800)));

        if ($this->presignService()->supportsPresignedUploads($disk)) {
            $presigned = $this->presignService()->presignedUploadUrl($disk, $path, $contentType, $expiresAt);

            return RecordApiResponseService::success([
                'key' => $path,
                'upload_url' => $presigned['url'],
                'headers' => $presigned['headers'] ?? [],
                'method' => 'PUT',
                'disk' => $disk,
                'expires_at' => $expiresAt->toISOString(),
            ]);
        }

        return RecordApiResponseService::success([
            'key' => $path,
            'upload_url' => null,
            'method' => 'POST',
            'disk' => $disk,
            'complete_endpoint' => url(RecordConfigService::apiPrefix() . '/' . (string) config('attachments.route_prefix', 'attachments') . '/complete-upload'),
        ]);
    }
```

- [ ] **Step 3: Add `completeUpload` method**

Add immediately after `createUploadUrl()`:

```php
    public function completeUpload(Request $request): JsonResponse
    {
        if (!$this->directUploadEnabled()) {
            return RecordApiResponseService::errorWrapped('Direct upload is disabled', Response::HTTP_NOT_FOUND);
        }

        $maxSize = config('attachments.max_upload_size', 10240);
        $maxTempTimeoutMinutes = (int) config('attachments.max_temp_timeout_minutes', 43200);

        $this->normalizeBooleanInputs($request, ['replace_old', 'as_temp']);

        $request->validate([
            'key' => 'required|string',
            'filename' => 'nullable|string|max:255',
            'content_type' => 'nullable|string|max:255',
            'visibility' => 'nullable|in:private,public,temp_private,temp_public',
            'file' => 'nullable|file|max:' . $maxSize,
            'folder_id' => 'nullable|string',
            'title' => 'nullable|string|max:255',
            'caption' => 'nullable|string',
            'record_id' => 'nullable|string',
            'record_type' => 'nullable|string',
            'collection_name' => 'nullable|string',
            'replace_old' => 'nullable|boolean',
            'as_temp' => 'nullable|boolean',
            'temp_timeout_minutes' => 'nullable|integer|min:1|max:' . $maxTempTimeoutMinutes,
            'temp_timeout_at' => 'nullable|date',
        ]);

        if ($this->tempTimeoutAtExceedsMaximum($request)) {
            return RecordApiResponseService::errorWrapped('Temporary timeout exceeds maximum allowed minutes', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $tenantId = $this->resolveTenantId($request);
        if (!$this->accessService()->folderExists($request->input('folder_id'), $tenantId)) {
            return RecordApiResponseService::errorWrapped('Folder not found', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $recordLinkError = $this->validateRequestedRecordLink($request, $tenantId);
        if ($recordLinkError instanceof JsonResponse) {
            return $recordLinkError;
        }

        $visibility = $this->resolveVisibility($request, 'private');
        $disk = $this->resolveDiskFromVisibility($visibility);
        $path = ltrim((string) $request->input('key'), '/');

        $file = $request->file('file');
        if (null !== $file) {
            // local/public fallback: the client posts the file server-side.
            Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path));
            $mimeType = (string) $file->getMimeType();
            $size = (int) $file->getSize();
        } else {
            if (!$this->presignService()->exists($disk, $path)) {
                return response()->json(['message' => 'File not found on disk'], 404);
            }

            $metadata = $this->presignService()->headMetadata($disk, $path);
            $mimeType = (string) $request->input('content_type', $metadata['content_type']);
            $size = (int) $metadata['content_length'];
        }

        $tempTimeout = $this->resolveTempTimeout($request, $visibility);
        $tenantColumn = RecordConfigService::tenantColumn();

        $attachmentPayload = [
            'folder_id' => $request->input('folder_id'),
            'title' => $request->input('title'),
            'caption' => $request->input('caption'),
            'disk' => $disk,
            'path' => $path,
            'filename' => basename((string) $request->input('filename', basename($path))),
            'mime_type' => $mimeType,
            'size' => $size,
            'visibility' => $visibility,
            'temp_timeout' => $tempTimeout,
        ];

        if (RecordConfigService::enableTenantId() && null !== $tenantId && '' !== $tenantId) {
            $attachmentPayload[$tenantColumn] = $tenantId;
        }

        $attachment = RecordService::executeCreate('sp_attachments', $attachmentPayload, [], $tenantId);
        $attachment = $this->extractRecordPayload($attachment);
        $attachment = $this->appendUrlToAttachment($attachment);

        try {
            $this->linkAttachmentIfRequested($request, (string) $attachment['id'], $tenantId);
        } catch (Exception $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->getCode() > 0 ? $exception->getCode() : 404);
        }

        return RecordApiResponseService::success($attachment);
    }
```

- [ ] **Step 4: Verify syntax + existing suite**

Run: `php -l src/Http/Controllers/AttachmentUploadController.php && vendor/bin/phpunit --filter Attachment`
Expected: no syntax errors; existing attachment tests still pass (new methods are unreachable without function registration).

- [ ] **Step 5: Commit**

```bash
git add src/Http/Controllers/AttachmentUploadController.php
git commit -m "feat(attachments): add create-upload-url and complete-upload endpoints"
```

### Task 6: Register the functions in config

**Files:**
- Modify: `config/sp-attachments.php` (inside `sp_attachments` `functions` array, after the `clone-temp` entry)

- [ ] **Step 1: Add `create-upload-url` and `complete-upload` function entries**

Insert after the `clone-temp` `RecordFunctionType` entry (before `'{id}/download'`):

```php
                'create-upload-url' => new RecordFunctionType(
                    httpMethod: [RecordFunctionMethodEnum::POST->value],
                    class: AttachmentUploadController::class,
                    functionName: 'createUploadUrl',
                    description: 'Request a presigned upload URL for a direct browser upload',
                    payloadSchema: [
                        'type' => 'object',
                        'properties' => [
                            'filename' => ['type' => 'string', 'description' => 'The original file name'],
                            'content_type' => ['type' => 'string', 'description' => 'MIME type of the file'],
                            'visibility' => ['type' => 'string', 'enum' => ['private', 'public', 'temp_private', 'temp_public']],
                            'folder_id' => ['type' => 'string'],
                        ],
                        'required' => ['filename'],
                    ]
                ),
                'complete-upload' => new RecordFunctionType(
                    httpMethod: [RecordFunctionMethodEnum::POST->value],
                    class: AttachmentUploadController::class,
                    functionName: 'completeUpload',
                    description: 'Persist an attachment after a direct upload (or post the file server-side for non-presign disks)',
                    payloadSchema: [
                        'type' => 'object',
                        'properties' => [
                            'key' => ['type' => 'string', 'description' => 'The storage key returned by create-upload-url'],
                            'filename' => ['type' => 'string'],
                            'content_type' => ['type' => 'string'],
                            'visibility' => ['type' => 'string', 'enum' => ['private', 'public', 'temp_private', 'temp_public']],
                            'file' => ['type' => 'string', 'format' => 'binary', 'description' => 'Required when the disk has no presign support'],
                            'folder_id' => ['type' => 'string'],
                            'title' => ['type' => 'string'],
                            'caption' => ['type' => 'string'],
                        ],
                        'required' => ['key'],
                    ]
                ),
```

- [ ] **Step 2: Verify syntax**

Run: `php -l config/sp-attachments.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add config/sp-attachments.php
git commit -m "feat(attachments): register create-upload-url and complete-upload functions"
```

### Task 7: Gate the functions — strip when disabled

**Files:**
- Modify: `src/Services/RecordConfigService.php`

- [ ] **Step 1: Add the gating constant and helper**

Add class constants near the top of `RecordConfigService` (before the first public method):

```php
    private const DIRECT_UPLOAD_FUNCTION_KEYS = [
        'create-upload-url',
        'complete-upload',
        'create-multipart-upload',
        'sign-multipart-part',
        'complete-multipart-upload',
        'abort-multipart-upload',
    ];

    private const PREVIEW_FUNCTION_KEY = '{id}/preview';
```

- [ ] **Step 2: Apply gating in `getTableConfig()`**

In `getTableConfig()` (currently ~line 319), change the return section from:

```php
        $tables = array_merge($webhookTables, $attachmentTables, $auditTables, $permissionTables, $recordTables);

        if ($table !== null) {
            return $tables[$table] ?? null;
        }

        return $tables;
```

to:

```php
        $tables = array_merge($webhookTables, $attachmentTables, $auditTables, $permissionTables, $recordTables);

        $tables = self::applyAttachmentFeatureGating($tables);

        if ($table !== null) {
            return $tables[$table] ?? null;
        }

        return $tables;
```

Add the private helper after `getTableConfig()` / `table()` (after the `table(string $table): mixed` method at ~line 331):

```php
    /**
     * Strip direct-upload and preview functions from the sp_attachments table
     * config when their respective features are disabled, so they never appear
     * in routes, OpenAPI, or the schema registry.
     *
     * @param array<string, mixed> $tables
     * @return array<string, mixed>
     */
    private static function applyAttachmentFeatureGating(array $tables): array
    {
        $attachment = $tables['sp_attachments'] ?? null;
        if (!$attachment instanceof RecordTableType) {
            return $tables;
        }

        $remove = [];
        if (!(bool) config('attachments.direct_upload.enabled', false)) {
            $remove = array_merge($remove, self::DIRECT_UPLOAD_FUNCTION_KEYS);
        }
        if (!(bool) config('attachments.preview_url_enabled', false)) {
            $remove[] = self::PREVIEW_FUNCTION_KEY;
        }

        if ([] === $remove) {
            return $tables;
        }

        // Clone so we never mutate the shared config object — toggling the flag
        // in a test must be able to re-enable the functions on a fresh read.
        $clone = clone $attachment;
        $clone->functions = array_diff_key($attachment->functions ?? [], array_flip($remove));
        $tables['sp_attachments'] = $clone;

        return $tables;
    }
```

Ensure `RecordTableType` is imported in `RecordConfigService.php` (it already is — used throughout).

- [ ] **Step 3: Verify syntax + full attachment suite**

Run: `php -l src/Services/RecordConfigService.php && vendor/bin/phpunit --filter Attachment`
Expected: no syntax errors; existing tests pass (default is disabled, so stripping happens but nothing depends on the new functions yet).

- [ ] **Step 4: Commit**

```bash
git add src/Services/RecordConfigService.php
git commit -m "feat(attachments): strip direct-upload and preview functions when disabled"
```

### Task 8: Write direct-upload behaviour tests

**Files:**
- Test: `tests/Feature/AttachmentDirectUploadTest.php` (new)

- [ ] **Step 1: Write the tests**

Create `tests/Feature/AttachmentDirectUploadTest.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Sopheak\Core\Http\Controllers\AttachmentUploadController;
use Sopheak\Core\Tests\TestCase;

class AttachmentDirectUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('attachments.direct_upload.enabled', true);
        Config::set('attachments.disk_private', 'local');
        Config::set('attachments.disk_public', 'public');
        Config::set('record.enable_tenant_id', false);

        $this->createAttachmentTables();
    }

    private function createAttachmentTables(): void
    {
        DB::statement('CREATE TABLE IF NOT EXISTS sp_attachments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            folder_id VARCHAR(255) NULL,
            title VARCHAR(255) NULL,
            caption TEXT NULL,
            disk VARCHAR(255) NOT NULL,
            path VARCHAR(255) NOT NULL,
            filename VARCHAR(255) NOT NULL,
            mime_type VARCHAR(255) NOT NULL,
            size INTEGER NOT NULL,
            visibility VARCHAR(255) NOT NULL,
            temp_timeout DATETIME NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        )');
    }

    private function controller(): AttachmentUploadController
    {
        return app(AttachmentUploadController::class);
    }

    /** @test */
    public function create_upload_url_falls_back_to_post_on_a_local_disk(): void
    {
        Storage::fake('local');

        $response = $this->controller()->createUploadUrl(
            Request::create('/', 'POST', ['filename' => 'poster.jpg', 'visibility' => 'private'])
        );

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertSame('POST', $data['method']);
        $this->assertNull($data['upload_url']);
        $this->assertSame('local', $data['disk']);
        $this->assertStringStartsWith('attachments/private/', $data['key']);
    }

    /** @test */
    public function complete_upload_persists_a_multipart_file_on_a_local_disk(): void
    {
        Storage::fake('local');

        $key = 'attachments/private/2026/08/17/poster.jpg';

        $response = $this->controller()->completeUpload(
            Request::create('/', 'POST', ['key' => $key, 'visibility' => 'private'], files: [
                'file' => UploadedFile::fake()->image('poster.jpg'),
            ])
        );

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertSame($key, $data['path']);
        $this->assertSame('local', $data['disk']);
        $this->assertSame('private', $data['visibility']);

        Storage::disk('local')->assertExists($key);

        $this->assertDatabaseHas('sp_attachments', [
            'path' => $key,
            'disk' => 'local',
            'visibility' => 'private',
        ]);
    }

    /** @test */
    public function complete_upload_rejects_a_missing_object_when_no_file_is_posted(): void
    {
        Storage::fake('local');

        $response = $this->controller()->completeUpload(
            Request::create('/', 'POST', ['key' => 'attachments/private/2026/08/17/missing.jpg'])
        );

        $response->assertStatus(404);
    }

    /** @test */
    public function create_upload_url_presigns_put_on_a_presign_capable_disk(): void
    {
        Config::set('attachments.disk_private', 'r2-private');

        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('providesTemporaryUploadUrls')->once()->andReturn(true);
        $disk->shouldReceive('temporaryUploadUrl')
            ->once()
            ->withArgs(static function (string $path, \DateTimeInterface $expiration, array $options): bool {
                return str_starts_with($path, 'attachments/private/')
                    && isset($options['ContentType']) && 'image/jpeg' === $options['ContentType'];
            })
            ->andReturn(['url' => 'https://r2.example/signed-put', 'headers' => ['Content-Type' => 'image/jpeg']]);

        Storage::shouldReceive('disk')->with('r2-private')->andReturn($disk);

        $response = $this->controller()->createUploadUrl(
            Request::create('/', 'POST', ['filename' => 'photo.jpg', 'content_type' => 'image/jpeg', 'visibility' => 'private'])
        );

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertSame('PUT', $data['method']);
        $this->assertSame('https://r2.example/signed-put', $data['upload_url']);
        $this->assertSame('r2-private', $data['disk']);
    }
}
```

Note: the controller's `resolveDiskFromVisibility` calls `Storage::disk($disk)` only indirectly through `AttachmentPresignService`; for the presign test the mock is registered via `Storage::shouldReceive('disk')`, which swaps the `Storage` facade — the same facade `AttachmentPresignService` uses.

- [ ] **Step 2: Run the tests**

Run: `vendor/bin/phpunit --filter AttachmentDirectUploadTest`
Expected: OK (4 tests). If the presign test errors on `Mockery` closure argument matching, adjust `withArgs` to positional `Mockery::on(...)` matchers.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/AttachmentDirectUploadTest.php
git commit -m "test(attachments): cover direct-upload presign and local fallback"
```

### Task 9: Write gating tests

**Files:**
- Test: `tests/Feature/AttachmentDirectUploadGatingTest.php` (new)

- [ ] **Step 1: Write the tests**

Create `tests/Feature/AttachmentDirectUploadGatingTest.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Support\Facades\Config;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class AttachmentDirectUploadGatingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $attachmentConfig = require __DIR__ . '/../../config/sp-attachments.php';
        Config::set('attachments.tables', $attachmentConfig['tables'] ?? []);
        Config::set('attachments.direct_upload.enabled', false);
        Config::set('attachments.preview_url_enabled', false);
        SchemaRegistryUtils::refresh();
    }

    /** @test */
    public function direct_upload_functions_are_absent_when_disabled(): void
    {
        $table = RecordConfigService::getTableConfig('sp_attachments');
        $this->assertInstanceOf(RecordTableType::class, $table);

        $functions = array_keys($table->functions ?? []);

        $this->assertNotContains('create-upload-url', $functions);
        $this->assertNotContains('complete-upload', $functions);
        $this->assertNotContains('create-multipart-upload', $functions);
    }

    /** @test */
    public function direct_upload_functions_are_present_when_enabled(): void
    {
        Config::set('attachments.direct_upload.enabled', true);
        SchemaRegistryUtils::refresh();

        $table = RecordConfigService::getTableConfig('sp_attachments');
        $this->assertInstanceOf(RecordTableType::class, $table);

        $functions = array_keys($table->functions ?? []);

        $this->assertContains('create-upload-url', $functions);
        $this->assertContains('complete-upload', $functions);
    }

    /** @test */
    public function toggling_back_off_removes_the_functions_again(): void
    {
        Config::set('attachments.direct_upload.enabled', true);
        SchemaRegistryUtils::refresh();

        Config::set('attachments.direct_upload.enabled', false);
        SchemaRegistryUtils::refresh();

        $table = RecordConfigService::getTableConfig('sp_attachments');
        $functions = array_keys($table->functions ?? []);

        $this->assertNotContains('create-upload-url', $functions);
    }
}
```

- [ ] **Step 2: Run the tests**

Run: `vendor/bin/phpunit --filter AttachmentDirectUploadGatingTest`
Expected: OK (3 tests). This verifies the clone-based strip is reversible (no shared-object mutation).

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/AttachmentDirectUploadGatingTest.php
git commit -m "test(attachments): cover direct-upload function gating"
```

### Task 10: Quality gate for Parts A + B

- [ ] **Step 1: Run the full quality pipeline**

Run: `composer quality`
Expected: format-check, phpstan, and phpunit all pass.

- [ ] **Step 2: Fix any Rector findings only for your new files**

Run: `composer format` if `format-check` reported findings in the new files, then re-run `composer quality`.

- [ ] **Step 3: Commit any formatting fixes**

```bash
git add -A
git commit -m "chore(attachments): apply rector formatting for direct-upload"
```

---

## Part C — Signed preview URL for private attachments

### Task 11: Create `AttachmentPreviewUrlService`

**Files:**
- Create: `src/Services/AttachmentPreviewUrlService.php`

- [ ] **Step 1: Implement the service**

Create `src/Services/AttachmentPreviewUrlService.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Services;

use Illuminate\Support\Carbon;

class AttachmentPreviewUrlService
{
    /**
     * Build a short-lived, self-contained preview URL that a browser can open
     * without sending an Authorization header.
     *
     * @param array<string, mixed> $attachment
     */
    public function signedUrl(array $attachment): string
    {
        $id = (string) ($attachment['id'] ?? '');
        $path = (string) ($attachment['path'] ?? '');
        $tenantId = (string) ($attachment[RecordConfigService::tenantColumn()] ?? '');
        $expires = Carbon::now()->getTimestamp() + (int) config('attachments.preview_url_ttl_seconds', 300);

        $signature = $this->signature($id, $path, $tenantId, $expires);

        $prefix = RecordConfigService::apiPrefix();
        $routePrefix = (string) config('attachments.route_prefix', 'attachments');

        return url($prefix . '/' . $routePrefix . '/' . $id . '/preview')
            . '?tenant=' . rawurlencode($tenantId)
            . '&expires=' . $expires
            . '&signature=' . $signature;
    }

    public function verify(string $id, string $path, string $tenantId, int $expires, string $signature): bool
    {
        if ($expires < Carbon::now()->getTimestamp()) {
            return false;
        }

        return hash_equals($this->signature($id, $path, $tenantId, $expires), $signature);
    }

    private function signature(string $id, string $path, string $tenantId, int $expires): string
    {
        return hash_hmac('sha256', implode('|', [$id, $path, $tenantId, (string) $expires]), (string) config('app.key'));
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Services/AttachmentPreviewUrlService.php
git commit -m "feat(attachments): add AttachmentPreviewUrlService"
```

### Task 12: Emit `preview_url` from `AttachmentUrlService`

**Files:**
- Modify: `src/Services/AttachmentUrlService.php`

- [ ] **Step 1: Add the preview accessor and resolver**

Add to `AttachmentUrlService` a private accessor and a resolver. First add the accessor after `temporaryUrl()`:

```php
    private function previewUrlService(): AttachmentPreviewUrlService
    {
        return app(AttachmentPreviewUrlService::class);
    }

    /**
     * @param array<string, mixed> $attachment
     */
    private function resolvePreviewUrl(array $attachment, string $baseApiUrl): ?string
    {
        if (!(bool) config('attachments.preview_url_enabled', false)) {
            return null;
        }

        $visibility = (string) ($attachment['visibility'] ?? 'private');

        if ('public' === $visibility) {
            return null;
        }

        if ('temp_public' === $visibility && !(bool) config('attachments.protect_temp_public_via_download', false)) {
            return null;
        }

        return $this->previewUrlService()->signedUrl($attachment);
    }
```

- [ ] **Step 2: Wire it into `appendUrls()`**

Change `appendUrls()` from:

```php
    public function appendUrls(array $attachment): array
    {
        $baseApiUrl = $this->baseApiUrl((string) ($attachment['id'] ?? ''));

        $attachment['download_url'] = $baseApiUrl . '/download';
        $attachment['url'] = $this->resolveUrl($attachment, $baseApiUrl);

        return $attachment;
    }
```

to:

```php
    public function appendUrls(array $attachment): array
    {
        $baseApiUrl = $this->baseApiUrl((string) ($attachment['id'] ?? ''));

        $attachment['download_url'] = $baseApiUrl . '/download';
        $attachment['url'] = $this->resolveUrl($attachment, $baseApiUrl);

        $previewUrl = $this->resolvePreviewUrl($attachment, $baseApiUrl);
        if (null !== $previewUrl) {
            $attachment['preview_url'] = $previewUrl;
        }

        return $attachment;
    }
```

- [ ] **Step 3: Verify existing tests pass (no `preview_url` when disabled)**

Run: `vendor/bin/phpunit --filter "AttachmentAfterReadAddsUrlsTest|AttachmentUrlEmbedTest|AttachmentArchitectureSafetyTest"`
Expected: all pass — `preview_url_enabled` defaults to false, so no new key appears.

- [ ] **Step 4: Commit**

```bash
git add src/Services/AttachmentUrlService.php
git commit -m "feat(attachments): emit preview_url for private attachments when enabled"
```

### Task 13: Add the `preview` controller method and function

**Files:**
- Modify: `src/Http/Controllers/AttachmentUploadController.php`
- Modify: `config/sp-attachments.php`

- [ ] **Step 1: Add the `preview` method**

Add to `AttachmentUploadController`, after `download()` (before `resolveReadResize()`):

```php
    public function preview(Request $request, string $id): StreamedResponse|JsonResponse|Response
    {
        $tenantId = $request->query('tenant', '');
        $expires = (int) $request->query('expires', 0);
        $signature = (string) $request->query('signature', '');

        try {
            $attachment = RecordService::executeGetById('sp_attachments', $id, [], '' !== $tenantId ? $tenantId : null);
            $attachment = $this->extractRecordPayload($attachment);
        } catch (Exception) {
            return response()->json(['message' => 'Attachment not found'], 404);
        }

        if (empty($attachment)) {
            return response()->json(['message' => 'Attachment not found'], 404);
        }

        if (!$this->previewUrlService()->verify($id, (string) ($attachment['path'] ?? ''), (string) $tenantId, $expires, $signature)) {
            return RecordApiResponseService::errorWrapped('Invalid or expired preview URL', Response::HTTP_GONE);
        }

        if ('' !== $tenantId) {
            $request->attributes->set('resolved_tenant_id', $tenantId);
        }

        return $this->serveFile($request, $id, true);
    }
```

Add the accessor next to the other `*Service()` helpers:

```php
    private function previewUrlService(): AttachmentPreviewUrlService
    {
        return app(AttachmentPreviewUrlService::class);
    }
```

Add the import:

```php
use Sopheak\Core\Services\AttachmentPreviewUrlService;
```

- [ ] **Step 2: Register the `{id}/preview` function**

In `config/sp-attachments.php`, insert after the `'{id}/download'` entry:

```php
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
```

- [ ] **Step 3: Verify syntax**

Run: `php -l src/Http/Controllers/AttachmentUploadController.php && php -l config/sp-attachments.php`
Expected: no syntax errors.

- [ ] **Step 4: Commit**

```bash
git add src/Http/Controllers/AttachmentUploadController.php config/sp-attachments.php
git commit -m "feat(attachments): add signed {id}/preview endpoint"
```

### Task 14: Write signed-preview tests

**Files:**
- Test: `tests/Feature/AttachmentPreviewUrlTest.php` (new)

- [ ] **Step 1: Write the tests**

Create `tests/Feature/AttachmentPreviewUrlTest.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Sopheak\Core\Http\Controllers\AttachmentUploadController;
use Sopheak\Core\Services\AttachmentPreviewUrlService;
use Sopheak\Core\Services\AttachmentUrlService;
use Sopheak\Core\Tests\TestCase;

class AttachmentPreviewUrlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('attachments.preview_url_enabled', true);
        Config::set('attachments.preview_url_ttl_seconds', 300);
        Config::set('attachments.disk_private', 'local');
        Config::set('record.enable_tenant_id', false);

        DB::statement('CREATE TABLE IF NOT EXISTS sp_attachments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            folder_id VARCHAR(255) NULL,
            title VARCHAR(255) NULL,
            caption TEXT NULL,
            disk VARCHAR(255) NOT NULL,
            path VARCHAR(255) NOT NULL,
            filename VARCHAR(255) NOT NULL,
            mime_type VARCHAR(255) NOT NULL,
            size INTEGER NOT NULL,
            visibility VARCHAR(255) NOT NULL,
            temp_timeout DATETIME NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        )');
    }

    private function seedPrivateAttachment(): array
    {
        Storage::fake('local');
        Storage::disk('local')->put('attachments/private/secret.jpg', 'fake-image-bytes');

        DB::table('sp_attachments')->insert([
            'disk' => 'local',
            'path' => 'attachments/private/secret.jpg',
            'filename' => 'secret.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 16,
            'visibility' => 'private',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('sp_attachments')->first();
    }

    /** @test */
    public function append_urls_emits_a_signed_preview_url_for_private_when_enabled(): void
    {
        $attachment = $this->seedPrivateAttachment();
        $urls = app(AttachmentUrlService::class)->appendUrls((array) $attachment);

        $this->assertArrayHasKey('preview_url', $urls);
        $this->assertStringContainsString('/preview?tenant=', $urls['preview_url']);
        $this->assertStringContainsString('&expires=', $urls['preview_url']);
        $this->assertStringContainsString('&signature=', $urls['preview_url']);
    }

    /** @test */
    public function append_urls_omits_preview_url_when_disabled(): void
    {
        Config::set('attachments.preview_url_enabled', false);

        $attachment = $this->seedPrivateAttachment();
        $urls = app(AttachmentUrlService::class)->appendUrls((array) $attachment);

        $this->assertArrayNotHasKey('preview_url', $urls);
    }

    /** @test */
    public function preview_endpoint_streams_with_a_valid_signature(): void
    {
        $attachment = $this->seedPrivateAttachment();
        $signedUrl = app(AttachmentPreviewUrlService::class)->signedUrl((array) $attachment);

        $query = [];
        parse_str((string) parse_url($signedUrl, PHP_URL_QUERY), $query);

        $response = app(AttachmentUploadController::class)->preview(
            Request::create('/preview', 'GET', $query),
            (string) $attachment->id
        );

        $response->assertStatus(200);
    }

    /** @test */
    public function preview_endpoint_rejects_a_tampered_signature(): void
    {
        $attachment = $this->seedPrivateAttachment();
        $signedUrl = app(AttachmentPreviewUrlService::class)->signedUrl((array) $attachment);

        $query = [];
        parse_str((string) parse_url($signedUrl, PHP_URL_QUERY), $query);
        $query['signature'] = str_repeat('0', 64);

        $response = app(AttachmentUploadController::class)->preview(
            Request::create('/preview', 'GET', $query),
            (string) $attachment->id
        );

        $response->assertStatus(410);
    }

    /** @test */
    public function preview_endpoint_rejects_an_expired_signature(): void
    {
        $attachment = $this->seedPrivateAttachment();
        $expired = now()->subMinute()->getTimestamp();
        $signature = hash_hmac('sha256', implode('|', [$attachment->id, $attachment->path, '', (string) $expired]), (string) config('app.key'));

        $response = app(AttachmentUploadController::class)->preview(
            Request::create('/preview', 'GET', ['tenant' => '', 'expires' => $expired, 'signature' => $signature]),
            (string) $attachment->id
        );

        $response->assertStatus(410);
    }
}
```

- [ ] **Step 2: Run the tests**

Run: `vendor/bin/phpunit --filter AttachmentPreviewUrlTest`
Expected: OK (5 tests).

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/AttachmentPreviewUrlTest.php
git commit -m "test(attachments): cover signed preview URL behaviour"
```

### Task 15: Quality gate for Part C

- [ ] **Step 1: Run the full quality pipeline**

Run: `composer quality`
Expected: format-check, phpstan, and phpunit all pass. Fix any Rector findings in new files via `composer format`, then re-run.

- [ ] **Step 2: Commit any formatting fixes**

```bash
git add -A
git commit -m "chore(attachments): apply rector formatting for preview"
```

---

## Part D — S3 multipart (large video)

### Task 16: Add `aws/aws-sdk-php` as a dev + suggested dependency

**Files:**
- Modify: `composer.json`

- [ ] **Step 1: Add the dev requirement and suggestion**

Run:

```bash
composer require --dev aws/aws-sdk-php:^3.0
```

- [ ] **Step 2: Add the `suggest` entry**

In `composer.json`, add a `"suggest"` block (or extend the existing one):

```json
    "suggest": {
        "aws/aws-sdk-php": "Required for S3/R2 multipart direct uploads (create-multipart-upload, sign-multipart-part, complete-multipart-upload, abort-multipart-upload)."
    }
```

- [ ] **Step 3: Commit**

```bash
git add composer.json composer.lock
git commit -m "build(attachments): add aws-sdk-php dev dependency for multipart"
```

### Task 17: Define the multipart driver interface

**Files:**
- Create: `src/Contracts/Attachment/AttachmentMultipartDriver.php`

- [ ] **Step 1: Implement the interface**

Create `src/Contracts/Attachment/AttachmentMultipartDriver.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Contracts\Attachment;

use Illuminate\Filesystem\FilesystemAdapter;

interface AttachmentMultipartDriver
{
    /**
     * Whether this driver can handle the given disk.
     */
    public function supports(FilesystemAdapter $disk): bool;

    /**
     * @return array{upload_id: string}
     */
    public function createMultipartUpload(FilesystemAdapter $disk, string $path, ?string $contentType): array;

    /**
     * @return array{url: string, headers: array<string, string>}
     */
    public function presignPart(FilesystemAdapter $disk, string $path, string $uploadId, int $partNumber, \DateTimeInterface $expiresAt): array;

    /**
     * @param array<int, array{part_number: int, etag: string}> $parts
     * @return array{content_type: string, content_length: int, etag: string}
     */
    public function completeMultipart(FilesystemAdapter $disk, string $path, string $uploadId, array $parts): array;

    public function abortMultipart(FilesystemAdapter $disk, string $path, string $uploadId): void;
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Contracts/Attachment/AttachmentMultipartDriver.php
git commit -m "feat(attachments): add AttachmentMultipartDriver contract"
```

### Task 18: Implement `S3MultipartDriver`

**Files:**
- Create: `src/Services/AttachmentMultipart/S3MultipartDriver.php`

- [ ] **Step 1: Implement the driver**

Create `src/Services/AttachmentMultipart/S3MultipartDriver.php` with these concrete operations:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Services\AttachmentMultipart;

use Aws\S3\S3ClientInterface;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;
use Sopheak\Core\Contracts\Attachment\AttachmentMultipartDriver;

class S3MultipartDriver implements AttachmentMultipartDriver
{
    public function supports(FilesystemAdapter $disk): bool
    {
        return $disk instanceof AwsS3V3Adapter && method_exists($disk, 'getClient');
    }

    public function createMultipartUpload(FilesystemAdapter $disk, string $path, ?string $contentType): array
    {
        $result = $this->client($disk)->createMultipartUpload(array_filter([
            'Bucket' => $this->bucket($disk),
            'Key' => $this->key($disk, $path),
            'ContentType' => $contentType,
        ], static fn(mixed $value): bool => null !== $value && '' !== $value));

        return ['upload_id' => (string) $result['UploadId']];
    }

    public function presignPart(FilesystemAdapter $disk, string $path, string $uploadId, int $partNumber, \DateTimeInterface $expiresAt): array
    {
        $client = $this->client($disk);
        $command = $client->getCommand('UploadPart', [
            'Bucket' => $this->bucket($disk),
            'Key' => $this->key($disk, $path),
            'UploadId' => $uploadId,
            'PartNumber' => $partNumber,
        ]);
        $request = $client->createPresignedRequest($command, $expiresAt);

        return ['url' => (string) $request->getUri(), 'headers' => $request->getHeaders()];
    }

    public function completeMultipart(FilesystemAdapter $disk, string $path, string $uploadId, array $parts): array
    {
        $this->client($disk)->completeMultipartUpload([
            'Bucket' => $this->bucket($disk),
            'Key' => $this->key($disk, $path),
            'UploadId' => $uploadId,
            'MultipartUpload' => [
                'Parts' => array_map(static fn(array $part): array => [
                    'PartNumber' => (int) $part['part_number'],
                    'ETag' => $part['etag'],
                ], $parts),
            ],
        ]);

        $head = $this->client($disk)->headObject([
            'Bucket' => $this->bucket($disk),
            'Key' => $this->key($disk, $path),
        ]);

        return [
            'content_type' => (string) ($head['ContentType'] ?? 'application/octet-stream'),
            'content_length' => (int) ($head['ContentLength'] ?? 0),
            'etag' => (string) ($head['ETag'] ?? ''),
        ];
    }

    public function abortMultipart(FilesystemAdapter $disk, string $path, string $uploadId): void
    {
        $this->client($disk)->abortMultipartUpload([
            'Bucket' => $this->bucket($disk),
            'Key' => $this->key($disk, $path),
            'UploadId' => $uploadId,
        ]);
    }

    private function client(FilesystemAdapter $disk): S3ClientInterface
    {
        /** @var S3ClientInterface $client */
        $client = $disk->getClient();

        return $client;
    }

    private function bucket(FilesystemAdapter $disk): string
    {
        return (string) $disk->getConfig()['bucket'];
    }

    private function key(FilesystemAdapter $disk, string $path): string
    {
        $root = trim((string) ($disk->getConfig()['root'] ?? ''), '/');

        return '' === $root ? ltrim($path, '/') : $root . '/' . ltrim($path, '/');
    }
}
```

The driver uses the disk's configured bucket and `root` prefix. `completeMultipart()` calls `headObject()` after completion; its `content_type`, `content_length`, and `etag` are authoritative for the persisted attachment.

- [ ] **Step 2: Commit**

```bash
git add src/Services/AttachmentMultipart/S3MultipartDriver.php
git commit -m "feat(attachments): implement S3 multipart driver"
```

### Task 19: Add multipart controller operations and tests

**Files:**
- Modify: `src/Services/AttachmentPresignService.php`
- Modify: `src/Http/Controllers/AttachmentUploadController.php`
- Modify: `config/sp-attachments.php`
- Create: `tests/Feature/AttachmentMultipartTest.php`

- [ ] **Step 1: Resolve the multipart driver without touching non-S3 disks**

Add an `AttachmentMultipartDriver` dependency to `AttachmentPresignService` and resolve the S3 driver only when `supports($disk)` is true. Local/public disks must return HTTP 422 from multipart endpoints; they continue using the existing `complete-upload` multipart POST fallback.

- [ ] **Step 2: Add `createMultipartUpload`**

Validate `filename`, optional `content_type`, `visibility`, and `folder_id`; resolve the disk through `AttachmentStorageService`; generate the key; call the driver; return `key`, `upload_id`, `disk`, `part_size` from `attachments.direct_upload.min_multipart_size_bytes`, and `expires_at` using the configured presign TTL.

- [ ] **Step 3: Add `signMultipartPart`**

Require `key`, `upload_id`, and `part_number` (`integer|min:1`). Resolve the disk from visibility, call `presignPart()`, and return `url`, `headers`, `part_number`, and `expires_at`. Never accept a bucket name from the client.

- [ ] **Step 4: Add `completeMultipartUpload`**

Require `key`, `upload_id`, and a non-empty `parts` array. Validate every part as `{part_number: integer|min:1, etag: required|string}`. Call `completeMultipart()`, then persist `sp_attachments` using the returned `content_type`, `content_length`, and `etag` metadata. Client filename/title/folder/visibility values are record metadata only; they cannot override object size, MIME type, or ETag.

- [ ] **Step 5: Add `abortMultipartUpload`**

Require `key`, `upload_id`, and visibility; call `abortMultipart()` and return the standard success response. No attachment row is created.

- [ ] **Step 6: Register the four functions**

Add `RecordFunctionType` entries for `create-multipart-upload`, `sign-multipart-part`, `complete-multipart-upload`, and `abort-multipart-upload` with POST methods, request/response schemas, and `disableCache: true`. The existing registry gate removes them when `direct_upload.enabled` is false.

- [ ] **Step 7: Test all multipart contract branches**

Create `tests/Feature/AttachmentMultipartTest.php` with a fake `AttachmentMultipartDriver` bound in the container. Assert: S3 create returns `upload_id` and 100 MiB `part_size`; signing returns a URL; completion persists HEAD-authoritative MIME/size/ETag; abort does not create a row; local/public disks return 422; and disabled direct upload returns 404.

- [ ] **Step 8: Run and commit**

Run: `vendor/bin/phpunit tests/Feature/AttachmentMultipartTest.php`
Expected: all multipart feature tests pass.

```bash
git add src/Services/AttachmentPresignService.php src/Http/Controllers/AttachmentUploadController.php config/sp-attachments.php tests/Feature/AttachmentMultipartTest.php
git commit -m "feat(attachments): add S3 multipart upload lifecycle"
```

---

## Part E — Docs

### Task 20: Document bucket/disk configuration

**Files:**
- Modify: `docs/guide/features/feature-attachments-visibility-access.md`

- [ ] **Step 1: Replace the "Filesystem Disks" section**

Replace the section `## Filesystem Disks (local vs public)` through the paragraph ending `...enforced.` with an expanded version covering S3/R2 two-bucket and single-bucket `root` setups. Replace with:

```markdown
## Filesystem Disks (local, S3, R2)

This module assumes different filesystem disks for public vs private assets:

- `public` / `temp_public` → `attachments.disk_public` (default: `public`)
- `private` / `temp_private` → `attachments.disk_private` (default: `local`)

When using local storage, the secure setup is:

- `public` disk root: `storage/app/public`, exposed via `/storage/*` (`php artisan storage:link`)
- `local` disk root: `storage/app`, NOT exposed by the web server

### S3 / R2 buckets

Cloudflare R2 is S3-compatible, so it uses Laravel's standard `s3` driver — no custom
driver is needed. The only R2-specific settings are `region => 'auto'`, `endpoint`, and
`use_path_style_endpoint => true`.

Two-bucket setup (recommended for hard isolation):

```php
// config/filesystems.php
'r2-public' => [
    'driver' => 's3',
    'key' => env('R2_PUBLIC_ACCESS_KEY'),
    'secret' => env('R2_PUBLIC_SECRET'),
    'region' => 'auto',
    'bucket' => env('R2_PUBLIC_BUCKET'),
    'url' => env('R2_PUBLIC_URL'),
    'endpoint' => env('R2_PUBLIC_ENDPOINT'),
    'use_path_style_endpoint' => true,
    'visibility' => 'public',
],
'r2-private' => [
    'driver' => 's3',
    'key' => env('R2_PRIVATE_ACCESS_KEY'),
    'secret' => env('R2_PRIVATE_SECRET'),
    'region' => 'auto',
    'bucket' => env('R2_PRIVATE_BUCKET'),
    'endpoint' => env('R2_PRIVATE_ENDPOINT'),
    'use_path_style_endpoint' => true,
    'visibility' => 'private',
],
```

```php
// config/sp-attachments.php
'disk_public' => 'r2-public',
'disk_private' => 'r2-private',
```

Single-bucket setup (prefixes via `root`):

```php
'r2-public'  => ['driver' => 's3', 'bucket' => 'my-bucket', 'root' => 'public',  /* ... */],
'r2-private' => ['driver' => 's3', 'bucket' => 'my-bucket', 'root' => 'private', /* ... */],
```

The bucket itself stays private; public files are only reachable through a CDN/`url` base
that maps to the `public/` prefix.

`public` attachments return a bucket/CDN URL (`disk->url()`); `private` attachments always
return API-proxied URLs (`/view`, `/download`) so auth + tenant scope + expiry checks are
enforced.

## Direct upload (opt-in)

Enable `attachments.direct_upload.enabled = true` to expose the direct-upload endpoints
(`create-upload-url`, `complete-upload`, and multipart). On S3/R2 disks these return
presigned PUT URLs so the browser uploads straight to the bucket; on `local`/`public`
disks they fall back to a server-side `complete-upload` multipart POST.

## Signed preview URLs (opt-in)

Enable `attachments.preview_url_enabled = true` to add a `preview_url` field to private
attachments. It is a short-lived HMAC-signed `/preview` URL that browsers can open in
`<img>`/`<video>` tags without an `Authorization` header.

## R2 bucket provisioning and CORS

Provision these buckets before integration testing:

- `karunafilm-public`
- `karunafilm-private`

Apply equivalent CORS rules to both buckets. Replace the origins with the production
dashboard and approved local development origins:

```json
[
  {
    "AllowedOrigins": [
      "https://admin.karunafilm.example",
      "http://localhost:3000"
    ],
    "AllowedMethods": ["GET", "HEAD", "PUT", "POST"],
    "AllowedHeaders": ["*"],
    "ExposeHeaders": ["ETag", "Content-Length", "Content-Type"],
    "MaxAgeSeconds": 3600
  }
]
```

The public bucket still requires the same CORS policy because browser uploads use
presigned requests. Do not make the private bucket publicly readable; CORS controls
browser cross-origin requests, while bucket access remains controlled by R2 policy and
presigned URLs.
```

- [ ] **Step 2: Commit**

```bash
git add docs/guide/features/feature-attachments-visibility-access.md
git commit -m "docs(attachments): document S3/R2 bucket and direct-upload configuration"
```

### Task 21: Rewrite the bug report's outdated premise

**Files:**
- Modify: `docs/bug-reports/sp-laravel-api-feature-direct-upload-s3-r2.md`

- [ ] **Step 1: Replace the "What already exists" table and "Implementation notes"**

Replace the `## What already exists in the stack` table (lines 64–71) and the `## Implementation notes for the package team` section (lines 141–156) with a corrected version:

```markdown
## What already exists in the stack (no new dependencies needed)

Laravel's `Illuminate\Filesystem\AwsS3V3Adapter` — which `FilesystemManager` returns for
the `s3` driver, and which backs `disk_public`/`disk_private` when they point at R2 —
already implements everything needed:

| Primitive | Where | Status |
| --- | --- | --- |
| Presigned GET URL | `AwsS3V3Adapter::temporaryUrl()` / `FilesystemAdapter::temporaryUrl()` | available |
| Presigned PUT URL | `AwsS3V3Adapter::temporaryUploadUrl()` → `['url', 'headers']` | **available** |
| `providesTemporaryUploadUrls()` | `FilesystemAdapter` (and `LocalFilesystemAdapter` for signed local routes) | **available** |
| S3 client accessor | `AwsS3V3Adapter::getClient(): S3Client` | **available** |
| Multipart + `headObject` | via `getClient()` → `S3Client` | available, but needs a package-side driver |

R2 is S3-compatible and uses the standard `s3` driver; the only R2-specific config is
`region => 'auto'`, `endpoint`, and `use_path_style_endpoint => true`.

## Implementation notes for the package team

- Build on `FilesystemAdapter` (`providesTemporaryUploadUrls()` / `temporaryUploadUrl()`),
  not on a custom driver. This makes the feature work on `local`, `public`, `s3`, and R2.
- Multipart is the only piece that needs the raw S3 client; isolate it behind
  `AttachmentMultipartDriver` with an `S3MultipartDriver` implementation.
- Keep visibility→disk mapping in `AttachmentStorageService::resolveDiskFromVisibility()`.
```

- [ ] **Step 2: Commit**

```bash
git add docs/bug-reports/sp-laravel-api-feature-direct-upload-s3-r2.md
git commit -m "docs(attachments): correct outdated S3/R2 adapter premise in bug report"
```

---

## Final verification

### Task 22: Full pipeline + release notes

- [ ] **Step 1: Run the complete quality gate**

Run: `composer quality`
Expected: format-check, phpstan (strict), and phpunit all pass. The full suite should still show ~634+ passing tests plus the new ones.

- [ ] **Step 2: Bump the version and changelog**

In `composer.json`, bump `"version"` to the next feature release (e.g. `0.5.0`). Add a `## [0.5.0]` entry to `CHANGELOG.md` summarizing: direct upload (`create-upload-url`/`complete-upload`), signed preview URLs, S3/R2 bucket docs, and multipart scaffolding.

- [ ] **Step 3: Commit**

```bash
git add composer.json CHANGELOG.md
git commit -m "chore(release): 0.5.0"
```

---

## Self-Review

**Spec coverage:** The client contract is covered end-to-end: presigned PUT (Tasks 4–8), visibility-only public/private bucket routing (Tasks 2, 5, 19), 100 MiB configurable multipart threshold (Tasks 1, 19), required key/upload ID/parts with ETags (Task 19), authoritative HEAD metadata (Tasks 18–19), signed five-minute private preview (Tasks 11–14), local/public fallback (Tasks 5 and 8), and disabled-by-default backward compatibility (Tasks 7 and 9). Completion preserves the existing attachment response shape.

**Placeholder scan:** No "TBD"/"TODO"/"similar to Task N". Multipart driver implementation, controller wiring, authoritative metadata, tests, gating, and documentation are all included as concrete tasks.

**Type consistency:** `AttachmentStorageService::{resolveDiskFromVisibility, storagePrefix, generateStoragePath}`, `AttachmentPresignService::{supportsPresignedUploads, presignedUploadUrl, exists, headMetadata}`, `AttachmentPreviewUrlService::{signedUrl, verify, signature}`, and `RecordConfigService::{DIRECT_UPLOAD_FUNCTION_KEYS, PREVIEW_FUNCTION_KEY, applyAttachmentFeatureGating}` are referenced consistently across tasks. Function keys in config (`create-upload-url`, `complete-upload`, `create-multipart-upload`, `sign-multipart-part`, `complete-multipart-upload`, `abort-multipart-upload`, `{id}/preview`) match the gating constants exactly.

---

## Implementation Status (2026-08-17)

**Done:** Tasks 1–21 implemented, tested, and verified against local MinIO (`http://localhost:9001`, buckets `develop-private`/`develop-publish`). **Task 22 done** — QA passed, version bumped to 0.5.0, CHANGELOG updated.

**QA (2026-08-17): PASS.** The `qa` agent independently verified all 12 acceptance criteria plus the contract decisions: full suite 748 tests / 2262 assertions, PHPStan clean, MinIO integration 4/4 ran (not skipped), and 15 out-of-repo probes confirmed token-before-lookup ordering, preview non-oracle behaviour, oversize rejection + object deletion, gating strip/restore, URL shapes, ETag omission, and OpenAPI hiding. Two LOW findings (both cleaned up post-QA, see 13 and 14): a duplicated token-verification block and a dead config key.

**Deviations from the plan (all review-driven):**

1. **Upload tokens bind issuance to completion.** `create-upload-url`/`create-multipart-upload` now also return `upload_token` + `expires_at` (unix int); `complete-upload`, `sign-multipart-part`, `complete-multipart-upload`, `abort-multipart-upload` require and verify them. Tokens are stateless HMACs (`sp-upload|key|disk|tenant|context|expires`) over `app.key`; context is `''` for single uploads and the S3 `upload_id` for multipart. This closes the "claim any existing object" hole.
2. **Keys must match the issued pattern.** All completion/signing endpoints validate the key with `AttachmentStorageService::isValidStoragePath()` (`{prefix}/{public|private}/YYYY/MM/DD/{uuid}.{ext}`) so clients cannot reference arbitrary paths.
3. **Preview is no longer an existence oracle.** `verify()` runs before any DB lookup and no longer includes the path (`sp-preview|id|tenantId|expires`); bad signatures get 410 even for nonexistent ids.
4. **Multipart endpoints now enforce folder + record-link validation** mirroring the single-upload endpoints.
5. **S3 exceptions map to 422** `RuntimeException`s (`S3MultipartDriver::call()`), never raw 500s.
6. **Multipart driver is bound in the container** (`CoreSpLaravelApiProvider::register()`, guarded by `class_exists`) — previously multipart endpoints always returned 422.
7. **Flysystem v3 discovery:** `AwsS3V3Adapter` (v3) has no `temporaryUploadUrl` and Laravel 13 never wires the callback for S3, so `providesTemporaryUploadUrls()` is always false for S3. Single-part presigned uploads now presign `PutObject` via the raw S3 client (`getClient()`), mirroring `S3MultipartDriver`. Non-S3 disks with a temporary-upload-url callback keep the old path.
8. **Tests:** `tests/Feature/AttachmentDirectUploadSecurityTest.php` (token/pattern/oracle/multipart security, 9 tests), `tests/Integration/S3DirectUploadIntegrationTest.php` (real MinIO: presigned PUT round trip, multipart lifecycle, public-bucket routing, abort — env-gated, skipped when unreachable). `league/flysystem-aws-s3-v3` added to require-dev; `composer.json` suggest list updated.
9. **Full suite: 745 tests pass**, PHPStan clean, cs-fixer/rector applied to changed files (repo-wide pre-existing rector findings remain in unrelated files).
10. **Function URL shape (verified via route dispatch tests):** `{id}`-pattern functions (`{id}/preview`, and pre-existing `{id}/download`/`{id}/view`) are dispatched with the id inside the function name — `{api}/{table}/rpc/{id}/preview` under the default `rpc_prefix='rpc'`. The bare `{table}/{id}/rpc/preview` form does not resolve (10009). `complete_endpoint` is a bare-key function and is emitted as `{api}/{table}/rpc/complete-upload` (or without `rpc` when `rpc_prefix=''`). HTTP-level route tests cover both prefix modes for preview.
11. **Post-review hardening:** token verification now runs before `folderExists`/record-link checks (no existence probing with forged tokens); presigned-PUT completion rejects objects above `max_upload_size` (S3 presigned PUT cannot express `content-length-range`, so the bound is enforced at completion, deleting the oversized object and refusing the row); multipart function entries gained `payloadSchema` for OpenAPI parity.
12. **Known pre-existing gap (out of scope):** with `rpc_prefix=''` the package registers no route that can dispatch `{id}`-pattern functions (`{table}/{id}/preview` → 404); the pre-existing `download`/`view` URLs share this trait. Consumers must use a non-empty `rpc_prefix` (the default `'rpc'`) for id-bearing attachment functions.
13. **Post-QA cleanup (LOW):** removed a duplicated `resolveVisibility`/`isValidStoragePath`/`verifyUploadToken` block in `completeMultipartUpload` that repeated the identical check already run before the folder/record-link validations (behaviour unchanged; multipart tests still green).
14. **Post-QA cleanup (LOW):** removed the dead config key `attachments.direct_upload.multipart` (never read anywhere in `src/`; multipart availability is implied by the disk and the feature flag). Docs examples in `docs/guide/features/feature-attachments-visibility-access.md` and the bug-report were synced.
