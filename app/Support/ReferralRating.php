<?php

namespace App\Support;

final class ReferralRating
{
    public static function fromPoints(int $points): float
    {
        return round(min(5.0, max(0, $points) / 200), 2);
    }
}
