<?php

namespace App\Services;

use App\Jobs\SendRegistrationEmailCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class RegistrationEmailVerification
{
    public function hasVerified(string $email): bool
    {
        return DB::table('registration_email_verifications')->where('email', $email)
            ->whereNotNull('verified_at')->whereNull('consumed_at')->where('expires_at', '>', now())->exists();
    }

    public function request(string $email): ?string
    {
        $mailer = config('mail.default');
        $withoutDelivery = blank($mailer) || in_array($mailer, ['log', 'null'], true)
            || ($mailer === 'smtp' && ! config('mail.smtp_configured'));
        $testMode = $withoutDelivery && app()->environment(['local', 'testing']);
        if ($withoutDelivery && ! $testMode) {
            throw new ServiceUnavailableHttpException(null, 'Email delivery is not configured.');
        }
        $code = (string) random_int(100000, 999999);
        $id = DB::transaction(function () use ($email, $code, $testMode): int {
            DB::table('registration_email_verifications')->where('email', $email)
                ->whereNull('consumed_at')->update(['consumed_at' => now()]);
            $id = DB::table('registration_email_verifications')->insertGetId([
                'email' => $email, 'code_hash' => Hash::make($code),
                'created_at' => now(), 'expires_at' => now()->addMinutes(10),
            ]);
            if (! $testMode) {
                SendRegistrationEmailCode::dispatch($id, $code);
            }
            return (int) $id;
        });

        return $testMode ? $code : null;
    }

    public function verify(string $email, string $code): bool
    {
        return DB::transaction(function () use ($email, $code): bool {
            $challenge = DB::table('registration_email_verifications')->where('email', $email)
                ->whereNull('consumed_at')->orderByDesc('id')->lockForUpdate()->first();
            if ($challenge === null) {
                return false;
            }
            if (now()->greaterThanOrEqualTo($challenge->expires_at) || $challenge->attempts >= 5) {
                DB::table('registration_email_verifications')->where('id', $challenge->id)->update(['consumed_at' => now()]);
                return false;
            }
            if (! Hash::check($code, $challenge->code_hash)) {
                DB::table('registration_email_verifications')->where('id', $challenge->id)->update([
                    'attempts' => $challenge->attempts + 1,
                    'consumed_at' => $challenge->attempts + 1 >= 5 ? now() : null,
                ]);
                return false;
            }
            DB::table('registration_email_verifications')->where('id', $challenge->id)->update(['verified_at' => now()]);
            DB::table('users')->where('email', $email)->update(['email_verified_at' => now(), 'updated_at' => now()]);
            return true;
        });
    }

    public function consumeForRegistration(string $email): bool
    {
        $challenge = DB::table('registration_email_verifications')->where('email', $email)
            ->whereNotNull('verified_at')->whereNull('consumed_at')->where('expires_at', '>', now())
            ->orderByDesc('id')->lockForUpdate()->first();
        if ($challenge === null) {
            return false;
        }
        DB::table('registration_email_verifications')->where('id', $challenge->id)->update(['consumed_at' => now()]);
        return true;
    }
}
