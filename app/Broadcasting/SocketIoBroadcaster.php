<?php

namespace App\Broadcasting;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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
        $conversationId = $message['conversation_id'] ?? null;
        if ($event !== 'message.sent' || ! is_string($conversationId) || ! Str::isUuid($conversationId)
            || $this->formatChannels($channels) !== ['private-conversation.'.$conversationId]) {
            throw new RuntimeException('Unsupported Socket.IO chat broadcast.');
        }
        $conversation = DB::table('conversations')->where('id', $conversationId)
            ->select('learner_id', 'provider_id')->first();
        if ($conversation === null) {
            return;
        }

        // Called by the broadcasts queue worker, never by the mobile client.
        Http::timeout(5)->connectTimeout(2)
            ->withHeaders(['X-Socket-Secret' => $secret])
            ->post(rtrim($this->config['url'], '/').'/internal/events', [
                'message' => $message,
                'recipientIds' => [$conversation->learner_id, $conversation->provider_id],
            ])->throw();
    }
}
