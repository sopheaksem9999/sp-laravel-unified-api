<?php

namespace Sopheak\Core\Console\Records;

use Sopheak\Core\Support\SchemaRegistry;
use Sopheak\Core\Support\RelationshipResolver;
use Sopheak\Core\Support\QueryBuilderFilters;
use Illuminate\Console\Command;

class ClearRecordCache extends Command
{
    protected $signature = 'sp-laravel-api:cache-clear';
    protected $description = 'Clear record table cache';

    public function handle(): void
    {
        SchemaRegistry::clearAllCache();
        RelationshipResolver::clearSchemaCache();
        QueryBuilderFilters::clearCache();
    }
}
