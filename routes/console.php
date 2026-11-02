<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn () => DB::table('email_otp_codes')->where('expires_at', '<', now()->subDay())
    ->limit(1000)->delete())->name('prune-expired-email-codes')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('presence:prune')->everyMinute()->withoutOverlapping(5)->evenInMaintenanceMode();

Schedule::command('reports:send-owner-weekly')->weeklyOn(1, '08:00')
    ->timezone(config('app.timezone'))->withoutOverlapping();

// Reconcile generated SEO metadata after provider/subject changes; keep a private pre-repair backup.
// Temporarily disabled: SEO generation runs only on profile publication/updates.
// Schedule::command('seo:repair --apply')->dailyAt('03:15')->withoutOverlapping();
