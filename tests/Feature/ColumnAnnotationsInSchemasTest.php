<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Support\Facades\Config;
use Sopheak\Core\Enums\RecordColumnTypeEnum as T;
use Sopheak\Core\Mcp\SchemaTools;
use Sopheak\Core\Services\OpenApiService;
use Sopheak\Core\Tests\Concerns\ResolvesRefs;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordColumnType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class ColumnAnnotationsInSchemasTest extends TestCase
{
    use ResolvesRefs;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('record.tables', [
            'customers' => new RecordTableType(table: 'customers', pmsName: 'customers', public: new RecordTablePublic(read: true, write: true), columns: [
                'id' => new RecordColumnType(type: T::BIGINT, nullable: false, primary: true, autoIncrement: true),
                'name' => new RecordColumnType(type: T::STRING, nullable: false, maxLength: 120, description: 'Legal name', example: 'Acme Ltd'),
            ]),
        ]);
        SchemaRegistryUtils::refresh();
    }

    public function test_openapi_column_properties_carry_description_example_and_max_length(): void
    {
        $spec = (new OpenApiService())->generateInternal();
        $found = null;
        foreach ($spec['components']['schemas'] ?? [] as $schema) {
            if (isset($schema['properties']['name']['description'])) {
                $found = $schema['properties']['name'];
                break;
            }
        }

        $this->assertNotNull($found, 'a customers schema exposes the name column');
        $this->assertSame('Legal name', $found['description']);
        $this->assertSame('Acme Ltd', $found['example']);
        $this->assertSame(120, $found['maxLength']);
    }

    public function test_mcp_fields_carry_description_and_example(): void
    {
        $fields = $this->resolveRefs(app(SchemaTools::class)->getEndpoint(['endpoint' => 'customers']))['fields'];
        $name = collect($fields)->firstWhere('name', 'name');

        $this->assertSame('Legal name', $name['description']);
        $this->assertSame('Acme Ltd', $name['example']);
        $this->assertSame(120, $name['maxLength']);
    }
}
