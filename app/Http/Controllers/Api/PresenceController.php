<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Presence\UserPresence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PresenceController extends Controller
{
    public function __invoke(Request $request, UserPresence $presence): JsonResponse
    {
        $data = $request->validate([
            'deviceId' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,128}$/D'],
            'event' => ['required', 'string', 'in:foreground,activity,heartbeat,background'],
            'userId' => ['prohibited'], 'isActive' => ['prohibited'], 'lastActiveAt' => ['prohibited'],
        ]);

        return response()->json(['isActive' => $presence->update(
            $request->attributes->get('authenticated_user'), $data['deviceId'], $data['event'],
        )])->header('Cache-Control', 'no-store');
    }
}
