<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class ChatActivity implements ShouldBroadcast
{
    use Dispatchable;

    public string $broadcastQueue = 'broadcasts';

    public bool $afterCommit = true;

    public function __construct(public readonly string $event, public readonly array $data) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversation.'.$this->data['conversationId'])];
    }

    public function broadcastAs(): string
    {
        return $this->event;
    }

    public function broadcastWith(): array
    {
        return ['data' => $this->data];
    }
}
