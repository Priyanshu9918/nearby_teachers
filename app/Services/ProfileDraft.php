<?php

namespace App\Services;

use App\Reporting\BusinessActivityRecorder;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ProfileDraft
{
    /** Called inside the section mutation transaction; the lock prevents lost concurrent saves. */
    public function saveStep(int $userId, int $step, string $type = 'teacher'): array
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Save draft progress inside the profile transaction.');
        }
        if ($step < 1 || $step > 5) {
            throw new LogicException('Only profile editor steps 1 to 5 can be saved.');
        }
        $table = ProfileEditor::table($type);
        $profile = DB::table($table)->where('user_id', $userId)->lockForUpdate()->first();
        abort_if($profile === null, 404, 'Profile not found.');
        $before = self::forProfile($profile);
        $steps = self::steps($profile);
        $steps[] = $step;
        $steps = array_values(array_unique($steps));
        sort($steps);
        $profile->completed_profile_steps = json_encode($steps, JSON_THROW_ON_ERROR);
        $profile->profile_draft_saved_at = now()->toDateTimeString();
        DB::table($table)->where('user_id', $userId)->update([
            'completed_profile_steps' => $profile->completed_profile_steps,
            'profile_draft_saved_at' => $profile->profile_draft_saved_at,
        ]);
        $after = self::forProfile($profile);
        app(BusinessActivityRecorder::class)->record($type.'.profile_draft_saved', $userId, $userId,
            before: ['completed_steps' => $before['completedSteps'], 'next_step' => $before['nextStep']],
            after: ['step' => $step, 'completed_steps' => $after['completedSteps'], 'next_step' => $after['nextStep']]);

        return self::loadForOwner($profile, $userId, $type);
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

    /** Owner response only. Business history must continue to use metadata from forProfile(). */
    public static function forOwner(?object $profile, object $user, string $type, array $subjectIds): array
    {
        ProfileEditor::table($type);
        $draft = self::forProfile($profile);
        $teacher = $type === 'teacher';
        $modes = $profile->lesson_modes ?? [];
        if (is_string($modes)) {
            $modes = json_decode($modes, true, flags: JSON_THROW_ON_ERROR) ?? [];
        }
        $basics = [
            'fullName' => $user->full_name ?? null,
            'listingName' => ($profile->listing_name ?? null) ?: ($user->full_name ?? null),
            'email' => $user->email ?? null,
        ];
        if (! $teacher) {
            $basics['contactPerson'] = $profile->contact_person ?? null;
        }
        $location = ['lessonModes' => $modes];
        foreach ([
            'latitude' => 'latitude', 'longitude' => 'longitude', 'addressText' => 'address_text',
            'city' => 'city', 'state' => 'state', 'country' => 'country', 'countryCode' => 'country_code',
            'district' => 'district', 'subDistrict' => 'sub_district', 'locality' => 'locality',
            'localityType' => 'locality_type', 'postalCode' => 'postal_code',
        ] as $input => $column) {
            $location[$input] = $profile->$column ?? null;
        }
        $data = [
            1 => $basics,
            2 => [$teacher ? 'primarySubjects' : 'subjectIds' => array_values(array_map('intval', $subjectIds))],
            3 => $location,
            4 => [$teacher ? 'ratePerHour' : 'startingRate' => $teacher ? ($profile->rate_per_hour ?? null) : ($profile->starting_rate ?? null),
                'currency' => $profile->currency ?? null, 'trialDays' => isset($profile->trial_days) ? (int) $profile->trial_days : null],
            5 => $teacher
                ? ['bio' => $profile->bio ?? null, 'linkedinUrl' => $profile->linkedin_url ?? null]
                : ['description' => $profile->description ?? null, 'websiteUrl' => $profile->website_url ?? null, 'instructorsText' => $profile->instructors_text ?? null],
            6 => ['agreeToTerms' => (bool) ($profile->is_profile_complete ?? false) && ($user->terms_accepted_at ?? null) !== null],
        ];
        $draft['steps'] = [];
        foreach ($data as $step => $values) {
            $draft['steps'][] = ['step' => $step, 'completed' => in_array($step, $draft['completedSteps'], true), 'data' => $values];
        }

        return $draft;
    }

    public static function loadForOwner(object $profile, int $userId, string $type): array
    {
        $user = DB::table('users')->where('id', $userId)->first(['full_name', 'email', 'terms_accepted_at']);
        abort_if($user === null, 404, 'User not found.');

        return self::forOwner($profile, $user, $type, ProfileEditor::subjects($userId, $type)->pluck('id')->all());
    }

    private static function steps(?object $profile): array
    {
        $steps = json_decode($profile->completed_profile_steps ?? '[]', true, flags: JSON_THROW_ON_ERROR) ?? [];
        $steps = array_values(array_unique(array_filter($steps, fn ($step) => is_int($step) && $step >= 1 && $step <= 5)));
        sort($steps);

        return $steps;
    }
}
