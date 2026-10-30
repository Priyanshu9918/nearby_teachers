<?php

namespace App\Broadcasting;

use App\Services\ChatBlocking;
use App\Support\RecordId;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class SocketIoBroadcaster extends Broadcaster
{
    public function __construct(private readonly array $config) {}

    public function auth($request)
    {
        if ($request->user() === null || ! str_starts_with((string) $request->channel_name, 'private-')) {
            throw new AccessDeniedHttpException;
        }

        return $this->verifyUserCanAccessChannel($request, substr($request->channel_name, 8));
    }

    public function validAuthenticationResponse($request, $result)
    {
        return response()->json(['authorized' => true, 'channel' => $request->channel_name]);
    }

    public function broadcast(array $channels, $event, array $payload = [])
    {
        $secret = (string) ($this->config['secret'] ?? '');
        if (strlen($secret) < 32) {
            throw new RuntimeException('SOCKET_IO_SHARED_SECRET must be at least 32 characters.');
        }
        $message = $payload['message'] ?? [];
        $data = $payload['data'] ?? [];
        $feedEvents = ['feed:comment.created', 'feed:comment.like_changed', 'feed:post.like_changed'];
        if (in_array($event, $feedEvents, true)) {
            $postId = $data['postId'] ?? null;
            if (! RecordId::valid($postId) || $this->formatChannels($channels) !== ['private-feed-post.'.$postId]
                || ! $this->validFeedData($event, $data, (int) $postId)) {
                throw new RuntimeException('Unsupported Socket.IO feed broadcast.');
            }
            if (! DB::table('posts')->where('id', $postId)->exists()) {
                return;
            }
            Http::timeout(5)->connectTimeout(2)
                ->withHeaders(['X-Socket-Secret' => $secret])
                ->post(rtrim($this->config['url'], '/').'/internal/events', [
                    'postId' => (int) $postId, 'event' => $event, 'data' => $data,
                ])->throw();

            return;
        }
        $supported = ['chat:delivered', 'chat:read', 'chat:typing', 'chat:stop_typing', 'chat:delete', 'chat:edit', 'chat:reply', 'chat:reaction'];
        $legacy = $event === 'message.sent';
        $conversationId = $legacy ? ($message['conversation_id'] ?? null) : ($data['conversationId'] ?? null);
        if ((! $legacy && ! in_array($event, $supported, true)) || ! RecordId::valid($conversationId)
            || $this->formatChannels($channels) !== ['private-conversation.'.$conversationId]) {
            throw new RuntimeException('Unsupported Socket.IO chat broadcast.');
        }
        $conversation = DB::table('conversations')->where('id', $conversationId)
            ->select('learner_id', 'provider_id')->first();
        if ($conversation === null) {
            return;
        }
        // Recheck at delivery time: already connected sockets must not bypass a
        // block, and queued messages/typing/receipts must not leak across it.
        if ($event !== 'chat:delete' && app(ChatBlocking::class)->blocked($conversation->learner_id, $conversation->provider_id)) {
            return;
        }

        // Reload persisted content so delayed jobs cannot republish an old/deleted body.
        if ($legacy || isset($data['message'])) {
            $id = $legacy ? ($message['id'] ?? null) : ($data['message']['id'] ?? null);
            $saved = DB::table('messages')->where('id', $id)->where('conversation_id', $conversationId)->first();
            if ($saved === null) {
                return;
            }
            if ($legacy) {
                $message = (array) $saved;
            } else {
                $data['message'] = (array) $saved;
            }
        }
        $packet = ['recipientIds' => [$conversation->learner_id, $conversation->provider_id]];
        if ($legacy) {
            $packet['message'] = $message;
        } else {
            $packet += ['event' => $event, 'data' => $data];
        }
        Http::timeout(5)->connectTimeout(2)
            ->withHeaders(['X-Socket-Secret' => $secret])
            ->post(rtrim($this->config['url'], '/').'/internal/events', $packet)->throw();
    }

    private function validFeedData(string $event, array $data, int $postId): bool
    {
        if ($event === 'feed:comment.created') {
            $comment = $data['comment'] ?? null;

            return is_array($comment) && RecordId::valid($comment['id'] ?? null)
                && (int) ($comment['post_id'] ?? 0) === $postId
                && RecordId::valid($comment['user_id'] ?? null)
                && (($comment['parent_comment_id'] ?? null) === null || RecordId::valid($comment['parent_comment_id']))
                && is_string($comment['body'] ?? null) && mb_strlen($comment['body']) <= 5000
                && is_string($comment['author_name'] ?? null) && mb_strlen($comment['author_name']) <= 160
                && in_array($comment['author_type'] ?? null, ['learner', 'teacher', 'institute'], true);
        }

        $actor = $data['user'] ?? null;

        return ($event === 'feed:post.like_changed' || RecordId::valid($data['commentId'] ?? null))
            && is_array($actor) && RecordId::valid($actor['user_id'] ?? null)
            && is_string($actor['name'] ?? null) && mb_strlen($actor['name']) <= 160
            && in_array($actor['type'] ?? null, ['learner', 'teacher', 'institute'], true)
            && is_bool($data['liked'] ?? null)
            && is_int($data['likeCount'] ?? null) && $data['likeCount'] >= 0;
    }

}
