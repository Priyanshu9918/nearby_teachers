<?php

namespace App\Console\Commands;

use App\Reporting\OwnerMetrics;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

class SendWeeklyOwnerReport extends Command
{
    protected $signature = 'reports:send-owner-weekly';

    protected $description = 'Email the owner a weekly platform summary and admin queues';

    public function handle(OwnerMetrics $metrics): int
    {
        $recipient = config('reporting.owner_email');
        if (! is_string($recipient) || ! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $this->error('Set OWNER_REPORT_EMAIL or ADMIN_EMAIL to a valid owner email address.');

            return self::FAILURE;
        }

        $timezone = config('app.timezone');
        $thisWeekStart = Carbon::now($timezone)->startOfWeek(Carbon::MONDAY);
        $lastWeekEnd = $thisWeekStart->copy()->subDay();
        $lastWeekStart = $lastWeekEnd->copy()->startOfWeek(Carbon::MONDAY);
        $weekBeforeEnd = $lastWeekStart->copy()->subDay();
        $weekBeforeStart = $weekBeforeEnd->copy()->startOfWeek(Carbon::MONDAY);

        $data = [
            'lastWeek' => [
                'from' => $lastWeekStart,
                'to' => $lastWeekEnd,
                'metrics' => $metrics->summarize($lastWeekStart, $lastWeekEnd),
            ],
            'weekBefore' => [
                'from' => $weekBeforeStart,
                'to' => $weekBeforeEnd,
                'metrics' => $metrics->summarize($weekBeforeStart, $weekBeforeEnd),
            ],
            'snapshot' => $metrics->snapshot(),
            'queues' => $metrics->queues(),
            'generatedAt' => Carbon::now($timezone),
        ];

        Mail::send('emails.owner-weekly-report', $data, function ($message) use ($recipient, $lastWeekStart, $lastWeekEnd): void {
            $message->to($recipient)->subject('Nearby Teacher weekly owner report · '
                .$lastWeekStart->format('j M').'–'.$lastWeekEnd->format('j M Y'));
        });

        $this->info('Weekly owner report sent to '.$recipient.'.');

        return self::SUCCESS;
    }
}
