<?php

namespace App\Services;

use App\Reporting\BusinessActivityRecorder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class SupportTicketManager
{
    public const STATUSES = ['pending', 'in_progress', 'resolved', 'rejected'];

    public function create(int|string $userId, string $subject, string $message, ?UploadedFile $screenshot = null): object
    {
        $path = $screenshot?->store('support-screenshots', 'local');
        if ($screenshot !== null && (! is_string($path) || $path === '')) {
            throw new \RuntimeException('Support screenshot could not be stored.');
        }
        try {
            return DB::transaction(function () use ($userId, $subject, $message, $path): object {
                $id = DB::table('support_tickets')->insertGetId([
                    'user_id' => $userId,
                    'subject' => $subject,
                    'message' => $message,
                    'screenshot_path' => $path,
                    'status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                app(BusinessActivityRecorder::class)->record('support_ticket.submitted', $id, $userId,
                    relatedUserId: $userId, after: ['status' => 'pending']);
                if ($path !== null) {
                    app(BusinessActivityRecorder::class)->record('support_ticket.screenshot_attached', $id, $userId,
                        relatedUserId: $userId, after: ['has_screenshot' => true]);
                }

                return DB::table('support_tickets')->where('id', $id)->first();
            }, 3);
        } catch (Throwable $error) {
            if (is_string($path)) {
                Storage::disk('local')->delete($path);
            }
            throw $error;
        }
    }

    public function updateStatus(int $id, string $status, int|string|null $actorId): ?object
    {
        return DB::transaction(function () use ($id, $status, $actorId): ?object {
            $saved = DB::table('support_tickets')->where('id', $id)->lockForUpdate()->first(['id', 'user_id', 'status']);
            if ($saved === null) {
                return null;
            }

            if ($saved->status !== $status) {
                DB::table('support_tickets')->where('id', $id)->update(['status' => $status, 'updated_at' => now()]);
                app(BusinessActivityRecorder::class)->record('support_ticket.status_changed', $id, $actorId,
                    relatedUserId: (int) $saved->user_id,
                    before: ['status' => $saved->status], after: ['status' => $status]);
            }

            return DB::table('support_tickets')->where('id', $id)->first();
        }, 3);
    }
}
