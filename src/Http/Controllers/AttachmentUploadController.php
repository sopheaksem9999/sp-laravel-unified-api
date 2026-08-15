<?php

declare(strict_types=1);

namespace Sopheak\Core\Http\Controllers;

use Exception;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\GifEncoder;
use Intervention\Image\Interfaces\EncoderInterface;
use Intervention\Image\Interfaces\ImageInterface;
use Sopheak\Core\Services\AttachmentAccessService;
use Sopheak\Core\Services\AttachmentUrlService;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Services\RecordApiResponseService;

class AttachmentUploadController extends Controller
{
    private function extractRecordPayload(mixed $result): array
    {
        if (is_array($result) && array_key_exists('data', $result)) {
            $data = $result['data'];
            if (is_array($data)) {
                return $data;
            }

            if (is_object($data)) {
                return (array) $data;
            }
        }

        if (is_object($result)) {
            return (array) $result;
        }

        return is_array($result) ? $result : [];
    }

    /**
     * @param array<int|string, mixed> $records
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRecordList(array $records): array
    {
        $normalized = [];
        foreach ($records as $record) {
            $normalized[] = $this->normalizeRecord($record);
        }

        return array_values(array_filter($normalized, fn(array $record): bool => [] !== $record));
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeRecord(mixed $record): array
    {
        if (is_array($record)) {
            return $record;
        }

        if (is_object($record)) {
            return (array) $record;
        }

        return [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function onlyExistingColumns(string $table, array $payload): array
    {
        if (!Schema::hasTable($table)) {
            return $payload;
        }

        return array_filter(
            $payload,
            fn(string $column): bool => Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * @param string[] $keys
     */
    private function normalizeBooleanInputs(Request $request, array $keys): void
    {
        foreach ($keys as $key) {
            if (!$request->has($key)) {
                continue;
            }

            $value = $request->input($key);
            if (!is_string($value)) {
                continue;
            }

            $normalized = strtolower($value);
            if ('true' === $normalized) {
                $request->merge([$key => true]);
            } elseif ('false' === $normalized) {
                $request->merge([$key => false]);
            }
        }
    }

    private function urlService(): AttachmentUrlService
    {
        return app(AttachmentUrlService::class);
    }

    private function accessService(): AttachmentAccessService
    {
        return app(AttachmentAccessService::class);
    }

    private function storageDisk(string $disk): FilesystemAdapter
    {
        return Storage::disk($disk);
    }

    private function resolveTenantId(Request $request): mixed
    {
        if (!RecordConfigService::enableTenantId()) {
            return null;
        }

        $recordContext = $request->attributes->get('record_context');
        $contextTenantId = is_array($recordContext) ? ($recordContext['tenant_id'] ?? null) : null;

        return $request->attributes->get('resolved_tenant_id')
            ?? $contextTenantId
            ?? $request->header(RecordConfigService::tenantHeader());
    }

    private function appendUrlToAttachment(array $attachment): array
    {
        return $this->urlService()->appendUrls($attachment);
    }

    private function isTemporaryVisibility(string $visibility): bool
    {
        return in_array($visibility, ['temp_private', 'temp_public'], true);
    }

    /**
     * @param array<string, mixed> $attachment
     */
    private function resolveTempExpirationAt(array $attachment): ?Carbon
    {
        $tempTimeout = $attachment['temp_timeout'] ?? null;
        if (is_string($tempTimeout) && '' !== trim($tempTimeout)) {
            try {
                return Carbon::parse($tempTimeout);
            } catch (Exception) {
                return null;
            }
        }

        $createdAt = $attachment['created_at'] ?? null;
        if (!is_string($createdAt) || '' === trim($createdAt)) {
            return null;
        }

        $fallbackMinutes = (int) config('attachments.temp_lifetime', 1440);
        if ($fallbackMinutes <= 0) {
            return null;
        }

        try {
            return Carbon::parse($createdAt)->addMinutes($fallbackMinutes);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $attachment
     */
    private function hasAttachmentExpired(array $attachment): bool
    {
        $visibility = (string) ($attachment['visibility'] ?? '');
        if (!$this->isTemporaryVisibility($visibility)) {
            return false;
        }

        $expiresAt = $this->resolveTempExpirationAt($attachment);
        if (!$expiresAt instanceof Carbon) {
            return false;
        }

        return $expiresAt->lessThanOrEqualTo(Carbon::now());
    }

    private function resolveVisibility(Request $request, string $defaultVisibility = 'private', bool $defaultAsTemp = false): string
    {
        $asTemp = $request->boolean('as_temp', $defaultAsTemp);
        $requestedVisibility = $request->input('visibility');

        $defaultTempVisibility = (string) config('attachments.default_temp_visibility', 'temp_private');
        if (!in_array($defaultTempVisibility, ['temp_private', 'temp_public'], true)) {
            $defaultTempVisibility = 'temp_private';
        }

        if (is_string($requestedVisibility) && '' !== trim($requestedVisibility)) {
            if ($asTemp && !in_array($requestedVisibility, ['temp_private', 'temp_public'], true)) {
                return $defaultTempVisibility;
            }

            return $requestedVisibility;
        }

        if ($asTemp) {
            return $defaultTempVisibility;
        }

        return $defaultVisibility;
    }

    private function resolveDiskFromVisibility(string $visibility): string
    {
        return in_array($visibility, ['public', 'temp_public'], true) ? (string) config('attachments.disk_public', 'public') : (string) config('attachments.disk_private', 'local');
    }

    private function resolveTempTimeout(Request $request, string $visibility): ?string
    {
        if (!in_array($visibility, ['temp_private', 'temp_public'], true)) {
            return null;
        }

        $timeoutAt = $request->input('temp_timeout_at');
        if (is_string($timeoutAt) && '' !== trim($timeoutAt)) {
            return Carbon::parse($timeoutAt)->toDateTimeString();
        }

        $timeoutMinutes = $request->input('temp_timeout_minutes');
        if (null !== $timeoutMinutes && '' !== $timeoutMinutes) {
            return Carbon::now()->addMinutes((int) $timeoutMinutes)->toDateTimeString();
        }

        $fallbackMinutes = max(1, (int) config('attachments.temp_lifetime', 1440));

        return Carbon::now()->addMinutes($fallbackMinutes)->toDateTimeString();
    }

    private function tempTimeoutAtExceedsMaximum(Request $request): bool
    {
        $timeoutAt = $request->input('temp_timeout_at');
        if (!is_string($timeoutAt) || '' === trim($timeoutAt)) {
            return false;
        }

        $maxTempTimeoutMinutes = max(1, (int) config('attachments.max_temp_timeout_minutes', 43200));

        try {
            return Carbon::parse($timeoutAt)->greaterThan(Carbon::now()->addMinutes($maxTempTimeoutMinutes));
        } catch (Exception) {
            return false;
        }
    }

    private function validateRequestedRecordLink(Request $request, mixed $tenantId): ?JsonResponse
    {
        if (!$request->filled(['record_id', 'record_type'])) {
            return null;
        }

        $recordType = (string) $request->input('record_type');
        $recordId = $request->input('record_id');

        if (!$this->accessService()->targetRecordAuthorized($request, 'link', $recordType, $recordId, $tenantId)) {
            return response()->json(['message' => 'Attachment access denied'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->accessService()->targetRecordExists($recordType, $recordId, $tenantId)) {
            return response()->json(['message' => 'Target record not found'], 404);
        }

        return null;
    }

    private function generateAttachmentStoragePath(string $visibility, ?string $extension = null): string
    {
        $filename = Str::uuid()->toString();
        if (is_string($extension) && '' !== trim($extension)) {
            $filename .= '.' . ltrim($extension, '.');
        }

        $baseDir = in_array($visibility, ['public', 'temp_public'], true) ? 'attachments/public' : 'attachments/private';

        return $baseDir . '/' . date('Y/m/d') . '/' . $filename;
    }

    /**
     * @param array<string, mixed> $sourceAttachment
     */
    private function copyAttachmentFile(array $sourceAttachment, string $targetDisk, string $targetPath): void
    {
        $sourceDiskName = (string) ($sourceAttachment['disk'] ?? 'local');
        $sourcePath = (string) ($sourceAttachment['path'] ?? '');

        if ('' === $sourcePath) {
            throw new Exception('Source attachment path is missing');
        }

        $sourceDisk = Storage::disk($sourceDiskName);
        if (!$sourceDisk->exists($sourcePath)) {
            throw new Exception('Source attachment file not found');
        }

        if ($sourceDiskName === $targetDisk) {
            if (!$sourceDisk->copy($sourcePath, $targetPath)) {
                throw new Exception('Failed to clone source attachment file');
            }

            return;
        }

        $stream = $sourceDisk->readStream($sourcePath);
        if (!is_resource($stream)) {
            throw new Exception('Failed to read source attachment stream');
        }

        try {
            if (!Storage::disk($targetDisk)->writeStream($targetPath, $stream)) {
                throw new Exception('Failed to write cloned attachment to target disk');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function linkAttachmentIfRequested(Request $request, string $attachmentId, mixed $tenantId): void
    {
        if (!$request->filled(['record_id', 'record_type'])) {
            return;
        }

        if (!$this->accessService()->targetRecordAuthorized($request, 'link', (string) $request->input('record_type'), $request->input('record_id'), $tenantId)) {
            throw new Exception(message: 'Attachment access denied', code: Response::HTTP_FORBIDDEN);
        }

        if (!$this->accessService()->targetRecordExists((string) $request->input('record_type'), $request->input('record_id'), $tenantId)) {
            throw new Exception('Target record not found');
        }

        $collectionName = $request->input('collection_name', 'default');
        $tenantColumn = RecordConfigService::tenantColumn();

        if ($request->boolean('replace_old')) {
            $existingLinks = RecordService::executeGetByFilter('sp_attachment_links', [
                'record_id' => 'eq.' . $request->input('record_id'),
                'record_type' => 'eq.' . $request->input('record_type'),
                'collection_name' => 'eq.' . $collectionName,
            ], $tenantId);

            if (!empty($existingLinks['data'])) {
                foreach ($this->normalizeRecordList($existingLinks['data']) as $oldLink) {
                    RecordService::executeDelete('sp_attachment_links', $oldLink['id'], [], $tenantId);

                    if (!empty($oldLink['attachment_id'])) {
                        $hasOtherLinks = $this->accessService()->attachmentHasOtherLinks(
                            (string) $oldLink['attachment_id'],
                            $oldLink['id'] ?? null,
                            $tenantId
                        );
                        if ($hasOtherLinks) {
                            continue;
                        }

                        $oldAttachments = RecordService::executeGetByFilter('sp_attachments', [
                            'id' => 'eq.' . $oldLink['attachment_id'],
                        ], $tenantId);

                        if (!empty($oldAttachments['data'][0])) {
                            $oldAttachment = $this->normalizeRecord($oldAttachments['data'][0]);
                            Storage::disk($oldAttachment['disk'])->delete($oldAttachment['path']);
                            RecordService::executeDelete('sp_attachments', $oldAttachment['id'], [], $tenantId);
                        }
                    }
                }
            }
        }

        $linkPayload = [
            'attachment_id' => $attachmentId,
            'record_id' => $request->input('record_id'),
            'record_type' => $request->input('record_type'),
            'collection_name' => $collectionName,
        ];

        if (RecordConfigService::enableTenantId() && null !== $tenantId && '' !== $tenantId) {
            $linkPayload[$tenantColumn] = $tenantId;
        }

        RecordService::executeCreate('sp_attachment_links', $linkPayload, [], $tenantId);
    }

    public function folders(Request $request): JsonResponse
    {
        return match (strtoupper($request->method())) {
            'GET' => $this->getFolders($request),
            'POST' => $this->createFolder($request),
            default => RecordApiResponseService::errorWrapped('Method not allowed', Response::HTTP_METHOD_NOT_ALLOWED),
        };
    }

    public function folderItem(Request $request, string $id): JsonResponse
    {
        return match (strtoupper($request->method())) {
            'PUT', 'PATCH' => $this->updateFolder($request, $id),
            'DELETE' => $this->deleteFolder($request, $id),
            default => RecordApiResponseService::errorWrapped('Method not allowed', Response::HTTP_METHOD_NOT_ALLOWED),
        };
    }

    public function record(Request $request, string $table, string $recordId): JsonResponse
    {
        return match (strtoupper($request->method())) {
            'GET' => $this->getForRecord($request, $table, $recordId),
            'POST' => $this->linkToRecord($request, $table, $recordId),
            default => RecordApiResponseService::errorWrapped('Method not allowed', Response::HTTP_METHOD_NOT_ALLOWED),
        };
    }

    public function getFolders(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $result = RecordService::executeGetByFilter('sp_attachment_folders', $request->query(), $tenantId);

        return RecordApiResponseService::success($result['data'] ?? []);
    }

    public function createFolder(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'parent_id' => 'nullable|string',
            'scope' => 'nullable|string|max:64',
            'visibility' => 'nullable|in:private,public,temp_private,temp_public',
            'owner_type' => 'nullable|string|max:255',
            'owner_id' => 'nullable|string|max:255',
            'metadata' => 'nullable|array',
        ]);

        $tenantId = $this->resolveTenantId($request);
        if (!$this->accessService()->folderExists($request->input('parent_id'), $tenantId)) {
            return RecordApiResponseService::errorWrapped('Parent folder not found', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $tenantColumn = RecordConfigService::tenantColumn();
        $payload = $this->onlyExistingColumns('sp_attachment_folders', [
            'name' => $request->input('name'),
            'parent_id' => $request->input('parent_id'),
            'scope' => $request->input('scope', 'internal'),
            'visibility' => $request->input('visibility', 'private'),
            'owner_type' => $request->input('owner_type'),
            'owner_id' => $request->input('owner_id'),
            'metadata' => $request->input('metadata'),
        ]);

        if (RecordConfigService::enableTenantId() && null !== $tenantId && '' !== $tenantId) {
            $payload[$tenantColumn] = $tenantId;
        }

        $folder = RecordService::executeCreate('sp_attachment_folders', $payload, [], $tenantId);
        $folder = $this->extractRecordPayload($folder);

        return RecordApiResponseService::success($folder);
    }

    public function updateFolder(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'parent_id' => 'nullable|string',
            'scope' => 'sometimes|string|max:64',
            'visibility' => 'sometimes|in:private,public,temp_private,temp_public',
            'owner_type' => 'nullable|string|max:255',
            'owner_id' => 'nullable|string|max:255',
            'metadata' => 'nullable|array',
        ]);

        $tenantId = $this->resolveTenantId($request);
        if (!$this->accessService()->folderExists($request->input('parent_id'), $tenantId)) {
            return RecordApiResponseService::errorWrapped('Parent folder not found', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $payload = $this->onlyExistingColumns(
            'sp_attachment_folders',
            $request->only(['name', 'parent_id', 'scope', 'visibility', 'owner_type', 'owner_id', 'metadata'])
        );
        $folder = RecordService::executeUpdate('sp_attachment_folders', $id, $payload, [], $tenantId);
        $folder = $this->extractRecordPayload($folder);

        return RecordApiResponseService::success($folder);
    }

    public function deleteFolder(Request $request, string $id): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        if (!$this->accessService()->canDeleteFolder($id, $tenantId)) {
            return RecordApiResponseService::errorWrapped('Folder is not empty', Response::HTTP_CONFLICT);
        }

        RecordService::executeDelete('sp_attachment_folders', $id, [], $tenantId);

        return RecordApiResponseService::success();
    }

    public function getForRecord(Request $request, string $table, string $recordId): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        if (!$this->accessService()->targetRecordAuthorized($request, 'read', $table, $recordId, $tenantId)) {
            return response()->json(['message' => 'Attachment access denied'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->accessService()->targetRecordExists($table, $recordId, $tenantId)) {
            return response()->json(['message' => 'Target record not found'], 404);
        }

        $collectionName = $request->query('collection_name');

        $filters = [
            'record_type' => 'eq.' . $table,
            'record_id' => 'eq.' . $recordId,
        ];

        if ($collectionName) {
            $filters['collection_name'] = 'eq.' . $collectionName;
        }

        $linksResult = RecordService::executeGetByFilter('sp_attachment_links', $filters, $tenantId);

        if (empty($linksResult['data'])) {
            return RecordApiResponseService::success([]);
        }

        $attachmentIds = array_column($linksResult['data'], 'attachment_id');

        $attachmentsResult = RecordService::executeGetByFilter('sp_attachments', [
            'id' => 'in.' . implode(',', $attachmentIds),
        ], $tenantId);

        $attachmentsById = [];
        if (!empty($attachmentsResult['data'])) {
            foreach ($attachmentsResult['data'] as $attachment) {
                $attachmentsById[$attachment['id']] = $this->appendUrlToAttachment($attachment);
            }
        }

        foreach ($linksResult['data'] as &$link) {
            $link['attachment'] = $attachmentsById[$link['attachment_id']] ?? null;
        }

        return RecordApiResponseService::success($linksResult['data']);
    }

    public function linkToRecord(Request $request, string $table, string $recordId): JsonResponse
    {
        $request->validate([
            'attachment_id' => 'required|string',
            'collection_name' => 'nullable|string',
        ]);

        $tenantId = $this->resolveTenantId($request);
        if (!$this->accessService()->targetRecordAuthorized($request, 'link', $table, $recordId, $tenantId)) {
            return response()->json(['message' => 'Attachment access denied'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->accessService()->targetRecordExists($table, $recordId, $tenantId)) {
            return response()->json(['message' => 'Target record not found'], 404);
        }

        $attachmentId = $request->input('attachment_id');
        $collectionName = $request->input('collection_name', 'default');

        // Check if attachment exists
        try {
            $attachmentResult = RecordService::executeGetById(
                table: 'sp_attachments',
                id: $attachmentId,
                queryParams: [],
                tenantId: $tenantId
            );
            $attachment = $this->extractRecordPayload($attachmentResult);
            if (empty($attachment)) {
                return response()->json(['message' => 'Attachment not found'], 404);
            }
        } catch (Exception) {
            return response()->json(['message' => 'Attachment not found'], 404);
        }

        // Check if link already exists
        $existingLinks = RecordService::executeGetByFilter('sp_attachment_links', [
            'attachment_id' => 'eq.' . $attachmentId,
            'record_type' => 'eq.' . $table,
            'record_id' => 'eq.' . $recordId,
            'collection_name' => 'eq.' . $collectionName,
        ], $tenantId);

        if (!empty($existingLinks['data'])) {
            return RecordApiResponseService::success($existingLinks['data'][0]);
        }

        $linkPayload = [
            'attachment_id' => $attachmentId,
            'record_id' => $recordId,
            'record_type' => $table,
            'collection_name' => $collectionName,
        ];

        if (RecordConfigService::enableTenantId() && $tenantId) {
            $tenantColumn = RecordConfigService::tenantColumn();
            $linkPayload[$tenantColumn] = $tenantId;
        }

        $link = RecordService::executeCreate('sp_attachment_links', $linkPayload, [], $tenantId);
        $link = $this->extractRecordPayload($link);
        return RecordApiResponseService::success($link);
    }

    public function unlinkFromRecord(Request $request, string $table, string $recordId, string $attachmentId): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        if (!$this->accessService()->targetRecordAuthorized($request, 'unlink', $table, $recordId, $tenantId)) {
            return response()->json(['message' => 'Attachment access denied'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->accessService()->targetRecordExists($table, $recordId, $tenantId)) {
            return response()->json(['message' => 'Target record not found'], 404);
        }

        $collectionName = $request->query('collection_name');

        $filters = [
            'attachment_id' => 'eq.' . $attachmentId,
            'record_type' => 'eq.' . $table,
            'record_id' => 'eq.' . $recordId,
        ];

        if ($collectionName) {
            $filters['collection_name'] = 'eq.' . $collectionName;
        }

        $existingLinks = RecordService::executeGetByFilter('sp_attachment_links', $filters, $tenantId);

        if (empty($existingLinks['data'])) {
            return response()->json(['message' => 'Link not found'], 404);
        }

        foreach ($existingLinks['data'] as $link) {
            RecordService::executeDelete('sp_attachment_links', $link['id'], [], $tenantId);
        }

        return RecordApiResponseService::success();
    }

    public function upload(Request $request): JsonResponse
    {
        $maxSize = config('attachments.max_upload_size', 10240);
        $maxTempTimeoutMinutes = (int) config('attachments.max_temp_timeout_minutes', 43200);

        $this->normalizeBooleanInputs($request, ['replace_old', 'as_temp']);

        $request->validate([
            'file' => 'required|file|max:' . $maxSize,
            'size_name' => 'nullable|string',
            'w' => 'nullable|integer|min:10|max:3000',
            'h' => 'nullable|integer|min:10|max:3000',
            'fit' => 'nullable|in:crop,contain',
            'record_id' => 'nullable|string',
            'record_type' => 'nullable|string',
            'collection_name' => 'nullable|string',
            'replace_old' => 'nullable|boolean',
            'folder_id' => 'nullable|string',
            'title' => 'nullable|string|max:255',
            'caption' => 'nullable|string',
            'visibility' => 'nullable|in:private,public,temp_private,temp_public',
            'as_temp' => 'nullable|boolean',
            'temp_timeout_minutes' => 'nullable|integer|min:1|max:' . $maxTempTimeoutMinutes,
            'temp_timeout_at' => 'nullable|date',
        ]);

        if ($this->tempTimeoutAtExceedsMaximum($request)) {
            return RecordApiResponseService::errorWrapped('Temporary timeout exceeds maximum allowed minutes', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $file = $request->file('file');
        if (!$file) {
            return response()->json(['message' => 'File is required'], 422);
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

        $width = $request->input('w');
        $height = $request->input('h');
        $fit = $request->input('fit', 'contain');
        $sizeName = $request->input('size_name');

        // Override with config sizes if size_name is provided
        if ($sizeName) {
            $configuredSizes = config('attachments.image_sizes', []);
            if (isset($configuredSizes[$sizeName])) {
                $width = $configuredSizes[$sizeName]['w'] ?? $width;
                $height = $configuredSizes[$sizeName]['h'] ?? $height;
                $fit = $configuredSizes[$sizeName]['fit'] ?? $fit;
            }
        }

        $fullPath = $this->generateAttachmentStoragePath($visibility, $file->getClientOriginalExtension());
        $path = dirname($fullPath);
        $filename = basename($fullPath);

        // 1. Handle Client-Dictated Resizing
        if (str_starts_with((string) $file->getMimeType(), 'image/') && ($width || $height)) {
            $manager = new ImageManager(new Driver());
            $image = $manager->decodePath($file->getRealPath());

            if ($fit === 'crop' && $width && $height) {
                $image->cover($width, $height);
            } else {
                $image->scale(width: $width, height: $height);
            }

            $encoded = $image->encode();
            Storage::disk($disk)->put($fullPath, (string) $encoded);
            $size = strlen((string) $encoded);
        } else {
            Storage::disk($disk)->putFileAs($path, $file, $filename);
            $size = $file->getSize();
        }

        // 2. Resolve Dynamic Tenant Configuration
        $tenantColumn = RecordConfigService::tenantColumn();
        $tempTimeout = $this->resolveTempTimeout($request, $visibility);

        // 3. Prepare Payload with Dynamic Tenant Column
        $attachmentPayload = [
            'folder_id' => $request->input('folder_id'),
            'title' => $request->input('title'),
            'caption' => $request->input('caption'),
            'disk' => $disk,
            'path' => $fullPath,
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $size,
            'visibility' => $visibility,
            'temp_timeout' => $tempTimeout,
        ];

        if (RecordConfigService::enableTenantId() && $tenantId) {
            $attachmentPayload[$tenantColumn] = $tenantId;
        }

        // 4. Save via RecordService
        $attachment = RecordService::executeCreate('sp_attachments', $attachmentPayload, [], $tenantId);
        $attachment = $this->extractRecordPayload($attachment);
        $attachment = $this->appendUrlToAttachment($attachment);

        // 5. Handle Linking & Replace Old (Avatar use-case)
        try {
            $this->linkAttachmentIfRequested($request, (string) $attachment['id'], $tenantId);
        } catch (Exception $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->getCode() > 0 ? $exception->getCode() : 404);
        }

        return RecordApiResponseService::success($attachment);
    }

    public function cloneTemp(Request $request): JsonResponse
    {
        $maxTempTimeoutMinutes = (int) config('attachments.max_temp_timeout_minutes', 43200);

        $this->normalizeBooleanInputs($request, ['replace_old', 'as_temp']);

        $request->validate([
            'attachment_id' => 'required|string',
            'visibility' => 'nullable|in:private,public,temp_private,temp_public',
            'as_temp' => 'nullable|boolean',
            'temp_timeout_minutes' => 'nullable|integer|min:1|max:' . $maxTempTimeoutMinutes,
            'temp_timeout_at' => 'nullable|date',
            'folder_id' => 'nullable|string',
            'title' => 'nullable|string|max:255',
            'caption' => 'nullable|string',
            'record_id' => 'nullable|string',
            'record_type' => 'nullable|string',
            'collection_name' => 'nullable|string',
            'replace_old' => 'nullable|boolean',
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

        $sourceId = (string) $request->input('attachment_id');

        try {
            $sourceAttachment = RecordService::executeGetById(
                table: 'sp_attachments',
                id: $sourceId,
                queryParams: [],
                tenantId: $tenantId
            );
            $sourceAttachment = $this->extractRecordPayload($sourceAttachment);
        } catch (Exception) {
            return response()->json(['message' => 'Attachment not found'], 404);
        }

        if (empty($sourceAttachment)) {
            return response()->json(['message' => 'Attachment not found'], 404);
        }

        $visibility = $this->resolveVisibility($request, (string) ($sourceAttachment['visibility'] ?? 'private'), true);
        $disk = $this->resolveDiskFromVisibility($visibility);

        $sourceFilename = (string) ($sourceAttachment['filename'] ?? '');
        $extension = pathinfo($sourceFilename, PATHINFO_EXTENSION);
        if ('' === $extension) {
            $sourcePath = (string) ($sourceAttachment['path'] ?? '');
            $extension = pathinfo($sourcePath, PATHINFO_EXTENSION);
        }

        $fullPath = $this->generateAttachmentStoragePath($visibility, $extension);
        $this->copyAttachmentFile($sourceAttachment, $disk, $fullPath);

        $tempTimeout = $this->resolveTempTimeout($request, $visibility);
        $tenantColumn = RecordConfigService::tenantColumn();
        $size = isset($sourceAttachment['size']) ? (int) $sourceAttachment['size'] : (int) Storage::disk($disk)->size($fullPath);

        $attachmentPayload = [
            'folder_id' => $request->input('folder_id', $sourceAttachment['folder_id'] ?? null),
            'title' => $request->input('title', $sourceAttachment['title'] ?? null),
            'caption' => $request->input('caption', $sourceAttachment['caption'] ?? null),
            'disk' => $disk,
            'path' => $fullPath,
            'filename' => $sourceFilename !== '' ? $sourceFilename : basename((string) ($sourceAttachment['path'] ?? $fullPath)),
            'mime_type' => (string) ($sourceAttachment['mime_type'] ?? 'application/octet-stream'),
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

    public function view(Request $request, string $id): StreamedResponse|JsonResponse|Response
    {
        return $this->serveFile($request, $id, true);
    }

    public function download(Request $request, string $id): StreamedResponse|JsonResponse|Response
    {
        return $this->serveFile($request, $id, false);
    }

    /**
     * Resolve read-time resize params from the query string.
     *
     * Returns null when no transformation should happen (feature disabled,
     * no params sent, or non-image attachment), which keeps the legacy
     * byte-for-byte serving path untouched. Returns a JsonResponse when
     * params fail validation.
     *
     * @param array<string, mixed> $attachment
     * @return array{width: int|null, height: int|null, fit: string, format: string}|JsonResponse|null
     */
    private function resolveReadResize(Request $request, array $attachment): array|JsonResponse|null
    {
        if (!(bool) config('attachments.read_resizing', false)) {
            return null;
        }

        $mimeType = (string) ($attachment['mime_type'] ?? '');
        if (!str_starts_with($mimeType, 'image/')) {
            return null;
        }

        if (!$request->hasAny(['w', 'h', 'format', 'size_name'])) {
            return null;
        }

        $width = $request->input('w');
        $height = $request->input('h');
        $fit = (string) $request->input('fit', 'contain');
        $format = (string) $request->input('format');
        $sizeName = (string) $request->input('size_name');

        if ('' !== $sizeName) {
            $configuredSizes = config('attachments.image_sizes', []);
            if (!is_array($configuredSizes) || !isset($configuredSizes[$sizeName])) {
                return RecordApiResponseService::errorWrapped('Unknown size_name', Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $width = $configuredSizes[$sizeName]['w'] ?? $width;
            $height = $configuredSizes[$sizeName]['h'] ?? $height;
            $fit = (string) ($configuredSizes[$sizeName]['fit'] ?? $fit);
        }

        if (!in_array($fit, ['contain', 'crop'], true)) {
            return RecordApiResponseService::errorWrapped('Invalid fit value', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $min = (int) config('attachments.read_resizing_min', 32);
        $max = (int) config('attachments.read_resizing_max', 2000);

        $width = $this->validateDimension($width, $min, $max);
        $height = $this->validateDimension($height, $min, $max);

        if ($width instanceof JsonResponse || $height instanceof JsonResponse) {
            return $width instanceof JsonResponse ? $width : $height;
        }

        if (null === $width && null === $height) {
            return null;
        }

        if ('' === $format) {
            $format = 'jpg';
        }

        $allowedFormats = (array) config('attachments.read_resizing_formats', ['webp', 'jpg', 'jpeg', 'png', 'gif']);
        if (!in_array($format, $allowedFormats, true)) {
            return RecordApiResponseService::errorWrapped('Invalid format value', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return [
            'width' => $width,
            'height' => $height,
            'fit' => $fit,
            'format' => $format,
        ];
    }

    private function validateDimension(mixed $value, int $min, int $max): int|null|JsonResponse
    {
        if (null === $value || '' === $value) {
            return null;
        }

        if (!is_numeric($value) || (int) $value < $min || (int) $value > $max) {
            return RecordApiResponseService::errorWrapped(
                sprintf('Dimension must be between %d and %d', $min, $max),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $attachment
     * @param array{width: int|null, height: int|null, fit: string, format: string} $resize
     */
    private function buildResizedResponse(array $attachment, array $resize): StreamedResponse|Response
    {
        $disk = $this->storageDisk((string) $attachment['disk']);
        $path = (string) $attachment['path'];

        $cacheKey = null;
        $cachePath = null;
        if ((bool) config('attachments.read_resize_cache', false)) {
            $cacheDisk = $this->storageDisk((string) config('attachments.read_resize_cache_disk', 'public'));
            $cacheKey = md5(implode('|', [
                (string) ($attachment['id'] ?? $path),
                (string) $resize['width'],
                (string) $resize['height'],
                $resize['fit'],
                $resize['format'],
                (string) $disk->lastModified($path),
            ]));
            $cachePath = 'attachments/resized/' . $cacheKey . '.' . $this->normalizeFormat($resize['format']);

            if ($cacheDisk->exists($cachePath)) {
                $ttlMinutes = max(1, (int) config('attachments.read_resize_cache_ttl_minutes', 10080));
                if (Carbon::createFromTimestamp($cacheDisk->lastModified($cachePath))->greaterThanOrEqualTo(Carbon::now()->subMinutes($ttlMinutes))) {
                    return $this->withCacheHeaders($cacheDisk->response($cachePath));
                }
            }
        }

        $manager = new ImageManager(new Driver());
        $image = $this->decodeAttachmentImage($manager, $disk, $path);

        if ('crop' === $resize['fit'] && $resize['width'] && $resize['height']) {
            $image->cover($resize['width'], $resize['height']);
        } else {
            $image->scale(width: $resize['width'], height: $resize['height']);
        }

        $format = $this->normalizeFormat($resize['format']);
        $mimeType = 'jpg' === $format ? 'image/jpeg' : 'image/' . $format;
        $encoded = $image->encode($this->encoderForFormat($format));

        if (null !== $cachePath && (bool) config('attachments.read_resize_cache', false)) {
            $this->storageDisk((string) config('attachments.read_resize_cache_disk', 'public'))->put($cachePath, (string) $encoded);
        }

        return $this->withCacheHeaders(
            response((string) $encoded, 200, ['Content-Type' => $mimeType])
        );
    }

    private function decodeAttachmentImage(ImageManager $manager, FilesystemAdapter $disk, string $path): ImageInterface
    {
        try {
            return $manager->decodePath($disk->path($path));
        } catch (Exception) {
            return $manager->decode((string) $disk->get($path));
        }
    }

    private function normalizeFormat(string $format): string
    {
        return 'jpeg' === $format ? 'jpg' : $format;
    }

    private function encoderForFormat(string $format): EncoderInterface
    {
        return match ($format) {
            'webp' => new WebpEncoder(),
            'jpg' => new JpegEncoder(),
            'png' => new PngEncoder(),
            default => new GifEncoder(),
        };
    }

    private function withCacheHeaders(Response $response): Response
    {
        $maxAge = (int) config('attachments.read_resize_cache_max_age', 0);
        if ($maxAge > 0) {
            $response->setPublic();
            $response->setMaxAge($maxAge);
        }

        return $response;
    }

    private function serveFile(Request $request, string $id, bool $inline): StreamedResponse|JsonResponse|Response
    {
        $tenantId = $this->resolveTenantId($request);

        try {
            $attachment = RecordService::executeGetById(
                table: 'sp_attachments',
                id: $id,
                queryParams: [],
                tenantId: $tenantId
            );
            $attachment = $this->extractRecordPayload($attachment);
        } catch (Exception) {
            return response()->json(['message' => 'Attachment not found'], 404);
        }

        if (empty($attachment)) {
            return response()->json(['message' => 'Attachment not found'], 404);
        }

        if ($this->hasAttachmentExpired($attachment)) {
            return RecordApiResponseService::errorWrapped('Attachment has expired', Response::HTTP_GONE);
        }

        $disk = $this->storageDisk((string) $attachment['disk']);

        if (!$disk->exists((string) $attachment['path'])) {
            return response()->json(['message' => 'File not found on disk'], 404);
        }

        if ($inline) {
            $resize = $this->resolveReadResize($request, $attachment);
            if ($resize instanceof JsonResponse) {
                return $resize;
            }

            if (is_array($resize)) {
                return $this->buildResizedResponse($attachment, $resize);
            }

            $mimeType = (string) ($attachment['mime_type'] ?? 'application/octet-stream');
            $response = $disk->response((string) $attachment['path']);
            if (method_exists($response, 'header')) {
                $response->header('Content-Type', $mimeType);
            }

            return $response;
        }

        return $disk->download((string) $attachment['path'], (string) $attachment['filename']);
    }
}
