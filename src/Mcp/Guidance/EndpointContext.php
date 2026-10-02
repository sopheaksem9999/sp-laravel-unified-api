<?php

declare(strict_types=1);

namespace Sopheak\Core\Mcp\Guidance;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Types\RecordTableType;
use Throwable;

/**
 * What an agent needs to know to call one action well, read from live config:
 * which headers to send, how it is throttled, how list paging and search work
 * on this table, how large and how async a bulk call may be, and which
 * resizing parameters the attachment view accepts.
 */
final class EndpointContext
{
    private const READS = ['list', 'read'];

    /**
     * @return array<string, string>
     */
    public function headers(RecordTableType $config, string $action): array
    {
        $needsAuth = in_array($action, self::READS, true) ? $config->isAuthRead : $config->isAuthWrite;

        return $this->headerSet($needsAuth, $config->hasTenantId);
    }

    /**
     * @param array<string, mixed> $function
     * @return array<string, string>
     */
    public function rpcHeaders(array $function, ?RecordTableType $table): array
    {
        return $this->headerSet(!($function['isPublic'] ?? false), $table instanceof RecordTableType && $table->hasTenantId);
    }

    /**
     * The throttle group an action runs under and, when the app defines the
     * limiter in a form we can read, its limit per minute (per `perSeconds`
     * instead, when the window is not a minute).
     *
     * @return array{group: string, limit?: int, perSeconds?: int}|null
     */
    public function throttle(string $action): ?array
    {
        $group = $this->groupFor($action);
        if (null === $group) {
            return null;
        }

        $throttle = ['group' => $group];

        try {
            $limiter = RateLimiter::limiter($group);
            $limit = is_callable($limiter) ? $limiter(request()) : null;
            if (is_array($limit)) {
                $limit = $limit[0] ?? null;
            }

            if ($limit instanceof Limit) {
                $throttle['limit'] = $limit->maxAttempts;
                if (60 !== $limit->decaySeconds) {
                    $throttle['perSeconds'] = $limit->decaySeconds;
                }
            }
        } catch (Throwable) {
            // The group is still worth naming without a limit.
        }

        return $throttle;
    }

    public function groupFor(string $action): ?string
    {
        return match (true) {
            in_array($action, self::READS, true) => 'api-reads',
            'rpc' === $action => 'api-functions',
            in_array($action, ['create', 'update', 'delete', 'upsert', 'restore', 'forceDelete'], true), str_starts_with($action, 'bulk') => 'api-writes',
            default => null,
        };
    }

    /** URI of a table's RPC function, honouring the configured route and rpc prefixes. */
    public function rpcUri(string $table, string $functionKey): string
    {
        $rpc = trim(RecordConfigService::rpcPrefix(), '/');

        return '/' . trim(RecordConfigService::apiPrefix(), '/') . '/' . $table . ('' === $rpc ? '' : '/' . $rpc) . '/' . $functionKey;
    }

    /**
     * @return array<string, mixed>
     */
    public function listExtras(RecordTableType $config): array
    {
        $extras = [
            'pagination' => [
                'limit_max' => RecordConfigService::limitMax(),
                'per_page_max' => RecordConfigService::perPageMax(),
            ],
        ];

        if (!empty($config->searchable)) {
            $extras['search'] = ['enabled' => true, 'columns' => array_values($config->searchable)];
        }

        return $extras;
    }

    /**
     * @return array<string, mixed>
     */
    public function bulkExtras(): array
    {
        $extras = [];
        if ('sync' !== config('queue.default', 'sync')) {
            $extras['async'] = [
                'query' => 'async=true',
                'header' => 'X-Async-Process: 1',
                'response' => '202 with status "queued"; the batch runs on the queue',
            ];
        }

        return $extras;
    }

    /**
     * Query schema for the attachment view's resizing parameters, or null
     * when read-time resizing is off.
     *
     * @return array<string, mixed>|null
     */
    public function resizingQuerySchema(): ?array
    {
        if (!(bool) config('attachments.read_resizing', false)) {
            return null;
        }

        $min = (int) config('attachments.read_resizing_min', 32);
        $max = (int) config('attachments.read_resizing_max', 2000);
        $sizes = array_keys((array) config('attachments.image_sizes', []));

        $size = ['type' => 'string', 'description' => 'A named size from the app configuration; overrides w, h and fit'];
        if ([] !== $sizes) {
            $size['enum'] = $sizes;
        }

        return [
            'type' => 'object',
            'properties' => [
                'w' => ['type' => 'integer', 'minimum' => $min, 'maximum' => $max, 'description' => 'Width in pixels'],
                'h' => ['type' => 'integer', 'minimum' => $min, 'maximum' => $max, 'description' => 'Height in pixels'],
                'fit' => ['type' => 'string', 'enum' => ['contain', 'crop'], 'default' => 'contain'],
                'format' => ['type' => 'string', 'enum' => array_values((array) config('attachments.read_resizing_formats', ['webp', 'jpg', 'jpeg', 'png', 'gif'])), 'description' => 'Output image format'],
                'size_name' => $size,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function headerSet(bool $needsAuth, bool $tenantScoped): array
    {
        $headers = [];
        if ($needsAuth) {
            $headers['Authorization'] = 'Bearer <token>';
        }

        if ($tenantScoped && RecordConfigService::enableTenantId()) {
            $headers[RecordConfigService::tenantHeader()] = '<tenant id>';
        }

        return $headers;
    }
}
