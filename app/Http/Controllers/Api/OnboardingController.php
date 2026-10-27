<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Reporting\BusinessActivityRecorder;
use App\Services\OnboardingState;
use App\Services\TeacherAddress;
use App\Services\TeacherLocation;
use App\Services\TeacherPublicPages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class OnboardingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authenticated_user');

        return response()->json($this->payload($user));
    }

    public function autosave(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authenticated_user');
        if ($user->phone_verified_at === null) {
            return $this->mobileNotVerifiedResponse();
        }
        if (! app(OnboardingState::class)->registrationCompleted($user)) {
            return response()->json([
                'error' => 'Complete registration before using autosave.',
                'registrationRequired' => true,
                'autosaveEnabled' => false,
                'registerEndpoint' => '/auth/register',
            ], 409);
        }

        $data = $this->validatedDraft($request, false);
        $location = in_array($data['accountType'] ?? $user->account_type, ['teacher', 'institute'], true)
            ? app(TeacherLocation::class)->prepare($data) : null;
        $this->persistDraft($user, $data, false, $location);

        return response()->json($this->payload(
            DB::table('users')->where('id', $user->id)->first()
        ));
    }

    public function complete(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authenticated_user');
        if ($user->phone_verified_at === null) {
            return $this->mobileNotVerifiedResponse();
        }

        $preparedData = null;
        $location = null;
        if (($request->exists('latitude') || $request->exists('longitude'))
            && ! app(OnboardingState::class)->registrationSubmitted($user)) {
            $preparedData = $this->validatedDraft($request, true, $user);
            if (in_array($preparedData['accountType'], ['teacher', 'institute'], true)) {
                // Resolve before holding registration/profile row locks.
                $location = app(TeacherLocation::class)->prepare($preparedData);
            }
        }

        DB::transaction(function () use ($request, $user, $preparedData, $location): void {
            $saved = DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            abort_if($saved === null, 403, 'Invalid or expired token');
            $state = app(OnboardingState::class);
            if ($state->registrationCompleted($saved)) {
                abort(response()->json([
                    'error' => 'Registration is already complete. Use PATCH /auth/onboarding to update your profile.',
                    ...$state->forUser($saved),
                ], 409));
            }
            if ($state->registrationSubmitted($saved)) {
                // The saved form resumes DOB/consent instead of resubmitting or changing role.
                return;
            }

            $data = $preparedData ?? $this->validatedDraft($request, true, $saved);
            $accountType = $this->normalizeAccountType($data['accountType']);
            $errors = $this->completionErrors($saved, $data, $accountType);
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $this->persistDraft($saved, $data, true, $location);
            $fresh = DB::table('users')->where('id', $saved->id)->first();
            app(BusinessActivityRecorder::class)->record('registration.submitted', $saved->id, $saved->id,
                before: ['account_type' => $saved->account_type, 'registration_submitted_at' => $saved->registration_submitted_at ?? null],
                after: ['account_type' => $accountType, 'registration_submitted_at' => $fresh->registration_submitted_at]);
            if ($state->registrationCompleted($fresh)) {
                app(BusinessActivityRecorder::class)->record('registration.completed', $saved->id, $saved->id,
                    before: ['account_type' => $saved->account_type, 'profile_completed_at' => $saved->profile_completed_at],
                    after: ['account_type' => $accountType, 'profile_completed_at' => $fresh->profile_completed_at]);
            }
        });
        $fresh = DB::table('users')->where('id', $user->id)->first();
        $payload = $this->payload($fresh);

        return response()->json($payload);
    }

    private function validatedDraft(Request $request, bool $completing, ?object $user = null): array
    {
        $requiresCredentials = $completing && ! $this->hasGoogleIdentity($user);
        $rules = [
            'accountType' => [$completing ? 'required' : 'sometimes', 'in:student,learner,teacher,institute'],
            'fullName' => [$completing ? 'required' : 'sometimes', 'nullable', 'string', 'max:160'],
            'email' => [$requiresCredentials ? 'required' : 'sometimes', 'nullable', 'email', 'max:255'],
            'password' => ['sometimes', 'nullable', 'string', 'min:8', 'max:72'],
            'phone' => ['prohibited'],
            'phoneVerifiedAt' => ['prohibited'],
            'mobileVerified' => ['prohibited'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'primarySubjectId' => ['prohibited'],
            'subjectIds' => ['prohibited'],
            'primarySubjects' => ['sometimes', 'array', 'min:1', 'max:5'],
            'primarySubjects.*' => ['required', 'integer', 'distinct', 'exists:subjects,id'],
            'contactPerson' => ['sometimes', 'nullable', 'string', 'max:160'],
            'termsAccepted' => ['sometimes', 'boolean'],
            'acknowledgeTeachersNotBackgroundChecked' => ['sometimes', 'boolean'],
        ];

        if ($completing) {
            if ($requiresCredentials) {
                $rules['password'] = ['required', 'string', 'min:8', 'max:72'];
            }
            $rules['city'] = ['required_if:accountType,teacher,institute', 'nullable', 'string', 'max:120'];
            $rules['primarySubjects'] = ['required_if:accountType,teacher', 'array', 'min:1', 'max:5'];
            $rules['contactPerson'] = ['required_if:accountType,institute', 'nullable', 'string', 'max:160'];
            $rules['termsAccepted'] = ['required', 'accepted'];
            $rules['acknowledgeTeachersNotBackgroundChecked'] = ['required', 'accepted'];
        }

        if (($request->input('accountType') ?? $request->attributes->get('authenticated_user')?->account_type ?? null) === 'institute') {
            $rules['primarySubjects'] = ['prohibited'];
        }

        $data = $request->validate($rules + TeacherAddress::rules());
        TeacherLocation::validatePair($data);
        if (isset($data['primarySubjects'])) {
            $data['primarySubjects'] = array_values(array_map('intval', $data['primarySubjects']));
        }

        return $data;
    }

    private function persistDraft(object $user, array $data, bool $completing, ?array $location = null): void
    {
        $now = now();
        DB::transaction(function () use ($user, $data, $completing, $now, $location): void {
            $user = DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            abort_if($user === null, 403, 'Invalid or expired token');
            $accountType = $this->normalizeAccountType($data['accountType'] ?? $user->account_type);
            $userUpdates = [
                'account_type' => $accountType,
                'updated_at' => $now,
            ];

            if (array_key_exists('fullName', $data)) {
                $userUpdates['full_name'] = trim((string) $data['fullName']);
            }

            if (array_key_exists('email', $data)) {
                $email = is_string($data['email']) ? strtolower(trim($data['email'])) : null;
                $email = $email === '' ? null : $email;
                if ($email !== null) {
                    $taken = DB::table('users')->where('email', $email)->where('id', '!=', $user->id)->exists();
                    if ($taken) {
                        throw ValidationException::withMessages([
                            'email' => 'This email is already linked to a different account.',
                        ]);
                    }
                }
                $userUpdates['email'] = $email;
                if ($email !== $user->email) {
                    $userUpdates['email_verified_at'] = null;
                }
            }

            if (! empty($data['password'])) {
                $userUpdates['password_hash'] = Hash::make($data['password']);
            }

            if (array_key_exists('termsAccepted', $data)) {
                $userUpdates['terms_accepted_at'] = $data['termsAccepted'] ? ($user->terms_accepted_at ?? $now) : null;
            }

            if (array_key_exists('acknowledgeTeachersNotBackgroundChecked', $data)) {
                $acknowledged = $data['acknowledgeTeachersNotBackgroundChecked']
                    ? ($user->safety_disclaimer_acknowledged_at ?? $now)
                    : null;
                $userUpdates['safety_disclaimer_acknowledged_at'] = $acknowledged;
            }

            if ($completing) {
                $userUpdates['terms_accepted_at'] = $user->terms_accepted_at ?? $now;
                $userUpdates['safety_disclaimer_acknowledged_at'] = $user->safety_disclaimer_acknowledged_at ?? $now;
                $userUpdates['registration_submitted_at'] = $user->registration_submitted_at ?? $now;
                if ($accountType !== 'learner' || (($user->date_of_birth ?? null) !== null
                    && ! app(OnboardingState::class)->requiresParentalConsent($user))) {
                    $userUpdates['profile_completed_at'] = $user->profile_completed_at ?? $now;
                }
            }

            DB::table('users')->where('id', $user->id)->update($userUpdates);

            if ($accountType === 'teacher') {
                $this->ensureProviderProfile('teacher_profiles', $user->id);
                $profileUpdates = ['updated_at' => $now];
                if (array_key_exists('city', $data)) {
                    $profileUpdates['city'] = $this->nullableString($data['city']);
                }
                if (array_key_exists('primarySubjects', $data)) {
                    // All selected subjects are peers; do not designate a single primary.
                    $profileUpdates['primary_subject_id'] = null;
                    DB::table('profile_subjects')->where('profile_type', 'teacher')->where('profile_id', $user->id)->delete();
                    foreach ($data['primarySubjects'] as $subjectId) {
                        DB::table('profile_subjects')->insert([
                            'profile_type' => 'teacher', 'profile_id' => $user->id, 'subject_id' => $subjectId,
                        ]);
                    }
                }
                DB::table('teacher_profiles')->where('user_id', $user->id)->update($profileUpdates);
                app(TeacherLocation::class)->store((int) $user->id, $location);
                app(TeacherAddress::class)->store((int) $user->id, $data);
                app(TeacherPublicPages::class)->publish((int) $user->id, (int) $user->id);
            } elseif ($accountType === 'institute') {
                $this->ensureProviderProfile('institute_profiles', $user->id);
                $profileUpdates = ['updated_at' => $now];
                if (array_key_exists('city', $data)) {
                    $profileUpdates['city'] = $this->nullableString($data['city']);
                }
                if (array_key_exists('contactPerson', $data)) {
                    $profileUpdates['contact_person'] = $this->nullableString($data['contactPerson']);
                }
                DB::table('institute_profiles')->where('user_id', $user->id)->update($profileUpdates);
                app(TeacherLocation::class)->store((int) $user->id, $location, 'institute');
                app(TeacherAddress::class)->store((int) $user->id, $data, 'institute');
            }
        });
    }

    private function completionErrors(object $user, array $data, string $accountType): array
    {
        $errors = [];
        $name = trim((string) ($data['fullName'] ?? $user->full_name ?? ''));
        if ($name === '' || strcasecmp($name, 'New user') === 0) {
            $errors['fullName'] = $accountType === 'institute'
                ? 'Enter the institute name.'
                : 'Enter your name.';
        }

        $email = $data['email'] ?? $user->email;
        if (! $this->hasGoogleIdentity($user) && (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $errors['email'] = 'Enter a valid email address.';
        }

        $passwordSet = ! empty($data['password']) || (bool) ($user->password_set ?? filled($user->password_hash ?? null));
        if (! $this->hasGoogleIdentity($user) && ! $passwordSet) {
            $errors['password'] = 'Set a password for next time.';
        }

        if ($accountType === 'teacher') {
            $profile = DB::table('teacher_profiles')->where('user_id', $user->id)->first();
            $city = $this->nullableString(array_key_exists('city', $data) ? $data['city'] : $profile?->city);
            if ($city === null) {
                $errors['city'] = 'Enter your city / location.';
            }
            if (empty($data['primarySubjects'])) {
                $errors['primarySubjects'] = 'Choose between one and five teaching subjects.';
            }
        }

        if ($accountType === 'institute') {
            $profile = DB::table('institute_profiles')->where('user_id', $user->id)->first();
            $city = $this->nullableString(array_key_exists('city', $data) ? $data['city'] : $profile?->city);
            if ($city === null) {
                $errors['city'] = 'Enter the city / location.';
            }
            $contact = $this->nullableString(array_key_exists('contactPerson', $data) ? $data['contactPerson'] : $profile?->contact_person);
            if ($contact === null) {
                $errors['contactPerson'] = 'Enter a contact person.';
            }
        }

        return $errors;
    }

    private function payload(?object $user): array
    {
        if ($user === null) {
            abort(403, 'Invalid or expired token');
        }

        $user = clone $user;
        $passwordSet = (bool) ($user->password_set ?? filled($user->password_hash ?? null));
        unset($user->password_hash, $user->password_set, $user->parental_consent_token);

        $mobileVerified = $user->phone_verified_at !== null;
        $accountType = (string) $user->account_type;
        $teacher = null;
        $institute = null;
        if ($accountType === 'teacher') {
            $teacher = DB::table('teacher_profiles as p')
                ->where('p.user_id', $user->id)
                ->select('p.public_page_slug', 'p.seo_country', 'p.user_id', 'p.slug', 'p.seo_slug', 'p.public_profile_slug', 'p.city', 'p.primary_subject_id', 'p.latitude', 'p.longitude', 'p.location_details',
                    ...array_map(fn ($column) => 'p.'.$column, array_values(TeacherAddress::FIELDS)))
                ->first();
        } elseif ($accountType === 'institute') {
            $institute = DB::table('institute_profiles')->select('city', 'contact_person', 'latitude', 'longitude', 'location_details', ...array_values(TeacherAddress::FIELDS))->where('user_id', $user->id)->first();
        }

        $provider = $teacher ?? $institute;
        $draft = [
            'accountType' => $accountType,
            'role' => $accountType === 'learner' ? 'student' : $accountType,
            'fullName' => $user->full_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'city' => $teacher->city ?? $institute->city ?? null,
            ...(in_array($accountType, ['teacher', 'institute'], true) ? TeacherAddress::draft($provider) : []),
            'latitude' => isset($provider->latitude) ? (float) $provider->latitude : null,
            'longitude' => isset($provider->longitude) ? (float) $provider->longitude : null,
            'locationDetails' => isset($provider->location_details) ? json_decode($provider->location_details, true, flags: JSON_THROW_ON_ERROR) : null,
            ...($accountType === 'institute' ? [] : ['primarySubjects' => $teacher === null ? [] : DB::table('profile_subjects')->where('profile_type', 'teacher')
                ->where('profile_id', $user->id)->orderBy('id')->pluck('subject_id')->map(fn ($id) => (int) $id)->all()]),
            'contactPerson' => $institute->contact_person ?? null,
            'passwordSet' => $passwordSet,
            'termsAccepted' => $user->terms_accepted_at !== null,
            'teachersNotBackgroundCheckedAcknowledged' => $user->safety_disclaimer_acknowledged_at !== null,
            'acknowledgeTeachersNotBackgroundChecked' => $user->safety_disclaimer_acknowledged_at !== null,
        ];

        if ($teacher !== null && $draft['primarySubjects'] === [] && $teacher->primary_subject_id !== null) {
            $draft['primarySubjects'] = [(int) $teacher->primary_subject_id];
        }

        $missing = $this->missingDraftFields($user, $accountType, $draft);
        $state = app(OnboardingState::class);
        $profileCompleted = $state->registrationCompleted($user);

        return [
            'mobileVerified' => $mobileVerified,
            'profileCompleted' => $profileCompleted,
            'registrationCompleted' => $profileCompleted,
            'autosaveEnabled' => $mobileVerified && $profileCompleted,
            'canSubmit' => $mobileVerified && $missing === [] && ! $state->registrationSubmitted($user),
            'blockers' => $mobileVerified ? [] : ['mobile_not_verified'],
            'missingFields' => $missing,
            'savedAt' => $user->updated_at ?? null,
            ...($teacher !== null && $profileCompleted && $user->suspended_at === null
                ? TeacherPublicPages::urls($teacher, $user->full_name) : []),
            ...app(OnboardingState::class)->forUser($user),
            'draft' => $draft,
            'user' => $user,
        ];
    }

    private function missingDraftFields(object $user, string $accountType, array $draft): array
    {
        $missing = [];
        $name = trim((string) $draft['fullName']);
        if ($name === '' || strcasecmp($name, 'New user') === 0) {
            $missing[] = 'fullName';
        }
        if (! $this->hasGoogleIdentity($user) && (! is_string($draft['email']) || $draft['email'] === '')) {
            $missing[] = 'email';
        }
        if (! $this->hasGoogleIdentity($user) && $draft['passwordSet'] !== true) {
            $missing[] = 'password';
        }
        if ($draft['termsAccepted'] !== true) {
            $missing[] = 'termsAccepted';
        }
        if ($draft['teachersNotBackgroundCheckedAcknowledged'] !== true) {
            $missing[] = 'acknowledgeTeachersNotBackgroundChecked';
        }
        if ($accountType === 'teacher') {
            if ($draft['city'] === null || $draft['city'] === '') {
                $missing[] = 'city';
            }
            if ($draft['primarySubjects'] === []) {
                $missing[] = 'primarySubjects';
            }
        }
        if ($accountType === 'institute') {
            if ($draft['city'] === null || $draft['city'] === '') {
                $missing[] = 'city';
            }
            if ($draft['contactPerson'] === null || $draft['contactPerson'] === '') {
                $missing[] = 'contactPerson';
            }
        }
        if ($user->phone_verified_at === null) {
            $missing[] = 'mobileVerified';
        }

        return $missing;
    }

    private function hasGoogleIdentity(?object $user): bool
    {
        return filled($user->google_id ?? null);
    }

    private function mobileNotVerifiedResponse(): JsonResponse
    {
        return response()->json([
            'error' => 'Verify your phone number before continuing.',
            'mobileVerified' => false,
            'phoneVerificationRequired' => true,
            'requestOtpEndpoint' => '/auth/otp/request',
            'verifyPhoneEndpoint' => '/auth/link-phone',
            'canSubmit' => false,
            'blockers' => ['mobile_not_verified'],
            'nextStep' => 'mobile_verification',
        ], 403);
    }

    private function normalizeAccountType(mixed $value): string
    {
        return match ($value) {
            'student', 'learner' => 'learner',
            'teacher' => 'teacher',
            'institute' => 'institute',
            default => 'learner',
        };
    }

    private function ensureProviderProfile(string $table, int $userId): void
    {
        DB::table($table)->insertOrIgnore([
            'user_id' => $userId,
            'lesson_modes' => '[]',
        ]);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
