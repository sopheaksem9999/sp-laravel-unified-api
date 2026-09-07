<?php

declare(strict_types=1);

namespace Sopheak\Core\Console;

use Sopheak\Core\Services\ApiClient\ApiClientEmitterInterface;
use Sopheak\Core\Services\ApiClient\BrunoEmitter;

class ExportBrunoCommand extends AbstractExportCommand
{
    protected $signature = 'sp-laravel-api:export-bruno
                            {--output= : Output folder path. Defaults to api-client/bruno (relative to project root).}
                            {--regen= : Comma-separated table keys to regenerate, or "all". Tables not listed are skipped if already in the collection.}
                            {--force : Regenerate all package-generated requests and collection support files.}
                            {--dry-run : Print the diff summary; do not write files.}';

    protected $description = 'Export the OpenAPI spec to a Bruno collection folder with sub-folders for each table.';

    protected function emitter(): ApiClientEmitterInterface
    {
        return new BrunoEmitter();
    }

    protected function defaultOutputPath(): string
    {
        return 'api-client/bruno';
    }

    protected function formatName(): string
    {
        return 'Bruno';
    }
}
