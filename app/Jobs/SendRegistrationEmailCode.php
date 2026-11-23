<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

final class SendRegistrationEmailCode implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 30;

    public function __construct(public int $challengeId, public string $code)
    {
        $this->onQueue('notifications')->afterCommit();
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(): void
    {
        $challenge = DB::table('registration_email_verifications')->where('id', $this->challengeId)
            ->whereNull('consumed_at')->whereNull('verified_at')->where('expires_at', '>', now())->first();
        if ($challenge === null) {
            return;
        }
        Mail::raw(
            "Your Nearby Teacher email verification code is {$this->code}.\n\n"
            .'This code expires in 10 minutes. Do not share it. If you did not request it, ignore this email.',
            fn ($message) => $message->to($challenge->email)->subject('Nearby Teacher Email verification code')
        );
    }
}
