<?php

namespace App\Http\Controllers\Api;

use App\Events\ChatActivity;
use App\Events\ChatTyping;
use App\Http\Controllers\Controller;
use App\Reporting\BusinessActivityRecorder;
use App\Services\ChatBlocking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ChatEventsController extends Controller
{
    public function __construct(private readonly MessagesController $messages) {}

    public function dispatch(Request $request, string $event): JsonResponse
    {
        $user = $request->attributes->get('authenticated_user');
        if ($event === 'chat:unread_count') {
            $data = $request->validate(['conversationId' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:9007199254740991']]);
            if (! empty($data['conversationId'])) {
                $this->conversation($data['conversationId'], $user->id);
            }
            $query = DB::table('messages as m')->join('conversations as c', 'c.id', '=', 'm.conversation_id')
                ->where(fn ($q) => $q->where('c.learner_id', $user->id)->orWhere('c.provider_id', $user->id))
                ->where('m.sender_id', '<>', $user->id)->whereNull('m.read_at')->whereNull('m.deleted_at');
            if (! empty($data['conversationId'])) {
                $query->where('c.id', $data['conversationId']);
            }

            return response()->json(['conversationId' => $data['conversationId'] ?? null, 'unreadCount' => $query->count()]);
        }
        $data = $request->validate(['conversationId' => ['required', 'integer', 'min:1', 'max:9007199254740991']]);
        $conversationId = (int) $data['conversationId'];
        $conversation = $this->conversation($conversationId, $user->id);
        if ($event === 'chat:history') {
            $page = $request->validate(['perPage' => ['sometimes', 'integer', 'between:1,100'], 'cursor' => ['sometimes', 'nullable', 'string', 'max:2048']]);
            $request->query->add(Arr::only($page, ['perPage', 'cursor']));

            return $this->messages->show($request, $conversationId);
        }
        if (! in_array($event, ['chat:delivered', 'chat:read'], true)) {
            if ($blocked = $this->messages->checkContactGates($user)) {
                return $blocked;
            }
        }
        if (in_array($event, ['chat:send', 'chat:reply'], true)) {
            if ($event === 'chat:reply') {
                $request->validate(['replyToId' => ['required', 'integer', 'min:1', 'max:9007199254740991']]);
            }

            return $this->messages->send($request, $conversationId);
        }
        if (in_array($event, ['chat:typing', 'chat:stop_typing'], true)) {
            app(ChatBlocking::class)->assertCanMessage($conversation->learner_id, $conversation->provider_id);
            $payload = ['conversationId' => $conversationId, 'userId' => $user->id,
                'typing' => $event === 'chat:typing', 'updatedAt' => now()->toISOString(), 'expiresAt' => now()->addSeconds(5)->toISOString()];
            event(new ChatTyping($event, $payload));

            return response()->json($payload);
        }
        $input = $request->validate(['messageId' => ['required', 'integer', 'min:1', 'max:9007199254740991']]);
        if ($event === 'chat:edit') {
            $input += $request->validate(['body' => ['required', 'string', 'max:5000', 'not_regex:/^\s*$/u']]);
        }
        if ($event === 'chat:reaction') {
            $input += $request->validate(['reaction' => ['present', 'nullable', Rule::in(['👍', '👎', '❤️', '😂', '😮', '😢', '🙏', '🎉'])]]);
        }
        $other = $conversation->learner_id === $user->id ? $conversation->provider_id : $conversation->learner_id;
        $payload = DB::transaction(function () use ($event, $input, $conversationId, $user, $other): array {
            app(ChatBlocking::class)->lockParticipants($user->id, $other);
            // Senders retain the ability to remove their own historical content.
            if ($event !== 'chat:delete') {
                app(ChatBlocking::class)->assertCanMessage($user->id, $other);
            }
            $message = DB::table('messages')->where('id', $input['messageId'])->where('conversation_id', $conversationId)->lockForUpdate()->first();
            abort_if($message === null, 404, 'Message not found in this conversation');
            $payload = ['conversationId' => $conversationId, 'messageId' => $message->id, 'userId' => $user->id];
            $changed = false;
            $recorder = app(BusinessActivityRecorder::class);
            $record = function (string $name, array $before, array $after) use ($recorder, $message, $user, $other, $conversationId): void {
                $recorder->record($name, $message->id, $user->id, $other, $before, ['conversation_id' => $conversationId, ...$after]);
            };
            if (in_array($event, ['chat:read', 'chat:delivered'], true)) {
                abort_if($message->sender_id === $user->id, 403, 'Only the recipient can confirm delivery or reading');
                abort_if($message->deleted_at !== null, 409, 'Message is deleted');
                $updates = [];
                if ($message->delivered_at === null) {
                    $updates['delivered_at'] = now()->format('Y-m-d H:i:s.u');
                    $record('message.delivered', ['delivered_at' => null], ['delivered_at' => $updates['delivered_at']]);
                }
                if ($event === 'chat:read' && $message->read_at === null) {
                    $updates['read_at'] = now()->format('Y-m-d H:i:s.u');
                    $record('message.read', ['read_at' => null], ['read_at' => $updates['read_at']]);
                }
                if ($updates) {
                    DB::table('messages')->where('id', $message->id)->update($updates);
                }
                $changed = $updates !== [];
                $payload += ['deliveredAt' => $updates['delivered_at'] ?? $message->delivered_at, 'readAt' => $updates['read_at'] ?? $message->read_at];
                if ($event === 'chat:read' && isset($updates['delivered_at'])) {
                    DB::afterCommit(fn () => event(new ChatActivity('chat:delivered', $payload)));
                }
            } elseif (in_array($event, ['chat:edit', 'chat:delete'], true)) {
                abort_if($message->sender_id !== $user->id, 403, 'Only the sender may edit or delete this message');
                if ($event === 'chat:edit') {
                    abort_if($message->deleted_at !== null, 409, 'Deleted messages cannot be edited');
                }
                if (($event === 'chat:delete' && $message->deleted_at === null) || ($event === 'chat:edit' && $message->body !== $input['body'])) {
                    $column = $event === 'chat:edit' ? 'edited_at' : 'deleted_at';
                    $timestamp = now()->format('Y-m-d H:i:s.u');
                    DB::table('messages')->where('id', $message->id)->update(['body' => $event === 'chat:delete' ? '' : $input['body'], $column => $timestamp, 'revision' => $message->revision + 1]);
                    $record($event === 'chat:edit' ? 'message.edited' : 'message.deleted', ['revision' => $message->revision, $column => $message->$column], ['revision' => $message->revision + 1, $column => $timestamp]);
                    $changed = true;
                }
                $payload['message'] = (array) DB::table('messages')->where('id', $message->id)->first();
            } elseif ($event === 'chat:reaction') {
                abort_if($message->deleted_at !== null, 409, 'Cannot react to a deleted message');
                // Message-row locking serializes concurrent reaction changes, including first insert.
                $previous = DB::table('message_reactions')->where('message_id', $message->id)->where('user_id', $user->id)->first();
                $old = $previous->reaction ?? null;
                if ($old !== $input['reaction']) {
                    DB::table('message_reactions')->updateOrInsert(['message_id' => $message->id, 'user_id' => $user->id],
                        ['conversation_id' => $conversationId, 'reaction' => $input['reaction'], 'created_at' => $previous->created_at ?? now(), 'updated_at' => now()]);
                    $id = DB::table('message_reactions')->where('message_id', $message->id)->where('user_id', $user->id)->value('id');
                    $recorder->record('message.reaction_changed', $id, $user->id, $other, ['reaction' => $old],
                        ['conversation_id' => $conversationId, 'message_id' => $message->id, 'reaction' => $input['reaction']]);
                    $changed = true;
                }
                $payload['reaction'] = $input['reaction'];
                $payload['updatedAt'] = DB::table('message_reactions')->where('message_id', $message->id)->where('user_id', $user->id)->value('updated_at');
            } else {
                abort(404);
            }
            if ($changed) {
                DB::afterCommit(fn () => event(new ChatActivity($event, $payload)));
            }

            return $payload;
        }, 3);

        return response()->json($payload);
    }

    private function conversation(int $id, int $userId): object
    {
        $conversation = DB::table('conversations')->where('id', $id)
            ->where(fn ($q) => $q->where('learner_id', $userId)->orWhere('provider_id', $userId))->first();
        abort_if($conversation === null, 404, 'Conversation not found');

        return $conversation;
    }
}
