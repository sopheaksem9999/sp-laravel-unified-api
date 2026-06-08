<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Http\Request;
use Sopheak\Core\Tests\Fixtures\RequestTimestampTestMiddleware;
use Sopheak\Core\Tests\TestCase;

class RequestTimestampMiddlewareTest extends TestCase
{
    /** @test */
    public function it_adds_timestamp_header_when_missing(): void
    {
        app('router')->get('/test-timestamp', fn(Request $request) => response()->json([
            'header' => $request->headers->get('X-Timestamp'),
            'attribute' => $request->attributes->get('request_timestamp'),
        ]))->middleware(RequestTimestampTestMiddleware::class);

        $response = $this->getJson('/test-timestamp');

        $response->assertStatus(200);
        $this->assertNotNull($response->headers->get('X-Timestamp'));
        $response->assertJsonStructure([
            'header',
            'attribute',
        ]);
    }

    /** @test */
    public function it_preserves_existing_timestamp_header(): void
    {
        app('router')->get('/test-timestamp-existing', fn(Request $request) => response()->json([
            'header' => $request->headers->get('X-Timestamp'),
            'attribute' => $request->attributes->get('request_timestamp'),
        ]))->middleware(RequestTimestampTestMiddleware::class);

        $expected = '2025-03-01T12:34:56+00:00';

        $response = $this->getJson('/test-timestamp-existing', [
            'X-Timestamp' => $expected,
        ]);

        $response->assertStatus(200);
        $this->assertSame($expected, $response->headers->get('X-Timestamp'));
        $response->assertJson([
            'header' => $expected,
            'attribute' => $expected,
        ]);
    }
}
