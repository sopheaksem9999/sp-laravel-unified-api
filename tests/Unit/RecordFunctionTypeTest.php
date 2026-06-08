<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use Sopheak\Core\Enums\RecordFunctionMethodEnum;
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

        $this->assertSame(['GET', 'POST'], $array['httpMethod']);
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

        $this->assertSame(RecordFunctionMethodEnum::GET, $array['httpMethod']);
    }

    /** @test */
    public function it_can_be_created_from_array_with_method_key(): void
    {
        $config = [
            'httpMethod' => ['GET'],
            'class' => 'App\\Services\\DummyService',
            'functionName' => 'handle',
        ];

        $type = RecordFunctionType::fromArray($config);

        $array = $type->toArray();

        $this->assertSame(['GET'], $array['httpMethod']);
    }

    /** @test */
    public function it_defaults_middleware_to_null(): void
    {
        $type = new RecordFunctionType(
            httpMethod: 'POST',
            class: 'App\\Services\\DummyService',
            functionName: 'handle',
        );

        $this->assertNull($type->middleware);
    }

    /** @test */
    public function it_accepts_array_middleware(): void
    {
        $type = new RecordFunctionType(
            httpMethod: 'POST',
            class: 'App\\Services\\DummyService',
            functionName: 'handle',
            middleware: ['auth:sanctum', 'throttle:10,1'],
        );

        $this->assertSame(['auth:sanctum', 'throttle:10,1'], $type->middleware);
    }

    /** @test */
    public function it_accepts_string_middleware(): void
    {
        $type = new RecordFunctionType(
            httpMethod: 'POST',
            class: 'App\\Services\\DummyService',
            functionName: 'handle',
            middleware: 'auth:sanctum',
        );

        $this->assertSame('auth:sanctum', $type->middleware);
    }

    /** @test */
    public function it_accepts_empty_array_middleware_as_explicit_opt_out(): void
    {
        $type = new RecordFunctionType(
            httpMethod: 'POST',
            class: 'App\\Services\\DummyService',
            functionName: 'handle',
            middleware: [],
        );

        // Empty array is non-null — it is an explicit "run with no middleware"
        $this->assertSame([], $type->middleware);
        $this->assertNotNull($type->middleware);
    }

    /** @test */
    public function it_includes_middleware_in_to_array_when_set(): void
    {
        $type = new RecordFunctionType(
            httpMethod: 'POST',
            class: 'App\\Services\\DummyService',
            functionName: 'handle',
            middleware: ['auth:sanctum'],
        );

        $this->assertArrayHasKey('middleware', $type->toArray());
        $this->assertSame(['auth:sanctum'], $type->toArray()['middleware']);
    }

    /** @test */
    public function it_includes_null_middleware_key_in_to_array(): void
    {
        // middleware is emitted unconditionally (like clearCacheTables), NOT conditionally
        // (unlike description/querySchema which are omitted when null).
        $type = new RecordFunctionType(
            httpMethod: 'POST',
            class: 'App\\Services\\DummyService',
            functionName: 'handle',
        );

        $array = $type->toArray();
        $this->assertArrayHasKey('middleware', $array);
        $this->assertNull($array['middleware']);
    }

    /** @test */
    public function it_round_trips_middleware_through_from_array(): void
    {
        $type = new RecordFunctionType(
            httpMethod: 'POST',
            class: 'App\\Services\\DummyService',
            functionName: 'handle',
            middleware: ['auth:sanctum'],
        );

        $restored = RecordFunctionType::fromArray($type->toArray());

        $this->assertSame(['auth:sanctum'], $restored->middleware);
    }

    /** @test */
    public function it_round_trips_null_middleware_through_from_array(): void
    {
        $type = new RecordFunctionType(
            httpMethod: 'POST',
            class: 'App\\Services\\DummyService',
            functionName: 'handle',
        );

        $restored = RecordFunctionType::fromArray($type->toArray());

        $this->assertNull($restored->middleware);
    }

    /** @test */
    public function it_restores_middleware_via_set_state(): void
    {
        $original = new RecordFunctionType(
            httpMethod: 'POST',
            class: 'App\\Services\\DummyService',
            functionName: 'handle',
            middleware: ['auth:sanctum'],
        );

        $exported = var_export($original, true);
        $restored = eval('return ' . $exported . ';');

        $this->assertInstanceOf(RecordFunctionType::class, $restored);
        $this->assertSame(['auth:sanctum'], $restored->middleware);
    }

    /** @test */
    public function it_restores_null_middleware_via_set_state_when_absent_from_cached_config(): void
    {
        // Simulates a config:cache payload generated before the middleware field existed.
        $restored = RecordFunctionType::__set_state([
            'httpMethod'       => 'POST',
            'class'            => 'App\\Services\\DummyService',
            'functionName'     => 'handle',
            'isPublic'         => false,
            'pmsName'          => null,
            'disableCache'     => false,
            'cacheTTL'         => null,
            'description'      => null,
            'querySchema'      => null,
            'payloadSchema'    => null,
            'responseSchema'   => null,
            'clearCacheTables' => null,
            // 'middleware' intentionally absent
        ]);

        $this->assertNull($restored->middleware);
    }
}
