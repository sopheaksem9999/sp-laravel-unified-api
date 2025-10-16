<?php

namespace Sopheak\Core\Console\Records;

use App\Utilities\Support\SchemaRegistry;
use Illuminate\Console\Command;

class GetRecordCache extends Command
{
    protected $signature = 'record:getCache';
    protected $description = 'Generate record table cache';

    public function handle(): void
    {
        SchemaRegistry::get();
    }
}
