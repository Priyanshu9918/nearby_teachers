<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendNotificationEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public string $to, public string $subject, public string $text)
    {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(): void
    {
        if (config('mail.default') === 'log') {
            Log::warning('Notification email skipped because no production mailer is configured.');

            return;
        }

        // Delivery exceptions propagate so the queue retries temporary transport failures.
        Mail::raw($this->text, fn ($message) => $message->to($this->to)->subject($this->subject));
    }
}
