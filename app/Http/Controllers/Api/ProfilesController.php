<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Reporting\BusinessActivityRecorder;
use App\Services\CountryCurrency;
use App\Services\InstitutePublicPages;
use App\Services\OnboardingState;
use App\Services\ProfileDraft;
use App\Services\ProfileEditor;
use App\Services\TeacherAddress;
use App\Services\TeacherLocation;
use App\Services\TeacherPublicPages;
use App\Support\PublicMediaUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProfilesController extends Controller
{
    private const LESSON_MODES = ['in_person', 'home_tuition', 'online'];

    private const INSTITUTE_TYPES = ['independent', 'franchise', 'mcp_partner', 'bsl_direct'];

    private const PHONE_REVEAL_DAILY_LIMIT = 5;

    public function updateMine(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authenticated_user');
        if (! app(OnboardingState::class)->registrationCompleted($user)) {
            return response()->json([
                'error' => 'Complete registration before updating your listing profile.',
                'registrationRequired' => true,
                'registerEndpoint' => '/auth/register',
            ], 409);
        }
        $type = $user->account_type;
        if (! in_array($type, ['teacher', 'institute'], true)) {
            return response()->json(['error' => 'Only teacher or institute accounts have a listing profile'], 400);
        }

        $isTeacher = $type === 'teacher';
        $subjectsField = $isTeacher ? 'primarySubjects' : 'subjectIds';
        $descriptionField = $isTeacher ? 'bio' : 'description';
        $rateField = $isTeacher ? 'ratePerHour' : 'startingRate';
        $rules = [
            $descriptionField => ['sometimes', 'nullable', 'string', 'max:2000'],
            $rateField => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'lessonModes' => ['sometimes', 'array'],
            'lessonModes.*' => ['string', 'distinct', 'in:'.implode(',', self::LESSON_MODES)],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'addressText' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'state' => ['sometimes', 'nullable', 'string', 'max:120'],
            'country' => ['sometimes', 'nullable', 'string', 'max:120'],
            'countryCode' => ['sometimes', 'nullable', 'string', 'size:2'],
            'trialDays' => ['sometimes', 'integer', 'between:0,7'],
            $subjectsField => $isTeacher ? ['sometimes', 'array', 'min:1', 'max:5'] : ['sometimes', 'array', 'max:5'],
            $subjectsField.'.*' => ['required', 'integer', 'distinct', 'exists:subjects,id'],
        ];
        $rules += TeacherAddress::rules();
        if ($isTeacher) {
            $rules['subjectIds'] = ['prohibited'];
            $rules['primarySubjectId'] = ['prohibited'];
            $rules['linkedinUrl'] = ['sometimes', 'nullable', 'url:http,https', 'max:2048'];
        } else {
            $rules['instructorsText'] = ['sometimes', 'nullable', 'string', 'max:2000'];
            $rules['websiteUrl'] = ['sometimes', 'nullable', 'url', 'max:2048'];
        }

        $data = $request->validate($rules);
        if ($request->exists('latitude') !== $request->exists('longitude')) {
            throw ValidationException::withMessages(['location' => 'Latitude and longitude must be provided together']);
        }
        if ($request->exists('latitude')
            && (($data['latitude'] === null) !== ($data['longitude'] === null))) {
            throw ValidationException::withMessages(['location' => 'Latitude and longitude must both be set or both be empty']);
        }
        if (array_key_exists('currency', $data) && $data['currency'] === null) {
            throw ValidationException::withMessages(['currency' => 'Currency cannot be empty']);
        }

        $location = app(TeacherLocation::class)->prepare($data);
        $profileTable = $isTeacher ? 'teacher_profiles' : 'institute_profiles';
        $updateParts = [];
        $bindings = [];
        $this->appendField($updateParts, $bindings, $data, $descriptionField, $descriptionField);
        $this->appendField($updateParts, $bindings, $data, $rateField, $isTeacher ? 'rate_per_hour' : 'starting_rate');
        $this->appendField($updateParts, $bindings, $data, 'addressText', 'address_text');
        $this->appendField($updateParts, $bindings, $data, 'city', 'city');
        $this->appendField($updateParts, $bindings, $data, 'countryCode', 'country_code', true);
        $this->appendField($updateParts, $bindings, $data, 'trialDays', 'trial_days');

        if ($isTeacher) {
            $this->appendField($updateParts, $bindings, $data, 'linkedinUrl', 'linkedin_url');
        } else {
            $this->appendField($updateParts, $bindings, $data, 'instructorsText', 'instructors_text');
            $this->appendField($updateParts, $bindings, $data, 'websiteUrl', 'website_url');
        }

        if (array_key_exists('currency', $data)) {
            $updateParts[] = 'currency = ?';
            $bindings[] = strtoupper($data['currency']);
        } elseif (array_key_exists('countryCode', $data) && ($derived = CountryCurrency::forCountry($data['countryCode'])) !== null) {
            $updateParts[] = 'currency = ?';
            $bindings[] = $derived;
        }

        if (array_key_exists('lessonModes', $data)) {
            $updateParts[] = 'lesson_modes = ?';
            $bindings[] = json_encode($data['lessonModes'], JSON_THROW_ON_ERROR);
        }
        $updateParts[] = 'updated_at = now()';

        DB::transaction(function () use ($data, $subjectsField, $user, $type, $profileTable, $updateParts, $bindings, $location): void {
            $lockedProfile = DB::table($profileTable)->where('user_id', $user->id)->lockForUpdate()->first();
            if ($lockedProfile === null) {
                abort(404, 'Profile not found');
            }

            DB::update(
                'UPDATE '.$profileTable.' SET '.implode(', ', $updateParts).' WHERE user_id = ?',
                [...$bindings, $user->id]
            );

            if (array_key_exists($subjectsField, $data)) {
                if ($type === 'teacher') {
                    DB::table($profileTable)->where('user_id', $user->id)->update(['primary_subject_id' => null]);
                }
                DB::table('profile_subjects')
                    ->where('profile_type', $type)
                    ->where('profile_id', $user->id)
                    ->delete();

                foreach ($data[$subjectsField] as $subjectId) {
                    DB::table('profile_subjects')->insert([
                        'profile_type' => $type,
                        'profile_id' => $user->id,
                        'subject_id' => $subjectId,
                    ]);
                }
            }

            $displayName = $type === 'institute' && filled($lockedProfile->listing_name ?? null) ? $lockedProfile->listing_name : $user->full_name;
            $this->ensureSlug($profileTable, $user->id, $displayName);
            app(TeacherLocation::class)->store((int) $user->id, $location, $type);
            app(TeacherAddress::class)->store((int) $user->id, $data, $type);
            $publisher = $type === 'teacher' ? TeacherPublicPages::class : InstitutePublicPages::class;
            app($publisher)->publish((int) $user->id, (int) $user->id);
        });

        $teacherResponse = [];
        if ($isTeacher) {
            $profile = DB::table($profileTable)->where('user_id', $user->id)
                ->select('public_page_slug', 'seo_country', 'user_id', 'slug', 'seo_slug', 'public_profile_slug', 'location_details')->first();
            $teacherResponse = [...TeacherPublicPages::urls($profile, $user->full_name),
                'locationDetails' => $profile->location_details === null ? null : json_decode($profile->location_details, true, flags: JSON_THROW_ON_ERROR)];
        }

        if (! $isTeacher) {
            $profile = DB::table($profileTable)->where('user_id', $user->id)
                ->first(['slug', 'is_profile_complete', 'location_details', 'public_page_slug', 'seo_country']);
            $teacherResponse = [...ProfileEditor::urls($profile, $type),
                'locationDetails' => $profile->location_details === null ? null : json_decode($profile->location_details, true, flags: JSON_THROW_ON_ERROR)];
        }

        return response()->json(['ok' => true, ...$teacherResponse]);
    }

    // Existing teacher routes remain restricted to teachers and delegate to the shared editor.
    public function updateTeacherStep(Request $request, string $step): JsonResponse
    {
        abort_unless($request->attributes->get('authenticated_user')->account_type === 'teacher', 403, 'Teacher account required.');

        return $this->updateStep($request, $step);
    }

    public function publishTeacherProfile(Request $request): JsonResponse
    {
        abort_unless($request->attributes->get('authenticated_user')->account_type === 'teacher', 403, 'Teacher account required.');

        return $this->publishProfile($request);
    }

    public function showMine(Request $request): JsonResponse
    {
        abort_unless($request->attributes->get('authenticated_user')->account_type === 'teacher', 403, 'Teacher account required.');

        return $this->showProvider($request);
    }

    public function uploadTeacherPhoto(Request $request): JsonResponse
    {
        abort_unless($request->attributes->get('authenticated_user')->account_type === 'teacher', 403, 'Teacher account required.');

        return $this->uploadPhoto($request);
    }

    private function providerType(Request $request): string
    {
        $type = $request->attributes->get('authenticated_user')->account_type;
        abort_unless(in_array($type, ['teacher', 'institute'], true), 403, 'Teacher or institute account required.');

        return $type;
    }

    /** Save one allowlisted section of either provider's six-step editor. */
    public function updateStep(Request $request, string $step): JsonResponse
    {
        $type = $this->providerType($request);
        $table = ProfileEditor::table($type);
        $user = $request->attributes->get('authenticated_user');
        if (! app(OnboardingState::class)->registrationCompleted($user)) {
            return response()->json(['error' => 'Complete registration before updating your profile.', 'registrationRequired' => true], 409);
        }
        $isTeacher = $type === 'teacher';
        $subjects = $isTeacher ? 'primarySubjects' : 'subjectIds';
        $story = $isTeacher ? 'bio' : 'description';
        $allowed = match ($step) {
            '1' => $isTeacher ? ['fullName', 'listingName'] : ['fullName', 'listingName', 'contactPerson'],
            '2' => [$subjects],
            '3' => ['lessonModes', 'latitude', 'longitude', 'addressText', 'city', 'state', 'country', 'countryCode', 'district', 'subDistrict', 'locality', 'localityType', 'postalCode'],
            '4' => [$isTeacher ? 'ratePerHour' : 'startingRate', 'currency', 'trialDays'],
            '5' => $isTeacher ? ['bio', 'linkedinUrl'] : ['description', 'websiteUrl', 'instructorsText'],
            default => abort(404),
        };
        $payload = $request->only($allowed);
        abort_if($payload === [], 422, 'Provide at least one field for this profile step.');

        if ($step === '1') {
            $rules = [
                'fullName' => ['sometimes', 'required', 'string', 'min:2', 'max:160'],
                'listingName' => ['sometimes', 'nullable', 'string', 'min:2', 'max:160'],
            ];
            if (! $isTeacher) {
                $rules['contactPerson'] = ['sometimes', 'required', 'string', 'min:2', 'max:160'];
            }
            $data = validator($payload, $rules)->validate();
            $draft = DB::transaction(function () use ($user, $data, $type, $table): array {
                abort_if(DB::table($table)->where('user_id', $user->id)->lockForUpdate()->first() === null, 404, 'Profile not found.');
                $changed = [];
                if (isset($data['fullName'])) {
                    DB::table('users')->where('id', $user->id)->update(['full_name' => trim($data['fullName']), 'updated_at' => now()]);
                    $changed[] = 'full_name';
                }
                $updates = ['updated_at' => now()];
                foreach (['listingName' => 'listing_name', 'contactPerson' => 'contact_person'] as $input => $column) {
                    if (array_key_exists($input, $data)) {
                        $updates[$column] = $data[$input] === null ? null : trim($data[$input]);
                        $changed[] = $column;
                    }
                }
                DB::table($table)->where('user_id', $user->id)->update($updates);
                app(BusinessActivityRecorder::class)->record($type.'.profile_step_updated', $user->id, $user->id,
                    after: ['step' => 1, 'changed_fields' => $changed]);
                $publisher = $type === 'teacher' ? TeacherPublicPages::class : InstitutePublicPages::class;
                app($publisher)->publish((int) $user->id, (int) $user->id);

                return app(ProfileDraft::class)->saveStep((int) $user->id, 1, $type);
            });
            $columns = ['u.full_name', 'u.email', 'p.listing_name'];
            if (! $isTeacher) {
                $columns[] = 'p.contact_person';
            }
            $saved = DB::table('users as u')->join($table.' as p', 'p.user_id', '=', 'u.id')->where('u.id', $user->id)->first($columns);

            return response()->json(['ok' => true, 'step' => 1, 'fullName' => $saved->full_name,
                'email' => $saved->email, 'listingName' => $saved->listing_name ?? $saved->full_name,
                ...($isTeacher ? [] : ['contactPerson' => $saved->contact_person]), 'profileDraft' => $draft]);
        }

        if ($step === '2') {
            validator($payload, [$subjects => ['required', 'array', 'min:1', 'max:5'],
                $subjects.'.*' => ['required', 'integer', 'distinct', 'exists:subjects,id']])->validate();
        }
        if ($step === '5') {
            // Validate the type before counting characters, including malformed array input.
            validator($payload, [$story => ['sometimes', 'nullable', 'string', 'max:2000']])->validate();
            if (isset($payload[$story]) && ProfileEditor::characterCount($payload[$story]) < 100) {
                throw ValidationException::withMessages([$story => 'Write at least 100 characters about '.($isTeacher ? 'yourself.' : 'your institute.')]);
            }
        }
        $request->replace($payload);
        $body = DB::transaction(function () use ($request, $step, $payload, $user, $type): array {
            $body = $this->updateMine($request)->getData(true);
            app(BusinessActivityRecorder::class)->record($type.'.profile_step_updated', $user->id, $user->id,
                after: ['step' => (int) $step, 'changed_fields' => array_keys($payload)]);
            $body['profileDraft'] = app(ProfileDraft::class)->saveStep((int) $user->id, (int) $step, $type);

            return $body;
        });

        return response()->json([...$body, 'step' => (int) $step]);
    }

    public function publishProfile(Request $request): JsonResponse
    {
        $type = $this->providerType($request);
        $table = ProfileEditor::table($type);
        $user = $request->attributes->get('authenticated_user');
        if (! app(OnboardingState::class)->registrationCompleted($user)) {
            return response()->json(['error' => 'Complete registration before publishing your profile.', 'registrationRequired' => true], 409);
        }
        $request->validate(['agreeToTerms' => ['required', 'accepted']]);

        return DB::transaction(function () use ($user, $type, $table): JsonResponse {
            $profile = DB::table($table)->where('user_id', $user->id)->lockForUpdate()->first();
            abort_if($profile === null, 404, 'Profile not found.');
            $missing = ProfileEditor::missingFields($profile, $type, ProfileEditor::subjects((int) $user->id, $type)->isNotEmpty());
            if ($missing !== []) {
                return response()->json(['error' => 'Complete the required profile sections before publishing.', 'missingFields' => $missing], 422);
            }
            DB::table('users')->where('id', $user->id)->update(['terms_accepted_at' => now(), 'updated_at' => now()]);
            DB::table($table)->where('user_id', $user->id)->update(['is_profile_complete' => true, 'updated_at' => now()]);
            if ($type === 'teacher') {
                app(TeacherPublicPages::class)->publish((int) $user->id, (int) $user->id);
            } else {
                $this->ensureSlug($table, (int) $user->id, $profile->listing_name ?: $user->full_name);
                app(InstitutePublicPages::class)->publish((int) $user->id, (int) $user->id);
            }
            $profile = DB::table($table)->where('user_id', $user->id)->first();
            if ($profile->public_page_slug === null) {
                // Roll back terms/completion when publication could not succeed.
                throw ValidationException::withMessages(['profile' => 'Profile is not eligible for a public page yet.']);
            }
            app(BusinessActivityRecorder::class)->record($type.'.profile_published', $user->id, $user->id,
                after: ['status' => 'published', 'terms_accepted' => true, 'profile_complete' => true]);

            return response()->json(['ok' => true, 'published' => true, 'profileComplete' => true,
                'profileDraft' => ProfileDraft::loadForOwner($profile, (int) $user->id, $type), ...ProfileEditor::urls($profile, $type)]);
        });
    }

    public function showProvider(Request $request): JsonResponse
    {
        $type = $this->providerType($request);
        $user = $request->attributes->get('authenticated_user');
        $columns = ['id', 'user_id', 'listing_name', 'is_profile_complete', 'completed_profile_steps', 'profile_draft_saved_at',
            'currency', 'lesson_modes', 'latitude', 'longitude', 'address_text', 'city', 'state', 'country', 'country_code', 'slug',
            'is_verified', 'verified_at', 'trial_days', 'rating_avg', 'rating_count', 'district', 'sub_district', 'locality',
            'locality_type', 'postal_code', 'location_details'];
        $columns = [...$columns, ...($type === 'teacher'
            ? ['primary_subject_id', 'bio', 'rate_per_hour', 'linkedin_url', 'public_page_slug', 'public_profile_slug', 'seo_slug', 'seo_country', 'seo_city_slug']
            : ['contact_person', 'description', 'starting_rate', 'instructors_text', 'website_url', 'institute_type', 'is_bsl_official', 'bsl_official_since', 'public_page_slug', 'seo_country', 'seo_city_slug'])];
        $profile = DB::table(ProfileEditor::table($type))->where('user_id', $user->id)->first($columns);
        abort_if($profile === null, 404, 'Profile not found.');
        $profile->lesson_modes = json_decode($profile->lesson_modes ?: '[]', true, flags: JSON_THROW_ON_ERROR);
        $profile->is_profile_complete = (bool) $profile->is_profile_complete;
        $profile->location_details = $profile->location_details === null ? null : json_decode($profile->location_details, true, flags: JSON_THROW_ON_ERROR);
        $userData = DB::table('users')->where('id', $user->id)->first(['id', 'full_name', 'email', 'phone', 'avatar_url', 'avatar_status', 'phone_verified_at', 'profile_completed_at', 'terms_accepted_at']);
        $profile->listing_name = $profile->listing_name ?: $userData->full_name;
        $subjects = ProfileEditor::subjects((int) $user->id, $type);

        return response()->json(['user' => $userData, 'profile' => $profile, 'profileComplete' => $profile->is_profile_complete,
            'profileDraft' => ProfileDraft::forOwner($profile, $userData, $type, $subjects->pluck('id')->all()), ...ProfileEditor::urls($profile, $type),
            'subjects' => $subjects]);
    }

    public function uploadPhoto(Request $request): JsonResponse
    {
        $type = $this->providerType($request);
        $user = $request->attributes->get('authenticated_user');
        $data = $request->validate(['image' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120']]);
        $path = $data['image']->store($type.'-photos', 'public');
        $url = PublicMediaUrl::fromStoragePath($path);
        DB::transaction(function () use ($user, $url, $type): void {
            DB::table('users')->where('id', $user->id)->update(['avatar_url' => $url, 'avatar_status' => 'pending', 'updated_at' => now()]);
            app(BusinessActivityRecorder::class)->record($type.'.avatar_submitted', $user->id, $user->id,
                after: ['avatar_status' => 'pending']);
        });

        return response()->json(['user' => DB::table('users')->where('id', $user->id)->first(['id', 'avatar_url', 'avatar_status']),
            'message' => 'Photo uploaded; it will appear on your public profile after review.'], 201);
    }

    public function show(Request $request, string $type, int $id): JsonResponse
    {
        $profileTable = $type === 'teacher' ? 'teacher_profiles' : 'institute_profiles';
        $profile = DB::table($profileTable.' as p')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->leftJoinSub(
                DB::table('bookings')
                    ->select('provider_id')
                    ->selectRaw('count(*) as lessons_completed')
                    ->where('status', 'completed')
                    ->where('provider_id', $id)
                    ->groupBy('provider_id'),
                'completed_bookings',
                'completed_bookings.provider_id',
                '=',
                'p.user_id'
            )
            ->where('p.user_id', $id)
            ->select('p.*')
            ->selectRaw('COALESCE(NULLIF(p.listing_name, \'\'), u.full_name) AS full_name')
            ->selectRaw("CASE WHEN u.avatar_status = 'approved' THEN u.avatar_url ELSE NULL END AS avatar_url")
            ->addSelect('u.phone')
            ->selectRaw('COALESCE(completed_bookings.lessons_completed, 0) AS lessons_completed')
            ->first();

        if ($profile === null) {
            return response()->json(['error' => 'Not found'], 404);
        }
        // Enrichment belongs to the owner onboarding response and admin reports only.
        unset($profile->location_details, $profile->completed_profile_steps, $profile->profile_draft_saved_at);
        unset($profile->seo_state_hash);
        unset($profile->district, $profile->sub_district, $profile->locality, $profile->locality_type, $profile->postal_code);

        $phoneQuota = null;
        $viewer = $request->attributes->get('authenticated_user');
        if ($viewer === null) {
            unset($profile->phone);
        } else {
            $phoneQuota = $this->recordPhoneReveal($viewer->id, $id);
            if (! $phoneQuota['allowed']) {
                unset($profile->phone);
            }
        }

        $subjects = DB::table('profile_subjects as ps')
            ->join('subjects as s', 's.id', '=', 'ps.subject_id')
            ->where('ps.profile_type', $type)
            ->where('ps.profile_id', $id)
            ->select('s.id', 's.name', 's.is_bsl')
            ->get()
            ->map(static function (object $subject): object {
                $subject->id = (int) $subject->id;
                $subject->is_bsl = (bool) $subject->is_bsl;

                return $subject;
            });

        $this->logProfileView($id, $request);

        return response()->json([
            'profile' => $profile,
            'subjects' => $subjects,
            ...($phoneQuota !== null && ! $phoneQuota['allowed']
                ? ['phoneLimitReached' => true, 'phoneRevealsRemainingToday' => 0]
                : ($phoneQuota !== null ? ['phoneRevealsRemainingToday' => $phoneQuota['remainingToday']] : [])),
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authenticated_user');
        if (! in_array($user->account_type, ['teacher', 'institute'], true)) {
            return response()->json(['error' => 'Only teacher or institute accounts have stats'], 400);
        }

        $requestedDays = filter_var($request->query('days'), FILTER_VALIDATE_INT);
        $days = $requestedDays !== false && $requestedDays > 0 ? min($requestedDays, 365) : 30;
        $since = now()->subDays($days);

        $profileViews = DB::table('page_views')
            ->where('page_type', 'app_profile')
            ->where('target', $user->id)
            ->where('created_at', '>', $since)
            ->count();

        return response()->json([
            'sinceDays' => $days,
            'profileViews' => $profileViews,
        ]);
    }

    public function verify(Request $request, string $type, int $id): JsonResponse
    {
        $data = $request->validate(['verified' => ['required', 'boolean']]);
        $table = $type === 'teacher' ? 'teacher_profiles' : 'institute_profiles';
        $profile = DB::table($table)->where('user_id', $id)->first();
        if ($profile === null) {
            return response()->json(['error' => 'Profile not found'], 404);
        }

        DB::table($table)->where('user_id', $id)->update([
            'is_verified' => $data['verified'],
            'verified_at' => $data['verified'] ? ($profile->verified_at ?? now()) : null,
        ]);

        return response()->json(['profile' => DB::table($table)->where('user_id', $id)->first()]);
    }

    public function classifyInstitute(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['institute_type' => ['required', 'in:'.implode(',', self::INSTITUTE_TYPES)]]);
        $updated = DB::table('institute_profiles')->where('user_id', $id)->update([
            'institute_type' => $data['institute_type'],
        ]);

        if ($updated === 0 && ! DB::table('institute_profiles')->where('user_id', $id)->exists()) {
            return response()->json(['error' => 'Profile not found'], 404);
        }

        return response()->json(['profile' => DB::table('institute_profiles')->where('user_id', $id)->first()]);
    }

    public function setBslVerified(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['verified' => ['required', 'boolean']]);
        $admin = $request->attributes->get('authenticated_user');
        $result = DB::transaction(function () use ($admin, $data, $id): array {
            $profile = DB::table('institute_profiles')->where('user_id', $id)->lockForUpdate()->first();
            if ($profile === null) {
                return ['error' => 'Profile not found', 'status' => 404];
            }
            if ($data['verified'] && $profile->institute_type !== 'bsl_direct') {
                return [
                    'error' => 'BSL Verified is reserved for directly-operated BSL centres. Franchise and MCP partners are not eligible.',
                    'status' => 400,
                ];
            }

            $wasVerified = (bool) $profile->is_bsl_official;
            if ($wasVerified !== (bool) $data['verified']) {
                DB::table('institute_profiles')->where('user_id', $id)->update([
                    'is_bsl_official' => $data['verified'],
                    'bsl_official_since' => $data['verified'] ? ($profile->bsl_official_since ?? now()) : null,
                    'updated_at' => now(),
                ]);
                app(BusinessActivityRecorder::class)->record('institute.bsl_verified_changed', (string) $profile->id,
                    $admin->id, $id,
                    before: ['is_bsl_official' => $wasVerified], after: ['is_bsl_official' => (bool) $data['verified']]);
            }

            return ['profile' => DB::table('institute_profiles')->where('user_id', $id)->first()];
        }, 3);

        return isset($result['error'])
            ? response()->json(['error' => $result['error']], $result['status'])
            : response()->json(['profile' => $result['profile']]);
    }

    private function appendField(array &$parts, array &$bindings, array $data, string $input, string $column, bool $uppercase = false): void
    {
        if (! array_key_exists($input, $data)) {
            return;
        }

        $parts[] = $column.' = ?';
        $value = $data[$input];
        $bindings[] = $uppercase && is_string($value) ? strtoupper($value) : $value;
    }

    private function ensureSlug(string $table, int $userId, string $fullName): void
    {
        $profile = DB::table($table)->select('slug', 'city')->where('user_id', $userId)->first();
        if ($profile === null || $profile->slug !== null) {
            return;
        }

        $base = Str::slug(trim($fullName.' '.($profile->city ?? ''))) ?: 'listing';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = $attempt === 0 ? $base : $base.'-'.Str::lower(Str::random(5));
            if (DB::table($table)->where('slug', $candidate)->exists()) {
                continue;
            }

            DB::table($table)->where('user_id', $userId)->whereNull('slug')->update(['slug' => $candidate]);

            return;
        }

        throw new \RuntimeException('Unable to create a unique profile URL. Please try again.');
    }

    private function recordPhoneReveal(int $viewerId, int $profileUserId): array
    {
        return DB::transaction(function () use ($viewerId, $profileUserId): array {
            DB::table('users')->where('id', $viewerId)->lockForUpdate()->first();
            $startOfDay = today()->startOfDay();
            $already = DB::table('phone_reveals')
                ->where('viewer_id', $viewerId)
                ->where('profile_user_id', $profileUserId)
                ->where('revealed_at', '>=', $startOfDay)
                ->exists();

            if ($already) {
                return ['allowed' => true];
            }

            $count = DB::table('phone_reveals')
                ->where('viewer_id', $viewerId)
                ->where('revealed_at', '>=', $startOfDay)
                ->distinct('profile_user_id')
                ->count('profile_user_id');
            if ($count >= self::PHONE_REVEAL_DAILY_LIMIT) {
                return ['allowed' => false, 'remainingToday' => 0];
            }

            DB::table('phone_reveals')->insert([
                'viewer_id' => $viewerId,
                'profile_user_id' => $profileUserId,
                'revealed_at' => now(),
            ]);

            return ['allowed' => true, 'remainingToday' => self::PHONE_REVEAL_DAILY_LIMIT - $count - 1];
        });
    }

    private function logProfileView(int $id, Request $request): void
    {
        try {
            DB::table('page_views')->insert([
                'page_type' => 'app_profile',
                'target' => $id,
                'referrer' => $request->header('referer'),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::error('Profile view logging failed.', ['exception' => $exception::class]);
        }
    }
}
