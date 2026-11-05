<?php

namespace App\Services;

use App\Reporting\BusinessActivityRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BslInstitute
{
    public function set(int $userId, bool $enabled, ?int $actorId = null): void
    {
        DB::transaction(function () use ($userId, $enabled, $actorId): void {
            // Serialize activation and catalog synchronization on the same existing row.
            DB::table('institute_profiles')->orderBy('id')->lockForUpdate()->get(['id']);
            $profile = DB::table('institute_profiles')->where('user_id', $userId)->first();
            abort_if($profile === null, 404);
            if ($enabled && DB::table('institute_profiles')->where('is_bsl', true)->where('user_id', '!=', $userId)->exists()) {
                throw ValidationException::withMessages(['is_bsl' => 'Another BSL institute is already active. Disable it first.']);
            }
            DB::table('institute_profiles')->where('id', $profile->id)->update(['is_bsl' => $enabled, 'updated_at' => now()]);
            if ((bool) $profile->is_bsl !== $enabled) {
                app(BusinessActivityRecorder::class)->record('institute.bsl_search_changed', $profile->id, $actorId, $userId,
                    before: ['is_bsl' => (bool) $profile->is_bsl], after: ['is_bsl' => $enabled]);
            }
            if ($enabled) {
                $normalIds = DB::table('profile_subjects as ps')
                    ->join('subjects as s', 's.id', '=', 'ps.subject_id')
                    ->where('ps.profile_type', 'institute')->where('ps.profile_id', $userId)
                    ->where('s.is_bsl', false)->orderBy('s.id')->pluck('s.id');
                if ($normalIds->isNotEmpty()) {
                    DB::table('profile_subjects')->where('profile_type', 'institute')
                        ->where('profile_id', $userId)->whereIn('subject_id', $normalIds)->delete();
                    app(BusinessActivityRecorder::class)->record('institute.bsl_subjects_removed', $profile->id, $actorId, $userId,
                        before: ['subject_ids' => $normalIds->all()]);
                }
                $this->syncSubjects($actorId);
            }
        });
    }

    public function syncSubjects(?int $actorId = null): void
    {
        DB::transaction(function () use ($actorId): void {
            DB::table('institute_profiles')->orderBy('id')->lockForUpdate()->get(['id']);
            $profile = DB::table('institute_profiles')->where('is_bsl', true)->first();
            if (! $profile) {
                return;
            }
            $assigned = DB::table('profile_subjects')->where('profile_type', 'institute')->where('profile_id', $profile->user_id)->pluck('subject_id');
            $missing = DB::table('subjects')->where('is_bsl', true)->whereNotIn('id', $assigned)->orderBy('id')->pluck('id');
            foreach ($missing as $id) {
                DB::table('profile_subjects')->insertOrIgnore(['profile_type' => 'institute', 'profile_id' => $profile->user_id, 'subject_id' => $id]);
            }
            if ($missing->isNotEmpty()) {
                app(BusinessActivityRecorder::class)->record('institute.bsl_subjects_assigned', $profile->id, $actorId, $profile->user_id,
                    after: ['subject_ids' => $missing->all()]);
            }
        });
    }
}
