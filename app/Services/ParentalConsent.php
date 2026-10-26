<?php

namespace App\Services;

use App\Reporting\BusinessActivityRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ParentalConsent
{
    /** Simple login status applies only to learners currently aged 13–17. */
    public function loginFields(object $user): array
    {
        if (($user->account_type ?? null) !== 'learner' || ($user->date_of_birth ?? null) === null) {
            return [];
        }
        $age = CarbonImmutable::parse($user->date_of_birth)->age;
        if ($age < 13 || $age >= 18) {
            return [];
        }

        return ['status' => match (true) {
            ($user->parental_consent_confirmed_at ?? null) !== null => 'approve',
            ($user->parental_consent_rejected_at ?? null) !== null => 'reject',
            default => 'pending',
        }];
    }

    public function status(object $user): string
    {
        if (! in_array($user->account_type ?? 'learner', ['learner', 'student'], true) || ! ($user->is_minor ?? false)) {
            return 'not_required';
        }
        if (($user->parental_consent_confirmed_at ?? null) !== null) {
            return 'approved';
        }
        if (($user->parental_consent_rejected_at ?? null) !== null) {
            return 'rejected';
        }
        if (($user->parental_consent_expires_at ?? null) !== null
            && now()->gte($user->parental_consent_expires_at)) {
            return 'expired';
        }

        if (! filled($user->guardian_name ?? null) || ! filled($user->guardian_contact ?? null)) {
            return 'details_required';
        }

        return 'pending';
    }

    public function find(string $token, bool $lock = false): object
    {
        abort_unless(preg_match('/^[a-f0-9]{48}$/D', $token), 404, 'Invalid consent link.');
        $query = DB::table('users')->where('parental_consent_token', hash('sha256', $token));
        if ($lock) {
            $query->lockForUpdate();
        }
        $user = $query->first();
        abort_if($user === null || ! $user->is_minor || $user->parental_consent_expires_at === null, 404, 'This consent link is invalid or has already been used.');
        abort_if($this->status($user) !== 'pending', 410, 'This consent link has expired or has already been used.');

        return $user;
    }

    public function decide(string $token, string $decision): string
    {
        abort_unless(in_array($decision, ['approve', 'reject'], true), 422, 'Invalid decision.');

        return DB::transaction(function () use ($token, $decision): string {
            $user = $this->find($token, true);
            $status = $decision === 'approve' ? 'approved' : 'rejected';
            $state = app(OnboardingState::class);
            $updates = [
                'parental_consent_confirmed_at' => $decision === 'approve' ? now() : null,
                'parental_consent_rejected_at' => $decision === 'reject' ? now() : null,
                'parental_consent_token' => null,
                'updated_at' => now(),
            ];
            if ($decision === 'approve' && $state->registrationDetailsCompleted($user)) {
                $updates['profile_completed_at'] = now();
            }
            DB::table('users')->where('id', $user->id)->update($updates);
            $fresh = DB::table('users')->where('id', $user->id)->first();
            if (! $state->registrationCompleted($user) && $state->registrationCompleted($fresh)) {
                app(BusinessActivityRecorder::class)->record('registration.completed', $user->id, null,
                    relatedUserId: $user->id,
                    before: ['account_type' => $user->account_type, 'profile_completed_at' => $user->profile_completed_at],
                    after: ['account_type' => $fresh->account_type, 'profile_completed_at' => $fresh->profile_completed_at]);
            }
            // Link possession identifies the decision; do not invent a guardian user ID.
            app(BusinessActivityRecorder::class)->record('parental_consent.decided', $user->id, null,
                relatedUserId: $user->id, before: ['status' => 'pending'], after: ['status' => $status]);

            return $status;
        });
    }
}
