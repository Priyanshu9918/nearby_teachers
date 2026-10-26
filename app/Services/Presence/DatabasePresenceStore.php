<?php

namespace App\Services\Presence;

use Illuminate\Support\Facades\DB;

final class DatabasePresenceStore implements PresenceStore
{
    public function update(int $userId, int $authVersion, string $deviceId, string $event, int $now): array
    {
        return DB::transaction(function () use ($userId, $authVersion, $deviceId, $event, $now): array {
            // One stable lock serializes the user's devices, logout and token
            // invalidation. Never touch users.updated_at for presence traffic.
            $user = DB::table('users')->where('id', $userId)->lockForUpdate()
                ->first(['auth_version', 'suspended_at']);
            if ($user === null || (int) $user->auth_version !== $authVersion || $user->suspended_at !== null) {
                return [3, 0];
            }
            DB::table('user_presence_devices')->where('user_id', $userId)
                ->where(fn ($query) => $query->where('expires_at', '<=', $now)->orWhere('auth_version', '!=', $authVersion))
                ->delete();
            $devices = DB::table('user_presence_devices')->where('user_id', $userId)
                ->limit(config('presence.max_devices') + 1)->get()->keyBy('device_hash');
            $hash = hash('sha256', $deviceId);
            $stored = $devices->get($hash);

            if ($event === 'background') {
                if ($stored !== null) {
                    DB::table('user_presence_devices')->where('id', $stored->id)->delete();
                    $devices->forget($hash);
                }
            } else {
                if ($stored === null && $event !== 'foreground') {
                    return [1, (int) $devices->contains(fn ($device) => $device->active_until > $now)];
                }
                if ($stored === null && $devices->count() >= config('presence.max_devices')) {
                    return [2, (int) $devices->contains(fn ($device) => $device->active_until > $now)];
                }
                $activityAt = $event === 'heartbeat' ? (int) $stored->activity_at : $now;
                $state = [
                    'activity_at' => $activityAt, 'heartbeat_at' => $now,
                    'active_until' => min($activityAt + config('presence.idle_seconds'), $now + config('presence.heartbeat_seconds')),
                    'expires_at' => $now + config('presence.heartbeat_seconds'),
                ];
                if ($stored === null) {
                    DB::table('user_presence_devices')->insert([
                        'user_id' => $userId, 'auth_version' => $authVersion, 'device_hash' => $hash, ...$state,
                    ]);
                } elseif ((int) $stored->heartbeat_at !== $now || (int) $stored->activity_at !== $activityAt) {
                    // Duplicate events in the same second need no write.
                    DB::table('user_presence_devices')->where('id', $stored->id)->update($state);
                }
                $devices->put($hash, (object) $state);
            }

            return [0, (int) $devices->contains(fn ($device) => $device->active_until > $now)];
        }, 3);
    }

    public function activeFor(array $versions, int $now): array
    {
        $flags = array_fill_keys(array_keys($versions), false);
        if ($versions === []) {
            return $flags;
        }
        // One indexed query per bounded batch; no per-provider reads/writes.
        $devices = DB::table('user_presence_devices as p')
            ->join('users as u', fn ($join) => $join->on('u.id', '=', 'p.user_id')->on('u.auth_version', '=', 'p.auth_version'))
            ->whereIn('p.user_id', array_keys($versions))->where('p.active_until', '>', $now)
            ->whereNull('u.suspended_at')->select('p.user_id', 'p.auth_version')->distinct()->get();
        foreach ($devices as $device) {
            $flags[$device->user_id] = (int) $device->auth_version === $versions[$device->user_id];
        }

        return $flags;
    }

    public function prune(int $now, int $batchSize, int $maxBatches): int
    {
        $total = 0;
        for ($batch = 0; $batch < $maxBatches; $batch++) {
            // Single indexed DELETE with a fixed ceiling; no offset scan,
            // table truncate, or unbounded transaction.
            $deleted = DB::table('user_presence_devices')->where('expires_at', '<=', $now)
                ->orderBy('expires_at')->orderBy('id')->limit($batchSize)->delete();
            $total += $deleted;
            if ($deleted < $batchSize) {
                break;
            }
        }

        return $total;
    }
}
