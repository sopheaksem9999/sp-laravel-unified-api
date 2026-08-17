<?php

declare(strict_types=1);

namespace Sopheak\Core\Services;

use Illuminate\Support\Carbon;

class AttachmentPreviewUrlService
{
    /**
     * @param array<string, mixed> $attachment
     */
    public function signedUrl(array $attachment): string
    {
        $id = (string) ($attachment['id'] ?? '');
        $tenantId = (string) ($attachment[RecordConfigService::tenantColumn()] ?? '');
        $expires = Carbon::now()->getTimestamp() + max(1, (int) config('attachments.preview_url_ttl_seconds', 300));
        $signature = $this->signature($id, $tenantId, $expires);
        $prefix = RecordConfigService::apiPrefix();
        $routePrefix = (string) config('attachments.route_prefix', 'attachments');
        $rpcPrefix = RecordConfigService::rpcPrefix();
        // '{id}' functions are dispatched with the id inside the function name
        // (e.g. '{table}/rpc/{id}/preview'); the bare '{table}/{id}/rpc/preview'
        // form does not resolve. With an empty rpc prefix the package registers
        // no route that carries an id inside the function name, so the shape
        // mirrors the pre-existing download/view URLs there.
        $path = $prefix . '/' . $routePrefix
            . ('' === $rpcPrefix ? '/' . $id : '/' . $rpcPrefix . '/' . $id)
            . '/preview';

        return url($path)
            . '?tenant=' . rawurlencode($tenantId)
            . '&expires=' . $expires
            . '&signature=' . $signature;
    }

    /**
     * Verify a preview URL signature. Deliberately excludes the storage path so
     * the caller can verify before touching the database — verifying after the
     * lookup would leak attachment existence to unauthenticated callers.
     */
    public function verify(string $id, string $tenantId, int $expires, string $signature): bool
    {
        if ($expires < Carbon::now()->getTimestamp()) {
            return false;
        }

        return hash_equals($this->signature($id, $tenantId, $expires), $signature);
    }

    private function signature(string $id, string $tenantId, int $expires): string
    {
        return hash_hmac('sha256', implode('|', ['sp-preview', $id, $tenantId, (string) $expires]), (string) config('app.key'));
    }
}
