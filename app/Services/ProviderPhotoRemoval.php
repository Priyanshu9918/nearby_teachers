<?php

namespace App\Services;

use App\Reporting\BusinessActivityRecorder;
use Illuminate\Support\Facades\DB;

final class ProviderPhotoRemoval
{
    public function remove(int $userId, ?int $actorId = null): bool
    {
        return DB::transaction(function () use ($userId, $actorId): bool {
            $user = DB::table('users')->where('id', $userId)->lockForUpdate()
                ->first(['id', 'account_type', 'avatar_url', 'avatar_status']);
            abort_if($user === null || ! in_array($user->account_type, ['teacher', 'institute'], true), 404);
            if (! filled($user->avatar_url)) {
                return false;
            }
            DB::table('users')->where('id', $userId)->update([
                'avatar_url' => null, 'avatar_status' => 'rejected', 'updated_at' => now(),
            ]);
            app(BusinessActivityRecorder::class)->record('provider.avatar_removed', $userId, $actorId, $userId,
                before: ['avatar_status' => $user->avatar_status, 'has_photo' => true],
                after: ['avatar_status' => 'rejected', 'has_photo' => false]);

            return true;
        }, 3);
    }
}
