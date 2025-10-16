<?php

namespace Sopheak\Core\Console\Records;

use App\Utilities\Support\QueryBuilderFilters;
use App\Utilities\Support\RelationshipResolver;
use App\Utilities\Support\SchemaRegistry;
use Illuminate\Console\Command;

class RecordRefreshCache extends Command
{
    protected $signature = 'record:refreshCache';
    protected $description = 'Refresh record table cache';

    public function handle(): void
    {
        SchemaRegistry::clearAllCache();
        RelationshipResolver::clearSchemaCache();
        QueryBuilderFilters::clearCache();

        SchemaRegistry::get();
        $this->info('Record table cache refreshed');
    }
}
