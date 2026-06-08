<?php

declare(strict_types=1);

namespace Sopheak\Core\Services\ApiClient;

class PostmanEmitter implements ApiClientEmitterInterface
{
    private const SCHEMA_URL = 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json';

    private const RELATIONSHIP_TIP = 'Tip: append ?select=*,customer:customers(id,name),items(*) to test relationship loading.';

    /**
     * @return array<string, mixed>
     */
    public function render(ExportResult $result): array
    {
        return [
            'info' => [
                'name' => $result->appName . ' API',
                'schema' => self::SCHEMA_URL,
            ],
            'auth' => [
                'type' => 'bearer',
                'bearer' => [
                    ['key' => 'token', 'value' => '{{bearerToken}}'],
                ],
            ],
            'variable' => [
                ['key' => 'baseUrl', 'value' => $result->baseUrl],
                ['key' => 'apiPrefix', 'value' => $result->apiPrefix],
                ['key' => 'bearerToken', 'value' => ''],
            ],
            'item' => array_map(
                static fn (ExportFolder $folder): array => [
                    'name' => $folder->name,
                    'item' => array_map(
                        self::renderItem(...),
                        $folder->requests,
                    ),
                ],
                $result->folders,
            ),
        ];
    }

    /**
     * @return string[]
     */
    public function extractRequestNames(?array $existing): array
    {
        if ($existing === null) {
            return [];
        }

        $names = [];
        foreach ($existing['item'] ?? [] as $folder) {
            foreach ($folder['item'] ?? [] as $request) {
                if (isset($request['name']) && is_string($request['name'])) {
                    $names[] = $request['name'];
                }
            }
        }

        return $names;
    }

    /**
     * @return array<string, mixed>
     */
    private static function renderItem(ExportRequest $req): array
    {
        $description = $req->description;
        if (self::isGetRequest($req)) {
            $description = $description . "\n\n" . self::RELATIONSHIP_TIP;
        }

        $item = [
            'name' => $req->name,
            'request' => [
                'method' => $req->method,
                'header' => array_map(
                    static fn (array $h): array => [
                        'key' => (string) ($h['name'] ?? ''),
                        'value' => (string) ($h['value'] ?? ''),
                    ],
                    $req->headers,
                ),
                'url' => self::buildUrl($req),
                'description' => $description,
            ],
        ];

        if (self::requestHasBody($req) && $req->bodyJson !== null) {
            $item['request']['body'] = [
                'mode' => 'raw',
                'raw' => $req->bodyJson,
                'options' => [
                    'raw' => [
                        'language' => 'json',
                    ],
                ],
            ];
        }

        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildUrl(ExportRequest $req): array
    {
        $urlTemplate = $req->urlTemplate;
        $host = '{{baseUrl}}{{apiPrefix}}';
        $pathPart = '';
        if (str_starts_with($urlTemplate, $host)) {
            $pathPart = substr($urlTemplate, strlen($host));
        }

        $pathSegments = $pathPart === '' || $pathPart === '/'
            ? []
            : array_values(array_filter(explode('/', $pathPart), static fn (string $s): bool => $s !== ''));

        $query = array_map(
            static fn (array $p): array => [
                'key' => (string) ($p['name'] ?? ''),
                'value' => (string) ($p['value'] ?? ''),
                'disabled' => ! ($p['enabled'] ?? true),
            ],
            $req->queryParams,
        );

        return [
            'raw' => $urlTemplate,
            'host' => [$host],
            'path' => $pathSegments,
            'query' => $query,
        ];
    }

    private static function isGetRequest(ExportRequest $req): bool
    {
        return strtoupper($req->method) === 'GET';
    }

    private static function requestHasBody(ExportRequest $req): bool
    {
        return in_array(strtoupper($req->method), ['POST', 'PUT', 'PATCH'], true);
    }
}
