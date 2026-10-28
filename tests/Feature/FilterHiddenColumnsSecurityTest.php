<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Support\Facades\Config;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\QueryBuilderFiltersUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class FilterHiddenColumnsSecurityTest extends TestCase
{
    /** @test */
    public function allowed_query_columns_exclude_hidden_columns(): void
    {
        Config::set('record.tables.users', new RecordTableType(
            table: 'users',
            hasTenantId: false,
            columns: [
                'id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'password' => ['type' => 'string'],
            ],
            columnHiddens: ['password']
        ));
        SchemaRegistryUtils::refresh();

        $allowed = QueryBuilderFiltersUtils::getFilterableColumns('users');
        $this->assertNotContains('password', $allowed);
        $this->assertContains('id', $allowed);
        $this->assertContains('name', $allowed);
    }
}
