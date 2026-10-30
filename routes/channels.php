<?php

use App\Services\ChatBlocking;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;

Broadcast::channel('conversation.{conversationId}', function (object $user, int $conversationId): bool {
    $conversation = DB::table('conversations')
        ->where('id', $conversationId)
        ->where(fn ($query) => $query
            ->where('learner_id', $user->id)
            ->orWhere('provider_id', $user->id))
        ->first(['learner_id', 'provider_id']);

    return $conversation !== null
        && ! app(ChatBlocking::class)->blocked($conversation->learner_id, $conversation->provider_id);
});

Broadcast::channel('feed-post.{postId}', function (object $user, int $postId): bool {
    return DB::table('posts')->where('id', $postId)->exists();
});
