<?php

namespace App\Support;

final class ReferralRating
{
    /** Present the account's point score without changing stored review aggregates. */
    public static function decorate(object $record, ?int $points = null): object
    {
        if (property_exists($record, 'rating_avg') && property_exists($record, 'rating_count') && ! property_exists($record, 'review_rating_count') && ! property_exists($record, 'review_rating_avg')) {
            $record->review_rating_avg = $record->rating_avg === null ? null : (float) $record->rating_avg;
        }
        if (! property_exists($record, 'review_rating_count')) {
            $record->review_rating_count = (int) ($record->rating_count ?? 0);
        }
        $record->rating_count = 100 + $record->review_rating_count;
        $record->referral_points = (int) ($points ?? $record->referral_points ?? 0);
        $record->rating_avg = $record->referral_points;
        $record->referral_rating = self::fromPoints($record->referral_points);

        return $record;
    }

    public static function fromPoints(int $points): float
    {
        return round(min(5.0, max(0, $points) / 200), 2);
    }
}
