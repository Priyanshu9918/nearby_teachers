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

    // Form + DOB can be ready while the learner is still awaiting consent.
    public function registrationDetailsCompleted(object $user): bool
    {
        if (! in_array($user->account_type ?? 'learner', ['learner', 'student'], true)) {
            return ($user->profile_completed_at ?? null) !== null;
        }

        return $this->registrationSubmitted($user) && ! $this->requiresDateOfBirth($user);
    }

    public function requiresParentalConsent(object $user): bool
    {
        return in_array($user->account_type ?? 'learner', ['learner', 'student'], true)
            && (bool) ($user->is_minor ?? false)
            && ($user->parental_consent_confirmed_at ?? null) === null;
    }

    public function registrationCompleted(object $user): bool
    {
        return ($user->profile_completed_at ?? null) !== null
            && $this->registrationDetailsCompleted($user) && ! $this->requiresParentalConsent($user);
    }

    public function forUser(object $user): array
    {
        $verified = ($user->phone_verified_at ?? null) !== null;
        $completed = $this->registrationCompleted($user);
        $submitted = $this->registrationSubmitted($user);
        $ageRequired = $this->requiresDateOfBirth($user);
        $consentStatus = app(ParentalConsent::class)->status($user);
        $consentPending = $this->requiresParentalConsent($user);
        $nextStep = match (true) {
            ! $verified => 'mobile_verification',
            ! $submitted => 'complete_profile',
            $ageRequired => 'age_verification',
            $consentStatus === 'details_required' => 'guardian_details',
            $consentPending => 'guardian_consent',
            ! $completed => 'complete_profile',
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
            'parentalConsentPending' => $consentStatus === 'pending',
            'parentalConsentRequired' => $consentPending,
            'parentalConsentStatus' => $consentStatus,
            'guardianDetailsRequired' => $consentStatus === 'details_required',
            // Incomplete users open the common form, with inline OTP if required.
            'nextScreen' => ! $completed && in_array($nextStep, ['mobile_verification', 'complete_profile'], true)
                ? 'onboarding' : $nextStep,
            'nextStep' => $nextStep,
            'nextStepEndpoint' => match ($nextStep) {
                'mobile_verification' => '/auth/link-phone',
                'complete_profile' => '/auth/register',
                'age_verification' => '/auth/date-of-birth',
                'guardian_details' => '/auth/guardian-details',
                'guardian_consent' => '/auth/session',
                default => '/profiles/me',
            },
            'onboardingEndpoint' => '/auth/onboarding',
            'registerEndpoint' => '/auth/register',
        ];
    }
}
