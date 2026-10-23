<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
            $allowed = DB::table('conversations')->where('id', $data['conversationId'])
                ->where(fn ($query) => $query->where('learner_id', $user->id)->orWhere('provider_id', $user->id))
                ->exists();
            if (! $allowed) {
                return response()->json(['error' => 'Conversation not found'], 404);
            }
            $room = 'private-conversation.'.$data['conversationId'];
        }

        return response()->json(['userId' => $user->id, 'room' => $room, 'expiresInSeconds' => 900, 'authVersion' => (int) ($user->auth_version ?? 0)]);
    }
}
