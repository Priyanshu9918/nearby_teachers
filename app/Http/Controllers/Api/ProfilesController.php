<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Reporting\BusinessActivityRecorder;
use App\Services\CountryCurrency;
use App\Services\OnboardingState;
use App\Services\TeacherAddress;
use App\Services\TeacherLocation;
use App\Services\TeacherPublicPages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
            if (DB::table($profileTable)->where('user_id', $user->id)->lockForUpdate()->first() === null) {
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

            $this->ensureSlug($profileTable, $user->id, $user->full_name);
            app(TeacherLocation::class)->store((int) $user->id, $location, $type);
            app(TeacherAddress::class)->store((int) $user->id, $data, $type);
            if ($type === 'teacher') {
                app(TeacherPublicPages::class)->publish((int) $user->id, (int) $user->id);
            }
        });

        $teacherResponse = [];
        if ($isTeacher) {
            $profile = DB::table($profileTable)->where('user_id', $user->id)
                ->select('public_page_slug', 'seo_country', 'user_id', 'slug', 'seo_slug', 'public_profile_slug', 'location_details')->first();
            $teacherResponse = [...TeacherPublicPages::urls($profile, $user->full_name),
                'locationDetails' => $profile->location_details === null ? null : json_decode($profile->location_details, true, flags: JSON_THROW_ON_ERROR)];
        }

        if (! $isTeacher) {
            $details = DB::table($profileTable)->where('user_id', $user->id)->value('location_details');
            $teacherResponse['locationDetails'] = $details === null ? null : json_decode($details, true, flags: JSON_THROW_ON_ERROR);
        }

        return response()->json(['ok' => true, ...$teacherResponse]);
    }

    /** Save one section of the teacher's six-step profile editor. */
    public function updateTeacherStep(Request $request, string $step): JsonResponse
    {
        $user = $request->attributes->get('authenticated_user');
        abort_unless($user->account_type === 'teacher', 403, 'Teacher account required.');
        if (! app(OnboardingState::class)->registrationCompleted($user)) {
            return response()->json(['error' => 'Complete registration before updating your profile.', 'registrationRequired' => true], 409);
        }

        $allowed = match ($step) {
            '1' => ['fullName', 'listingName'],
            '2' => ['primarySubjects'],
            '3' => ['lessonModes', 'latitude', 'longitude', 'addressText', 'city', 'state', 'country', 'countryCode', 'district', 'subDistrict', 'locality', 'localityType', 'postalCode'],
            '4' => ['ratePerHour', 'currency', 'trialDays'],
            '5' => ['bio', 'linkedinUrl'],
            default => abort(404),
        };

        $payload = $request->only($allowed);
        abort_if($payload === [], 422, 'Provide at least one field for this profile step.');

        if ($step === '1') {
            $data = validator($payload, [
                'fullName' => ['sometimes', 'required', 'string', 'min:2', 'max:160'],
                'listingName' => ['sometimes', 'nullable', 'string', 'min:2', 'max:160'],
            ])->validate();
            DB::transaction(function () use ($user, $data): void {
                abort_if(DB::table('teacher_profiles')->where('user_id', $user->id)->lockForUpdate()->first() === null, 404, 'Teacher profile not found.');
                $updates = ['updated_at' => now()];
                $changedFields = [];
                if (isset($data['fullName'])) {
                    $updates['full_name'] = trim($data['fullName']);
                    $changedFields[] = 'full_name';
                }
                if (array_key_exists('listingName', $data)) {
                    $updates['listing_name'] = $data['listingName'] === null ? null : trim($data['listingName']);
                    $changedFields[] = 'listing_name';
                }
                DB::table('users')->where('id', $user->id)->update(array_intersect_key($updates, ['full_name' => true, 'updated_at' => true]));
                if (array_key_exists('listing_name', $updates)) {
                    DB::table('teacher_profiles')->where('user_id', $user->id)->update(['listing_name' => $updates['listing_name'], 'updated_at' => now()]);
                }
                app(BusinessActivityRecorder::class)->record('teacher.profile_step_updated', (int) $user->id, (int) $user->id,
                    after: ['step' => 1, 'changed_fields' => $changedFields]);
                app(TeacherPublicPages::class)->publish((int) $user->id, (int) $user->id);
            });

            $saved = DB::table('users as u')->join('teacher_profiles as p', 'p.user_id', '=', 'u.id')
                ->where('u.id', $user->id)->first(['u.full_name', 'u.email', 'p.listing_name']);

            return response()->json(['ok' => true, 'step' => 1, 'fullName' => $saved->full_name,
                'email' => $saved->email, 'listingName' => $saved->listing_name ?? $saved->full_name]);
        }

        $stepFields = match ($step) {
            '2' => ['primarySubjects' => $payload['primarySubjects'] ?? null],
            '3' => $payload,
            '4' => $payload,
            '5' => $payload,
        };
        if ($step === '5' && array_key_exists('bio', $stepFields) && $stepFields['bio'] !== null
            && $this->wordCount(trim($stepFields['bio'])) < 100) {
            throw ValidationException::withMessages(['bio' => 'Write at least 100 words about yourself.']);
        }

        $request->replace($stepFields);
        $body = DB::transaction(function () use ($request, $step, $stepFields, $user): array {
            $body = $this->updateMine($request)->getData(true);
            app(BusinessActivityRecorder::class)->record('teacher.profile_step_updated', (int) $user->id, (int) $user->id,
                after: ['step' => (int) $step, 'changed_fields' => array_keys($stepFields)]);

            return $body;
        });

        return response()->json([...$body, 'step' => (int) $step]);
    }

    public function publishTeacherProfile(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authenticated_user');
        abort_unless($user->account_type === 'teacher', 403, 'Teacher account required.');
        if (! app(OnboardingState::class)->registrationCompleted($user)) {
            return response()->json(['error' => 'Complete registration before publishing your profile.', 'registrationRequired' => true], 409);
        }
        $request->validate(['agreeToTerms' => ['required', 'accepted']]);
        $profile = DB::table('teacher_profiles')->where('user_id', $user->id)->first();
        abort_if($profile === null, 404, 'Teacher profile not found.');
        $subjects = TeacherPublicPages::subjects((int) $user->id);
        $missing = [];
        if ($subjects->isEmpty()) $missing[] = 'primarySubjects';
        if (trim((string) $profile->city) === '') $missing[] = 'city';
        if (trim((string) $profile->district) === '') $missing[] = 'district';
        if (\App\Services\SeoDirectory::country($profile) === null) $missing[] = 'countryCode';
        if (trim((string) $profile->bio) === '' || $this->wordCount(trim((string) $profile->bio)) < 100) $missing[] = 'bio';
        if (json_decode($profile->lesson_modes ?: '[]', true) === []) $missing[] = 'lessonModes';
        if ($missing !== []) {
            return response()->json(['error' => 'Complete the required profile sections before publishing.', 'missingFields' => $missing], 422);
        }

        DB::transaction(function () use ($user): void {
            DB::table('users')->where('id', $user->id)->update(['terms_accepted_at' => now(), 'updated_at' => now()]);
            DB::table('teacher_profiles')->where('user_id', $user->id)->update(['is_profile_complete' => true, 'updated_at' => now()]);
            app(TeacherPublicPages::class)->publish((int) $user->id, (int) $user->id);
            app(BusinessActivityRecorder::class)->record('teacher.profile_published', (int) $user->id, (int) $user->id,
                after: ['status' => 'published', 'terms_accepted' => true, 'profile_complete' => true]);
        });

        $profile = DB::table('teacher_profiles')->where('user_id', $user->id)->first();
        if ($profile->public_page_slug === null) {
            return response()->json(['error' => 'Profile is saved but not eligible for a public page yet.', 'published' => false], 422);
        }

        return response()->json(['ok' => true, 'published' => true, 'profileComplete' => true,
            ...TeacherPublicPages::urls($profile, $user->full_name)]);
    }

    public function showMine(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authenticated_user');
        abort_unless($user->account_type === 'teacher', 403, 'Teacher account required.');
        $profile = DB::table('teacher_profiles')->where('user_id', $user->id)
            ->first(['id', 'user_id', 'primary_subject_id', 'listing_name', 'is_profile_complete', 'bio', 'rate_per_hour', 'currency', 'lesson_modes', 'latitude', 'longitude', 'address_text', 'city', 'state', 'country', 'country_code', 'linkedin_url', 'slug', 'is_verified', 'verified_at', 'trial_days', 'rating_avg', 'rating_count', 'district', 'sub_district', 'locality', 'locality_type', 'postal_code', 'location_details', 'public_page_slug', 'public_profile_slug', 'seo_slug', 'seo_country', 'seo_city_slug']);
        abort_if($profile === null, 404, 'Teacher profile not found.');
        $profile->lesson_modes = json_decode($profile->lesson_modes ?: '[]', true, flags: JSON_THROW_ON_ERROR);
        $profile->is_profile_complete = (bool) $profile->is_profile_complete;
        $profile->location_details = $profile->location_details === null ? null : json_decode($profile->location_details, true, flags: JSON_THROW_ON_ERROR);
        $userData = DB::table('users')->where('id', $user->id)->first(['id', 'full_name', 'email', 'phone', 'avatar_url', 'avatar_status', 'phone_verified_at', 'profile_completed_at']);
        $profile->listing_name = $profile->listing_name ?: $userData->full_name;

        return response()->json(['user' => $userData, 'profile' => $profile,
            'profileComplete' => $profile->is_profile_complete,
            ...TeacherPublicPages::urls($profile, $userData->full_name),
            'subjects' => TeacherPublicPages::subjects((int) $user->id)]);
    }

    public function uploadTeacherPhoto(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authenticated_user');
        abort_unless($user->account_type === 'teacher', 403, 'Teacher account required.');
        $data = $request->validate(['image' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120']]);
        $path = $data['image']->store('teacher-photos', 'public');
        $url = url(Storage::disk('public')->url($path));
        DB::transaction(function () use ($user, $url): void {
            DB::table('users')->where('id', $user->id)->update(['avatar_url' => $url, 'avatar_status' => 'pending', 'updated_at' => now()]);
            app(BusinessActivityRecorder::class)->record('teacher.avatar_submitted', (int) $user->id, (int) $user->id,
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
        unset($profile->location_details);
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

        $newRequests = DB::table('bookings')
            ->where('provider_id', $user->id)
            ->where('status', 'requested')
            ->count();
        $profileViews = DB::table('page_views')
            ->where('page_type', 'app_profile')
            ->where('target', $user->id)
            ->where('created_at', '>', $since)
            ->count();

        return response()->json([
            'sinceDays' => $days,
            'newRequests' => $newRequests,
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
        $profile = DB::table('institute_profiles')->where('user_id', $id)->first();

        if ($profile === null) {
            return response()->json(['error' => 'Profile not found'], 404);
        }
        if ($data['verified'] && $profile->institute_type !== 'bsl_direct') {
            return response()->json([
                'error' => 'BSL Verified is reserved for directly-operated BSL centres. Franchise and MCP partners are not eligible.',
            ], 400);
        }

        DB::table('institute_profiles')->where('user_id', $id)->update([
            'is_bsl_official' => $data['verified'],
            'bsl_official_since' => $data['verified'] ? ($profile->bsl_official_since ?? now()) : null,
        ]);

        return response()->json(['profile' => DB::table('institute_profiles')->where('user_id', $id)->first()]);
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

    private function wordCount(string $value): int
    {
        return preg_match_all("/[\\p{L}\\p{N}]+(?:['’][\\p{L}\\p{N}]+)*/u", $value) ?: 0;
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
