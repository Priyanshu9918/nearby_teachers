<?php

namespace App\Reporting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SearchAnalyticsRecorder
{
    public function record(
        string $searchType,
        string $locationType,
        ?string $location,
        ?int $subjectId,
        int $resultCount,
        ?float $latitude = null,
        ?float $longitude = null,
        ?float $radiusKm = null,
        bool $freeOnly = false,
    ): void {
        $location = $this->safeLocation($location);
        DB::table('search_analytics')->insert([
            'search_type' => $searchType,
            'location_type' => $locationType,
            'location' => $location,
            'latitude_band' => $latitude === null ? null : round($latitude, 1),
            'longitude_band' => $longitude === null ? null : round($longitude, 1),
            'radius_km' => $radiusKm,
            'subject_id' => $subjectId,
            'result_count' => max(0, $resultCount),
            'free_only' => $freeOnly,
            'created_at' => now(),
        ]);
    }

    private function safeLocation(?string $location): ?string
    {
        if ($location === null || trim($location) === '') {
            return null;
        }

        // Location filters are useful for recruitment planning; free-text names/queries are never stored.
        $location = preg_replace('/[^\p{L}\p{N}\s.,\'-]/u', '', Str::squish($location)) ?? '';

        return $location === '' ? null : mb_substr($location, 0, 160, 'UTF-8');
    }
}
