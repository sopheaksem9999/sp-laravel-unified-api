<?php

namespace Sopheak\Core\Tests\Fixtures;

use Closure;
use Illuminate\Http\Request;
use Sopheak\Core\Utilities\TimeUtils;

class RequestTimestampTestMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $existing = $request->headers->get('X-Timestamp');

        if (!$existing) {
            $current = TimeUtils::now($request)->toISOString();
            $request->headers->set('X-Timestamp', $current);
            $request->attributes->set('request_timestamp', $current);
        } else {
            $request->attributes->set('request_timestamp', $existing);
        }

        $response = $next($request);
        $response->headers->set('X-Timestamp', $request->headers->get('X-Timestamp'));

        return $response;
    }
}

