<?php

namespace App\Jobs;

use App\Reporting\BusinessActivityRecorder;
use App\Services\PushAudience;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/** Create a reportable, content-free push campaign for one chat recipient. */
class QueueChatMessagePush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $recipientId, public readonly int $senderId)
    {
        $this->onQueue('push');
    }

    public function handle(PushAudience $audience): void
    {
        if (! (bool) config('services.nearby_teacher.firebase_messaging_enabled')) {
            return;
        }

        $filters = ['user_id' => $this->recipientId];
        $recipientCount = $audience->query('user', $filters)->count();
        if ($recipientCount === 0) {
            return;
        }

        DB::transaction(function () use ($filters, $recipientCount): void {
            $now = now();
            $campaignId = DB::table('push_campaigns')->insertGetId([
                'title' => 'New message',
                'body' => 'You have a new message.',
                'data' => json_encode(['type' => 'chat_message'], JSON_THROW_ON_ERROR),
                'target_type' => 'user',
                'target_filters' => json_encode($filters, JSON_THROW_ON_ERROR),
                'recipient_count' => $recipientCount,
                'status' => 'queued',
                'queued_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            app(BusinessActivityRecorder::class)->record('push.campaign_queued', $campaignId, $this->senderId,
                relatedUserId: $this->recipientId,
                after: ['target_type' => 'user', 'recipient_count' => $recipientCount,
                    'title_length' => strlen('New message'), 'body_length' => strlen('You have a new message.')]);
            DB::afterCommit(static fn () => SendPushCampaign::dispatch($campaignId)->onQueue('push'));
        });
    }
}
