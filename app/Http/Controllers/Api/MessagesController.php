<?php

namespace App\Http\Controllers\Api;

use App\Events\ChatMessageSent;
use App\Http\Controllers\Controller;
use App\Support\CursorPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
            ->select('c.id', 'c.learner_id', 'c.provider_id', 'other.full_name as other_name')
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
            'providerId' => ['required', 'uuid', 'exists:users,id'],
            'body' => ['nullable', 'string', 'max:5000'],
        ]);
        if ($data['providerId'] === $user->id || $user->account_type !== 'learner') {
            return response()->json(['error' => 'Only learners may start a conversation with a different provider'], 400);
        }
        $providerExists = DB::table('users')->where('id', $data['providerId'])
            ->whereIn('account_type', ['teacher', 'institute'])->exists();
        if (! $providerExists) {
            return response()->json(['error' => 'Provider not found'], 404);
        }

        [$conversationId, $messageId] = DB::transaction(function () use ($data, $user): array {
            DB::table('conversations')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'learner_id' => $user->id,
                'provider_id' => $data['providerId'],
                'created_at' => now(),
            ]);
            $conversationId = DB::table('conversations')
                ->where('learner_id', $user->id)
                ->where('provider_id', $data['providerId'])
                ->value('id');

            $messageId = null;
            if (filled($data['body'] ?? null)) {
                $messageId = (string) Str::uuid();
                DB::table('messages')->insert([
                    'id' => $messageId,
                    'conversation_id' => $conversationId,
                    'sender_id' => $user->id,
                    'body' => $data['body'],
                    'created_at' => now(),
                ]);
            }

            return [$conversationId, $messageId];
        });

        if ($messageId !== null) {
            event(new ChatMessageSent($this->savedMessage($messageId)));
        }

        return response()->json(['conversationId' => $conversationId]);
    }

    public function show(Request $request, string $conversationId): JsonResponse
    {
        $user = $request->attributes->get('authenticated_user');
        if (! $this->isParticipant($user->id, $conversationId)) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        $messages = DB::table('messages')->where('conversation_id', $conversationId)
            ->orderByDesc('created_at')->orderByDesc('id');

        return CursorPage::respond($request, $messages, 'messages', reverse: true);
    }

    public function send(Request $request, string $conversationId): JsonResponse
    {
        $user = $request->attributes->get('authenticated_user');
        if ($response = $this->checkContactGates($user)) {
            return $response;
        }
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        if (! $this->isParticipant($user->id, $conversationId)) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        $id = (string) Str::uuid();
        DB::table('messages')->insert([
            'id' => $id,
            'conversation_id' => $conversationId,
            'sender_id' => $user->id,
            'body' => $data['body'],
            'created_at' => now(),
        ]);
        $message = $this->savedMessage($id);
        event(new ChatMessageSent($message));

        return response()->json(['message' => $message]);
    }

    private function savedMessage(string $id): array
    {
        $message = DB::table('messages')->where('id', $id)->first();
        if ($message === null) {
            throw new \RuntimeException('The saved chat message could not be loaded.');
        }

        return (array) $message;
    }

    private function isParticipant(string $userId, string $conversationId): bool
    {
        return DB::table('conversations')->where('id', $conversationId)
            ->where(fn ($query) => $query->where('learner_id', $userId)->orWhere('provider_id', $userId))
            ->exists();
    }

    private function checkContactGates(object $user): ?JsonResponse
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
