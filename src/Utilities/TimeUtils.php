<?php

namespace Sopheak\Core\Utilities;

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
                return Carbon::parse($timestamp, $timezone)->setTimezone(config('app.timezone'));
            } catch (Exception) {
            }
        }

        if ($timezone) {
            try {
                return Carbon::now($timezone)->setTimezone(config('app.timezone'));
            } catch (Exception) {
            }
        }

        return now();
    }
}
