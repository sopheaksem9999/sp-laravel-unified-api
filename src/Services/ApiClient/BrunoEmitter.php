<?php

declare(strict_types=1);

namespace Sopheak\Core\Services\ApiClient;

class BrunoEmitter implements ApiClientEmitterInterface
{
    /**
     * @return array<string, string>
     */
    public function render(ExportResult $result): array
    {
        $files = [];

        $files['bruno.json'] = (string) json_encode([
            'version' => '1',
            'name' => $result->appName . ' API',
            'type' => 'collection',
            'ignore' => [
                'node_modules',
                '.git',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $files['collection.bru'] = $this->renderCollectionBru($result);
        $files['environments/Local.bru'] = $this->renderEnvironmentBru($result);

        foreach ($result->folders as $folder) {
            $folderName = $this->sanitizeFilename($folder->name);
            $seq = 1;
            foreach ($folder->requests as $req) {
                $fileName = $this->sanitizeFilename($req->name) . '.bru';
                $relativePath = $folderName . '/' . $fileName;
                $files[$relativePath] = $this->renderRequestBru($req, $seq++, $result->accessTokenKey);
            }
        }

        return $files;
    }

    /**
     * @return string[]
     */
    public function extractRequestNames(?array $existing): array
    {
        if ($existing === null || $existing === []) {
            return [];
        }

        $names = [];
        foreach ($existing as $value) {
            $content = is_string($value) ? $value : (is_array($value) ? json_encode($value) : '');
            if ($content !== '' && preg_match('/^\s*name:\s*(.+)$/m', $content, $matches)) {
                $name = trim($matches[1]);
                if ($name !== '' && ! str_ends_with($name, 'API')) {
                    $names[] = $name;
                }
            }
        }

        return array_values(array_unique($names));
    }

    private function renderCollectionBru(ExportResult $result): string
    {
        $lines = [];
        $lines[] = 'meta {';
        $lines[] = '  name: ' . $result->appName . ' API';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = 'auth {';
        $lines[] = '  mode: bearer';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = 'auth:bearer {';
        $lines[] = '  token: {{bearerToken}}';
        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function renderEnvironmentBru(ExportResult $result): string
    {
        $lines = [];
        $lines[] = 'vars {';
        $lines[] = '  baseUrl: ' . $result->baseUrl;
        $lines[] = '  apiPrefix: ' . $result->apiPrefix;
        $lines[] = '}';
        $lines[] = '';
        $lines[] = 'vars:secret [';
        $lines[] = '  bearerToken';
        $lines[] = ']';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function renderRequestBru(ExportRequest $req, int $seq, string $accessTokenKey): string
    {
        $lines = [];

        $lines[] = 'meta {';
        $lines[] = '  name: ' . $req->name;
        $lines[] = '  type: http';
        $lines[] = '  seq: ' . $seq;
        $lines[] = '}';
        $lines[] = '';

        $method = strtolower($req->method);
        $hasBody = in_array(strtoupper($req->method), ['POST', 'PUT', 'PATCH'], true) && $req->bodyJson !== null;
        $url = $this->convertUrlParams($req->urlTemplate);

        $lines[] = $method . ' {';
        $lines[] = '  url: ' . $url;
        $lines[] = '  body: ' . ($hasBody ? 'json' : 'none');
        $lines[] = '  auth: ' . ($req->requiresAuth ? 'inherit' : 'none');
        $lines[] = '}';
        $lines[] = '';

        if ($req->pathParams !== []) {
            $lines[] = 'params:path {';
            foreach ($req->pathParams as $param) {
                $lines[] = '  ' . $param . ': 1';
            }
            $lines[] = '}';
            $lines[] = '';
        }

        $queryParams = $req->queryParams;
        if (strtoupper($req->method) === 'GET') {
            $queryParams[] = [
                'name' => 'select',
                'value' => '',
                'enabled' => false,
            ];
        }

        if ($queryParams !== []) {
            $lines[] = 'params:query {';
            foreach ($queryParams as $param) {
                $key = (string) $param['name'];
                $enabled = (bool) ($param['enabled'] ?? true);
                $prefix = $enabled ? '' : '~';
                $value = (string) ($param['value'] ?? '');
                $lines[] = '  ' . $prefix . $key . ': ' . $value;
            }
            $lines[] = '}';
            $lines[] = '';
        }

        if ($req->headers !== []) {
            $lines[] = 'headers {';
            foreach ($req->headers as $header) {
                $lines[] = '  ' . $header['name'] . ': ' . $header['value'];
            }
            $lines[] = '}';
            $lines[] = '';
        }

        if ($hasBody && $req->bodyJson !== null) {
            $lines[] = 'body:json {';
            $lines[] = '  ' . str_replace("\n", "\n  ", trim($req->bodyJson));
            $lines[] = '}';
            $lines[] = '';
        }

        if ($req->isLoginRequest) {
            $lines[] = 'script:post-response {';
            $lines[] = '  ' . str_replace("\n", "\n  ", trim($this->loginTokenCaptureScript($accessTokenKey)));
            $lines[] = '}';
            $lines[] = '';
        }

        if ($req->description !== '') {
            $lines[] = 'docs {';
            $lines[] = '  ' . str_replace("\n", "\n  ", trim($req->description));
            $lines[] = '}';
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Recursively searches the JSON response for a key named $accessTokenKey
     * (mirroring the docs UI's login proxy in routes/web.php) and writes the
     * match into the `bearerToken` runtime variable.
     */
    private function loginTokenCaptureScript(string $accessTokenKey): string
    {
        $key = (string) json_encode($accessTokenKey);

        return <<<JS
        const key = {$key};
        const body = res.body;
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
          bru.setVar("bearerToken", token);
        }
        JS;
    }

    private function convertUrlParams(string $url): string
    {
        return (string) preg_replace('/(?<!\{)\{([a-zA-Z0-9_]+)\}(?!\})/', ':$1', $url);
    }

    private function sanitizeFilename(string $name): string
    {
        return str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $name);
    }
}
