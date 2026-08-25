<?php

namespace App\Events;

use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Broadcast when a message's reaction set changes (a reactor added or removed an
 * emoji). Rides the SAME presence channel as MessageSent so every open chat
 * client updates that message's reaction chips live, without a reload. The
 * payload carries the fully re-aggregated reaction list for the message so
 * clients replace rather than patch.
 */
class ReactionUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $chatType,
        public int $chatId,
        public int $messageId,
        public array $reactions,
    ) {}

    public function broadcastOn(): array
    {
        if ($this->chatType === 'group') {
            return [new PresenceChannel('chat.group.' . $this->chatId)];
        }

        return [new PresenceChannel('chat.dm.' . $this->chatId)];
    }

    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->messageId,
            'reactions' => $this->reactions,
        ];
    }

    public function broadcastAs(): string
    {
        return 'reaction.updated';
    }
}
