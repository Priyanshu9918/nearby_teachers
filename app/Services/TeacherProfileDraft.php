<?php

namespace App\Services;

use App\Reporting\BusinessActivityRecorder;
use Illuminate\Support\Facades\DB;
use LogicException;

final class TeacherProfileDraft
{
    /** Called inside the section mutation transaction; the lock prevents lost concurrent saves. */
    public function saveStep(int $userId, int $step): array
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Save draft progress inside the profile transaction.');
        }
        if ($step < 1 || $step > 5) {
            throw new LogicException('Only profile editor steps 1 to 5 can be saved.');
        }
        $profile = DB::table('teacher_profiles')->where('user_id', $userId)->lockForUpdate()->first();
        abort_if($profile === null, 404, 'Teacher profile not found.');
        $before = self::forProfile($profile);
        $steps = self::steps($profile);
        $steps[] = $step;
        $steps = array_values(array_unique($steps));
        sort($steps);
        $profile->completed_profile_steps = json_encode($steps, JSON_THROW_ON_ERROR);
        $profile->profile_draft_saved_at = now()->toDateTimeString();
        DB::table('teacher_profiles')->where('user_id', $userId)->update([
            'completed_profile_steps' => $profile->completed_profile_steps,
            'profile_draft_saved_at' => $profile->profile_draft_saved_at,
        ]);
        $after = self::forProfile($profile);
        app(BusinessActivityRecorder::class)->record('teacher.profile_draft_saved', $userId, $userId,
            before: ['completed_steps' => $before['completedSteps'], 'next_step' => $before['nextStep']],
            after: ['step' => $step, 'completed_steps' => $after['completedSteps'], 'next_step' => $after['nextStep']]);

        return $after;
    }

    public static function forProfile(?object $profile): array
    {
        $published = (bool) ($profile->is_profile_complete ?? false);
        $steps = $published ? range(1, 6) : self::steps($profile);
        $next = $published ? null : (array_values(array_diff(range(1, 6), $steps))[0] ?? 6);

        return [
            'status' => $published ? 'published' : 'draft',
            'totalSteps' => 6,
            'completedSteps' => $steps,
            'nextStep' => $next,
            'lastSavedAt' => $profile->profile_draft_saved_at ?? null,
        ];
    }

    private static function steps(?object $profile): array
    {
        $steps = json_decode($profile->completed_profile_steps ?? '[]', true, flags: JSON_THROW_ON_ERROR) ?? [];
        $steps = array_values(array_unique(array_filter($steps, fn ($step) => is_int($step) && $step >= 1 && $step <= 5)));
        sort($steps);

        return $steps;
    }
}
