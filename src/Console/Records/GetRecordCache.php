<?php

namespace Sopheak\Core\Console\Records;

use Sopheak\Core\Support\SchemaRegistry;
use Illuminate\Console\Command;

class GetRecordCache extends Command
{
    protected $signature = 'sp-laravel-api:cache-generate';
    protected $description = 'Generate record table cache';

    public function handle(): void
    {
        SchemaRegistry::get();
    }
}
