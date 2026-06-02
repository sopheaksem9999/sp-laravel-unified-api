<?php

namespace Sopheak\Core\Services\ApiClient;

class ExportRequest
{
    /**
     * @param array<int, string>           $pathParams  path parameter names, in order, e.g. ['id']
     * @param array<int, array<string, mixed>> $queryParams  [['name' => 'page', 'value' => '1', 'enabled' => true, 'type' => 'query', 'description' => '...']]
     * @param array<int, array<string, mixed>> $headers     [['name' => 'Accept', 'value' => 'application/json', 'enabled' => true]]
     * @param string|null                  $bodyJson    raw JSON example body for POST/PUT/PATCH; null for GET/DELETE
     */
    public function __construct(
        public readonly string $name,
        public readonly string $method,
        public readonly string $urlTemplate,
        public readonly string $description,
        public readonly array $pathParams = [],
        public readonly array $queryParams = [],
        public readonly array $headers = [],
        public readonly ?string $bodyJson = null,
    ) {
    }
}
