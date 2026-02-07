<?php

namespace Sopheak\Core\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Utilities\TimeUtils;

class TimeUtilsTest extends TestCase
{
    /** @test */
    public function it_uses_timestamp_header_when_present(): void
    {
        Config::set('app.timezone', 'UTC');

        $request = Request::create('/test', 'GET');
        $request->headers->set('X-Timezone', 'UTC');
        $request->headers->set('X-Timestamp', '2025-01-01T10:00:00+00:00');

        $result = TimeUtils::now($request);

        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertSame(Carbon::parse('2025-01-01T10:00:00+00:00')->toISOString(), $result->toISOString());
    }

    /** @test */
    public function it_falls_back_to_timezone_when_timestamp_missing(): void
    {
        Config::set('app.timezone', 'UTC');

        $fixedNow = Carbon::parse('2025-02-01T03:00:00+00:00');
        Carbon::setTestNow($fixedNow);

        $request = Request::create('/test', 'GET');
        $request->headers->set('X-Timezone', 'UTC');

        $result = TimeUtils::now($request);

        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertSame($fixedNow->toISOString(), $result->toISOString());

        Carbon::setTestNow();
    }
}
