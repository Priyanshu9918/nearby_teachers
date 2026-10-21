<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class RevokeChatSessions implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 10;

    public function __construct(public string $userId, public int $authVersion)
    {
        $this->onQueue('broadcasts');
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function handle(): void
    {
        if (config('broadcasting.default') !== 'socketio') {
            return;
        }
        $secret = (string) config('broadcasting.connections.socketio.secret');
        if (strlen($secret) < 32) {
            throw new RuntimeException('Socket.IO bridge secret is not configured.');
        }
        Http::timeout(5)->connectTimeout(2)->withHeaders(['X-Socket-Secret' => $secret])
            ->post(rtrim(config('broadcasting.connections.socketio.url'), '/').'/internal/sessions/revoke', [
                'userId' => $this->userId, 'authVersion' => $this->authVersion,
            ])->throw();
    }
}
