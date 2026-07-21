<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TimeEntryRecorded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $tenantId,
        public int $userId,
        public string $type,   // 'check_in', 'check_out', 'break_start', etc.
        public string $time,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('tenant.' . $this->tenantId . '.clock')];
    }

    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->userId,
            'type' => $this->type,
            'time' => $this->time,
        ];
    }
}
