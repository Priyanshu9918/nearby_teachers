<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Reporting\BusinessActivityRecorder;
use App\Services\OnboardingState;
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
        $this->persistDraft($user, $data, false);

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

        DB::transaction(function () use ($request, $user): void {
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

            $data = $this->validatedDraft($request, true, $saved);
            $accountType = $this->normalizeAccountType($data['accountType']);
            $errors = $this->completionErrors($saved, $data, $accountType);
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $this->persistDraft($saved, $data, true);
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
            'primarySubjectId' => ['sometimes', 'nullable', 'integer', 'exists:subjects,id'],
            'contactPerson' => ['sometimes', 'nullable', 'string', 'max:160'],
            'termsAccepted' => ['sometimes', 'boolean'],
            'acknowledgeTeachersNotBackgroundChecked' => ['sometimes', 'boolean'],
        ];

        if ($completing) {
            if ($requiresCredentials) {
                $rules['password'] = ['required', 'string', 'min:8', 'max:72'];
            }
            $rules['city'] = ['required_if:accountType,teacher,institute', 'nullable', 'string', 'max:120'];
            $rules['primarySubjectId'] = ['required_if:accountType,teacher', 'nullable', 'integer', 'exists:subjects,id'];
            $rules['contactPerson'] = ['required_if:accountType,institute', 'nullable', 'string', 'max:160'];
            $rules['termsAccepted'] = ['required', 'accepted'];
            $rules['acknowledgeTeachersNotBackgroundChecked'] = ['required', 'accepted'];
        }

        return $request->validate($rules);
    }

    private function persistDraft(object $user, array $data, bool $completing): void
    {
        $now = now();
        DB::transaction(function () use ($user, $data, $completing, $now): void {
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
                if (array_key_exists('primarySubjectId', $data)) {
                    $profileUpdates['primary_subject_id'] = $data['primarySubjectId'];
                    if ($data['primarySubjectId'] !== null) {
                        DB::table('profile_subjects')->insertOrIgnore([
                            'profile_type' => 'teacher',
                            'profile_id' => $user->id,
                            'subject_id' => $data['primarySubjectId'],
                        ]);
                    }
                }
                DB::table('teacher_profiles')->where('user_id', $user->id)->update($profileUpdates);
                app(TeacherPublicPages::class)->publish((int) $user->id);
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
            $subjectId = array_key_exists('primarySubjectId', $data) ? $data['primarySubjectId'] : $profile?->primary_subject_id;
            if ($subjectId === null) {
                $errors['primarySubjectId'] = 'Choose a primary teaching subject.';
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
                ->leftJoin('subjects as s', 's.id', '=', 'p.primary_subject_id')
                ->where('p.user_id', $user->id)
                ->select('p.user_id', 'p.slug', 'p.seo_slug', 'p.public_profile_slug', 'p.city', 'p.primary_subject_id', 's.name as primary_subject_name')
                ->first();
        } elseif ($accountType === 'institute') {
            $institute = DB::table('institute_profiles')->select('city', 'contact_person')->where('user_id', $user->id)->first();
        }

        $draft = [
            'accountType' => $accountType,
            'role' => $accountType === 'learner' ? 'student' : $accountType,
            'fullName' => $user->full_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'city' => $teacher->city ?? $institute->city ?? null,
            'primarySubjectId' => isset($teacher->primary_subject_id) && $teacher->primary_subject_id !== null
                ? (int) $teacher->primary_subject_id
                : null,
            'primarySubjectName' => $teacher->primary_subject_name ?? null,
            'contactPerson' => $institute->contact_person ?? null,
            'passwordSet' => $passwordSet,
            'termsAccepted' => $user->terms_accepted_at !== null,
            'teachersNotBackgroundCheckedAcknowledged' => $user->safety_disclaimer_acknowledged_at !== null,
            'acknowledgeTeachersNotBackgroundChecked' => $user->safety_disclaimer_acknowledged_at !== null,
        ];

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
            if ($draft['primarySubjectId'] === null) {
                $missing[] = 'primarySubjectId';
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
