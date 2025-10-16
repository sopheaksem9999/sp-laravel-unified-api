# ERP Core Package Docs & CLI Overview

This document summarizes the ERP Core package features and command-line tools available after installation.

## Installation (Path Repository)
- Ensure your root `composer.json` includes:
  - `repositories`: `{ "type": "path", "url": "packages/app-core", "options": { "symlink": true } }`
  - `require`: `"sopheak/app-core": "*"`
- Install: `composer update sopheak/app-core -W`
- Auto-discovery registers `Sopheak\\Core\\CoreServiceProvider`.

## Middleware
- Alias: `request.id`
- Purpose: injects a unique `X-Request-ID` and sets `request_id` attribute.
- Usage: `Route::middleware(['request.id'])->group(function () { ... });`

## Response Service
- Resolve: `$responses = app('api.response');`
- Methods:
  - `success($data, $meta = [], $status = 200)`
  - `created($data, $meta = [])`
  - `updated($data, $meta = [])`
  - `deleted($data, $meta = [])`
  - `error($message = 'Error', $errors = [], $meta = [], $status = 400)`
  - `validationError($errors, $meta = [])`
  - `unauthorized($message = 'Unauthorized', $meta = [])`
  - `forbidden($message = 'Forbidden', $meta = [])`
  - `notFound($message = 'Not Found', $meta = [])`
  - `serverError($message = 'Server Error', $meta = [])`
- All payloads include `meta.request_id` when middleware is active.

## CLI Commands
- `php artisan app-core:openapi --out=storage/api-v2.json`
  - Generates an OpenAPI 3.0 stub.
  - Options:
    - `--out`: Output path; defaults `storage/api-v2.json`.

## Config
- Publish: `php artisan vendor:publish --tag=app-core-config`
- File: `config/app-core.php`
  - `response.include_request_id`: inject `meta.request_id`.
  - `openapi.output`: default OpenAPI output filename.

## Examples
```php
// routes/api.php
Route::middleware(['request.id'])->group(function () {
    Route::get('/health', function () {
        return app('api.response')->success(['status' => 'ok']);
    });
});
```

## Notes
- Extend the OpenAPI generator to include your endpoints.
- Keep `request.id` middleware enabled for traceability in logs and responses.
