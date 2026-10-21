<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class SendEmailCode implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public string $challengeId, public string $code)
    {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(): void
    {
        $challenge = DB::table('email_otp_codes as c')->join('users as u', 'u.id', '=', 'c.user_id')
            ->where('c.id', $this->challengeId)->whereNull('c.consumed_at')
            ->where('c.expires_at', '>', now())->whereColumn('c.email', 'u.email')
            ->whereNull('u.suspended_at')->select('c.email', 'c.purpose')->first();
        if ($challenge === null) {
            return; // Do not send an expired, superseded, consumed or old-address code.
        }
        if (in_array(config('mail.default'), ['log', 'null'], true)) {
            throw new RuntimeException('Email OTP delivery requires a configured mail transport.');
        }
        $action = $challenge->purpose === 'password_reset' ? 'password reset' : 'email verification';
        Mail::raw(
            "Your Nearby Teacher {$action} code is {$this->code}.\n\n"
            .'This code expires 10 minutes after it was requested. Do not share it. '
            .'If you did not request this code, ignore this email.',
            fn ($message) => $message->to($challenge->email)->subject('Nearby Teacher '.ucfirst($action).' code')
        );
    }
}
