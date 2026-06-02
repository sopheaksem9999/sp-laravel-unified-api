<?php

namespace Sopheak\Core\Services\ApiClient;

interface ApiClientEmitterInterface
{
    /**
     * Render an ExportResult into the tool-specific collection shape.
     *
     * Returned array is what gets json_encode'd and written to disk.
     *
     * @return array<string, mixed>
     */
    public function render(ExportResult $result): array;

    /**
     * Extract the set of request names already present in an existing collection.
     *
     * Used by the service to decide whether a new request is "added", "skipped",
     * or "regenerated". Return an empty array when there is no existing collection
     * or the collection is invalid/empty.
     *
     * @param  array<string, mixed>|null $existing
     * @return string[]
     */
    public function extractRequestNames(?array $existing): array;
}
