<?php

declare(strict_types=1);

namespace Sopheak\Core\Services\AttachmentMultipart;

use DateTimeInterface;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3ClientInterface;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;
use RuntimeException;
use Sopheak\Core\Contracts\Attachment\AttachmentMultipartDriver;

class S3MultipartDriver implements AttachmentMultipartDriver
{
    public function supports(FilesystemAdapter $disk): bool
    {
        return $disk instanceof AwsS3V3Adapter && method_exists($disk, 'getClient');
    }

    /**
     * @return array<string, string>
     */
    public function createMultipartUpload(FilesystemAdapter $disk, string $path, ?string $contentType): array
    {
        $result = $this->call(fn(): mixed => $this->client($disk)->createMultipartUpload(array_filter([
            'Bucket' => $this->bucket($disk),
            'Key' => $this->key($disk, $path),
            'ContentType' => $contentType,
        ], static fn(mixed $value): bool => null !== $value && '' !== $value)));

        return ['upload_id' => (string) $result['UploadId']];
    }

    /**
     * @return array<string, mixed>
     */
    public function presignPart(FilesystemAdapter $disk, string $path, string $uploadId, int $partNumber, DateTimeInterface $expiresAt): array
    {
        $client = $this->client($disk);
        $command = $this->call(fn(): mixed => $client->getCommand('UploadPart', [
            'Bucket' => $this->bucket($disk), 'Key' => $this->key($disk, $path),
            'UploadId' => $uploadId, 'PartNumber' => $partNumber,
        ]));
        $request = $this->call(fn(): mixed => $client->createPresignedRequest($command, $expiresAt));

        return ['url' => (string) $request->getUri(), 'headers' => $request->getHeaders()];
    }

    /**
     * @param mixed[][] $parts
     * @return array<string, int|string>
     */
    public function completeMultipart(FilesystemAdapter $disk, string $path, string $uploadId, array $parts): array
    {
        $this->call(fn(): mixed => $this->client($disk)->completeMultipartUpload([
            'Bucket' => $this->bucket($disk), 'Key' => $this->key($disk, $path), 'UploadId' => $uploadId,
            'MultipartUpload' => ['Parts' => array_map(static fn(array $part): array => [
                'PartNumber' => (int) $part['part_number'], 'ETag' => $part['etag'],
            ], $parts)],
        ]));
        $head = $this->call(fn(): mixed => $this->client($disk)->headObject([
            'Bucket' => $this->bucket($disk), 'Key' => $this->key($disk, $path),
        ]));

        return [
            'content_type' => (string) ($head['ContentType'] ?? 'application/octet-stream'),
            'content_length' => (int) ($head['ContentLength'] ?? 0),
            'etag' => (string) ($head['ETag'] ?? ''),
        ];
    }

    public function abortMultipart(FilesystemAdapter $disk, string $path, string $uploadId): void
    {
        $this->call(fn(): mixed => $this->client($disk)->abortMultipartUpload([
            'Bucket' => $this->bucket($disk), 'Key' => $this->key($disk, $path), 'UploadId' => $uploadId,
        ]));
    }

    /**
     * Map S3 API failures to a 422-carrying RuntimeException instead of leaking
     * raw AWS errors as 500s.
     */
    private function call(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (S3Exception $s3Exception) {
            throw new RuntimeException(
                $s3Exception->getAwsErrorMessage() ?? 'S3 operation failed',
                422,
                $s3Exception
            );
        }
    }

    private function client(FilesystemAdapter $disk): S3ClientInterface
    {
        return $disk->getClient();
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
