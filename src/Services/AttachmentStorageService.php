<?php

declare(strict_types=1);

namespace Sopheak\Core\Services;

use Illuminate\Support\Str;

class AttachmentStorageService
{
    public function resolveDiskFromVisibility(string $visibility): string
    {
        return in_array($visibility, ['public', 'temp_public'], true)
            ? (string) config('attachments.disk_public', 'public')
            : (string) config('attachments.disk_private', 'local');
    }

    public function storagePrefix(): string
    {
        $prefix = trim((string) config('attachments.direct_upload.storage_prefix', 'attachments'));

        return '' === $prefix ? 'attachments' : trim($prefix, '/');
    }

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

    /**
     * Whether a storage key follows the canonical attachment key layout:
     * {prefix}/{public|private}/YYYY/MM/DD/{uuid}.{ext}. Direct-upload
     * endpoints only accept keys in this shape.
     */
    public function isValidStoragePath(string $path): bool
    {
        $prefix = preg_quote($this->storagePrefix(), '#');

        return 1 === preg_match(
            '#^' . $prefix . '/(public|private)/\d{4}/\d{2}/\d{2}/[0-9a-f\-]{36}(\.[A-Za-z0-9]{1,16})?$#',
            $path
        );
    }
}
