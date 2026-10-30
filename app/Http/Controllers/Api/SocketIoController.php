<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ChatBlocking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SocketIoController extends Controller
{
    public function authorizeSocket(Request $request): JsonResponse
    {
        $data = $request->validate(['conversationId' => ['sometimes', 'required', 'integer', 'min:1', 'max:9007199254740991']]);
        $user = $request->attributes->get('authenticated_user');
        $room = null;
        if (isset($data['conversationId'])) {
            $conversation = DB::table('conversations')->where('id', $data['conversationId'])
                ->where(fn ($query) => $query->where('learner_id', $user->id)->orWhere('provider_id', $user->id))
                ->first(['learner_id', 'provider_id']);
            if ($conversation === null) {
                return response()->json(['error' => 'Conversation not found'], 404);
            }
            app(ChatBlocking::class)->assertCanMessage($conversation->learner_id, $conversation->provider_id);
            $room = 'private-conversation.'.$data['conversationId'];
        }

        return response()->json(['userId' => $user->id, 'room' => $room, 'expiresInSeconds' => 900, 'authVersion' => (int) ($user->auth_version ?? 0)]);
    }

    public function authorizeFeed(Request $request): JsonResponse
    {
        $data = $request->validate(['postId' => ['required', 'integer', 'min:1', 'max:9007199254740991']]);
        $post = DB::table('posts')->where('id', $data['postId'])->first(['id']);
        if ($post === null) {
            return response()->json(['error' => 'Post not found'], 404);
        }

        $user = $request->attributes->get('authenticated_user');

        return response()->json(['userId' => $user->id, 'postId' => $post->id,
            'room' => 'feed-post.'.$post->id, 'expiresInSeconds' => 900,
            'authVersion' => (int) ($user->auth_version ?? 0)]);
    }
}
