<?php

namespace App\Jobs;

use App\Reporting\BusinessActivityRecorder;
use App\Services\FirebaseMessagingClient;
use App\Services\PushAudience;
use App\Services\PushDeviceRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendPushCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 75;

    public function __construct(public readonly int $campaignId, public readonly ?int $afterDeviceId = null)
    {
        $this->onQueue('push');
    }

    public function handle(PushAudience $audience, FirebaseMessagingClient $messaging, PushDeviceRegistry $devices): void
    {
        $campaign = DB::table('push_campaigns')->where('id', $this->campaignId)->first();
        if ($campaign === null || in_array($campaign->status, ['completed', 'completed_with_errors', 'failed'], true)) {
            return;
        }
        $filters = json_decode((string) $campaign->target_filters, true);
        $filters = is_array($filters) ? $filters : [];
        DB::table('push_campaigns')->where('id', $this->campaignId)->where('status', 'queued')->update([
            'status' => 'sending', 'started_at' => now(), 'updated_at' => now(),
        ]);

        $query = $audience->query($campaign->target_type, $filters);
        if ($this->afterDeviceId !== null) {
            $query->where('d.id', '>', $this->afterDeviceId);
        }
        $batch = $query->limit(101)->get();
        if ($batch->isEmpty()) {
            $this->finishCampaign();

            return;
        }
        $hasMore = $batch->count() > 100;
        $batch = $batch->take(100);
        $deviceIds = $batch->pluck('id')->all();
        $alreadyAccepted = DB::table('push_deliveries')->where('campaign_id', $this->campaignId)
            ->whereIn('device_id', $deviceIds)->where('status', 'accepted')->pluck('device_id')->all();
        $alreadyAccepted = array_fill_keys($alreadyAccepted, true);
        $recipients = [];
        $attempts = [];
        $now = now();

        foreach ($batch as $device) {
            if (isset($alreadyAccepted[$device->id])) {
                continue;
            }
            $previous = DB::table('push_deliveries')->where('campaign_id', $this->campaignId)->where('device_id', $device->id)->first();
            $attempts[$device->id] = min(255, ((int) ($previous->attempt_count ?? 0)) + 1);
            try {
                $recipients[$device->id] = ['fid' => Crypt::decryptString($device->fid_ciphertext), 'device' => $device];
            } catch (Throwable) {
                $recipients[$device->id] = ['fid' => null, 'device' => $device];
            }
        }

        DB::transaction(function () use ($batch, $attempts, $now): void {
            foreach ($batch as $device) {
                $existing = DB::table('push_deliveries')->where('campaign_id', $this->campaignId)->where('device_id', $device->id)->first();
                if ($existing?->status === 'accepted') {
                    continue;
                }
                $values = ['status' => 'sending', 'error_code' => null, 'attempt_count' => $attempts[$device->id] ?? 0,
                    'attempted_at' => $now, 'updated_at' => $now];
                if ($existing === null) {
                    DB::table('push_deliveries')->insert(['campaign_id' => $this->campaignId,
                        'device_id' => $device->id, ...$values, 'created_at' => $now]);
                } else {
                    DB::table('push_deliveries')->where('id', $existing->id)->update($values);
                }
            }
        });

        $sendable = array_filter($recipients, static fn (array $recipient): bool => is_string($recipient['fid']));
        $results = $messaging->sendBatch($sendable, $campaign);
        foreach ($recipients as $deviceId => $recipient) {
            if ($recipient['fid'] === null) {
                $results[$deviceId] = ['status' => 'failed', 'error_code' => 'TOKEN_UNAVAILABLE'];
            }
        }

        DB::transaction(function () use ($results, $attempts, $devices, $recipients): void {
            $activity = app(BusinessActivityRecorder::class);
            foreach ($results as $deviceId => $result) {
                $deliveryQuery = DB::table('push_deliveries')->where('campaign_id', $this->campaignId)->where('device_id', $deviceId);
                $deliveryQuery->update([
                    'status' => $result['status'],
                    'provider_message_id' => $result['message_id'] ?? null,
                    'error_code' => $result['error_code'] ?? null,
                    'attempt_count' => $attempts[$deviceId] ?? 1,
                    'attempted_at' => now(), 'updated_at' => now(),
                ]);
                $deliveryId = $deliveryQuery->value('id');
                if ($deliveryId !== null) {
                    $activity->record('push.delivery_result', (int) $deliveryId, null,
                        relatedUserId: $recipients[$deviceId]['device']->user_id ?? null,
                        after: ['status' => $result['status'], 'error_code' => $result['error_code'] ?? null,
                            'attempt_count' => $attempts[$deviceId] ?? 1]);
                }
                if (($result['invalid_fid'] ?? false) === true) {
                    $devices->disableInvalidFid($deviceId);
                }
            }
        });

        if ($hasMore) {
            self::dispatch($this->campaignId, (int) $batch->last()->id)->onQueue('push');
        } else {
            $this->finishCampaign();
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::transaction(function (): void {
            $campaign = DB::table('push_campaigns')->where('id', $this->campaignId)->lockForUpdate()->first();
            if ($campaign === null || in_array($campaign->status, ['completed', 'completed_with_errors', 'failed'], true)) {
                return;
            }
            $inProgress = DB::table('push_deliveries as pd')->leftJoin('push_devices as d', 'd.id', '=', 'pd.device_id')
                ->where('pd.campaign_id', $this->campaignId)->where('pd.status', 'sending')
                ->get(['pd.id', 'pd.device_id', 'pd.attempt_count', 'd.user_id']);
            foreach ($inProgress as $delivery) {
                DB::table('push_deliveries')->where('id', $delivery->id)->update([
                    'status' => 'failed', 'error_code' => 'CAMPAIGN_WORKER_FAILED', 'updated_at' => now(),
                ]);
                app(BusinessActivityRecorder::class)->record('push.delivery_result', $delivery->id, null,
                    relatedUserId: $delivery->user_id,
                    after: ['status' => 'failed', 'error_code' => 'CAMPAIGN_WORKER_FAILED', 'attempt_count' => (int) $delivery->attempt_count]);
            }
            $counts = $this->deliveryCounts();
            DB::table('push_campaigns')->where('id', $this->campaignId)->update([
                'status' => 'failed', 'finished_at' => now(), 'updated_at' => now(),
            ]);
            app(BusinessActivityRecorder::class)->record('push.campaign_completed', $this->campaignId, null,
                after: ['status' => 'failed', 'accepted_count' => $counts['accepted'], 'failed_count' => $counts['failed']]);
        });
    }

    private function finishCampaign(): void
    {
        DB::transaction(function (): void {
            $campaign = DB::table('push_campaigns')->where('id', $this->campaignId)->lockForUpdate()->first();
            if ($campaign === null || in_array($campaign->status, ['completed', 'completed_with_errors', 'failed'], true)) {
                return;
            }
            $counts = $this->deliveryCounts();
            $status = $counts['failed'] > 0 ? 'completed_with_errors' : 'completed';
            DB::table('push_campaigns')->where('id', $this->campaignId)->update([
                'status' => $status, 'finished_at' => now(), 'updated_at' => now(),
            ]);
            app(BusinessActivityRecorder::class)->record('push.campaign_completed', $this->campaignId, null,
                after: ['status' => $status, 'accepted_count' => $counts['accepted'], 'failed_count' => $counts['failed']]);
        });
    }

    private function deliveryCounts(): array
    {
        $counts = DB::table('push_deliveries')->where('campaign_id', $this->campaignId)
            ->selectRaw("SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted_count")
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count")
            ->first();

        return ['accepted' => (int) ($counts->accepted_count ?? 0), 'failed' => (int) ($counts->failed_count ?? 0)];
    }
}
