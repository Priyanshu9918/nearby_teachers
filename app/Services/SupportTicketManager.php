<?php

namespace App\Services;

use App\Reporting\BusinessActivityRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SupportTicketManager
{
    public const STATUSES = ['pending', 'in_progress', 'resolved', 'rejected'];

    public function create(int|string $userId, string $subject, string $message): object
    {
        $id = (string) Str::uuid();

        return DB::transaction(function () use ($id, $userId, $subject, $message): object {
            DB::table('support_tickets')->insert([
                'id' => $id,
                'user_id' => (string) $userId,
                'subject' => $subject,
                'message' => $message,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            app(BusinessActivityRecorder::class)->record('support_ticket.submitted', $id, (string) $userId,
                relatedUserId: (string) $userId, after: ['status' => 'pending']);

            return DB::table('support_tickets')->where('id', $id)->first();
        }, 3);
    }

    public function updateStatus(string $id, string $status, int|string|null $actorId): ?object
    {
        return DB::transaction(function () use ($id, $status, $actorId): ?object {
            $saved = DB::table('support_tickets')->where('id', $id)->lockForUpdate()->first(['id', 'user_id', 'status']);
            if ($saved === null) {
                return null;
            }

            if ($saved->status !== $status) {
                DB::table('support_tickets')->where('id', $id)->update(['status' => $status, 'updated_at' => now()]);
                app(BusinessActivityRecorder::class)->record('support_ticket.status_changed', $id, $actorId,
                    relatedUserId: (string) $saved->user_id,
                    before: ['status' => $saved->status], after: ['status' => $status]);
            }

            return DB::table('support_tickets')->where('id', $id)->first();
        }, 3);
    }
}
