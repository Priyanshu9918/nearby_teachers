<?php

namespace App\Services;

final class OnboardingState
{
    public function requiresDateOfBirth(object $user): bool
    {
        return in_array($user->account_type ?? 'learner', ['learner', 'student'], true)
            && ($user->date_of_birth ?? null) === null;
    }

    public function registrationSubmitted(object $user): bool
    {
        return ($user->registration_submitted_at ?? null) !== null
            || ($user->profile_completed_at ?? null) !== null;
    }

    public function registrationCompleted(object $user): bool
    {
        return ($user->profile_completed_at ?? null) !== null && ! $this->requiresDateOfBirth($user);
    }

    public function forUser(object $user): array
    {
        $verified = ($user->phone_verified_at ?? null) !== null;
        $completed = $this->registrationCompleted($user);
        $submitted = $this->registrationSubmitted($user);
        $ageRequired = $this->requiresDateOfBirth($user);
        $consentPending = (bool) ($user->is_minor ?? false) && ($user->parental_consent_confirmed_at ?? null) === null;
        $nextStep = match (true) {
            ! $verified => 'mobile_verification',
            ! $submitted => 'complete_profile',
            $ageRequired => 'age_verification',
            ! $completed => 'complete_profile',
            $consentPending => 'guardian_consent',
            default => 'home',
        };

        return [
            'mobileVerified' => $verified,
            'phoneVerificationRequired' => ! $verified,
            'profileCompleted' => $completed,
            'registrationCompleted' => $completed,
            'registrationSubmitted' => $submitted,
            'onboardingRequired' => ! $completed,
            'ageVerificationRequired' => $ageRequired,
            'parentalConsentPending' => $consentPending,
            // Incomplete users open the common form, with inline OTP if required.
            'nextScreen' => ! $completed && in_array($nextStep, ['mobile_verification', 'complete_profile'], true)
                ? 'onboarding' : $nextStep,
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
