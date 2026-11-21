<?php

namespace App\Support;

final class ReferralRating
{
    /** Keep referral points separate from the actual review rating and count. */
    public static function decorate(object $record, ?int $points = null): object
    {
        if (property_exists($record, 'rating_avg') && property_exists($record, 'rating_count') && ! property_exists($record, 'review_rating_count') && ! property_exists($record, 'review_rating_avg')) {
            $record->review_rating_avg = (int) $record->rating_count > 0 && $record->rating_avg !== null
                ? (float) $record->rating_avg : null;
        }
        if (! property_exists($record, 'review_rating_count')) {
            $record->review_rating_count = (int) ($record->rating_count ?? 0);
        }
        if ($record->review_rating_count === 0) {
            $record->review_rating_avg = null;
        }
        $record->rating_count = $record->review_rating_count;
        $record->referral_points = (int) ($points ?? $record->referral_points ?? 0);
        $record->rating_avg = $record->review_rating_count > 0 ? ($record->review_rating_avg ?? null) : null;
        $record->referral_rating = self::fromPoints($record->referral_points);

        return $record;
    }

    public static function fromPoints(int $points): float
    {
        return round(min(5.0, max(0, $points) / 200), 2);
    }
}
