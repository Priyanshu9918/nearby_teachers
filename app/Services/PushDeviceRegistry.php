<?php

namespace App\Services;

use App\Reporting\BusinessActivityRecorder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PushDeviceRegistry
{
    public function register(object $user, array $data): array
    {
        $fidHash = $this->fingerprint($data['firebaseInstallationId']);
        $location = $this->locationFor($user, $data);

        return DB::transaction(function () use ($user, $data, $fidHash, $location): array {
            $device = DB::table('push_devices')->where('fid_hash', $fidHash)->lockForUpdate()->first();
            $now = now();
            $id = $device?->id ?? (string) Str::uuid();
            $hasLocation = $location['city'] !== null || $location['state'] !== null || $location['country'] !== null;
            $locationChanged = $device === null
                || $device->location_city !== $location['city']
                || $device->location_state !== $location['state']
                || $device->location_country !== $location['country']
                || (bool) $device->location_consent !== $location['consent']
                || $device->location_source !== $location['source'];
            $changed = $device === null
                || $device->user_id !== (string) $user->id
                || $device->platform !== $data['platform']
                || $locationChanged
                || $device->disabled_at !== null;
            $values = [
                'user_id' => (string) $user->id,
                'fid_hash' => $fidHash,
                'fid_ciphertext' => Crypt::encryptString($data['firebaseInstallationId']),
                'platform' => $data['platform'],
                'location_city' => $location['city'],
                'location_state' => $location['state'],
                'location_country' => $location['country'],
                'location_consent' => $location['consent'],
                'location_source' => $location['source'],
                'last_registered_at' => $now,
                'last_seen_at' => $now,
                'disabled_at' => null,
                'updated_at' => $now,
            ];

            if ($device === null) {
                DB::table('push_devices')->insert(['id' => $id, ...$values, 'created_at' => $now]);
            } else {
                DB::table('push_devices')->where('id', $id)->update($values);
            }

            if ($changed) {
                app(BusinessActivityRecorder::class)->record(
                    'push.device_registered', $id, (string) $user->id,
                    before: [], after: ['platform' => $data['platform'], 'has_location' => $hasLocation,
                        'location_changed' => $locationChanged, 'location_source' => $location['source']],
                );
            }

            return ['deviceId' => $id, 'registered' => true];
        });
    }

    public function unregister(object $user, string $firebaseInstallationId): bool
    {
        $fidHash = $this->fingerprint($firebaseInstallationId);

        return DB::transaction(function () use ($user, $fidHash): bool {
            $device = DB::table('push_devices')->where('fid_hash', $fidHash)
                ->where('user_id', (string) $user->id)->lockForUpdate()->first();
            if ($device === null || $device->disabled_at !== null) {
                return false;
            }

            DB::table('push_devices')->where('id', $device->id)->update([
                'fid_ciphertext' => null,
                'location_city' => null, 'location_state' => null, 'location_country' => null,
                'location_consent' => false, 'location_source' => null, 'disabled_at' => now(), 'updated_at' => now(),
            ]);
            app(BusinessActivityRecorder::class)->record('push.device_unregistered', $device->id, (string) $user->id,
                before: [], after: ['platform' => $device->platform]);

            return true;
        });
    }

    public function disableInvalidFid(string $deviceId): void
    {
        DB::transaction(function () use ($deviceId): void {
            $device = DB::table('push_devices')->where('id', $deviceId)->lockForUpdate()->first();
            if ($device === null || $device->disabled_at !== null) {
                return;
            }

            DB::table('push_devices')->where('id', $deviceId)->update([
                'fid_ciphertext' => null,
                'location_city' => null, 'location_state' => null, 'location_country' => null,
                'location_consent' => false, 'location_source' => null, 'disabled_at' => now(), 'updated_at' => now(),
            ]);
            app(BusinessActivityRecorder::class)->record('push.device_fid_invalidated', $deviceId, null,
                before: [], after: ['reason' => 'fcm_unregistered']);
        });
    }

    private function locationFor(object $user, array $data): array
    {
        if (($user->account_type ?? null) === 'teacher') {
            $profile = DB::table('teacher_profiles')->where('user_id', $user->id)->first(['city', 'state', 'country']);
            if ($profile !== null) {
                return ['city' => $this->cleanLocation($profile->city), 'state' => $this->cleanLocation($profile->state), 'country' => $this->cleanLocation($profile->country),
                    'consent' => false, 'source' => 'profile'];
            }
        }
        if (($user->account_type ?? null) === 'institute') {
            $profile = DB::table('institute_profiles')->where('user_id', $user->id)->first(['city', 'state', 'country']);
            if ($profile !== null) {
                return ['city' => $this->cleanLocation($profile->city), 'state' => $this->cleanLocation($profile->state), 'country' => $this->cleanLocation($profile->country),
                    'consent' => false, 'source' => 'profile'];
            }
        }

        $consented = (bool) ($data['locationConsent'] ?? false);

        return [
            'city' => $consented ? $this->cleanLocation($data['locationCity'] ?? null) : null,
            'state' => $consented ? $this->cleanLocation($data['locationState'] ?? null) : null,
            'country' => $consented ? $this->cleanLocation($data['locationCountry'] ?? null) : null,
            'consent' => $consented,
            'source' => $consented ? 'learner_consent' : null,
        ];
    }

    private function fingerprint(string $value): string
    {
        return hash_hmac('sha256', trim($value), (string) config('app.key'));
    }

    private function cleanLocation(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
