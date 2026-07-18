<?php

namespace App\Events;

use App\Models\LmsMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $chatType,
        public int $chatId,
        public array $message,
    ) {}

    public function broadcastOn(): array
    {
        if ($this->chatType === 'group') {
            return [
                new PresenceChannel('chat.group.' . $this->chatId),
            ];
        }

        return [
            new PresenceChannel('chat.dm.' . $this->chatId),
        ];
    }

    public function broadcastWith(): array
    {
        return $this->message;
    }

    public function broadcastAs(): string
    {
        return 'message.created';
    }
}
