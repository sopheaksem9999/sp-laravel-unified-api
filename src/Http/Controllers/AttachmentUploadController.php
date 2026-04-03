<?php

namespace Sopheak\Core\Http\Controllers;

use Illuminate\Filesystem\FilesystemAdapter;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
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
        $visibility = (string) ($attachment['visibility'] ?? 'private');

        if ($this->shouldUseDirectAssetUrl($visibility)) {
            /** @var FilesystemAdapter $disk */
            $disk = Storage::disk((string) ($attachment['disk'] ?? 'local'));
            $attachment['url'] = $disk->url((string) ($attachment['path'] ?? ''));
        } else {
            $attachmentPrefix = config('attachments.route_prefix', 'attachments');
            $attachment['url'] = url(RecordConfigService::apiPrefix() . '/' . $attachmentPrefix . '/' . ((string) ($attachment['id'] ?? '')) . '/download');
        }

        return $attachment;
    }

    private function shouldUseDirectAssetUrl(string $visibility): bool
    {
        if ('public' === $visibility) {
            return true;
        }

        if ('temp_public' === $visibility) {
            return !(bool) config('attachments.protect_temp_public_via_download', false);
        }

        return false;
    }

    private function isTemporaryVisibility(string $visibility): bool
    {
        return in_array($visibility, ['temp_private', 'temp_public'], true);
    }

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
        return in_array($visibility, ['public', 'temp_public'], true) ? 'public' : 'local';
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

    private function generateAttachmentStoragePath(?string $extension = null): string
    {
        $filename = Str::uuid()->toString();
        if (is_string($extension) && '' !== trim($extension)) {
            $filename .= '.' . ltrim($extension, '.');
        }

        return 'attachments/' . date('Y/m/d') . '/' . $filename;
    }

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

        $collectionName = $request->input('collection_name', 'default');
        $tenantColumn = RecordConfigService::tenantColumn();

        if ($request->boolean('replace_old')) {
            $existingLinks = RecordService::executeGetByFilter('sp_attachment_links', [
                'record_id' => 'eq.' . $request->input('record_id'),
                'record_type' => 'eq.' . $request->input('record_type'),
                'collection_name' => 'eq.' . $collectionName,
            ], $tenantId);

            if (!empty($existingLinks['data'])) {
                foreach ($existingLinks['data'] as $oldLink) {
                    RecordService::executeDelete('sp_attachment_links', $oldLink['id'], [], $tenantId);

                    if (!empty($oldLink['attachment_id'])) {
                        $oldAttachments = RecordService::executeGetByFilter('sp_attachments', [
                            'id' => 'eq.' . $oldLink['attachment_id'],
                        ], $tenantId);

                        if (!empty($oldAttachments['data'][0])) {
                            $oldAttachment = $oldAttachments['data'][0];
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
        $result = RecordService::executeGetByFilter('sp_document_folders', $request->query(), $tenantId);

        return RecordApiResponseService::success($result['data'] ?? []);
    }

    public function createFolder(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'parent_id' => 'nullable|string',
        ]);

        $tenantId = $this->resolveTenantId($request);
        $tenantColumn = RecordConfigService::tenantColumn();
        $payload = [
            'id' => Str::uuid()->toString(),
            'name' => $request->input('name'),
            'parent_id' => $request->input('parent_id'),
        ];

        if (RecordConfigService::enableTenantId() && null !== $tenantId && '' !== $tenantId) {
            $payload[$tenantColumn] = $tenantId;
        }

        $folder = RecordService::executeCreate('sp_document_folders', $payload, [], $tenantId);
        $folder = $this->extractRecordPayload($folder);

        return RecordApiResponseService::success($folder);
    }

    public function updateFolder(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'parent_id' => 'nullable|string',
        ]);

        $tenantId = $this->resolveTenantId($request);
        $payload = $request->only(['name', 'parent_id']);
        $folder = RecordService::executeUpdate('sp_document_folders', $id, $payload, [], $tenantId);
        $folder = $this->extractRecordPayload($folder);

        return RecordApiResponseService::success($folder);
    }

    public function deleteFolder(Request $request, string $id): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        RecordService::executeDelete('sp_document_folders', $id, [], $tenantId);

        return RecordApiResponseService::success();
    }

    public function getForRecord(Request $request, string $table, string $recordId): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);

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
            'id' => 'in.' . implode(',', $attachmentIds)
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

        $attachmentId = $request->input('attachment_id');
        $collectionName = $request->input('collection_name', 'default');

        // Check if attachment exists
        try {
            $attachmentResult = RecordService::executeGetById('sp_attachments', $attachmentId, [], $tenantId);
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

        $file = $request->file('file');
        if (!$file) {
            return response()->json(['message' => 'File is required'], 422);
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

        $fullPath = $this->generateAttachmentStoragePath($file->getClientOriginalExtension());
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
        $tenantId = $this->resolveTenantId($request);
        $tempTimeout = $this->resolveTempTimeout($request, $visibility);

        // 3. Prepare Payload with Dynamic Tenant Column
        $attachmentPayload = [
            'id' => Str::uuid()->toString(),
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
        $this->linkAttachmentIfRequested($request, (string) $attachment['id'], $tenantId);

        return RecordApiResponseService::success($attachment);
    }

    public function cloneTemp(Request $request): JsonResponse
    {
        $maxTempTimeoutMinutes = (int) config('attachments.max_temp_timeout_minutes', 43200);
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

        $tenantId = $this->resolveTenantId($request);
        $sourceId = (string) $request->input('attachment_id');

        try {
            $sourceAttachment = RecordService::executeGetById('sp_attachments', $sourceId, [], $tenantId);
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

        $fullPath = $this->generateAttachmentStoragePath($extension);
        $this->copyAttachmentFile($sourceAttachment, $disk, $fullPath);

        $tempTimeout = $this->resolveTempTimeout($request, $visibility);
        $tenantColumn = RecordConfigService::tenantColumn();
        $size = isset($sourceAttachment['size']) ? (int) $sourceAttachment['size'] : (int) Storage::disk($disk)->size($fullPath);

        $attachmentPayload = [
            'id' => Str::uuid()->toString(),
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
        $this->linkAttachmentIfRequested($request, (string) $attachment['id'], $tenantId);

        return RecordApiResponseService::success($attachment);
    }

    public function download(Request $request, string $id): StreamedResponse|JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);

        try {
            $attachment = RecordService::executeGetById('sp_attachments', $id, [], $tenantId);
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

        if (!Storage::disk($attachment['disk'])->exists($attachment['path'])) {
            return response()->json(['message' => 'File not found on disk'], 404);
        }

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($attachment['disk']);
        
        return $disk->download($attachment['path'], $attachment['filename']);
    }
}
