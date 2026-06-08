<?php

namespace Sopheak\Core\Services;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AttachmentUrlService
{
    /**
     * @param array<string, mixed> $attachment
     * @return array<string, mixed>
     */
    public function appendUrls(array $attachment): array
    {
        $baseApiUrl = $this->baseApiUrl((string) ($attachment['id'] ?? ''));

        $attachment['download_url'] = $baseApiUrl . '/download';
        $attachment['url'] = $this->resolveUrl($attachment, $baseApiUrl);

        return $attachment;
    }

    private function resolveUrl(array $attachment, string $baseApiUrl): string
    {
        $visibility = (string) ($attachment['visibility'] ?? 'private');
        if (!$this->canUseDirectUrl($visibility)) {
            return $baseApiUrl . '/view';
        }

        $strategy = (string) config('attachments.url_strategy', 'auto');
        if ('api' === $strategy) {
            return $baseApiUrl . '/view';
        }

        $diskName = (string) ($attachment['disk'] ?? 'local');
        $path = $this->normalizePath((string) ($attachment['path'] ?? ''));

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($diskName);

        if ('temporary' === $strategy) {
            return $this->temporaryUrl($disk, $path) ?? $baseApiUrl . '/view';
        }

        if ('direct' === $strategy || 'auto' === $strategy) {
            return $this->directUrl($disk, $diskName, $path);
        }

        return $baseApiUrl . '/view';
    }

    private function baseApiUrl(string $id): string
    {
        $attachmentPrefix = (string) config('attachments.route_prefix', 'attachments');

        return url(RecordConfigService::apiPrefix() . '/' . $attachmentPrefix . '/' . $id);
    }

    private function canUseDirectUrl(string $visibility): bool
    {
        if ('public' === $visibility) {
            return true;
        }

        if ('temp_public' === $visibility) {
            return !(bool) config('attachments.protect_temp_public_via_download', false);
        }

        return false;
    }

    private function normalizePath(string $path): string
    {
        if (Str::startsWith($path, '/')) {
            return ltrim($path, '/');
        }

        return $path;
    }

    private function directUrl(FilesystemAdapter $disk, string $diskName, string $path): string
    {
        if (in_array($diskName, ['local', 'public'], true)) {
            return asset('storage/' . $path);
        }

        return $disk->url($path);
    }

    private function temporaryUrl(FilesystemAdapter $disk, string $path): ?string
    {
        if (!method_exists($disk, 'temporaryUrl')) {
            return null;
        }

        $minutes = max(1, (int) config('attachments.temporary_url_ttl_minutes', 5));

        return $disk->temporaryUrl($path, Carbon::now()->addMinutes($minutes));
    }
}
