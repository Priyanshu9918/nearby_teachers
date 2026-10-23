<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendNotificationEmail;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OperationsController extends Controller
{
    public function report(Request $request): JsonResponse
    {
        $data = $request->validate([
            'targetType' => ['required', 'in:user,post,comment,message'],
            'targetId' => ['required', 'integer', 'min:1', 'max:9007199254740991'],
            'reasonCategory' => ['required', 'in:fraud,fake_profile,inappropriate,spam,other'],
            'reason' => ['nullable', 'string', 'max:5000'],
        ]);
        $id = DB::table('reports')->insertGetId([
            'reporter_id' => $request->attributes->get('authenticated_user')->id,
            'target_type' => $data['targetType'],
            'target_id' => $data['targetId'],
            'reason_category' => $data['reasonCategory'],
            'reason' => $data['reason'] ?? null,
            'created_at' => now(),
        ]);

        return response()->json(['report' => DB::table('reports')->where('id', $id)->first()]);
    }

    public function reports(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['sometimes', 'in:open,reviewed,dismissed']]);
        $reports = DB::table('reports as r')
            ->join('users as u', 'u.id', '=', 'r.reporter_id')
            ->where('r.status', $data['status'] ?? 'open')
            ->select('r.*', 'u.full_name as reporter_name')
            ->orderByRaw("CASE WHEN r.reason_category = 'fraud' THEN 0 ELSE 1 END")
            ->orderByDesc('r.created_at')
            ->get();

        return response()->json(['reports' => $reports]);
    }

    public function updateReport(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:open,reviewed,dismissed']]);
        $updated = DB::table('reports')->where('id', $id)->update($data);
        if ($updated === 0 && ! DB::table('reports')->where('id', $id)->exists()) {
            return response()->json(['error' => 'Report not found'], 404);
        }

        return response()->json(['report' => DB::table('reports')->where('id', $id)->first()]);
    }

    public function block(Request $request, int $userId): JsonResponse
    {
        $user = $request->attributes->get('authenticated_user');
        if ($userId === $user->id) {
            return response()->json(['error' => 'You cannot block your own account'], 400);
        }
        DB::table('blocks')->insertOrIgnore([
            'blocker_id' => $user->id,
            'blocked_id' => $userId,
            'created_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    public function unblock(Request $request, int $userId): JsonResponse
    {
        DB::table('blocks')
            ->where('blocker_id', $request->attributes->get('authenticated_user')->id)
            ->where('blocked_id', $userId)->delete();

        return response()->json(['ok' => true]);
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
            ->select('u.id', 'u.full_name', 'r.created_at')
            ->orderByDesc('r.created_at')->get();

        return response()->json([
            'code' => $code,
            'link' => rtrim(config('services.nearby_teacher.app_base_url', 'https://bsl.international'), '/').'/download?ref='.$code,
            'referredCount' => $referred->count(),
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
            $id = DB::table('referrals')->insertGetId([
                'referrer_id' => $referrer,
                'referred_id' => $user->id,
                'created_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            return response()->json(['error' => 'This account has already been credited to a referrer'], 409);
        }

        return response()->json(['referral' => DB::table('referrals')->where('id', $id)->first()]);
    }

    public function topReferrals(): JsonResponse
    {
        $top = DB::table('users as u')
            ->join('referrals as r', 'r.referrer_id', '=', 'u.id')
            ->select('u.id', 'u.full_name')
            ->selectRaw('count(r.id) as referred_count')
            ->groupBy('u.id', 'u.full_name')
            ->orderByDesc('referred_count')
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
