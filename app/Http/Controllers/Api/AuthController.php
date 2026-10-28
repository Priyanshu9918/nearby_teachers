<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RevokeChatSessions;
use App\Reporting\BusinessActivityRecorder;
use App\Services\AuthenticationService;
use App\Services\OnboardingState;
use App\Services\OtpDelivery;
use App\Services\ParentalConsent;
use App\Services\TeacherPublicPages;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Throwable;

class AuthController extends Controller
{
    private const OTP_TTL_MINUTES = 10;

    private const MAX_OTP_REQUESTS = 3;

    private const MAX_OTP_ATTEMPTS = 5;

    private const SAFETY_DISCLAIMER = 'Nearby Teacher does not conduct criminal background checks, identity verification, or credential checks on teachers or institutes — except the "BSL Verified" badge, which only confirms an institute is directly operated by BSL and is not a safety certification. You are solely responsible for evaluating the suitability and safety of any teacher or institute before engaging their services, especially for a minor in your care. We recommend independently verifying credentials, checking references, and meeting before committing to lessons — particularly for in-person or unsupervised sessions involving a minor.';

    private const LANGUAGES = ['en', 'hi', 'es', 'fr', 'ar', 'zh', 'pt', 'ru'];

    public function __construct(private readonly AuthenticationService $authentication, private readonly OtpDelivery $delivery) {}

    public function requestOtp(Request $request): JsonResponse
    {
        if ($error = $this->authorizeOtpFlow($request)) {
            return $error;
        }

        [$phone, $country] = $this->validatedPhone($request->input('phone'));

        if (DB::table('otp_codes')
            ->where('phone', $phone)
            ->where('created_at', '>', now()->subMinutes(10))
            ->count() >= self::MAX_OTP_REQUESTS) {
            return response()->json(['error' => 'Too many codes requested. Try again in a few minutes.'], 429);
        }

        $code = (string) random_int(100000, 999999);
        $id = DB::table('otp_codes')->insertGetId([
            'phone' => $phone,
            'google_user_id' => $request->attributes->get('authenticated_user')?->id,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::OTP_TTL_MINUTES),
            'created_at' => now(),
        ]);

        try {
            $delivery = $this->delivery->send($phone, $code, $country);
        } catch (Throwable $exception) {
            DB::table('otp_codes')->where('id', $id)->update(['consumed_at' => now()]);
            Log::error('OTP delivery failed.', ['exception' => $exception::class]);

            return response()->json(['error' => 'Unable to send a verification code right now. Please try again later.'], 503);
        }
        $statuses = array_map(fn (array $channel) => $channel['status'], $delivery['channels']);
        $accepted = count(array_filter($statuses, fn (string $status) => in_array($status, ['accepted', 'simulated'], true)));
        DB::transaction(function () use ($id, $phone, $delivery, $accepted): void {
            foreach ($delivery['channels'] as $channel => $result) {
                $attemptId = DB::table('otp_delivery_attempts')->insertGetId(['otp_code_id' => $id, 'channel' => $channel,
                    'provider' => $result['provider'], 'status' => $result['status'],
                    'phone_last_four' => substr($phone, -4), 'created_at' => now(),
                ]);
                app(BusinessActivityRecorder::class)->record('otp.delivery_result', $attemptId, null,
                    after: ['channel' => $channel, 'provider' => $result['provider'], 'status' => $result['status']]);
            }
            if ($accepted === 0) {
                DB::table('otp_codes')->where('id', $id)->update(['consumed_at' => now()]);
            }
        });
        if ($accepted === 0) {
            return response()->json(['error' => 'Unable to send a verification code right now. Please try again later.', 'channels' => $statuses], 503);
        }
        $partial = $accepted < count($statuses);

        return response()->json([
            'ok' => true, 'expiresInMinutes' => self::OTP_TTL_MINUTES, 'channels' => $statuses,
            ...($partial ? ['partialDelivery' => true] : []),
            ...($delivery['testMode'] && app()->environment(['local', 'testing']) ? ['testMode' => true, 'otp' => $code] : []),
        ], $partial ? 202 : 200);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        if ($error = $this->authorizeOtpFlow($request)) {
            return $error;
        }
        if ($request->attributes->has('authenticated_user')) {
            return $this->linkPhone($request);
        }

        [$phone] = $this->validatedPhone($request->input('phone'));
        $data = $request->validate(['code' => ['required', 'digits:6']]);

        $result = DB::transaction(function () use ($phone, $data): array {
            $otp = DB::table('otp_codes')->where('phone', $phone)
                ->orderByDesc('created_at')->lockForUpdate()->first();

            if ($otp === null) {
                return ['error' => 'No OTP was requested for this number'];
            }
            $this->assertOtpIdentity($otp, null);
            if ($otp->consumed_at !== null) {
                return ['error' => 'This code has already been used'];
            }
            if (CarbonImmutable::parse($otp->expires_at)->isPast()) {
                return ['error' => 'This code has expired'];
            }
            if ((int) $otp->attempts >= self::MAX_OTP_ATTEMPTS) {
                return ['error' => 'Too many incorrect attempts — request a new code'];
            }
            if (! Hash::check($data['code'], $otp->code_hash)) {
                DB::table('otp_codes')->where('id', $otp->id)->increment('attempts');

                return ['error' => 'Incorrect code'];
            }

            DB::table('otp_codes')->where('id', $otp->id)->update(['consumed_at' => now()]);
            $user = DB::table('users')->where('phone', $phone)->first();
            if ($user !== null) {
                if (app(OnboardingState::class)->registrationDetailsCompleted($user)) {
                    return ['error' => 'This account is already registered. Log in with your email and password.', 'loginRequired' => true];
                }

                return ['user' => $user];
            }

            $userId = DB::table('users')->insertGetId([
                'account_type' => 'learner',
                'full_name' => 'New user',
                'phone' => $phone,
                'phone_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['user' => DB::table('users')->where('id', $userId)->first()];
        });

        if (isset($result['error'])) {
            if ($result['loginRequired'] ?? false) {
                return response()->json(['error' => $result['error'], 'loginRequired' => true, 'loginEndpoint' => '/auth/login'], 409);
            }
            throw ValidationException::withMessages(['code' => $result['error']]);
        }
        $user = $result['user'];
        if ($user->phone_verified_at === null) {
            DB::table('users')->where('id', $user->id)->update([
                'phone_verified_at' => now(),
                'updated_at' => now(),
            ]);
            $user->phone_verified_at = now();
        }

        $user = $this->authentication->publicUser($user);

        return response()->json([
            'token' => $this->authentication->issueOtpToken($user->id, (int) ($user->auth_version ?? 0)),
            'user' => $user,
            'authMethod' => 'phone_otp',
            ...app(OnboardingState::class)->forUser($user),
        ]);
    }

    public function googleSignIn(Request $request): JsonResponse
    {
        $data = $request->validate(['firebaseIdToken' => ['required', 'string', 'max:16384']]);
        try {
            $claims = $this->authentication->verifyFirebaseClaims($data['firebaseIdToken']);
        } catch (\RuntimeException) {
            return response()->json(['error' => 'Google sign-in is temporarily unavailable. Please try again later.', 'code' => 'FIREBASE_AUTH_UNAVAILABLE'], 503);
        }
        if ($claims === null || ($claims->firebase->sign_in_provider ?? null) !== 'google.com') {
            return response()->json(['error' => 'That Google sign-in could not be verified. Try again.'], 400);
        }
        if (($claims->email_verified ?? false) !== true
            || ! is_string($claims->email ?? null)
            || filter_var($claims->email, FILTER_VALIDATE_EMAIL) === false) {
            return response()->json(['error' => 'Use a verified Google email address to continue.'], 400);
        }

        $user = $this->authentication->publicUser($this->authentication->resolveGoogleUser($claims));
        if ($user === null) {
            return response()->json(['error' => 'This Google account conflicts with an existing account. Log in with the linked account or contact support.'], 409);
        }

        if ($user->suspended_at !== null) {
            return response()->json(['error' => 'This account has been suspended.', 'suspended' => true], 403);
        }

        return response()->json([
            'token' => $this->authentication->issueSessionToken($user->id, (int) ($user->auth_version ?? 0)),
            'user' => $user,
            'authMethod' => 'google',
            ...app(OnboardingState::class)->forUser($user),
            ...app(ParentalConsent::class)->loginFields($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authenticated_user');
        DB::transaction(function () use ($user): void {
            $saved = DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            abort_if($saved === null || (int) $saved->auth_version !== (int) $user->auth_version,
                403, 'Invalid or expired token');
            $version = (int) $saved->auth_version + 1;
            DB::table('users')->where('id', $saved->id)->update([
                'auth_version' => $version,
                'auth_tokens_invalid_before' => now(),
                'updated_at' => now(),
            ]);
            app(BusinessActivityRecorder::class)->record('auth.logged_out', $saved->id, $saved->id,
                before: ['auth_version' => (int) $saved->auth_version],
                after: ['auth_version' => $version, 'scope' => 'all_sessions']);
            RevokeChatSessions::dispatch($saved->id, $version);
        });

        return response()->json([
            'ok' => true,
            'message' => 'Logged out successfully.',
            'loggedOutAllDevices' => true,
        ]);
    }

    public function session(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authenticated_user');
        $onboarding = app(OnboardingController::class)->show($request)->getData(true);
        $accountType = (string) $user->account_type;
        $profileTable = match ($accountType) {
            'teacher' => 'teacher_profiles',
            'institute' => 'institute_profiles',
            default => null,
        };
        $profile = $profileTable === null ? null : DB::table($profileTable)->where('user_id', $user->id)->first();
        $subjects = in_array($accountType, ['teacher', 'institute'], true)
            ? DB::table('profile_subjects as ps')->join('subjects as s', 's.id', '=', 'ps.subject_id')
                ->where('ps.profile_type', $accountType)->where('ps.profile_id', $user->id)
                ->orderBy('s.name')->get(['s.id', 's.name', 's.domain_id'])
                ->map(fn (object $subject): array => ['id' => (int) $subject->id, 'name' => $subject->name, 'domainId' => $subject->domain_id === null ? null : (int) $subject->domain_id])->all()
            : [];

        if ($profile !== null) {
            unset($profile->seo_state_hash);
            if (isset($profile->lesson_modes)) {
                $profile->lesson_modes = json_decode($profile->lesson_modes ?: '[]', true, flags: JSON_THROW_ON_ERROR);
            }
            if (isset($profile->location_details)) {
                $profile->location_details = $profile->location_details === null ? null : json_decode($profile->location_details, true, flags: JSON_THROW_ON_ERROR);
            }
            foreach (['is_profile_complete', 'is_verified', 'is_bsl_official'] as $field) {
                if (isset($profile->$field)) {
                    $profile->$field = (bool) $profile->$field;
                }
            }
            $profile->subjects = $subjects;
        }

        $registered = (bool) ($onboarding['registrationCompleted'] ?? false);
        $profileComplete = match ($accountType) {
            'teacher', 'institute' => (bool) ($profile->is_profile_complete ?? false),
            default => $registered,
        };
        $completionMissing = $onboarding['missingFields'] ?? [];
        if (in_array($accountType, ['teacher', 'institute'], true) && ! $profileComplete) {
            if ($profile === null) {
                $completionMissing[] = $accountType === 'teacher' ? 'teacherProfile' : 'instituteProfile';
            } else {
                $completionMissing = [...$completionMissing, ...\App\Services\ProfileEditor::missingFields($profile, $accountType, $subjects !== [])];
                $completionMissing[] = 'publishProfile';
            }
        }
        $completionMissing = array_values(array_unique($completionMissing));

        // Session responses intentionally omit credentials, auth internals, and consent tokens.
        $safeUser = DB::table('users')->where('id', $user->id)->first([
            'id', 'account_type', 'full_name', 'email', 'phone', 'phone_pin', 'avatar_url', 'avatar_status',
            'is_admin', 'suspended_reason',
            'date_of_birth', 'is_minor', 'guardian_name', 'guardian_contact', 'parental_consent_confirmed_at',
            'parental_consent_rejected_at', 'parental_consent_expires_at', 'safety_disclaimer_acknowledged_at',
            'preferred_language', 'suspended_at', 'phone_verified_at', 'email_verified_at', 'referral_code',
            'terms_accepted_at', 'registration_submitted_at', 'profile_completed_at', 'created_at', 'updated_at',
        ]);
        $safeUser->password_set = (bool) ($user->password_set ?? false);
        $sessionProfile = $profile ?? $safeUser;
        $profileLinks = in_array($accountType, ['teacher', 'institute'], true)
            ? \App\Services\ProfileEditor::urls($profile, $accountType) : [];

        return response()->json([
            'ok' => true,
            ...$onboarding,
            'user' => $safeUser,
            'profile' => $sessionProfile,
            'subjects' => $subjects,
            'accountType' => $accountType,
            ...$profileLinks,
            // profileCompleted remains registration completion for existing clients.
            'profileComplete' => $profileComplete,
            'publicProfilePublished' => in_array($accountType, ['teacher', 'institute'], true) && $profileComplete && filled($profileLinks['webPageUrl'] ?? null),
            'profileCompletion' => ['isComplete' => $profileComplete, 'missingFields' => $completionMissing],
            ...(in_array($accountType, ['teacher', 'institute'], true) ? ['profileDraft' => \App\Services\ProfileDraft::forOwner($profile, $safeUser, $accountType, array_column($subjects, 'id'))] : []),
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['prohibited'],
            'password' => ['required', 'string', 'min:8', 'max:72'],
        ]);

        $user = DB::table('users')->where('email', strtolower(trim($data['email'])))->first();
        if ($user === null || ! filled($user->password_hash) || ! Hash::check($data['password'], $user->password_hash)) {
            return response()->json(['error' => 'Incorrect email or password.'], 401);
        }
        if ($user->suspended_at !== null) {
            return response()->json(['error' => 'This account has been suspended.', 'suspended' => true], 403);
        }
        if (! app(OnboardingState::class)->registrationDetailsCompleted($user)) {
            return response()->json([
                'error' => 'Complete your registration before logging in.',
                'code' => 'REGISTRATION_INCOMPLETE',
                'registrationRequired' => true,
                ...app(OnboardingState::class)->forUser($user),
                ...app(ParentalConsent::class)->loginFields($user),
            ], 409);
        }

        $user = $this->authentication->publicUser($user);

        return response()->json([
            'token' => $this->authentication->issueSessionToken($user->id, (int) ($user->auth_version ?? 0)),
            'user' => $user,
            'authMethod' => 'password',
            ...app(OnboardingState::class)->forUser($user),
            ...app(ParentalConsent::class)->loginFields($user),
        ]);
    }

    public function setAccountType(Request $request): JsonResponse
    {
        $data = $request->validate([
            'accountType' => ['required', 'in:learner,teacher,institute'],
        ]);
        $user = $request->attributes->get('authenticated_user');
        $now = now();

        DB::transaction(function () use ($data, $user, $now): void {
            DB::table('users')->where('id', $user->id)->update([
                'account_type' => $data['accountType'],
                'updated_at' => $now,
            ]);

            if ($data['accountType'] === 'teacher') {
                DB::table('teacher_profiles')->insertOrIgnore([
                    'user_id' => $user->id,
                ] + $this->emptyLessonModes());
            } elseif ($data['accountType'] === 'institute') {
                DB::table('institute_profiles')->insertOrIgnore([
                    'user_id' => $user->id,
                ] + $this->emptyLessonModes());
            }
        });

        return response()->json(['ok' => true]);
    }

    public function setPreferredLanguage(Request $request): JsonResponse
    {
        $data = $request->validate(['language' => ['required', 'in:'.implode(',', self::LANGUAGES)]]);
        $user = $request->attributes->get('authenticated_user');

        DB::table('users')->where('id', $user->id)->update([
            'preferred_language' => $data['language'],
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'preferredLanguage' => $data['language']]);
    }

    public function setAvatar(Request $request): JsonResponse
    {
        $data = $request->validate(['avatarUrl' => ['required', 'url', 'max:2048']]);
        $user = $request->attributes->get('authenticated_user');

        DB::table('users')->where('id', $user->id)->update([
            'avatar_url' => $data['avatarUrl'],
            'avatar_status' => 'pending',
            'updated_at' => now(),
        ]);

        return response()->json([
            'user' => DB::table('users')->select('id', 'avatar_url', 'avatar_status')->where('id', $user->id)->first(),
            'message' => 'Photo submitted — it will appear on your profile once reviewed.',
        ]);
    }

    public function linkPhone(Request $request): JsonResponse
    {
        [$phone] = $this->validatedPhone($request->input('phone'));
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $user = $request->attributes->get('authenticated_user');
        $result = DB::transaction(function () use ($user, $phone, $data): array {
            $saved = DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            abort_if($saved === null, 403, 'Invalid or expired token');
            $existing = DB::table('users')->select('id')->where('phone', $phone)->lockForUpdate()->first();
            if ($existing !== null && $existing->id !== $saved->id) {
                abort(response()->json([
                    'error' => 'This phone number is already linked to a different account. Log in with that account to manage it.',
                ], 409));
            }

            $error = $this->consumeOtp($phone, $data['code'], $saved->id);
            if ($error !== null) {
                return ['error' => $error];
            }
            DB::table('users')->where('id', $saved->id)->update([
                'phone' => $phone, 'phone_verified_at' => now(), 'updated_at' => now(),
            ]);
            if ($saved->phone_verified_at === null || $saved->phone !== $phone) {
                app(BusinessActivityRecorder::class)->record('identity.phone_verified', $saved->id, $saved->id,
                    before: ['verified' => $saved->phone_verified_at !== null],
                    after: ['verified' => true, 'phone_changed' => $saved->phone !== $phone]);
            }

            return ['user' => DB::table('users')->where('id', $saved->id)->first()];
        });
        if (isset($result['error'])) {
            throw ValidationException::withMessages(['code' => $result['error']]);
        }
        $fresh = $this->authentication->publicUser($result['user']);

        return response()->json([
            ...(filled($fresh->google_id ?? null) ? [
                'token' => $this->authentication->issueSessionToken($fresh->id, (int) ($fresh->auth_version ?? 0)),
                'authMethod' => 'google',
            ] : []),
            'user' => $fresh,
            ...app(OnboardingState::class)->forUser($fresh),
        ]);
    }

    public function linkGoogle(Request $request): JsonResponse
    {
        $data = $request->validate(['firebaseIdToken' => ['required', 'string', 'max:16384']]);
        try {
            $claims = $this->authentication->verifyFirebaseClaims($data['firebaseIdToken']);
        } catch (\RuntimeException) {
            return response()->json(['error' => 'Google sign-in is temporarily unavailable. Please try again later.', 'code' => 'FIREBASE_AUTH_UNAVAILABLE'], 503);
        }

        if ($claims === null) {
            return response()->json(['error' => 'That Google sign-in could not be verified. Try again.'], 400);
        }
        if (($claims->firebase->sign_in_provider ?? null) !== 'google.com') {
            return response()->json(['error' => 'That token was not from a Google sign-in.'], 400);
        }
        if (($claims->email_verified ?? false) !== true || ! is_string($claims->email ?? null) || filter_var($claims->email, FILTER_VALIDATE_EMAIL) === false) {
            return response()->json(['error' => 'Use a verified Google email address to continue.'], 400);
        }

        $user = $request->attributes->get('authenticated_user');

        return DB::transaction(function () use ($user, $claims): JsonResponse {
            $current = DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            abort_if($current === null, 401);
            if ($current->google_id !== null && $current->google_id !== $claims->sub) {
                return response()->json(['error' => 'This account is already linked to another Google identity.'], 409);
            }
            $existing = DB::table('users')->where('id', '!=', $current->id)->where(function ($query) use ($claims): void {
                $query->where('google_id', $claims->sub)->orWhere('email', strtolower(trim($claims->email)));
            })->lockForUpdate()->first();
            if ($existing !== null) {
                return response()->json(['error' => 'This Google account is already linked to a different account. Log in with that account or contact support.'], 409);
            }
            DB::table('users')->where('id', $current->id)->update([
                'google_id' => $claims->sub,
                'email' => $current->email ?? strtolower(trim($claims->email)),
                'email_verified_at' => $current->email === null || strcasecmp($current->email, $claims->email) === 0 ? now() : $current->email_verified_at,
                'updated_at' => now(),
            ]);
            if ($current->google_id === null) {
                app(BusinessActivityRecorder::class)->record('identity.google_linked', $current->id, $current->id,
                    before: ['linked' => false], after: ['linked' => true]);
            }
            $fresh = $this->authentication->publicUser(DB::table('users')->where('id', $current->id)->first());

            return response()->json(['user' => $fresh, ...app(OnboardingState::class)->forUser($fresh)]);
        });
    }

    public function setDateOfBirth(Request $request): JsonResponse
    {
        $data = $request->validate([
            'dateOfBirth' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'guardianName' => ['prohibited'],
            'guardianContact' => ['prohibited'],
        ]);
        $birthDate = CarbonImmutable::createFromFormat('Y-m-d', $data['dateOfBirth']);
        $age = $birthDate->age;
        $user = $request->attributes->get('authenticated_user');

        if ($age > 120) {
            return response()->json(['error' => 'Invalid date of birth'], 400);
        }
        if ($age < 13) {
            return response()->json(['error' => 'You must be at least 13 to use Nearby Teacher'], 400);
        }

        $isMinor = $age < 18;
        $fresh = DB::transaction(function () use ($user, $birthDate, $isMinor): object {
            $saved = DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            abort_if($saved === null, 403, 'Invalid or expired token');
            abort_if($saved->date_of_birth !== null && $saved->date_of_birth !== $birthDate->toDateString(), 409, 'Date of birth is already set. Contact support to correct it.');
            $state = app(OnboardingState::class);
            $updates = [
                'date_of_birth' => $birthDate->toDateString(),
                'is_minor' => $isMinor,
                'updated_at' => now(),
            ];
            if ($saved->account_type === 'learner' && $state->registrationSubmitted($saved)
                && (! $isMinor || $saved->parental_consent_confirmed_at !== null)
                && ! $state->registrationCompleted($saved)) {
                $updates['profile_completed_at'] = now();
            }
            DB::table('users')->where('id', $saved->id)->update($updates);
            $fresh = DB::table('users')->where('id', $saved->id)->first();
            app(BusinessActivityRecorder::class)->record('onboarding.age_verified', $saved->id, $saved->id,
                before: ['age_verified' => $saved->date_of_birth !== null, 'is_minor' => (bool) $saved->is_minor],
                after: ['age_verified' => true, 'is_minor' => $isMinor]);
            if (! $state->registrationCompleted($saved) && $state->registrationCompleted($fresh)) {
                app(BusinessActivityRecorder::class)->record('registration.completed', $saved->id, $saved->id,
                    before: ['account_type' => $saved->account_type, 'profile_completed_at' => $saved->profile_completed_at],
                    after: ['account_type' => $fresh->account_type, 'profile_completed_at' => $fresh->profile_completed_at]);
            }

            return $fresh;
        });

        return response()->json([
            'isMinor' => $isMinor,
            ...app(OnboardingState::class)->forUser($fresh),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function setGuardianDetails(Request $request): JsonResponse
    {
        $data = $request->validate([
            'guardianName' => ['required', 'string', 'max:160'],
            'guardianContact' => ['required', 'string', 'max:255'],
            'dateOfBirth' => ['prohibited'],
        ]);
        try {
            [$phone] = $this->validatedPhone($data['guardianContact']);
        } catch (ValidationException) {
            throw ValidationException::withMessages([
                'guardianContact' => 'Enter a valid parent phone number with country code, e.g. +919876543211',
            ]);
        }
        $user = $request->attributes->get('authenticated_user');
        $token = bin2hex(random_bytes(24));
        $fresh = DB::transaction(function () use ($user, $data, $phone, $token): object {
            $saved = DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            abort_if($saved === null, 403, 'Invalid or expired token');
            abort_if($saved->account_type !== 'learner' || $saved->date_of_birth === null || ! $saved->is_minor,
                409, 'Save a learner date of birth requiring parent consent before submitting guardian details.');
            DB::table('users')->where('id', $saved->id)->update([
                'guardian_name' => $data['guardianName'],
                'guardian_contact' => $phone,
                'parental_consent_token' => hash('sha256', $token),
                'parental_consent_expires_at' => now()->addHours(48),
                'parental_consent_confirmed_at' => null,
                'parental_consent_rejected_at' => null,
                'updated_at' => now(),
            ]);
            app(BusinessActivityRecorder::class)->record('parental_consent.requested', $saved->id, $saved->id,
                before: ['status' => app(ParentalConsent::class)->status($saved)], after: ['status' => 'pending']);

            return DB::table('users')->where('id', $saved->id)->first();
        });

        return response()->json([
            ...app(OnboardingState::class)->forUser($fresh),
            'message' => 'Send this link to the parent/guardian phone. Messaging and booking require approval.',
            'parentalConsent' => [
                'status' => 'pending',
                'link' => route('parental-consent.show', ['token' => $token]),
                'expiresAt' => $fresh->parental_consent_expires_at,
                'parent' => ['name' => $fresh->guardian_name, 'phone' => $fresh->guardian_contact],
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function parentalConsentStatus(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authenticated_user');
        abort_unless($user->account_type === 'learner', 403, 'Consent status is available only for learners.');
        $state = app(OnboardingState::class)->forUser($user);
        if ($user->date_of_birth === null) {
            return response()->json([
                'error' => 'Save the learner date of birth first.', 'code' => 'DOB_REQUIRED', ...$state,
            ], 409)->header('Cache-Control', 'no-store, private');
        }
        $fields = app(ParentalConsent::class)->loginFields($user);

        return response()->json([
            ...$state,
            'status' => $fields['status'] ?? 'not_required',
            'expiresAt' => $user->parental_consent_expires_at,
            'approvedAt' => $user->parental_consent_confirmed_at,
            'rejectedAt' => $user->parental_consent_rejected_at,
            'parent' => $user->guardian_name === null && $user->guardian_contact === null ? null : [
                'name' => $user->guardian_name, 'phone' => $user->guardian_contact,
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function confirmParentalConsent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'size:48', 'regex:/^[a-f0-9]+$/'],
            'decision' => ['sometimes', 'in:approve,reject'],
        ]);
        $status = app(ParentalConsent::class)->decide($data['token'], $data['decision'] ?? 'approve');

        return response()->json(['ok' => true, 'parentalConsentStatus' => $status])
            ->header('Cache-Control', 'no-store');
    }

    public function safetyDisclaimer(): JsonResponse
    {
        return response()->json(['text' => self::SAFETY_DISCLAIMER]);
    }

    public function acknowledgeSafetyDisclaimer(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authenticated_user');
        DB::table('users')->where('id', $user->id)->update([
            'safety_disclaimer_acknowledged_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    private function validatedPhone(mixed $input): array
    {
        if (! is_string($input)) {
            throw ValidationException::withMessages([
                'phone' => 'Enter a valid phone number with country code, e.g. +919876543210',
            ]);
        }

        try {
            $util = PhoneNumberUtil::getInstance();
            $number = $util->parse($input);
            if (! $util->isValidNumber($number)) {
                throw new NumberParseException(NumberParseException::NOT_A_NUMBER, 'Invalid phone number.');
            }

            return [$util->format($number, PhoneNumberFormat::E164), $util->getRegionCodeForNumber($number)];
        } catch (NumberParseException) {
            throw ValidationException::withMessages([
                'phone' => 'Enter a valid phone number with country code, e.g. +919876543210',
            ]);
        }
    }

    private function authorizeOtpFlow(Request $request): ?JsonResponse
    {
        $request->validate(['authMethod' => ['sometimes', 'in:google,phone_otp']]);
        $google = $request->input('authMethod') === 'google';
        if (! $request->hasHeader('Authorization')) {
            return $google ? response()->json([
                'error' => 'Google signup requires the bearer token returned by /auth/google.',
                'code' => 'GOOGLE_AUTH_REQUIRED',
            ], 401) : null;
        }
        $token = $request->bearerToken();
        $user = $token ? $this->authentication->authenticate($token) : null;
        if ($user === null) {
            return response()->json(['error' => 'Invalid or expired token'], 403);
        }
        if (($user->suspended_at ?? null) !== null) {
            return response()->json(['error' => 'This account has been suspended.', 'suspended' => true], 403);
        }
        if (! filled($user->google_id ?? null) || $request->input('authMethod') === 'phone_otp') {
            return response()->json([
                'error' => 'Normal signup OTP requests must omit Authorization. Google signup requires a Google-linked bearer.',
                'code' => 'OTP_BEARER_NOT_GOOGLE',
            ], 409);
        }
        $request->attributes->set('authenticated_user', $user);

        return null;
    }

    private function assertOtpIdentity(object $otp, ?int $userId): void
    {
        if (($otp->google_user_id ?? null) === null) {
            return;
        }
        if ($userId !== $otp->google_user_id) {
            abort(response()->json([
                'error' => 'Verify this OTP with the same Google bearer used to request it.',
                'code' => $userId === null ? 'GOOGLE_AUTH_REQUIRED' : 'OTP_GOOGLE_ACCOUNT_MISMATCH',
            ], $userId === null ? 401 : 403));
        }
    }

    private function consumeOtp(string $phone, string $code, ?int $userId = null): ?string
    {
        return DB::transaction(function () use ($phone, $code, $userId): ?string {
            $otp = DB::table('otp_codes')->where('phone', $phone)
                ->orderByDesc('created_at')->lockForUpdate()->first();

            if ($otp === null) {
                return 'No OTP was requested for this number';
            }
            $this->assertOtpIdentity($otp, $userId);
            if ($otp->consumed_at !== null) {
                return 'This code has already been used';
            }
            if (CarbonImmutable::parse($otp->expires_at)->isPast()) {
                return 'This code has expired';
            }
            if ((int) $otp->attempts >= self::MAX_OTP_ATTEMPTS) {
                return 'Too many incorrect attempts — request a new code';
            }
            if (! Hash::check($code, $otp->code_hash)) {
                DB::table('otp_codes')->where('id', $otp->id)->increment('attempts');

                return 'Incorrect code';
            }

            DB::table('otp_codes')->where('id', $otp->id)->update(['consumed_at' => now()]);

            return null;
        });
    }

    private function emptyLessonModes(): array
    {
        return ['lesson_modes' => '[]'];
    }
}
