<?php

namespace Sopheak\Core\Triggers;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Sopheak\Core\Attributes\RecordTrigger;
use Sopheak\Core\Services\RecordConfigService;

class AttachmentTrigger
{
    #[RecordTrigger('afterRead')]
    public function appendUrl(array $data): array
    {
        if (isset($data['data']) && is_array($data['data'])) {
            foreach ($data['data'] as &$attachment) {
                $attachment = $this->appendUrlToAttachment($attachment);
            }
        } elseif (isset($data['id'])) {
            $data = $this->appendUrlToAttachment($data);
        }

        return $data;
    }

    #[RecordTrigger('beforeDelete')]
    public function deletePhysicalFile(string $id, array $oldData): void
    {
        if (!empty($oldData['disk']) && !empty($oldData['path'])) {
            Storage::disk($oldData['disk'])->delete($oldData['path']);
        }
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
            $attachment['url'] = url(RecordConfigService::apiPrefix() . '/' . $attachmentPrefix . '/' . ($attachment['id'] ?? '') . '/download');
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
}
