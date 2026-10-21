<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RevokeChatSessions;
use App\Services\EmailCodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PasswordRecoveryController extends Controller
{
    public function __construct(private readonly EmailCodes $codes) {}

    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $this->codes->request(strtolower(trim($data['email'])), 'password_reset');

        return response()->json([
            'ok' => true,
            'message' => 'If an eligible account exists, a password reset code will be sent to its email.',
            'expiresInMinutes' => 10,
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'], 'code' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ]);
        $verified = $this->codes->consume(strtolower(trim($data['email'])), 'password_reset', $data['code'], function ($user) use ($data): void {
            DB::table('users')->where('id', $user->id)->update([
                'password_hash' => Hash::make($data['password']), 'email_verified_at' => now(),
                'auth_version' => $user->auth_version + 1, 'auth_tokens_invalid_before' => now(), 'updated_at' => now(),
            ]);
            DB::table('email_otp_codes')->where('user_id', $user->id)->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);
            RevokeChatSessions::dispatch($user->id, $user->auth_version + 1);
        });
        if (! $verified) {
            throw ValidationException::withMessages(['code' => 'Invalid or expired code. Request a new code and try again.']);
        }

        return response()->json(['ok' => true, 'message' => 'Password reset successfully. Log in with your email and new password.']);
    }

    public function requestVerification(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authenticated_user');
        if (! filled($user->email)) {
            throw ValidationException::withMessages(['email' => 'Add your email address before verifying it.']);
        }
        $this->codes->request($user->email, 'email_verification');

        return response()->json(['ok' => true, 'message' => 'An email verification code has been requested.', 'expiresInMinutes' => 10]);
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $user = $request->attributes->get('authenticated_user');
        if (! filled($user->email) || ! $this->codes->consume($user->email, 'email_verification', $data['code'], function ($saved): void {
            DB::table('users')->where('id', $saved->id)->update(['email_verified_at' => now(), 'updated_at' => now()]);
        })) {
            throw ValidationException::withMessages(['code' => 'Invalid or expired code. Request a new code and try again.']);
        }

        return response()->json(['ok' => true, 'emailVerified' => true]);
    }
}
