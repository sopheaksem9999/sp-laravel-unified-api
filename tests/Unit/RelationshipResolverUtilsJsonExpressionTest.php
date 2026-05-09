<?php

namespace Sopheak\Core\Tests\Unit;

use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Utilities\RelationshipResolverUtils;

class RelationshipResolverUtilsJsonExpressionTest extends TestCase
{
    public function test_pgsql_json_object_expression_uses_row_to_json_for_wide_tables(): void
    {
        DB::shouldReceive('getDriverName')
            ->once()
            ->andReturn('pgsql');

        $method = new ReflectionMethod(RelationshipResolverUtils::class, 'buildJsonObjectExpression');

        $columns = [];
        $schemaColumns = [];
        for ($i = 1; $i <= 51; ++$i) {
            $name = 'col_' . $i;
            $columns[] = $name;
            $schemaColumns[$name] = (object) [];
        }

        $expression = $method->invoke(null, $columns, $schemaColumns, 'vendors');

        $this->assertStringContainsString('row_to_json', $expression);
        $this->assertStringNotContainsString('json_build_object', $expression);
    }
}
