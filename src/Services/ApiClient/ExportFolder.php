<?php

declare(strict_types=1);

namespace Sopheak\Core\Services\ApiClient;

class ExportFolder
{
    /**
     * @param ExportRequest[] $requests
     */
    public function __construct(
        public readonly string $name,
        public readonly array $requests = [],
    ) {
    }
}
