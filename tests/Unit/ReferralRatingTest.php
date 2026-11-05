<?php

namespace Tests\Unit;

use App\Support\ReferralRating;
use PHPUnit\Framework\TestCase;

class ReferralRatingTest extends TestCase
{
    public function test_rating_scales_to_five_and_stays_within_bounds(): void
    {
        foreach ([-10 => 0.0, 0 => 0.0, 100 => 0.5, 500 => 2.5, 999 => 5.0, 1000 => 5.0, 2000 => 5.0] as $points => $expected) {
            $this->assertSame($expected, ReferralRating::fromPoints($points));
        }
    }
}
