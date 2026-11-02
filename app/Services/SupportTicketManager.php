<?php

namespace App\Services;

use App\Reporting\BusinessActivityRecorder;
use Illuminate\Support\Facades\DB;

final class SupportTicketManager
{
    public const STATUSES = ['pending', 'in_progress', 'resolved', 'rejected'];

    public function create(int|string $userId, string $subject, string $message): object
    {
        return DB::transaction(function () use ($userId, $subject, $message): object {
            $id = DB::table('support_tickets')->insertGetId([
                'user_id' => $userId,
                'subject' => $subject,
                'message' => $message,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            app(BusinessActivityRecorder::class)->record('support_ticket.submitted', $id, $userId,
                relatedUserId: $userId, after: ['status' => 'pending']);

            return DB::table('support_tickets')->where('id', $id)->first();
        }, 3);
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
