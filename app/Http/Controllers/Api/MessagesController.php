<?php

namespace App\Http\Controllers\Api;

use App\Events\ChatActivity;
use App\Events\ChatMessageSent;
use App\Http\Controllers\Controller;
use App\Reporting\BusinessActivityRecorder;
use App\Services\ChatBlocking;
use App\Support\CursorPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MessagesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authenticated_user');
        // One indexed latest-message lookup per owned conversation, not a global ranking.
        $latestId = DB::table('messages as recent')
            ->select('recent.id')->whereColumn('recent.conversation_id', 'c.id')
            ->orderByDesc('recent.created_at')->orderByDesc('recent.id')->limit(1);

        $conversations = DB::table('conversations as c')
            ->join('users as other', function ($join) use ($user): void {
                $join->whereRaw('other.id = CASE WHEN c.learner_id = ? THEN c.provider_id ELSE c.learner_id END', [$user->id]);
            })
            ->leftJoin('messages as last_message', function ($join) use ($latestId): void {
                $join->where('last_message.id', '=', $latestId);
            })
            ->where(fn ($query) => $query->where('c.learner_id', $user->id)->orWhere('c.provider_id', $user->id))
            ->select('c.id', 'c.learner_id', 'c.provider_id', 'other.id as other_user_id', 'other.full_name as other_name')
            ->selectRaw('EXISTS (SELECT 1 FROM blocks WHERE blocker_id = ? AND blocked_id = other.id) AS blocked_by_me', [$user->id])
            ->selectRaw('NOT EXISTS (SELECT 1 FROM blocks WHERE (blocker_id = ? AND blocked_id = other.id) OR (blocker_id = other.id AND blocked_id = ?)) AS can_message', [$user->id, $user->id])
            ->selectRaw("CASE WHEN other.avatar_status = 'approved' THEN other.avatar_url ELSE NULL END AS other_avatar")
            ->addSelect('last_message.body as last_message', 'last_message.created_at as last_message_at')
            ->selectRaw('COALESCE(last_message.created_at, c.created_at) AS activity_at')
            ->orderByDesc('activity_at')->orderByDesc('c.id');

        return CursorPage::respond($request, $conversations, 'conversations');
    }

    public function start(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authenticated_user');
        if ($response = $this->checkContactGates($user)) {
            return $response;
        }
        $data = $request->validate([
            'providerId' => ['required', 'integer', 'min:1', 'max:9007199254740991', 'exists:users,id'],
            'body' => ['nullable', 'string', 'max:5000'],
        ]);
        if ((int) $data['providerId'] === $user->id) {
            return response()->json(['error' => 'Choose a different user to start a conversation'], 400);
        }

        [$conversationId, $messageId] = DB::transaction(function () use ($data, $user): array {
            // Lock both users in a stable order so opposite-side starts serialize.
            $participants = app(ChatBlocking::class)->lockParticipants($user->id, (int) $data['providerId']);
            app(ChatBlocking::class)->assertCanMessage($user->id, (int) $data['providerId']);
            $recipient = $participants->first(fn ($participant) => $participant->id !== $user->id);
            $conversationId = DB::table('conversations')
                ->where(fn ($query) => $query
                    ->where(fn ($pair) => $pair->where('learner_id', $user->id)->where('provider_id', $recipient->id))
                    ->orWhere(fn ($pair) => $pair->where('learner_id', $recipient->id)->where('provider_id', $user->id)))
                ->orderBy('created_at')->orderBy('id')
                ->value('id');
            if ($conversationId === null) {
                // Retain legacy learner/provider columns without limiting who can chat.
                $first = $participants->where('account_type', 'learner')->count() === 1
                    ? $participants->firstWhere('account_type', 'learner') : $participants->first();
                $second = $participants->first(fn ($participant) => $participant->id !== $first->id);
                $conversationId = DB::table('conversations')->insertGetId([
                    'learner_id' => $first->id,
                    'provider_id' => $second->id,
                    'created_at' => now(),
                ]);
            }

            $messageId = null;
            if (filled($data['body'] ?? null)) {
                $messageId = DB::table('messages')->insertGetId([
                    'conversation_id' => $conversationId,
                    'sender_id' => $user->id,
                    'body' => $data['body'],
                    'created_at' => now(),
                ]);
                app(BusinessActivityRecorder::class)->record('message.created', $messageId, $user->id, $recipient->id,
                    after: ['conversation_id' => $conversationId]);
            }

            return [$conversationId, $messageId];
        }, 3);

        if ($messageId !== null) {
            event(new ChatMessageSent($this->savedMessage($messageId)));
        }

        return response()->json(['conversationId' => $conversationId]);
    }

    public function show(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->attributes->get('authenticated_user');
        $conversation = $this->conversation($user->id, $conversationId);
        if ($conversation === null) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        $messages = DB::table('messages')->where('conversation_id', $conversationId)
            ->orderByDesc('created_at')->orderByDesc('id');

        $response = CursorPage::respond($request, $messages, 'messages', reverse: true);
        $result = $response->getData(true);
        $other = $conversation->learner_id === $user->id ? $conversation->provider_id : $conversation->learner_id;
        $result['chatStatus'] = ['otherUserId' => $other, ...app(ChatBlocking::class)->status($user->id, $other)];
        $reactions = DB::table('message_reactions')->whereIn('message_id', array_column($result['messages'], 'id'))
            ->whereNotNull('reaction')->select('message_id', 'user_id', 'reaction', 'updated_at')->get()->groupBy('message_id');
        foreach ($result['messages'] as &$message) {
            $message['reactions'] = $message['deleted_at'] === null ? ($reactions->get($message['id'])?->all() ?? []) : [];
        }
        unset($message);

        return $response->setData($result);
    }

    public function send(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->attributes->get('authenticated_user');
        if ($response = $this->checkContactGates($user)) {
            return $response;
        }
        $data = $request->validate(['body' => ['required', 'string', 'max:5000', 'not_regex:/^\\s*$/u'], 'replyToId' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:9007199254740991']]);
        $conversation = $this->conversation($user->id, $conversationId);
        if ($conversation === null) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        $id = DB::transaction(function () use ($conversationId, $conversation, $user, $data): int {
            app(ChatBlocking::class)->lockParticipants($conversation->learner_id, $conversation->provider_id);
            app(ChatBlocking::class)->assertCanMessage($conversation->learner_id, $conversation->provider_id);
            if (! empty($data['replyToId'])) {
                $parent = DB::table('messages')->where('id', $data['replyToId'])->where('conversation_id', $conversationId)->lockForUpdate()->first();
                abort_if($parent === null, 404, 'Reply target not found in this conversation');
                abort_if($parent->deleted_at !== null, 409, 'Cannot reply to a deleted message');
            }
            $id = DB::table('messages')->insertGetId([
                'conversation_id' => $conversationId,
                'sender_id' => $user->id,
                'body' => $data['body'],
                'reply_to_id' => $data['replyToId'] ?? null,
                'created_at' => now(),
            ]);
            app(BusinessActivityRecorder::class)->record('message.created', $id, $user->id,
                $conversation->learner_id === $user->id ? $conversation->provider_id : $conversation->learner_id,
                after: ['conversation_id' => $conversationId]);
            if (! empty($data['replyToId'])) {
                app(BusinessActivityRecorder::class)->record('message.replied', $id, $user->id,
                    $conversation->learner_id === $user->id ? $conversation->provider_id : $conversation->learner_id,
                    after: ['conversation_id' => $conversationId, 'reply_to_id' => $data['replyToId']]);
            }

            return $id;
        }, 3);
        $message = $this->savedMessage($id);
        event(new ChatMessageSent($message));
        if (! empty($data['replyToId'])) {
            event(new ChatActivity('chat:reply', ['conversationId' => $conversationId, 'message' => $message]));
        }

        return response()->json(['message' => $message]);
    }

    private function savedMessage(int $id): array
    {
        $message = DB::table('messages')->where('id', $id)->first();
        if ($message === null) {
            throw new \RuntimeException('The saved chat message could not be loaded.');
        }

        return (array) $message;
    }

    private function conversation(int $userId, int $conversationId): ?object
    {
        return DB::table('conversations')->where('id', $conversationId)
            ->where(fn ($query) => $query->where('learner_id', $userId)->orWhere('provider_id', $userId))
            ->first(['id', 'learner_id', 'provider_id']);
    }

    public function checkContactGates(object $user): ?JsonResponse
    {
        if ($user->safety_disclaimer_acknowledged_at === null) {
            return response()->json([
                'error' => 'Please review and acknowledge the safety disclaimer before messaging or booking a lesson.',
                'disclaimerEndpoint' => '/auth/safety-disclaimer',
                'acknowledgeEndpoint' => '/auth/acknowledge-safety-disclaimer',
            ], 403);
        }
        if ($user->is_minor && $user->parental_consent_confirmed_at === null) {
            return response()->json([
                'error' => 'A parent or guardian needs to confirm consent before this account can message or book a lesson.',
            ], 403);
        }

        return null;
    }
}
