<?php

namespace App\Services;

use App\Reporting\BusinessActivityRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

final class TeacherLocation
{
    public static function validatePair(array $data): void
    {
        if (array_key_exists('latitude', $data) !== array_key_exists('longitude', $data)
            || (array_key_exists('latitude', $data) && (($data['latitude'] === null) !== ($data['longitude'] === null)))) {
            throw ValidationException::withMessages(['location' => 'Latitude and longitude must both be supplied or both be empty.']);
        }
    }

    public function prepare(array $data): ?array
    {
        self::validatePair($data);
        if (! array_key_exists('latitude', $data)) {
            return null;
        }
        $latitude = $data['latitude'] === null ? null : (float) $data['latitude'];
        $longitude = $data['longitude'] === null ? null : (float) $data['longitude'];

        return ['latitude' => $latitude, 'longitude' => $longitude,
            'details' => $latitude === null ? null : app(GoogleReverseGeocoder::class)->lookup($latitude, $longitude)];
    }

    public function store(int $userId, ?array $prepared): void
    {
        if ($prepared === null) {
            return;
        }
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Store teacher location in the source transaction.');
        }
        $before = DB::table('teacher_profiles')->where('user_id', $userId)->lockForUpdate()->first();
        abort_if($before === null, 404, 'Teacher profile not found');
        $old = $before->location_details === null ? null : json_decode($before->location_details, true, flags: JSON_THROW_ON_ERROR);
        DB::table('teacher_profiles')->where('user_id', $userId)->update([
            'latitude' => $prepared['latitude'], 'longitude' => $prepared['longitude'],
            'location_details' => $prepared['details'] === null ? null : json_encode($prepared['details'], JSON_THROW_ON_ERROR),
        ]);
        app(BusinessActivityRecorder::class)->record('teacher.location_updated', $userId, $userId,
            before: ['has_coordinates' => $before->latitude !== null && $before->longitude !== null,
                'geocoding_status' => $old['status'] ?? null],
            after: ['has_coordinates' => $prepared['latitude'] !== null, 'geocoding_status' => $prepared['details']['status'] ?? 'cleared']);
    }
}
