<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Sopheak\Core\Enums\RecordSchemaTypeEnum;
use Illuminate\Support\Facades\Config;
use ReflectionMethod;
use Sopheak\Core\Attributes\RecordFunction;
use Sopheak\Core\Services\OpenApiService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordFunctionType;
use Sopheak\Core\Types\RecordSchemaType as S;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class SchemaTypedFunctions
{
    /**
     * @return array{}
     */
    #[RecordFunction(
        table: 'widgets',
        httpMethod: 'POST',
        payloadSchema: new S(RecordSchemaTypeEnum::OBJECT, properties: ['sku' => new S(RecordSchemaTypeEnum::STRING)], required: ['sku']),
    )]
    public function restock(): array
    {
        return [];
    }
}

class RecordSchemaTypeInFunctionsTest extends TestCase
{
    public function test_an_attribute_can_carry_a_typed_schema(): void
    {
        $attribute = (new ReflectionMethod(SchemaTypedFunctions::class, 'restock'))->getAttributes(RecordFunction::class)[0]->newInstance();
        $function = new RecordFunctionType(httpMethod: 'POST', class: SchemaTypedFunctions::class, functionName: 'restock', payloadSchema: $attribute->payloadSchema);

        $this->assertSame(['type' => 'object', 'properties' => ['sku' => ['type' => 'string']], 'required' => ['sku']], $function->payloadSchema);
    }

    public function test_openapi_documents_a_typed_payload_schema(): void
    {
        Config::set('record.tables', [
            'widgets' => new RecordTableType(table: 'widgets', pmsName: 'widgets', public: new RecordTablePublic(read: true, write: true), functions: [
                'restock' => new RecordFunctionType(
                    httpMethod: 'POST',
                    class: SchemaTypedFunctions::class,
                    functionName: 'restock',
                    payloadSchema: S::object(['sku' => S::string('Stock unit'), 'qty' => S::integer(minimum: 1)], ['sku', 'qty']),
                ),
            ]),
        ]);
        SchemaRegistryUtils::refresh();

        $spec = (new OpenApiService())->generateInternal();
        $found = null;
        foreach ($spec['paths'] as $path => $operations) {
            if (str_contains((string) $path, 'restock')) {
                $found = $operations['post']['requestBody']['content']['application/json']['schema'] ?? null;
            }
        }

        $this->assertNotNull($found);
        $this->assertSame(['sku', 'qty'], $found['required']);
        $this->assertSame(['type' => 'integer', 'minimum' => 1], $found['properties']['qty']);
        $this->assertSame('Stock unit', $found['properties']['sku']['description']);
    }
}
