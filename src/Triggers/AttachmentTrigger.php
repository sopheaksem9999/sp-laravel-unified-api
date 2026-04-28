<?php

namespace Sopheak\Core\Triggers;

use Exception;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;
use Sopheak\Core\Attributes\RecordTrigger;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Services\RecordService;

/**
 * Handles attachment URL appending and physical file deletion.
 *
 * Extends RecordTriggerBase for easy customization.
 */
class AttachmentTrigger extends RecordTriggerBase
{
    #[RecordTrigger('afterRead')]
    public static function afterRead(Request $request, string $table, array $context): void
    {
        if (isset($context['data']) && is_array($context['data'])) {
            foreach ($context['data'] as &$attachment) {
                if (is_object($attachment)) {
                    $attachment = (object) self::appendUrlToAttachment((array) $attachment);
                } elseif (is_array($attachment)) {
                    $attachment = self::appendUrlToAttachment($attachment);
                }
            }

            if (isset($context['response'])) {
                $content = json_decode((string) $context['response']->getContent(), true) ?? [];
                $content['data'] = $context['data'];
                $context['response']->setData($content);
            }
        } elseif (isset($context['response'])) {
            $content = json_decode((string) $context['response']->getContent(), true);
            if (is_array($content) && isset($content['data']['id'])) {
                $content['data'] = self::appendUrlToAttachment($content['data']);
                $context['response']->setData($content);
            }
        } elseif (isset($context['record'])) {
            if (is_object($context['record'])) {
                $context['record'] = (object) self::appendUrlToAttachment((array) $context['record']);
            } elseif (is_array($context['record'])) {
                $context['record'] = self::appendUrlToAttachment($context['record']);
            }
        }
    }

    #[RecordTrigger('beforeDelete')]
    public static function beforeDelete(Request $request, string $table, mixed $context): void
    {
        $id = is_array($context) ? ($context['id'] ?? null) : $context;
        if (!$id) {
            return;
        }

        $oldData = is_array($context) ? ($context['record'] ?? null) : null;

        // If we don't have the old data (e.g. during a bulk delete), we must fetch it.
        if (!$oldData) {
            $tenantId = is_array($context) ? ($context['tenant_id'] ?? $context['tenantColumn'] ?? null) : null;
            try {
                $record = RecordService::executeGetById($table, $id, [], $tenantId);
                $oldData = $record['data'] ?? null;
            } catch (Exception) {
                $oldData = null;
            }
        }

        if (is_object($oldData)) {
            $oldData = (array) $oldData;
        }

        if (!empty($oldData['disk']) && !empty($oldData['path'])) {
            Storage::disk($oldData['disk'])->delete($oldData['path']);
        }
    }

    private static function appendUrlToAttachment(array $attachment): array
    {
        $visibility = (string) ($attachment['visibility'] ?? 'private');

        $attachmentPrefix = config('attachments.route_prefix', 'attachments');
        $baseApiUrl = url(RecordConfigService::apiPrefix() . '/' . $attachmentPrefix . '/' . ($attachment['id'] ?? ''));

        $attachment['download_url'] = $baseApiUrl . '/download';

        if (self::shouldUseDirectAssetUrl($visibility)) {
            $diskName = (string) ($attachment['disk'] ?? 'local');
            /** @var FilesystemAdapter $disk */
            $disk = Storage::disk($diskName);

            $path = (string) ($attachment['path'] ?? '');
            if (Str::startsWith($path, '/')) {
                $path = ltrim($path, '/');
            }

            if (in_array($diskName, ['local', 'public'], true)) {
                $attachment['url'] = asset('storage/' . $path);
            } else {
                // For cloud disks like s3, use the native disk URL generator
                $attachment['url'] = $disk->url($path);
            }
        } else {
            $attachment['url'] = $baseApiUrl . '/view';
        }

        return $attachment;
    }

    private static function shouldUseDirectAssetUrl(string $visibility): bool
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
