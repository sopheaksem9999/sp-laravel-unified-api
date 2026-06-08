<?php

declare(strict_types=1);

namespace Sopheak\Core\Console;

use Sopheak\Core\Services\ApiClient\ApiClientEmitterInterface;
use Sopheak\Core\Services\ApiClient\BrunoEmitter;

class ExportBrunoCommand extends AbstractExportCommand
{
    protected $signature = 'sp-laravel-api:export-bruno
                            {--output= : Output file path. Defaults to api-clients/bruno/collection.bru (relative to project root).}
                            {--regen= : Comma-separated table keys to regenerate, or "all". Tables not listed are skipped if already in the collection.}
                            {--dry-run : Print the diff summary; do not write the file.}';

    protected $description = 'Export the OpenAPI spec to a Bruno v3 collection file.';

    protected function emitter(): ApiClientEmitterInterface
    {
        return new BrunoEmitter();
    }

    protected function defaultOutputPath(): string
    {
        return 'api-clients/bruno/collection.bru';
    }

    protected function formatName(): string
    {
        return 'Bruno';
    }
}
