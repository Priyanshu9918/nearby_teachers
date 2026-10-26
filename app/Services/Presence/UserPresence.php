<?php

namespace App\Services\Presence;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class UserPresence
{
    public function __construct(private readonly PresenceStore $store) {}

    public function update(object $user, string $deviceId, string $event): bool
    {
        abort_unless(config('presence.enabled'), response()->json([
            'error' => 'Presence is temporarily unavailable.', 'code' => 'PRESENCE_UNAVAILABLE',
        ], 503));
        try {
            [$code, $active] = $this->store->update((int) $user->id, (int) ($user->auth_version ?? 0), $deviceId, $event, now()->timestamp);
        } catch (Throwable $error) {
            $this->unavailable($error);
            abort(response()->json(['error' => 'Presence is temporarily unavailable.', 'code' => 'PRESENCE_UNAVAILABLE'], 503));
        }
        abort_if($code === 1, response()->json([
            'error' => 'Send foreground before activity or heartbeat.', 'code' => 'PRESENCE_FOREGROUND_REQUIRED',
        ], 409));
        abort_if($code === 2, response()->json([
            'error' => 'Too many foreground devices. Send background on an unused device or wait for its heartbeat to expire.',
            'code' => 'PRESENCE_DEVICE_LIMIT',
        ], 429));

        return (bool) $active;
    }

    /** Decorates existing ordered results; presence never changes inclusion or rank. */
    public function decorate(array|Collection $rows): array|Collection
    {
        $ids = [];
        foreach ($rows as $row) {
            $row->isActive = false;
            $ids[] = (int) $row->user_id;
        }
        if (! config('presence.enabled') || $ids === []) {
            return $rows;
        }
        $active = [];
        try {
            foreach (array_chunk(array_values(array_unique($ids)), config('presence.batch_size')) as $chunk) {
                // Current versions exclude logout/password-reset sessions immediately.
                // One query and one Redis pipeline per batch, never per result.
                $versions = DB::table('users')->whereIn('id', $chunk)->whereIn('account_type', ['teacher', 'institute'])
                    ->whereNull('suspended_at')->whereNotNull('phone_verified_at')->whereNotNull('profile_completed_at')
                    ->pluck('auth_version', 'id')->map(static fn ($version) => (int) $version)->all();
                $active += $this->store->activeFor($versions, now()->timestamp);
            }
        } catch (Throwable $error) {
            // An optional presence outage must not fail discovery or claim users active.
            $this->unavailable($error);

            return $rows;
        }
        foreach ($rows as $row) {
            $row->isActive = $active[(int) $row->user_id] ?? false;
        }

        return $rows;
    }

    private function unavailable(Throwable $error): void
    {
        // Do not log raw Redis exception messages/URLs, identifiers or credentials.
        Log::warning('Presence Redis is unavailable.', ['exception' => $error::class]);
    }
}
