<?php

namespace App\Http\Controllers;

use App\Jobs\SendPushCampaign;
use App\Reporting\BusinessActivityRecorder;
use App\Reporting\ReportAccessLog;
use App\Services\FirebaseMessagingClient;
use App\Services\PushAudience;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class AdminPushNotificationController extends Controller
{
    public function index(Request $request, ReportAccessLog $access): View
    {
        $access->record($request, 'push-campaigns', 'view');

        return view('admin.push-notifications.index', [
            'messagingEnabled' => (bool) config('services.nearby_teacher.firebase_messaging_enabled'),
        ]);
    }

    public function preview(Request $request, PushAudience $audience, ReportAccessLog $access): JsonResponse
    {
        $data = $audience->validate($request);
        $filters = $audience->filters($data);
        $count = $audience->query($data['target_type'], $filters)->count();
        $access->record($request, 'push-devices', 'preview');

        return response()->json(['recipientCount' => $count, 'targetType' => $data['target_type']])
            ->header('Cache-Control', 'no-store');
    }

    public function store(
        Request $request,
        PushAudience $audience,
        FirebaseMessagingClient $messaging,
        ReportAccessLog $access,
        BusinessActivityRecorder $activity,
    ): RedirectResponse {
        $target = $audience->validate($request);
        $message = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:2000'],
            'deep_link' => ['nullable', 'string', 'max:300', 'regex:/^\/(?!\/)[A-Za-z0-9_\/?=&.%~-]*$/D'],
            'confirmation' => ['required', 'in:SEND'],
        ]);
        try {
            $messaging->assertConfigured();
        } catch (Throwable) {
            return back()->withInput()->withErrors([
                'firebase' => 'Push sending is not ready. Configure the Firebase Messaging service account and enable FCM before creating a campaign.',
            ]);
        }

        $filters = $audience->filters($target);
        $recipientCount = $audience->query($target['target_type'], $filters)->count();
        if ($recipientCount < 1) {
            throw ValidationException::withMessages(['audience' => 'No active, push-enabled devices match this audience.']);
        }
        $data = filled($message['deep_link'] ?? null) ? ['deep_link' => $message['deep_link']] : null;
        $payload = json_encode(['message' => [
            'token' => str_repeat('x', 128),
            'notification' => ['title' => trim($message['title']), 'body' => trim($message['body'])],
            'data' => ['campaign_id' => (string) PHP_INT_MAX] + ($data ?? []),
        ]], JSON_THROW_ON_ERROR);
        if (strlen($payload) > 4096) {
            throw ValidationException::withMessages(['body' => 'Keep the notification title and message within Firebase Cloud Messaging’s 4 KB payload limit.']);
        }
        $adminEmail = filled(config('admin.email')) ? (string) config('admin.email') : null;

        DB::transaction(function () use ($target, $filters, $message, $data, $recipientCount, $adminEmail, $activity, $request): void {
            $campaignId = DB::table('push_campaigns')->insertGetId([
                'title' => trim($message['title']),
                'body' => trim($message['body']),
                'data' => $data === null ? null : json_encode($data, JSON_THROW_ON_ERROR),
                'target_type' => $target['target_type'],
                'target_filters' => $filters === [] ? null : json_encode($filters, JSON_THROW_ON_ERROR),
                'recipient_count' => $recipientCount,
                'status' => 'queued',
                'created_by_email' => $adminEmail,
                'queued_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $activity->record('push.campaign_queued', $campaignId, null, after: [
                'target_type' => $target['target_type'], 'recipient_count' => $recipientCount,
                'title_length' => mb_strlen(trim($message['title'])), 'body_length' => mb_strlen(trim($message['body'])),
            ]);
            $access->record($request, 'push-campaigns', 'create', $campaignId);
            DB::afterCommit(static fn () => SendPushCampaign::dispatch($campaignId)->onQueue('push'));
        });

        return redirect()->route('admin.push-notifications.index')->with(
            'status', 'Campaign queued for '.$recipientCount.' matching device(s). FCM accepted and failed counts are available in campaign reports.'
        );
    }
}
