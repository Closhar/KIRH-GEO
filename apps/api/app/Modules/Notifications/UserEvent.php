<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

final class UserEvent implements ShouldBroadcastNow
{
    public function __construct(private string $userId, public string $type, public string $event_id) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('users.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'geo.event';
    }

    public function broadcastWith(): array
    {
        return ['type' => $this->type, 'event_id' => $this->event_id];
    }
}
