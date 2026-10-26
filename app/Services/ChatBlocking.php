<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ChatBlocking
{
    /** Serialize block changes and chat writes using the same stable lock order. */
    public function lockParticipants(int $first, int $second): Collection
    {
        $participants = DB::table('users')->whereIn('id', [$first, $second])
            ->orderBy('id')->lockForUpdate()->get(['id', 'account_type']);
        abort_if($participants->count() !== 2, 404, 'User not found');

        return $participants;
    }

    public function between(int $first, int $second): Builder
    {
        return DB::table('blocks')->where(fn ($query) => $query
            ->where(fn ($pair) => $pair->where('blocker_id', $first)->where('blocked_id', $second))
            ->orWhere(fn ($pair) => $pair->where('blocker_id', $second)->where('blocked_id', $first)));
    }

    public function blocked(int $first, int $second): bool
    {
        return $this->between($first, $second)->exists();
    }

    public function assertCanMessage(int $first, int $second): void
    {
        // A locking read sees the latest committed block after waiting for user locks,
        // including under MySQL's REPEATABLE READ isolation.
        $blocked = DB::transactionLevel() > 0
            ? $this->between($first, $second)->lockForUpdate()->first(['id']) !== null
            : $this->blocked($first, $second);
        abort_if($blocked, response()->json([
            'error' => 'Messaging is unavailable for this conversation.',
            'code' => 'CHAT_BLOCKED',
        ], 403));
    }

    public function status(int $viewer, int $other): array
    {
        $blocks = $this->between($viewer, $other)->get(['blocker_id']);

        return [
            'blockedByMe' => $blocks->contains('blocker_id', $viewer),
            'canMessage' => $blocks->isEmpty(),
        ];
    }
}
