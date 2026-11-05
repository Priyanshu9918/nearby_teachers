<?php

namespace App\Services;

use App\Reporting\BusinessActivityRecorder;
use Illuminate\Support\Facades\DB;

final class RegistrationPoints
{
    public function awardExisting(int $userId): void
    {
        DB::transaction(function () use ($userId): void {
            $user = DB::table('users')->where('id', $userId)->lockForUpdate()->first(['id', 'referral_points']);
            if (! $user || DB::table('business_activity')->where('event', 'user.registration_points_granted')
                ->where('entity_type', 'user')->where('entity_id', $userId)->exists()) {
                return;
            }
            DB::table('users')->where('id', $userId)->increment('referral_points', 100);
            app(BusinessActivityRecorder::class)->record('user.registration_points_granted', $userId, null, $userId,
                before: ['referral_points' => (int) $user->referral_points],
                after: ['referral_points' => (int) $user->referral_points + 100, 'point_delta' => 100]);
        });
    }
}
