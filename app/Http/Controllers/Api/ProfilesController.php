<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CountryCurrency;
use App\Services\OnboardingState;
use App\Services\TeacherPublicPages;
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
            'subjectIds' => ['sometimes', 'array', 'max:5'],
            'subjectIds.*' => ['integer', 'distinct', 'exists:subjects,id'],
        ];
        if ($isTeacher) {
            $rules['linkedinUrl'] = ['sometimes', 'nullable', 'url:http,https', 'max:2048'];
        } else {
            $rules['instructorsText'] = ['sometimes', 'nullable', 'string', 'max:2000'];
            $rules['websiteUrl'] = ['sometimes', 'nullable', 'url', 'max:2048'];
        }

        $data = $request->validate($rules);
        if (isset($data[$descriptionField]) && str_word_count(trim($data[$descriptionField])) > 14) {
            throw ValidationException::withMessages([$descriptionField => 'Bio must be 14 words or fewer']);
        }
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

        $profileTable = $isTeacher ? 'teacher_profiles' : 'institute_profiles';
        $updateParts = [];
        $bindings = [];
        $this->appendField($updateParts, $bindings, $data, $descriptionField, $descriptionField);
        $this->appendField($updateParts, $bindings, $data, $rateField, $isTeacher ? 'rate_per_hour' : 'starting_rate');
        $this->appendField($updateParts, $bindings, $data, 'addressText', 'address_text');
        $this->appendField($updateParts, $bindings, $data, 'city', 'city');
        $this->appendField($updateParts, $bindings, $data, 'state', 'state');
        $this->appendField($updateParts, $bindings, $data, 'country', 'country');
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
        if (array_key_exists('latitude', $data)) {
            $updateParts[] = 'latitude = ?';
            $bindings[] = $data['latitude'] === null ? null : (float) $data['latitude'];
            $updateParts[] = 'longitude = ?';
            $bindings[] = $data['longitude'] === null ? null : (float) $data['longitude'];
        }
        $updateParts[] = 'updated_at = now()';

        DB::transaction(function () use ($request, $user, $type, $profileTable, $updateParts, $bindings): void {
            if (DB::table($profileTable)->where('user_id', $user->id)->lockForUpdate()->first() === null) {
                abort(404, 'Profile not found');
            }

            DB::update(
                'UPDATE '.$profileTable.' SET '.implode(', ', $updateParts).' WHERE user_id = ?',
                [...$bindings, $user->id]
            );

            if (array_key_exists('subjectIds', $request->all())) {
                DB::table('profile_subjects')
                    ->where('profile_type', $type)
                    ->where('profile_id', $user->id)
                    ->delete();

                foreach ($request->input('subjectIds') as $subjectId) {
                    DB::table('profile_subjects')->insert([
                        'profile_type' => $type,
                        'profile_id' => $user->id,
                        'subject_id' => $subjectId,
                    ]);
                }
            }

            $this->ensureSlug($profileTable, $user->id, $user->full_name);
            if ($type === 'teacher') {
                app(TeacherPublicPages::class)->publish((int) $user->id);
            }
        });

        return response()->json(['ok' => true, ...($isTeacher
            ? TeacherPublicPages::urls(DB::table($profileTable)->where('user_id', $user->id)->first(), $user->full_name)
            : [])]);
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
            ->select('p.*', 'u.full_name')
            ->selectRaw("CASE WHEN u.avatar_status = 'approved' THEN u.avatar_url ELSE NULL END AS avatar_url")
            ->addSelect('u.phone')
            ->selectRaw('COALESCE(completed_bookings.lessons_completed, 0) AS lessons_completed')
            ->first();

        if ($profile === null) {
            return response()->json(['error' => 'Not found'], 404);
        }

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
