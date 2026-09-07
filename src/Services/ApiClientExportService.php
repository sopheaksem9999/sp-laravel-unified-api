<?php

declare(strict_types=1);

namespace Sopheak\Core\Services;

use Sopheak\Core\Services\ApiClient\ApiClientEmitterInterface;
use Sopheak\Core\Services\ApiClient\ExportFolder;
use Sopheak\Core\Services\ApiClient\ExportRequest;
use Sopheak\Core\Services\ApiClient\ExportResult;

class ApiClientExportService
{
    /**
     * Build an ExportResult by diffing a fresh OpenAPI spec against an existing
     * collection (decoded from JSON), honoring the regen filter.
     *
     * @param  array<string, mixed>        $spec
     * @param  array<string, mixed>|null   $existing
     * @param  string[]|null               $regenKeys null = default (process all), ['all'] = regenerate everything,
     *                                       ['users','orders'] = regenerate only those tables
     * @param  ApiClientEmitterInterface   $emitter   used to read the existing collection's request names
     */
    public function build(
        array $spec,
        ?array $existing,
        ?array $regenKeys,
        ApiClientEmitterInterface $emitter,
    ): ExportResult {
        $appName = (string) config('app.name', 'API');
        $baseUrl = (string) ($spec['servers'][0]['url'] ?? (string) config('app.url', ''));
        $apiPrefix = '/' . ltrim(RecordConfigService::apiPrefix(), '/');
        $apiPrefixForStrip = rtrim($apiPrefix, '/');
        $loginPath = $this->normalizeLoginPath((string) config('record.api_docs.login_api', ''));
        $accessTokenKey = (string) config('record.api_docs.access_token_key', 'access_token');

        $existingNames = $emitter->extractRequestNames($existing);

        $allRequests = $this->parsePaths($spec['paths'] ?? [], $apiPrefixForStrip, $loginPath);
        $regenKeysProvided = $regenKeys !== null;
        $regenAll = $regenKeysProvided && in_array('all', $regenKeys, true);
        $regenSet = ($regenKeysProvided && ! $regenAll)
            ? array_flip(array_map(strtolower(...), $regenKeys))
            : [];

        $foldersAccumulator = [];
        $folderOrder = [];
        $added = [];
        $regenerated = [];
        $skipped = [];
        $generatedTableNames = [];
        $regenerateTags = [];

        foreach ($allRequests as $req) {
            $tag = $req['tag'];
            $isRpc = str_starts_with((string) $tag, 'RPC');
            $tableKey = $tag;
            $folderName = $tag;

            $inRegenSet = $regenAll
                || isset($regenSet[strtolower((string) $tableKey)])
                || ($isRpc && isset($regenSet['rpc']));
            $inExisting = in_array($req['name'], $existingNames, true);

            if ($inRegenSet) {
                $regenerateTags[strtolower((string) $tag)] = true;
            }

            if ($inExisting) {
                if ($inRegenSet) {
                    $regenerated[] = $req['name'];
                } else {
                    $skipped[] = $req['name'];
                }
            } else {
                $added[] = $req['name'];
            }

            if (! isset($foldersAccumulator[$folderName])) {
                $foldersAccumulator[$folderName] = [];
                $folderOrder[] = $folderName;
            }

            $foldersAccumulator[$folderName][] = new ExportRequest(
                name: $req['name'],
                method: $req['method'],
                urlTemplate: '{{baseUrl}}{{apiPrefix}}' . $req['strippedPath'],
                description: $req['description'],
                pathParams: $req['pathParams'],
                queryParams: $req['queryParams'],
                headers: [['name' => 'Accept', 'value' => 'application/json', 'enabled' => true]],
                bodyJson: $req['bodyJson'],
                requiresAuth: $req['requiresAuth'],
                isLoginRequest: $req['isLoginRequest'],
            );

            if (! $isRpc) {
                $generatedTableNames[$tag] = true;
            }
        }

        // Order folders: per-table in spec order, then all RPC-prefixed folders last.
        $orderedFolders = [];
        $rpcFolders = [];
        foreach ($folderOrder as $name) {
            if (str_starts_with($name, 'RPC')) {
                $rpcFolders[] = new ExportFolder($name, $foldersAccumulator[$name]);
            } else {
                $orderedFolders[] = new ExportFolder($name, $foldersAccumulator[$name]);
            }
        }

        array_push($orderedFolders, ...$rpcFolders);

        // Suggestions = all non-RPC table names in spec that have no generated requests.
        $allTableNames = [];
        foreach ($allRequests as $req) {
            if (! str_starts_with((string) $req['tag'], 'RPC')) {
                $allTableNames[$req['tag']] = true;
            }
        }

        $suggestions = array_values(array_keys(array_diff_key($allTableNames, $generatedTableNames)));

        return new ExportResult(
            appName: $appName,
            baseUrl: $baseUrl,
            apiPrefix: $apiPrefix,
            folders: $orderedFolders,
            added: $added,
            regenerated: $regenerated,
            skipped: $skipped,
            suggestions: $suggestions,
            accessTokenKey: $accessTokenKey,
            regenerateTags: $regenerateTags,
            regenerateAll: $regenAll,
        );
    }

    /**
     * Normalize `record.api_docs.login_api` (relative path or absolute URL) to a
     * bare, leading-slash path so it can be compared against OpenAPI path keys.
     */
    private function normalizeLoginPath(string $loginApi): string
    {
        if ($loginApi === '') {
            return '';
        }

        $path = $loginApi;
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $path = (string) (parse_url($path, PHP_URL_PATH) ?? '');
        }

        if ($path === '') {
            return '';
        }

        return rtrim('/' . ltrim($path, '/'), '/');
    }

    /**
     * Parse OpenAPI `paths` into a flat list of (path, method, tag, name, params, body) entries.
     *
     * @param  array<string, array<string, mixed>> $paths
     * @return array<int, array<string, mixed>>
     */
    private function parsePaths(array $paths, string $apiPrefixForStrip, string $loginPath = ''): array
    {
        $requests = [];
        $validMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

        foreach ($paths as $path => $methods) {
            $strippedPath = str_starts_with($path, $apiPrefixForStrip . '/')
                ? substr($path, strlen($apiPrefixForStrip))
                : $path;
            $isLoginRequest = $loginPath !== '' && rtrim((string) $path, '/') === $loginPath;

            foreach ($methods as $method => $op) {
                if (! is_array($op)) {
                    continue;
                }

                $methodUpper = strtoupper((string) $method);
                if (! in_array($methodUpper, $validMethods, true)) {
                    continue;
                }

                $tags = $op['tags'] ?? [];
                $tag = isset($tags[0]) ? (string) $tags[0] : 'Default';
                $name = (string) ($op['summary'] ?? $op['operationId'] ?? '');
                $description = (string) ($op['description'] ?? '');
                $requiresAuth = ($op['security'] ?? [['bearerAuth' => []]]) !== [];

                $queryParams = [];
                $pathParams = [];
                foreach ($op['parameters'] ?? [] as $param) {
                    $in = (string) ($param['in'] ?? '');
                    $paramName = (string) ($param['name'] ?? '');

                    if ($in === 'query') {
                        // 'select' has complex nested-parentheses syntax with no sensible
                        // placeholder value; the emitters already surface it via a "Tip:"
                        // note in GET request descriptions instead of a blank query field.
                        if ($paramName === 'select') {
                            continue;
                        }

                        $queryParams[] = $this->buildQueryParam($param);
                    } elseif ($in === 'path') {
                        $pathParams[] = $paramName;
                    }
                }

                $requests[] = [
                    'name' => $name,
                    'description' => $description,
                    'method' => $methodUpper,
                    'tag' => $tag,
                    'strippedPath' => $strippedPath,
                    'pathParams' => $pathParams,
                    'queryParams' => $queryParams,
                    'bodyJson' => $this->buildBodyExample($op['requestBody'] ?? null),
                    'requiresAuth' => $requiresAuth,
                    'isLoginRequest' => $isLoginRequest,
                ];
            }
        }

        return $requests;
    }

    /**
     * @param  array<string, mixed> $param
     * @return array<string, mixed>
     */
    private function buildQueryParam(array $param): array
    {
        $schema = $param['schema'] ?? [];
        // Only parameters with a real default are enabled in the exported
        // request. Placeholders (e.g. per-column filters, sortby, search)
        // are included but disabled so a one-click send doesn't fire a
        // request full of empty/example values that the API rejects.
        $hasDefault = array_key_exists('default', $schema);
        $value = $schema['default'] ?? '';

        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }

        return [
            'name' => (string) $param['name'],
            'value' => (string) $value,
            'enabled' => $hasDefault,
            'type' => 'query',
        ];
    }

    /**
     * @param  array<string, mixed>|null $requestBody
     */
    private function buildBodyExample(?array $requestBody): ?string
    {
        if ($requestBody === null) {
            return null;
        }

        $schema = $requestBody['content']['application/json']['schema'] ?? null;
        if ($schema === null) {
            return null;
        }

        if (array_key_exists('example', $schema)) {
            $encoded = json_encode($schema['example'], JSON_UNESCAPED_SLASHES);
            return $encoded === false ? null : $encoded;
        }

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            $encoded = json_encode(
                $this->buildExampleFromProperties($schema['properties']),
                JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
            );
            return $encoded === false ? null : $encoded;
        }

        return null;
    }

    /**
     * @param  array<string, mixed> $properties
     * @return array<string, mixed>
     */
    private function buildExampleFromProperties(array $properties): array
    {
        $out = [];
        foreach ($properties as $name => $prop) {
            if (! is_array($prop)) {
                $out[(string) $name] = null;
                continue;
            }

            $type = (string) ($prop['type'] ?? 'string');
            $out[(string) $name] = match ($type) {
                'string' => '',
                'integer', 'number' => 0,
                'boolean' => false,
                'array' => [],
                'object' => (object) [],
                default => null,
            };
        }

        return $out;
    }
}
