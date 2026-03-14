<?php

namespace Sopheak\Core\Utilities;

use Carbon\Month;
use Carbon\WeekDay;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TimeUtils
{
    public static function now(?Request $request = null): Carbon
    {
        $request ??= request();
        $timezone = null;
        $timestamp = null;

        if ($request) {
            $timezone = $request->header('X-Timezone') ?: config('app.timezone');
            $timestamp = $request->header('X-Timestamp');
        }

        if ($timestamp && $timezone) {
            try {
                return Carbon::parse($timestamp, $timezone);
            } catch (Exception) {
            }
        }

        if ($timezone) {
            try {
                return Carbon::now($timezone);
            } catch (Exception) {
            }
        }

        return now();
    }

    public static function parse(DateTimeInterface|WeekDay|Month|string|int|float|null $time, DateTimeZone|string|int|null $timezone = null): Carbon {
        return Carbon::parse($time, $timezone);
    }
}
