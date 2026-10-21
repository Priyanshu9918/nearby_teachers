<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;

Broadcast::channel('conversation.{conversationId}', function (object $user, string $conversationId): bool {
    return DB::table('conversations')
        ->where('id', $conversationId)
        ->where(fn ($query) => $query
            ->where('learner_id', $user->id)
            ->orWhere('provider_id', $user->id))
        ->exists();
});
