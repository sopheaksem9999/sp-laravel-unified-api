<?php

declare(strict_types=1);

namespace Sopheak\Core\Contracts\Attachment;

use DateTimeInterface;
use Illuminate\Filesystem\FilesystemAdapter;

interface AttachmentMultipartDriver
{
    public function supports(FilesystemAdapter $disk): bool;

    /** @return array{upload_id: string} */
    public function createMultipartUpload(FilesystemAdapter $disk, string $path, ?string $contentType): array;

    /** @return array{url: string, headers: array<string, mixed>} */
    public function presignPart(FilesystemAdapter $disk, string $path, string $uploadId, int $partNumber, DateTimeInterface $expiresAt): array;

    /** @param array<int, array{part_number: int, etag: string}> $parts */
    /** @return array{content_type: string, content_length: int, etag: string} */
    public function completeMultipart(FilesystemAdapter $disk, string $path, string $uploadId, array $parts): array;

    public function abortMultipart(FilesystemAdapter $disk, string $path, string $uploadId): void;
}
