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
}
