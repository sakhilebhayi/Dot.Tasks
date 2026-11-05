<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * What day it is for the business in front of us. Timestamps stay in the app's own time zone, so this changes which
 * date counts as today, and nothing that is stored.
 */
class Today
{
    private static ?string $timezone = null;

    public static function use(?string $timezone): void
    {
        self::$timezone = $timezone;
    }

    /** Midnight at the start of today there, as a plain date. */
    public static function date(): Carbon
    {
        return Carbon::parse(Carbon::now(self::$timezone)->toDateString());
    }
}
