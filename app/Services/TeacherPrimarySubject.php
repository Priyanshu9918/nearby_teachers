<?php

namespace App\Services;

use App\Reporting\BusinessActivityRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TeacherPrimarySubject
{
    /** Call inside the source transaction, after resolving Other selections. */
    public function save(int $userId, array $data): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Save primary subject inside the source transaction.');
        }
        $profile = DB::table('teacher_profiles')->where('user_id', $userId)->lockForUpdate()->first();
        abort_if($profile === null, 404, 'Profile not found.');
        $selected = array_map('intval', $data['primarySubjects'] ?? DB::table('profile_subjects')
            ->where('profile_type', 'teacher')->where('profile_id', $userId)->pluck('subject_id')->all());
        $before = $profile->primary_subject_id === null ? null : (int) $profile->primary_subject_id;
        $primary = array_key_exists('primarySubjectId', $data) ? (int) $data['primarySubjectId'] : $before;
        if ($primary !== null && ! in_array($primary, $selected, true)) {
            throw ValidationException::withMessages([
                'primarySubjectId' => 'The primary subject must be included in primarySubjects. Select a new primary when removing the current one.',
            ]);
        }
        if ($before !== $primary) {
            DB::table('teacher_profiles')->where('user_id', $userId)->update(['primary_subject_id' => $primary]);
            app(BusinessActivityRecorder::class)->record('teacher.primary_subject_changed', $userId, $userId,
                before: ['primary_subject_id' => $before], after: ['primary_subject_id' => $primary]);
        }
    }
}
