<?php

declare(strict_types=1);

namespace Sopheak\Core\Services;

use Illuminate\Filesystem\FilesystemAdapter;
use DateTimeInterface;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Sopheak\Core\Contracts\Attachment\AttachmentMultipartDriver;

class AttachmentPresignService
{
    public function __construct(private readonly ?AttachmentMultipartDriver $multipartDriver = null) {}

    private function multipartDriver(string $disk): array
    {
        $adapter = Storage::disk($disk);
        if (!$this->multipartDriver instanceof AttachmentMultipartDriver || !$this->multipartDriver->supports($adapter)) {
            return [$adapter, null];
        }

        return [$adapter, $this->multipartDriver];
    }

    public function createMultipart(string $disk, string $path, ?string $contentType): ?array
    {
        [$adapter, $driver] = $this->multipartDriver($disk);
        return null === $driver ? null : $driver->createMultipartUpload($adapter, $path, $contentType);
    }

    public function signMultipartPart(string $disk, string $path, string $uploadId, int $partNumber, DateTimeInterface $expiresAt): ?array
    {
        [$adapter, $driver] = $this->multipartDriver($disk);
        return null === $driver ? null : $driver->presignPart($adapter, $path, $uploadId, $partNumber, $expiresAt);
    }

    /** @param array<int, array{part_number: int, etag: string}> $parts */
    public function completeMultipart(string $disk, string $path, string $uploadId, array $parts): ?array
    {
        [$adapter, $driver] = $this->multipartDriver($disk);
        return null === $driver ? null : $driver->completeMultipart($adapter, $path, $uploadId, $parts);
    }

    public function abortMultipart(string $disk, string $path, string $uploadId): bool
    {
        [$adapter, $driver] = $this->multipartDriver($disk);
        if (null === $driver) {
            return false;
        }

        $driver->abortMultipart($adapter, $path, $uploadId);
        return true;
    }

    public function supportsPresignedUploads(string $disk): bool
    {
        $adapter = Storage::disk($disk);

        return !$adapter->getAdapter() instanceof LocalFilesystemAdapter
            && ($adapter->providesTemporaryUploadUrls() || method_exists($adapter, 'getClient'));
    }

    /**
     * @return array{url: string, headers: array<string, mixed>}
     */
    public function presignedUploadUrl(string $disk, string $path, ?string $contentType, DateTimeInterface $expiresAt): array
    {
        $adapter = Storage::disk($disk);
        $options = null !== $contentType && '' !== $contentType ? ['ContentType' => $contentType] : [];

        // Flysystem v3 S3 adapters dropped the native upload-URL method, so
        // presign PutObject through the raw S3 client when available.
        if (method_exists($adapter, 'getClient')) {
            $client = $adapter->getClient();
            [$bucket, $key] = $this->s3BucketAndKey($adapter, $path);
            $command = $client->getCommand('PutObject', array_filter(
                ['Bucket' => $bucket, 'Key' => $key, ...$options],
                static fn(mixed $value): bool => null !== $value && '' !== $value
            ));
            $request = $client->createPresignedRequest($command, $expiresAt);

            return ['url' => (string) $request->getUri(), 'headers' => $request->getHeaders()];
        }

        return $adapter->temporaryUploadUrl($path, $expiresAt, $options);
    }

    /** @return array{0: string, 1: string} bucket and fully prefixed key */
    private function s3BucketAndKey(FilesystemAdapter $adapter, string $path): array
    {
        $config = $adapter->getConfig();
        $root = trim((string) ($config['root'] ?? ''), '/');
        $key = '' === $root ? ltrim($path, '/') : $root . '/' . ltrim($path, '/');

        return [(string) ($config['bucket'] ?? ''), $key];
    }

    public function exists(string $disk, string $path): bool
    {
        return Storage::disk($disk)->exists($path);
    }

    /**
     * Issue a short-lived, stateless token binding an upload flow to the exact
     * key, disk, tenant, and context ('' for single uploads, the S3 upload id
     * for multipart) it was created for. Completion/signing endpoints must
     * verify this token so clients cannot claim arbitrary existing objects.
     */
    public function issueUploadToken(string $key, string $disk, string $tenantId, string $context, int $expires): string
    {
        return hash_hmac(
            'sha256',
            implode('|', ['sp-upload', $key, $disk, $tenantId, $context, (string) $expires]),
            (string) config('app.key')
        );
    }

    public function verifyUploadToken(string $key, string $disk, string $tenantId, string $context, int $expires, string $token): bool
    {
        if ($expires < now()->getTimestamp()) {
            return false;
        }

        return hash_equals($this->issueUploadToken($key, $disk, $tenantId, $context, $expires), $token);
    }

    /**
     * @return array{content_type: string, content_length: int}
     */
    public function headMetadata(string $disk, string $path): array
    {
        $adapter = Storage::disk($disk);
        $mimeType = $adapter->mimeType($path);

        return [
            'content_type' => false === $mimeType ? 'application/octet-stream' : (string) $mimeType,
            'content_length' => (int) $adapter->size($path),
        ];
    }
}
