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
