<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RevokeChatSessions;
use App\Jobs\SendNotificationEmail;
use App\Reporting\BusinessActivityRecorder;
use App\Services\AuthenticationService;
use App\Services\OnboardingState;
use App\Services\OtpDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
        $id = (string) Str::uuid();

        DB::table('otp_codes')->insert([
            'id' => $id,
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
                $attemptId = (string) Str::uuid();
                DB::table('otp_delivery_attempts')->insert([
                    'id' => $attemptId, 'otp_code_id' => $id, 'channel' => $channel,
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
                if ($user->profile_completed_at !== null || filled($user->password_hash)) {
                    return ['error' => 'This account is already registered. Log in with your email and password.', 'loginRequired' => true];
                }

                return ['user' => $user];
            }

            $userId = (string) Str::uuid();
            DB::table('users')->insert([
                'id' => $userId,
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

        return response()->json([
            'user' => $user,
            ...app(OnboardingState::class)->forUser($user),
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

        $user = $this->authentication->publicUser($user);

        return response()->json([
            'token' => $this->authentication->issueSessionToken($user->id, (int) ($user->auth_version ?? 0)),
            'user' => $user,
            'authMethod' => 'password',
            ...app(OnboardingState::class)->forUser($user),
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
                    'id' => (string) Str::uuid(),
                    'user_id' => $user->id,
                ] + $this->emptyLessonModes());
            } elseif ($data['accountType'] === 'institute') {
                DB::table('institute_profiles')->insertOrIgnore([
                    'id' => (string) Str::uuid(),
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
            'guardianName' => ['nullable', 'string', 'max:160'],
            'guardianContact' => ['nullable', 'string', 'max:255'],
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

        if ($age < 18) {
            if (empty($data['guardianName']) || empty($data['guardianContact'])) {
                return response()->json([
                    'error' => 'A parent or guardian name and contact (email or phone) are required for users under 18',
                ], 400);
            }
            if (str_contains($data['guardianContact'], '@')) {
                if (filter_var($data['guardianContact'], FILTER_VALIDATE_EMAIL) === false) {
                    return response()->json(['error' => 'Enter a valid guardian email address or phone number'], 400);
                }
            } else {
                try {
                    $this->validatedPhone($data['guardianContact']);
                } catch (ValidationException) {
                    return response()->json(['error' => 'Enter a valid guardian email address or phone number'], 400);
                }
            }

            $token = bin2hex(random_bytes(24));
            DB::table('users')->where('id', $user->id)->update([
                'date_of_birth' => $birthDate->toDateString(),
                'is_minor' => true,
                'guardian_name' => $data['guardianName'],
                'guardian_contact' => $data['guardianContact'],
                'parental_consent_token' => $token,
                'parental_consent_confirmed_at' => null,
                'updated_at' => now(),
            ]);

            if (str_contains($data['guardianContact'], '@')) {
                $this->sendGuardianConsentEmail($user->full_name, $data['guardianContact'], $token);
            } else {
                Log::warning('Guardian consent was recorded with a phone contact; SMS consent delivery is not configured.');
            }

            return response()->json([
                'isMinor' => true,
                'parentalConsentPending' => true,
                'message' => 'A consent request has been sent to your parent/guardian. Messaging and booking are unavailable until they confirm.',
            ]);
        }

        DB::table('users')->where('id', $user->id)->update([
            'date_of_birth' => $birthDate->toDateString(),
            'is_minor' => false,
            'updated_at' => now(),
        ]);

        return response()->json(['isMinor' => false]);
    }

    public function confirmParentalConsent(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:128']]);

        $user = DB::transaction(function () use ($data): ?object {
            $user = DB::table('users')->select('id', 'full_name')
                ->where('parental_consent_token', $data['token'])
                ->lockForUpdate()
                ->first();
            if ($user === null) {
                return null;
            }

            DB::table('users')->where('id', $user->id)->where('parental_consent_token', $data['token'])->update([
                'parental_consent_confirmed_at' => now(),
                'parental_consent_token' => null,
                'updated_at' => now(),
            ]);

            return $user;
        });

        if ($user === null) {
            return response()->json(['error' => 'This consent link is invalid or has already been used.'], 404);
        }

        return response()->json(['ok' => true, 'confirmedFor' => $user->full_name]);
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

    private function assertOtpIdentity(object $otp, ?string $userId): void
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

    private function consumeOtp(string $phone, string $code, ?string $userId = null): ?string
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

    private function sendGuardianConsentEmail(string $fullName, string $guardianContact, string $token): void
    {
        $consentLink = rtrim(config('services.nearby_teacher.app_base_url'), '/').'/parental-consent/'.$token;
        SendNotificationEmail::dispatch(
            $guardianContact,
            $fullName.' wants to use Nearby Teacher — your consent is needed',
            $fullName.' has signed up for Nearby Teacher and listed you as their parent/guardian. '
            .'Nearby Teacher connects learners with nearby teachers and institutes; background checks are not '
            .'conducted on listings. If you consent to '.$fullName.' using the platform, confirm here: '
            .$consentLink."\n\nIf you did not expect this, you can ignore this email."
        );
    }
}
