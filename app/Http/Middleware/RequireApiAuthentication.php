<?php

namespace App\Http\Middleware;

use App\Services\AuthenticationService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireApiAuthentication
{
    public function __construct(private readonly AuthenticationService $authentication) {}

    public function handle(Request $request, Closure $next): Response
    {
        $registering = $request->isMethod('POST')
            && $request->is('auth/register', 'auth/onboarding/complete');
        $token = $request->bearerToken();

        if (! $token) {
            if ($registering) {
                return $this->registrationOtpRequired();
            }

            return new JsonResponse(['error' => 'Missing Authorization header'], 401);
        }

        $user = $this->authentication->authenticate($token);

        if ($user === null) {
            if ($registering) {
                return $this->registrationOtpRequired(status: 401);
            }

            return new JsonResponse(['error' => 'Invalid or expired token'], 401);
        }

        if ($user->suspended_at !== null) {
            return new JsonResponse(array_filter([
                'error' => 'This account has been suspended.',
                'suspended' => true,
                'reason' => $user->suspended_reason,
            ], static fn ($value) => $value !== null), 403);
        }

        if ($registering && $user->phone_verified_at === null) {
            return $this->registrationOtpRequired('/auth/link-phone');
        }

        $onboardingDraft = $request->is('auth/onboarding') && $request->isMethod('GET');

        if (! $user->is_admin
            && $user->phone_verified_at === null
            && ! $request->is('auth/session', 'auth/link-phone')
            && ! $onboardingDraft) {
            return new JsonResponse([
                'error' => 'Verify your phone number before using the app.',
                'mobileVerified' => false,
                'phoneVerificationRequired' => true,
                'requestOtpEndpoint' => '/auth/otp/request',
                'verifyPhoneEndpoint' => '/auth/link-phone',
            ], 403);
        }

        $request->attributes->set('authenticated_user', $user);
        $request->setUserResolver(static fn () => $user);

        return $next($request);
    }

    private function registrationOtpRequired(string $verifyEndpoint = '/auth/otp/verify', int $status = 403): JsonResponse
    {
        $message = 'Please verify your mobile number with OTP before registering.';

        return new JsonResponse([
            'error' => $message,
            'message' => $message,
            'code' => 'OTP_VERIFICATION_REQUIRED',
            'mobileVerified' => false,
            'phoneVerificationRequired' => true,
            'canSubmit' => false,
            'nextStep' => 'mobile_verification',
            'requestOtpEndpoint' => '/auth/otp/request',
            'verifyPhoneEndpoint' => $verifyEndpoint,
        ], $status);
    }
}
