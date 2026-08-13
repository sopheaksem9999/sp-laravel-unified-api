<?php

declare(strict_types=1);

namespace Sopheak\Core\Support;

/**
 * Request- and job-scoped scratch space for the cache layer.
 *
 * This used to live in request()->attributes, which is wrong outside PHP-FPM:
 * request() is a long-lived singleton in queue workers, Octane and Artisan, so a
 * memoized namespace version was never refreshed and the process kept resolving
 * a version another process had already moved past -- serving stale entries for
 * the rest of its life.
 *
 * Registered with the container as a scoped binding. QueueServiceProvider calls
 * forgetScopedInstances() between jobs; HTTP requests reset it on RouteMatched,
 * because the container is not rebuilt per request under Octane or in Testbench.
 */
final class CacheRequestContext
{
    /** @var array<string, int> */
    private array $namespaceVersions = [];

    /** @var array<string, int> */
    private array $stats = [];

    public function namespaceVersion(string $key): ?int
    {
        return $this->namespaceVersions[$key] ?? null;
    }

    public function rememberNamespaceVersion(string $key, int $version): void
    {
        $this->namespaceVersions[$key] = $version;
    }

    /**
     * @return array<string, int>
     */
    public function stats(): array
    {
        return $this->stats;
    }

    public function incrementStat(string $key): void
    {
        $this->stats[$key] = ($this->stats[$key] ?? 0) + 1;
    }

    public function reset(): void
    {
        $this->namespaceVersions = [];
        $this->stats = [];
    }
}
