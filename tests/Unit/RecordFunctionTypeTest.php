<?php

namespace Sopheak\Core\Tests\Unit;

use Sopheak\Core\Enums\RecordFunctionMethodEnum;
use Sopheak\Core\Enums\RecordfunctionNameEnum;
use Sopheak\Core\Types\RecordFunctionType;
use Sopheak\Core\Tests\TestCase;

class RecordFunctionTypeTest extends TestCase
{
    /** @test */
    public function it_exports_http_method_to_method_key_in_array(): void
    {
        $type = new RecordFunctionType(
            httpMethod: ['GET', 'POST'],
            class: 'App\\Services\\DummyService',
            functionName: 'handle',
        );

        $array = $type->toArray();

        $this->assertSame(['GET', 'POST'], $array['method']);
    }

    /** @test */
    public function it_accepts_enum_as_http_method_and_preserves_value(): void
    {
        $type = new RecordFunctionType(
            httpMethod: RecordFunctionMethodEnum::GET,
            class: 'App\\Services\\DummyService',
            functionName: 'handle',
        );

        $array = $type->toArray();

        $this->assertSame(RecordFunctionMethodEnum::GET, $array['method']);
    }

    /** @test */
    public function it_can_be_created_from_array_with_method_key(): void
    {
        $config = [
            'method' => ['GET'],
            'class' => 'App\\Services\\DummyService',
            'functionName' => 'handle',
        ];

        $type = RecordFunctionType::fromArray($config);

        $array = $type->toArray();

        $this->assertSame(['GET'], $array['method']);
    }
}
