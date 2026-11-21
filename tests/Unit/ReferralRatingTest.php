<?php

namespace Tests\Unit;

use App\Support\ReferralRating;
use PHPUnit\Framework\TestCase;

class ReferralRatingTest extends TestCase
{
    public function test_response_keeps_referral_points_separate_from_real_review_aggregates(): void
    {
        $record = (object) ['referral_points' => 100, 'rating_avg' => '4.2', 'rating_count' => 7];
        ReferralRating::decorate($record);
        ReferralRating::decorate($record);
        $this->assertSame(4.2, $record->rating_avg);
        $this->assertSame(4.2, $record->review_rating_avg);
        $this->assertSame(7, $record->rating_count);
        $this->assertSame(7, $record->review_rating_count);
        $this->assertSame(0.5, $record->referral_rating);

        ReferralRating::decorate($record, 107);
        $this->assertSame(4.2, $record->rating_avg);
        $this->assertSame(107, $record->referral_points);
        $this->assertSame(0.54, $record->referral_rating);
        $this->assertSame(4.2, $record->review_rating_avg);
    }

    public function test_points_rating_preserves_null_reviews_and_does_not_invent_points(): void
    {
        $record = (object) ['referral_points' => 0, 'rating_avg' => null, 'rating_count' => 0];
        ReferralRating::decorate($record);
        $this->assertNull($record->rating_avg);
        $this->assertNull($record->review_rating_avg);
        $this->assertSame(0, $record->rating_count);
        $this->assertSame(0, $record->review_rating_count);
        $this->assertSame(0.0, $record->referral_rating);
    }

    public function test_rating_scales_to_five_and_stays_within_bounds(): void
    {
        foreach ([-10 => 0.0, 0 => 0.0, 100 => 0.5, 500 => 2.5, 999 => 5.0, 1000 => 5.0, 2000 => 5.0] as $points => $expected) {
            $this->assertSame($expected, ReferralRating::fromPoints($points));
        }
    }
}
