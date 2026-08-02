<?php

declare(strict_types=1);

namespace Sopheak\Core\Services\ApiClient;

class ExportResult
{
    /**
     * @param ExportFolder[] $folders      final folder list to render
     * @param string[]       $added        request names that were newly added
     * @param string[]       $regenerated  request names that were regenerated
     * @param string[]       $skipped      request names that were skipped (existing + not in --regen)
     * @param string[]       $suggestions  table keys that exist in OpenAPI but were not generated this run
     */
    public function __construct(
        public readonly string $appName,
        public readonly string $baseUrl,
        public readonly string $apiPrefix,
        public readonly array $folders,
        public readonly array $added = [],
        public readonly array $regenerated = [],
        public readonly array $skipped = [],
        public readonly array $suggestions = [],
        public readonly string $accessTokenKey = 'access_token',
    ) {}

    public function isEmpty(): bool
    {
        return $this->folders === [];
    }

    public function totalRequestCount(): int
    {
        $count = 0;
        foreach ($this->folders as $folder) {
            $count += count($folder->requests);
        }

        return $count;
    }
}
