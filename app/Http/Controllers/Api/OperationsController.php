<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendNotificationEmail;
use App\Reporting\BusinessActivityRecorder;
use App\Reporting\ReportAccessLog;
use App\Services\ChatBlocking;
use App\Services\SafetyReportCreator;
use App\Support\SafetyReportCategory;
use App\Support\SafetyReportTarget;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OperationsController extends Controller
{
    public function report(Request $request, SafetyReportCreator $reportCreator): JsonResponse
    {
        $request->merge(['reasonCategory' => SafetyReportCategory::normalize($request->input('reasonCategory'))]);
        [$targetType, $targetId] = SafetyReportTarget::normalize($request->input('targetType'), $request->input('targetId'));
        $data = $request->validate([
            'reasonCategory' => ['required', 'string', 'in:fraud,fake_profile,inappropriate,spam,other'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:5000', 'prohibited_with:additionalDetails'],
            'additionalDetails' => ['sometimes', 'nullable', 'string', 'max:500'],
            'evidenceLink' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
            'screenshot' => ['sometimes', 'nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ]);
        $user = $request->attributes->get('authenticated_user');
        abort_unless($user !== null && in_array($user->account_type, ['learner', 'teacher', 'institute'], true), 403, 'User account required.');

        $details = trim($data['additionalDetails'] ?? $data['reason'] ?? '');
        $report = $reportCreator->create((int) $user->id, $targetType, $targetId,
            $data['reasonCategory'], $details === '' ? null : $details,
            $data['evidenceLink'] ?? null, $data['screenshot'] ?? null);

        return response()->json(['report' => [
            'id' => $report->id,
            'reporter_id' => $report->reporter_id,
            'target_type' => $report->target_type,
            'target_id' => $report->target_id,
            'reason_category' => $report->reason_category,
            'reason' => $report->reason,
            'evidence_link' => $report->evidence_link,
            'screenshot_available' => (bool) $report->screenshot_available,
            'status' => $report->status,
            'created_at' => $report->created_at,
        ]], 201);
    }

    public function reports(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'in:open,reviewed,dismissed'],
            'page' => ['sometimes', 'integer', 'between:1,100000'],
            'perPage' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $page = (int) ($data['page'] ?? 1);
        $perPage = (int) ($data['perPage'] ?? 30);
        app(ReportAccessLog::class)->record($request, 'reports', 'api-view');
        $rows = DB::table('reports as r')
            ->join('users as u', 'u.id', '=', 'r.reporter_id')
            ->where('r.status', $data['status'] ?? 'open')
            ->select('r.id', 'r.reporter_id', 'r.target_type', 'r.target_id', 'r.reason_category', 'r.reason',
                'r.evidence_link', 'r.status', 'r.created_at', 'u.full_name as reporter_name',
                'u.account_type as reporter_type')
            ->selectRaw('CASE WHEN r.screenshot_path IS NOT NULL THEN 1 ELSE 0 END AS screenshot_available')
            ->orderByRaw("CASE WHEN r.reason_category = 'fraud' THEN 0 ELSE 1 END")
            ->orderByDesc('r.created_at')
            ->orderByDesc('r.id')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage + 1)
            ->get();
        $hasMore = $rows->count() > $perPage;
        $reports = $rows->take($perPage)->values();

        return response()->json([
            'reports' => $reports,
            'pagination' => ['page' => $page, 'perPage' => $perPage, 'hasMore' => $hasMore],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function updateReport(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:open,reviewed,dismissed']]);
        $actor = $request->attributes->get('authenticated_user');
        $report = DB::transaction(function () use ($data, $id, $actor): ?object {
            $saved = DB::table('reports')->where('id', $id)->lockForUpdate()->first(['id', 'reporter_id', 'status']);
            if ($saved === null) {
                return null;
            }
            if ($saved->status !== $data['status']) {
                DB::table('reports')->where('id', $id)->update($data);
                app(BusinessActivityRecorder::class)->record('safety_report.status_changed', (string) $id,
                    $actor->id, (int) $saved->reporter_id,
                    before: ['status' => $saved->status], after: ['status' => $data['status']]);
            }

            return DB::table('reports')->where('id', $id)
                ->select('id', 'reporter_id', 'target_type', 'target_id', 'reason_category', 'reason',
                    'evidence_link', 'status', 'created_at')
                ->selectRaw('CASE WHEN screenshot_path IS NOT NULL THEN 1 ELSE 0 END AS screenshot_available')
                ->first();
        }, 3);
        if ($report === null) {
            return response()->json(['error' => 'Report not found'], 404);
        }

        return response()->json(['report' => $report]);
    }

    public function block(Request $request, int $userId): JsonResponse
    {
        $user = $request->attributes->get('authenticated_user');
        if ($userId === $user->id) {
            return response()->json(['error' => 'You cannot block your own account'], 400);
        }
        DB::transaction(function () use ($user, $userId): void {
            app(ChatBlocking::class)->lockParticipants($user->id, $userId);
            if (DB::table('blocks')->where('blocker_id', $user->id)->where('blocked_id', $userId)->exists()) {
                return;
            }
            DB::table('blocks')->insert([
                'blocker_id' => $user->id, 'blocked_id' => $userId, 'created_at' => now(),
            ]);
            app(BusinessActivityRecorder::class)->record('user.blocked', $userId, $user->id, $userId,
                before: ['blocked' => false], after: ['blocked' => true]);
        }, 3);

        return response()->json(['ok' => true, 'userId' => $userId, 'blocked' => true,
            'chatStatus' => ['otherUserId' => $userId, ...app(ChatBlocking::class)->status($user->id, $userId)]]);
    }

    public function unblock(Request $request, int $userId): JsonResponse
    {
        $user = $request->attributes->get('authenticated_user');
        if ($userId === $user->id) {
            return response()->json(['error' => 'You cannot unblock your own account'], 400);
        }
        DB::transaction(function () use ($user, $userId): void {
            app(ChatBlocking::class)->lockParticipants($user->id, $userId);
            $deleted = DB::table('blocks')->where('blocker_id', $user->id)->where('blocked_id', $userId)->delete();
            if ($deleted) {
                app(BusinessActivityRecorder::class)->record('user.unblocked', $userId, $user->id, $userId,
                    before: ['blocked' => true], after: ['blocked' => false]);
            }
        }, 3);

        return response()->json(['ok' => true, 'userId' => $userId, 'blocked' => false,
            'chatStatus' => ['otherUserId' => $userId, ...app(ChatBlocking::class)->status($user->id, $userId)]]);
    }

    public function suggest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'suggestionType' => ['required', 'in:course,city,state,country'],
            'name' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $id = DB::table('suggestions')->insertGetId([
            'submitted_by' => $request->attributes->get('authenticated_user')->id,
            'suggestion_type' => $data['suggestionType'],
            'name' => $data['name'],
            'notes' => $data['notes'] ?? null,
            'created_at' => now(),
        ]);

        return response()->json(['suggestion' => DB::table('suggestions')->where('id', $id)->first()]);
    }

    public function suggestions(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['sometimes', 'in:open,added,declined']]);
        $suggestions = DB::table('suggestions as s')
            ->join('users as u', 'u.id', '=', 's.submitted_by')
            ->where('s.status', $data['status'] ?? 'open')
            ->select('s.*', 'u.full_name as submitted_by_name')
            ->orderByDesc('s.created_at')
            ->get();

        return response()->json(['suggestions' => $suggestions]);
    }

    public function updateSuggestion(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:open,added,declined']]);
        $updated = DB::table('suggestions')->where('id', $id)->update($data);
        if ($updated === 0 && ! DB::table('suggestions')->where('id', $id)->exists()) {
            return response()->json(['error' => 'Suggestion not found'], 404);
        }

        return response()->json(['suggestion' => DB::table('suggestions')->where('id', $id)->first()]);
    }

    public function createTicket(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:10000'],
        ]);
        $user = $request->attributes->get('authenticated_user');
        $id = DB::table('support_tickets')->insertGetId([
            'user_id' => $user->id,
            'subject' => $data['subject'],
            'message' => $data['message'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $to = config('services.nearby_teacher.support_email');
        if (! filled($to)) {
            Log::warning('Support ticket email was not sent because SUPPORT_EMAIL is not configured.');
        } else {
            SendNotificationEmail::dispatch(
                $to,
                'New support ticket: '.$data['subject'],
                "From: {$user->full_name} (".($user->email ?? $user->phone).")\n\n{$data['message']}"
            );
        }

        return response()->json(['ticket' => DB::table('support_tickets')->where('id', $id)->first()]);
    }

    public function mineTickets(Request $request): JsonResponse
    {
        $tickets = DB::table('support_tickets')
            ->where('user_id', $request->attributes->get('authenticated_user')->id)
            ->orderByDesc('created_at')->get();

        return response()->json(['tickets' => $tickets]);
    }

    public function tickets(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['sometimes', 'in:open,in_progress,resolved']]);
        $tickets = DB::table('support_tickets as t')
            ->join('users as u', 'u.id', '=', 't.user_id')
            ->where('t.status', $data['status'] ?? 'open')
            ->select('t.*', 'u.full_name as user_name', 'u.email as user_email', 'u.phone as user_phone')
            ->orderByDesc('t.created_at')->get();

        return response()->json(['tickets' => $tickets]);
    }

    public function updateTicket(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:open,in_progress,resolved']]);
        $updated = DB::table('support_tickets')->where('id', $id)->update([
            'status' => $data['status'],
            'updated_at' => now(),
        ]);
        if ($updated === 0 && ! DB::table('support_tickets')->where('id', $id)->exists()) {
            return response()->json(['error' => 'Ticket not found'], 404);
        }

        return response()->json(['ticket' => DB::table('support_tickets')->where('id', $id)->first()]);
    }

    public function referralMine(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authenticated_user');
        $code = DB::table('users')->where('id', $user->id)->value('referral_code');
        for ($attempt = 0; $code === null && $attempt < 5; $attempt++) {
            $candidate = $this->newReferralCode();
            try {
                DB::table('users')->where('id', $user->id)->whereNull('referral_code')
                    ->update(['referral_code' => $candidate]);
            } catch (QueryException $exception) {
                if (! $this->isUniqueConstraintViolation($exception)) {
                    throw $exception;
                }
            }
            $code = DB::table('users')->where('id', $user->id)->value('referral_code');
        }
        if ($code === null) {
            return response()->json(['error' => 'Could not generate a referral code, try again'], 500);
        }

        $referred = DB::table('referrals as r')
            ->join('users as u', 'u.id', '=', 'r.referred_id')
            ->where('r.referrer_id', $user->id)
            ->select('u.id', 'u.full_name', 'u.account_type', 'r.created_at')
            ->selectRaw("CASE WHEN u.avatar_status = 'approved' THEN u.avatar_url ELSE NULL END AS avatar_url")
            ->orderByDesc('r.created_at')->orderByDesc('r.id')->get();

        return response()->json([
            'code' => $code,
            'link' => rtrim(config('services.nearby_teacher.app_base_url', 'https://bsl.international'), '/').'/download?ref='.$code,
            'referredCount' => $referred->count(),
            'referralPoints' => (int) DB::table('users')->where('id', $user->id)->value('referral_points'),
            'referred' => $referred,
        ]);
    }

    public function redeemReferral(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:32']]);
        $user = $request->attributes->get('authenticated_user');
        $referrer = DB::table('users')->where('referral_code', $data['code'])->value('id');
        if ($referrer === null) {
            return response()->json(['error' => 'Referral code not found'], 404);
        }
        if ($referrer === $user->id) {
            return response()->json(['error' => "You can't refer yourself"], 400);
        }
        try {
            $id = DB::transaction(function () use ($referrer, $user): int|string {
                DB::table('users')->where('id', $referrer)->lockForUpdate()->first(['id']);
                $id = DB::table('referrals')->insertGetId([
                    'referrer_id' => $referrer,
                    'referred_id' => $user->id,
                    'created_at' => now(),
                ]);
                app(BusinessActivityRecorder::class)->record('referral.joined', $id, $user->id, $referrer,
                    after: ['source' => 'referral_code']);

                return $id;
            }, 3);
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            return response()->json(['error' => 'This account has already been credited to a referrer'], 409);
        }

        return response()->json([
            'referral' => DB::table('referrals')->where('id', $id)->first(),
            'referrerReferralPoints' => (int) DB::table('users')->where('id', $referrer)->value('referral_points'),
        ]);
    }

    public function topReferrals(): JsonResponse
    {
        $top = DB::table('users as u')
            ->where('u.referral_points', '>', 0)
            ->select('u.id', 'u.full_name', 'u.referral_points')
            ->selectSub(DB::table('referrals')->selectRaw('count(*)')->whereColumn('referrer_id', 'u.id'), 'referred_count')
            ->orderByDesc('u.referral_points')->orderByDesc('referred_count')
            ->limit(50)->get();

        return response()->json(['topReferrers' => $top]);
    }

    public function recordPageView(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pageType' => ['required', 'in:institute,teacher,city_index,city_feed,city_directory,download,app_profile'],
            'target' => ['nullable', 'string', 'max:255'],
            'platform' => ['nullable', 'string', 'max:32'],
            'referrer' => ['nullable', 'string', 'max:2048'],
        ]);
        DB::table('page_views')->insert([
            'page_type' => $data['pageType'],
            'target' => $data['target'] ?? null,
            'platform' => $data['platform'] ?? null,
            'referrer' => $data['referrer'] ?? null,
            'user_agent' => substr((string) $request->userAgent(), 0, 2048),
            'created_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    public function analyticsSummary(Request $request): JsonResponse
    {
        $requestedDays = $request->query('days');
        $days = is_numeric($requestedDays) && (float) $requestedDays > 0
            ? (int) floor((float) $requestedDays)
            : 30;
        $since = now()->subDays($days);

        $topPages = DB::table('page_views')
            ->where('created_at', '>', $since)
            ->whereIn('page_type', ['institute', 'teacher'])
            ->select('page_type', 'target')
            ->selectRaw('count(*) as views')
            ->groupBy('page_type', 'target')
            ->orderByDesc('views')->limit(20)->get();
        $topCities = DB::table('page_views')
            ->where('created_at', '>', $since)
            ->whereIn('page_type', ['city_index', 'city_feed'])
            ->select('target as city')
            ->selectRaw('count(*) as views')
            ->groupBy('target')
            ->orderByDesc('views')->limit(20)->get();
        $downloads = DB::table('page_views')
            ->where('created_at', '>', $since)
            ->where('page_type', 'download')
            ->select('platform')
            ->selectRaw('count(*) as clicks')
            ->groupBy('platform')->orderByDesc('clicks')->get();
        $totals = DB::table('page_views')
            ->where('created_at', '>', $since)
            ->select('page_type')
            ->selectRaw('count(*) as views')
            ->groupBy('page_type')->get();

        return response()->json([
            'sinceDays' => $days,
            'topPages' => $topPages,
            'topCities' => $topCities,
            'downloadsByPlatform' => $downloads,
            'totalsByPageType' => $totals,
        ]);
    }

    private function newReferralCode(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($index = 0; $index < 7; $index++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? '';
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return $sqlState === '23000' && $driverCode === 1062;
    }
}
