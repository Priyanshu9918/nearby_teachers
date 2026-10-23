<?php

namespace App\Events;

use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** Ephemeral hints should not wait behind the persistent-message queue. */
class ChatTyping extends ChatActivity implements ShouldBroadcastNow {}
