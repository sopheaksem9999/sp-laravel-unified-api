<?php

namespace Sopheak\Core\Services\ApiClient;

class BrunoEmitter implements ApiClientEmitterInterface
{
    private const SELECT_DESCRIPTION = 'Set to load relationships, e.g. *,customer:customers(id,name),items(*)';

    /**
     * @return array<string, mixed>
     */
    public function render(ExportResult $result): array
    {
        return [
            'meta' => [
                'name' => $result->appName . ' API',
                'type' => 'collection',
                'version' => 'v3',
            ],
            'auth' => [
                'mode' => 'bearer',
                'bearer' => [
                    'token' => '{{bearerToken}}',
                ],
            ],
            'vars' => [
                'baseUrl' => [
                    'value' => $result->baseUrl,
                    'enabled' => true,
                    'secret' => false,
                ],
                'apiPrefix' => [
                    'value' => $result->apiPrefix,
                    'enabled' => true,
                    'secret' => false,
                ],
                'bearerToken' => [
                    'value' => '',
                    'enabled' => true,
                    'secret' => true,
                ],
            ],
            'folders' => array_map(
                static fn (ExportFolder $folder): array => [
                    'name' => $folder->name,
                    'requests' => array_map(
                        self::renderRequest(...),
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
        foreach ($existing['folders'] ?? [] as $folder) {
            foreach ($folder['requests'] ?? [] as $request) {
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
    private static function renderRequest(ExportRequest $req): array
    {
        $out = [
            'name' => $req->name,
            'type' => 'http',
            'method' => $req->method,
            'url' => $req->urlTemplate,
            'params' => self::buildParams($req),
            'headers' => $req->headers,
            'docs' => $req->description,
        ];

        if (self::requestHasBody($req) && $req->bodyJson !== null) {
            $out['body'] = [
                'mode' => 'json',
                'json' => $req->bodyJson,
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function buildParams(ExportRequest $req): array
    {
        $params = $req->queryParams;

        if (self::isGetRequest($req)) {
            $params[] = [
                'name' => 'select',
                'value' => '',
                'enabled' => false,
                'type' => 'query',
                'description' => self::SELECT_DESCRIPTION,
            ];
        }

        return $params;
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
