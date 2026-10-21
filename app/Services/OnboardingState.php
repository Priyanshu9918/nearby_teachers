<?php

namespace App\Services;

final class OnboardingState
{
    public function forUser(object $user): array
    {
        $verified = ($user->phone_verified_at ?? null) !== null;
        $completed = ($user->profile_completed_at ?? null) !== null;
        $learner = ($user->account_type ?? 'learner') === 'learner';
        $ageRequired = $learner && ($user->date_of_birth ?? null) === null;
        $consentPending = (bool) ($user->is_minor ?? false) && ($user->parental_consent_confirmed_at ?? null) === null;
        $nextStep = match (true) {
            ! $verified => 'mobile_verification',
            ! $completed => 'complete_profile',
            $ageRequired => 'age_verification',
            $consentPending => 'guardian_consent',
            default => 'home',
        };

        return [
            'mobileVerified' => $verified,
            'phoneVerificationRequired' => ! $verified,
            'profileCompleted' => $completed,
            'registrationCompleted' => $completed,
            'onboardingRequired' => ! $completed,
            'ageVerificationRequired' => $ageRequired,
            'parentalConsentPending' => $consentPending,
            // Incomplete users open the common form, with inline OTP if required.
            'nextScreen' => ! $completed ? 'onboarding' : $nextStep,
            'nextStep' => $nextStep,
            'nextStepEndpoint' => match ($nextStep) {
                'mobile_verification' => '/auth/link-phone',
                'complete_profile' => '/auth/register',
                'age_verification' => '/auth/date-of-birth',
                'guardian_consent' => '/auth/session',
                default => '/profiles/me',
            },
            'onboardingEndpoint' => '/auth/onboarding',
            'registerEndpoint' => '/auth/register',
        ];
    }
}
