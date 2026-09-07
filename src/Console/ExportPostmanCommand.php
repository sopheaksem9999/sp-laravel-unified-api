<?php

declare(strict_types=1);

namespace Sopheak\Core\Console;

use Sopheak\Core\Services\ApiClient\ApiClientEmitterInterface;
use Sopheak\Core\Services\ApiClient\PostmanEmitter;

class ExportPostmanCommand extends AbstractExportCommand
{
    protected $signature = 'sp-laravel-api:export-postman
                            {--output= : Output file path. Defaults to api-client/postman/collection.json (relative to project root).}
                            {--regen= : Comma-separated table keys to regenerate, or "all". Tables not listed are skipped if already in the collection.}
                            {--force : Regenerate all package-generated requests and collection metadata.}
                            {--dry-run : Print the diff summary; do not write the file.}';

    protected $description = 'Export the OpenAPI spec to a Postman v2.1 collection file.';

    protected function emitter(): ApiClientEmitterInterface
    {
        return new PostmanEmitter();
    }

    protected function defaultOutputPath(): string
    {
        return 'api-client/postman/collection.json';
    }

    protected function formatName(): string
    {
        return 'Postman';
    }
}
