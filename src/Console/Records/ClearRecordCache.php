<?php

namespace Sopheak\Core\Console\Records;

use App\Utilities\Support\SchemaRegistry;
use App\Utilities\Support\RelationshipResolver;
use App\Utilities\Support\QueryBuilderFilters;
use Illuminate\Console\Command;

class ClearRecordCache extends Command
{
    protected $signature = 'record:cacheClear';
    protected $description = 'Clear record table cache';

    public function handle(): void
    {
        SchemaRegistry::clearAllCache();
        RelationshipResolver::clearSchemaCache();
        QueryBuilderFilters::clearCache();
    }
}
