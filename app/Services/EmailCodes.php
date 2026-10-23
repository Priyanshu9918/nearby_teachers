<?php

namespace App\Services;

use App\Jobs\SendEmailCode;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class EmailCodes
{
    public function request(string $email, string $purpose): void
    {
        // Hash even for unknown accounts; the public response never discloses eligibility.
        $code = (string) random_int(100000, 999999);
        $hash = Hash::make($code);
        DB::transaction(function () use ($email, $purpose, $code, $hash): void {
            $user = DB::table('users')->where('email', $email)->lockForUpdate()->first();
            if ($user === null || $user->suspended_at !== null) {
                return;
            }
            DB::table('email_otp_codes')->where('user_id', $user->id)->where('purpose', $purpose)
                ->whereNull('consumed_at')->update(['consumed_at' => now()]);
            $id = DB::table('email_otp_codes')->insertGetId(['user_id' => $user->id, 'email' => $email, 'purpose' => $purpose,
                'code_hash' => $hash, 'created_at' => now(), 'expires_at' => now()->addMinutes(10),
            ]);
            SendEmailCode::dispatch($id, $code);
        });
    }

    public function consume(string $email, string $purpose, string $code, Closure $onVerified): bool
    {
        return DB::transaction(function () use ($email, $purpose, $code, $onVerified): bool {
            $user = DB::table('users')->where('email', $email)->lockForUpdate()->first();
            if ($user === null || $user->suspended_at !== null) {
                return false;
            }
            $challenge = DB::table('email_otp_codes')->where('user_id', $user->id)
                ->where('email', $email)->where('purpose', $purpose)->whereNull('consumed_at')
                ->lockForUpdate()->first();
            if ($challenge === null) {
                return false;
            }
            if (now()->greaterThanOrEqualTo($challenge->expires_at) || $challenge->attempts >= 5) {
                DB::table('email_otp_codes')->where('id', $challenge->id)->update(['consumed_at' => now()]);

                return false;
            }
            if (! Hash::check($code, $challenge->code_hash)) {
                DB::table('email_otp_codes')->where('id', $challenge->id)->update([
                    'attempts' => $challenge->attempts + 1,
                    'consumed_at' => $challenge->attempts + 1 >= 5 ? now() : null,
                ]);

                return false;
            }
            DB::table('email_otp_codes')->where('id', $challenge->id)->update(['consumed_at' => now()]);
            $onVerified($user);

            return true;
        });
    }
}
