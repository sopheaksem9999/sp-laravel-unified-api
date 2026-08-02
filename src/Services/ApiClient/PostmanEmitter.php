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
                static fn(ExportFolder $folder): array => [
                    'name' => $folder->name,
                    'item' => array_map(
                        static fn(ExportRequest $req): array => self::renderItem($req, $result->accessTokenKey),
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
    private static function renderItem(ExportRequest $req, string $accessTokenKey): array
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
                    static fn(array $h): array => [
                        'key' => (string) ($h['name'] ?? ''),
                        'value' => (string) ($h['value'] ?? ''),
                    ],
                    $req->headers,
                ),
                'url' => self::buildUrl($req),
                'description' => $description,
            ],
        ];

        if (! $req->requiresAuth) {
            $item['request']['auth'] = ['type' => 'noauth'];
        }

        if ($req->isLoginRequest) {
            $item['event'] = [
                [
                    'listen' => 'test',
                    'script' => [
                        'type' => 'text/javascript',
                        'exec' => self::loginTokenCaptureScriptLines($accessTokenKey),
                    ],
                ],
            ];
        }

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
     * Recursively searches the JSON response for a key named $accessTokenKey
     * (mirroring the docs UI's login proxy in routes/web.php) and writes the
     * match into the `bearerToken` collection variable.
     *
     * @return string[]
     */
    private static function loginTokenCaptureScriptLines(string $accessTokenKey): array
    {
        $key = (string) json_encode($accessTokenKey);

        $script = <<<JS
        const key = {$key};
        const body = pm.response.json();
        let token = null;

        if (body && typeof body === "object" && !Array.isArray(body) && typeof body[key] === "string" && body[key] !== "") {
          token = body[key];
        } else {
          const stack = [body];
          let guard = 0;
          while (stack.length > 0 && guard < 200) {
            guard++;
            const current = stack.shift();
            if (!current || typeof current !== "object") {
              continue;
            }
            if (!Array.isArray(current) && typeof current[key] === "string" && current[key] !== "") {
              token = current[key];
              break;
            }
            if (Array.isArray(current)) {
              current.forEach((item) => stack.push(item));
            } else {
              Object.keys(current).forEach((k) => stack.push(current[k]));
            }
          }
        }

        if (token) {
          pm.collectionVariables.set("bearerToken", token);
        }
        JS;

        return explode("\n", $script);
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
            : array_values(array_filter(explode('/', $pathPart), static fn(string $s): bool => $s !== ''));

        $query = array_map(
            static fn(array $p): array => [
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
