<?php

namespace App\Reporting;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReportAccessLog
{
    public function record(Request $request, string $report, string $action, int|string|null $id = null): void
    {
        $apiAdmin = $request->attributes->get('authenticated_user');
        $apiEmail = is_string($apiAdmin->email ?? null) ? trim($apiAdmin->email) : '';
        $configuredEmail = trim((string) config('admin.email'));
        $adminEmail = $apiEmail !== '' ? $apiEmail : ($configuredEmail !== ''
            ? $configuredEmail
            : (isset($apiAdmin->id) ? 'user:'.$apiAdmin->id : 'admin-session'));
        DB::table('admin_report_access_log')->insert([
            'id' => (string) Str::uuid(),
            'admin_email' => $adminEmail,
            'report' => $report, 'record_id' => $id, 'action' => $action,
            'ip_address' => $request->ip(), 'created_at' => now(),
        ]);
    }
}
