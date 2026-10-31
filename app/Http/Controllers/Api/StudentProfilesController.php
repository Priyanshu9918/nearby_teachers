<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Reporting\BusinessActivityRecorder;
use App\Services\OnboardingState;
use App\Support\PublicMediaUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

class StudentProfilesController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authenticated_user');
        abort_unless($user->account_type === 'learner', 403, 'Student account required.');

        $saved = DB::table('users')->where('id', $user->id)->first([
            'id', 'full_name', 'email', 'email_verified_at', 'date_of_birth', 'is_minor', 'phone',
            'phone_verified_at', 'phone_pin', 'avatar_url', 'avatar_status', 'created_at', 'updated_at',
            'account_type', 'registration_submitted_at', 'profile_completed_at', 'parental_consent_confirmed_at',
        ]);
        abort_if($saved === null, 404, 'Student profile not found.');

        return response()->json([
            'profile' => [
                'userId' => $saved->id,
                'name' => $saved->full_name,
                'email' => $saved->email,
                'emailVerifiedAt' => $saved->email_verified_at,
                'dob' => $saved->date_of_birth,
                'isMinor' => (bool) $saved->is_minor,
                'phone' => $saved->phone,
                'phoneVerifiedAt' => $saved->phone_verified_at,
                'phone_pin' => $saved->phone_pin,
                'profileImage' => $saved->avatar_url,
                'profileImageStatus' => $saved->avatar_status,
                'createdAt' => $saved->created_at,
                'updatedAt' => $saved->updated_at,
            ],
            'profileComplete' => app(OnboardingState::class)->registrationCompleted($saved),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authenticated_user');
        abort_unless($user->account_type === 'learner', 403, 'Student account required.');

        if (is_string($request->input('phone'))) {
            $request->merge(['phone' => $this->normalizePhone($request->input('phone'))]);
        }
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id, 'id')],
            'phone' => ['sometimes', 'required', 'string', 'max:32', Rule::unique('users', 'phone')->ignore($user->id, 'id')],
            'phone_pin' => ['sometimes', 'nullable', 'string', 'max:20'],
            // DOB is set through /auth/date-of-birth to preserve age/guardian checks.
            'dob' => ['prohibited'],
            'dateOfBirth' => ['prohibited'],
            'profileImage' => ['prohibited'],
            'profile_image' => ['prohibited'],
            'image' => ['prohibited'],
        ]);
        if ($data === []) {
            return $this->show($request);
        }

        if (array_key_exists('email', $data) && $data['email'] !== null) {
            $data['email'] = strtolower(trim($data['email']));
        }
        $fieldMap = ['name' => 'full_name', 'email' => 'email', 'phone' => 'phone', 'phone_pin' => 'phone_pin'];
        $changedFields = [];
        DB::transaction(function () use ($user, $data, $fieldMap, &$changedFields): void {
            $saved = DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            abort_if($saved === null, 404, 'Student profile not found.');

            $updates = [];
            foreach ($fieldMap as $input => $column) {
                if (! array_key_exists($input, $data)) {
                    continue;
                }
                $value = $data[$input];
                if ((string) ($saved->{$column} ?? '') !== (string) ($value ?? '')) {
                    $updates[$column] = $value;
                    $changedFields[] = $input;
                }
            }

            if (array_key_exists('email', $updates)) {
                $updates['email_verified_at'] = null;
            }
            if (array_key_exists('phone', $updates)) {
                $updates['phone_verified_at'] = null;
            }
            if ($updates === []) {
                return;
            }
            $updates['updated_at'] = now();
            DB::table('users')->where('id', $user->id)->update($updates);
            app(BusinessActivityRecorder::class)->record('learner.profile_updated', (string) $user->id, (string) $user->id,
                before: ['changed_fields' => []],
                after: ['changed_fields' => array_values($changedFields)]);
        });

        return $this->show($request);
    }

    public function uploadPhoto(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authenticated_user');
        abort_unless($user->account_type === 'learner', 403, 'Student account required.');
        $data = $request->validate([
            'image' => ['required_without:profile_image', 'prohibited_with:profile_image', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
            'profile_image' => ['required_without:image', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ]);

        $path = ($data['profile_image'] ?? $data['image'])->store('student-photos', 'public');
        $url = PublicMediaUrl::fromStoragePath($path);
        DB::transaction(function () use ($user, $url): void {
            DB::table('users')->where('id', $user->id)->update([
                'avatar_url' => $url,
                'avatar_status' => 'pending',
                'updated_at' => now(),
            ]);
            app(BusinessActivityRecorder::class)->record('learner.avatar_submitted', (string) $user->id, (string) $user->id,
                after: ['avatar_status' => 'pending']);
        });

        $profile = DB::table('users')->where('id', $user->id)->first(['id', 'avatar_url', 'avatar_status']);

        return response()->json([
            'profileImage' => $profile->avatar_url,
            'profileImageStatus' => $profile->avatar_status,
            'message' => 'Photo uploaded; it will appear on your profile after review.',
        ], 201)->header('Cache-Control', 'no-store, private');
    }

    private function normalizePhone(string $input): string
    {
        try {
            $util = PhoneNumberUtil::getInstance();
            $number = $util->parse($input);
            if (! $util->isValidNumber($number)) {
                throw new NumberParseException(NumberParseException::NOT_A_NUMBER, 'Invalid phone number.');
            }

            return $util->format($number, PhoneNumberFormat::E164);
        } catch (NumberParseException) {
            throw ValidationException::withMessages([
                'phone' => 'Enter a valid phone number with country code, e.g. +919876543210',
            ]);
        }
    }
}
